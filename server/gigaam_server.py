"""GigaAM v3 (Russian ASR with punctuation) over HTTP: POST /transcribe (audio_file) -> {"text"}."""
import io
import os
import tempfile
import time

import gigaam
import soundfile as sf
import uvicorn
from fastapi import FastAPI, File, UploadFile

MODEL = os.environ.get("GIGAAM_MODEL", "v3_e2e_rnnt")
model = gigaam.load_model(MODEL)
app = FastAPI()


@app.get("/health")
def health():
    return {"status": "ok", "model": MODEL}


CHUNK_S = 20  # GigaAM's short-form transcribe() rejects audio over ~25 s


def _run(path):
    text = model.transcribe(path)
    if not isinstance(text, str):  # some versions return an object/segments
        text = getattr(text, "text", str(text))
    return text.strip()


@app.post("/transcribe")
async def transcribe(audio_file: UploadFile = File(...)):
    data = await audio_file.read()
    t0 = time.time()
    audio, sr = sf.read(io.BytesIO(data), dtype="float32")
    if audio.ndim > 1:
        audio = audio.mean(axis=1)
    step = CHUNK_S * sr
    parts = []
    for start in range(0, max(len(audio), 1), step):
        chunk = audio[start:start + step]
        if len(chunk) < sr // 4:  # skip trailing slivers under 0.25 s
            continue
        with tempfile.NamedTemporaryFile(suffix=".wav") as tmp:
            sf.write(tmp.name, chunk, sr)
            parts.append(_run(tmp.name))
    return {"text": " ".join(p for p in parts if p), "runtime": round(time.time() - t0, 3)}


if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=int(os.environ.get("PORT", "8026")), log_level="warning")
