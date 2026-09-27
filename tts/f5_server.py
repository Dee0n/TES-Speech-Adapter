"""XTTS-compatible F5-TTS server for CHIM (Russian, Skyrim dub voices).

CHIM's xtts-fastapi driver POSTs {"text", "speaker_wav", "language"} to
/tts_to_audio and expects WAV bytes back. Voices are reference clips from the
Russian Skyrim dub in voices/<voicetype>.wav; their transcripts are made once
(via the local Whisper server) and cached next to them as <voicetype>.txt.
"""
import argparse
import io
import os
import re
import shutil
import threading
import time

import numpy as np
import requests
import soundfile as sf
import uvicorn
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse, Response

ROOT = os.path.dirname(os.path.abspath(__file__))
VOICES = os.path.join(ROOT, "voices")
PREPARED = os.path.join(VOICES, "prepared")
os.makedirs(PREPARED, exist_ok=True)
CKPT = os.path.join(ROOT, "models/F5TTS_v1_Base_v4_winter/model_212000.safetensors")
VOCAB = os.path.join(ROOT, "models/F5TTS_v1_Base/vocab.txt")
STT_URL = "http://127.0.0.1:8026/transcribe"  # GigaAM directly: keeps reference prep out of the player STT logs

ap = argparse.ArgumentParser()
ap.add_argument("--port", type=int, default=8022)
ap.add_argument("--nfe", type=int, default=16)
ap.add_argument("--speed", type=float, default=1.0)
args = ap.parse_args()

from f5_tts.api import F5TTS  # noqa: E402  (heavy import after arg parsing)
from f5_tts.infer.utils_infer import preprocess_ref_audio_text  # noqa: E402
from ruaccent import RUAccent  # noqa: E402

print("Loading F5-TTS ...", flush=True)
tts = F5TTS(model="F5TTS_v1_Base", ckpt_file=CKPT, vocab_file=VOCAB, device="cuda")
accent = RUAccent()
accent.load(omograph_model_size="turbo3.1", use_dictionary=True, tiny_mode=False)


def _fill_missing_inputs(session):
    # Newer tokenizers stop returning token_type_ids, but RUAccent's ONNX
    # stress model still requires it, so every out-of-dictionary word
    # (Вайтран, каджит...) failed. Feed zeros like the old tokenizer did.
    run, required = session.run, [i.name for i in session.get_inputs()]

    def patched(output_names, feed, *a, **k):
        for name in required:
            if name not in feed and "input_ids" in feed:
                feed[name] = np.zeros_like(feed["input_ids"])
        return run(output_names, feed, *a, **k)

    session.run = patched


_fill_missing_inputs(accent.accent_model.session)
lock = threading.Lock()
app = FastAPI()


def voices():
    return sorted(f[:-4] for f in os.listdir(VOICES) if f.lower().endswith(".wav"))


def stress(text):
    # The model was trained on text with "+" before stressed vowels.
    try:
        return accent.process_all(text)
    except Exception as e:
        print(f"[ruaccent] {e}", flush=True)
        return text


def reference(voice):
    """(wav, text) for a voice, prepared once and cached in voices/prepared/.

    F5 silently clips references over 12 s. If the transcript still covers the
    full clip, the words cut from the audio leak into the start of every
    generated line (malenord began each line with "...возможно, бандиты").
    So clip first with F5's own preprocessing, then transcribe the clipped audio.
    """
    wav = os.path.join(PREPARED, voice + ".wav")
    txt = os.path.join(PREPARED, voice + ".txt")
    if not (os.path.exists(wav) and os.path.exists(txt)):
        clipped, _ = preprocess_ref_audio_text(
            os.path.join(VOICES, voice + ".wav"), "-", show_info=lambda *a, **k: None
        )
        shutil.copyfile(clipped, wav)
        with open(wav, "rb") as f:
            r = requests.post(STT_URL, files={"audio_file": f}, timeout=120)
        open(txt, "w", encoding="utf-8").write(stress(r.json()["text"].strip()))
    return wav, open(txt, encoding="utf-8").read().strip()


def clean(text):
    text = re.sub(r"\[[^\]]*\]|\*[^*]*\*", " ", text)  # paralinguistic tags, *actions*
    text = text.replace("…", ",").replace("...", ",")
    return re.sub(r"\s+", " ", text).strip()


def pick_voice(requested):
    v = str(requested or "").replace("\\", "/").split("/")[-1]
    v = v[:-4] if v.lower().endswith(".wav") else v
    have = set(voices())
    if v in have:
        return v
    if v.lower() in have:
        return v.lower()
    fallback = "femalenord" if v.lower().startswith("female") else "malenord"
    print(f"[voice] '{v}' not found, using {fallback}", flush=True)
    return fallback


@app.get("/")
def root():
    return {"engine": "F5-TTS RU", "voices": len(voices())}


@app.get("/speakers")
@app.get("/speakers_list")
def speakers():
    return voices()


@app.get("/languages")
def languages():
    return {"languages": ["ru"]}


@app.post("/set_tts_settings")
def settings():
    return {"message": "ignored"}


@app.post("/tts_to_audio")
@app.post("/tts_to_audio/")
async def tts_to_audio(req: Request):
    data = await req.json()
    text = clean(data.get("text", ""))
    if not text:
        return JSONResponse({"error": "empty text"}, 400)
    voice = pick_voice(data.get("speaker_wav"))
    t0 = time.time()
    with lock:
        ref_wav, ref_txt = reference(voice)
        wav, sr, _ = tts.infer(
            ref_file=ref_wav,
            ref_text=ref_txt,
            gen_text=stress(text),
            nfe_step=args.nfe,
            speed=args.speed,
            remove_silence=False,
            show_info=lambda *a, **k: None,
        )
    buf = io.BytesIO()
    sf.write(buf, wav, sr, format="WAV", subtype="PCM_16")
    print(f"[tts] {voice} {len(text)} chars -> {len(wav)/sr:.1f}s audio in {time.time()-t0:.2f}s", flush=True)
    return Response(buf.getvalue(), media_type="audio/wav")


def prepare_all():
    # Clip + transcribe every reference up front so no NPC's first line waits for it.
    for v in voices():
        try:
            with lock:
                reference(v)
        except Exception as e:
            print(f"[prepare] {v}: {e}", flush=True)
    print("[prepare] all voices ready", flush=True)


if __name__ == "__main__":
    print(f"F5-TTS RU ready: {len(voices())} voices, port {args.port}", flush=True)
    threading.Thread(target=prepare_all, daemon=True).start()
    uvicorn.run(app, host="0.0.0.0", port=args.port, log_level="warning")
