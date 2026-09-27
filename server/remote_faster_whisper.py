#!/usr/bin/env python3

# Remote Faster Whisper
# An API interface for Faster Whisper to parse audio over HTTP
#
#    Copyright (C) 2023 Joshua M. Boniface <joshua@boniface.me>
#
#    This program is free software: you can redistribute it and/or modify
#    it under the terms of the GNU General Public License as published by
#    the Free Software Foundation, version 3.
#
#    This program is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU General Public License for more details.
#
#    You should have received a copy of the GNU General Public License
#    along with this program.  If not, see <https://www.gnu.org/licenses/>.
#
###############################################################################

from configargparse import ArgParser
from flask import Flask, Blueprint, request
from speech_recognition.audio import AudioData
from faster_whisper import WhisperModel
from io import BytesIO
from os.path import exists
from os import makedirs
from time import time
from yaml import safe_load
from speech_recognition import Recognizer, AudioFile
from numpy import float32
from soundfile import read as sf_read
from re import sub, search

import faster_whisper.utils
import requests as _requests

try:
    from rapidfuzz.distance import Levenshtein as _RF_LEV
except ImportError:
    _RF_LEV = None

try:
    from pymorphy3 import MorphAnalyzer as _MorphAnalyzer
except ImportError:
    _MorphAnalyzer = None

_TRANSLIT_MULTI = [
    ("sch", "ш"), ("sh", "ш"), ("ch", "ч"), ("zh", "ж"), ("kh", "х"), ("th", "т"),
    ("ph", "ф"), ("ck", "к"), ("qu", "кв"), ("ts", "ц"), ("oo", "у"), ("ee", "и"),
    ("ea", "и"), ("ya", "я"), ("yu", "ю"), ("ye", "е"), ("ow", "ов"),
]
_TRANSLIT_ONE = dict(zip("abcdefghijklmnopqrstuvwxyz",
                         ["а", "б", "к", "д", "е", "ф", "г", "х", "и", "дж", "к", "л", "м",
                          "н", "о", "п", "к", "р", "с", "т", "у", "в", "у", "кс", "и", "з"]))


def _translit_word(w):
    s, out, i = w.lower(), "", 0
    if len(s) > 3 and s.endswith("e") and s[-2] not in "aeiouy":
        s = s[:-1]  # silent final e: Stride -> Стрид
    if s.startswith("h"):
        out, i = "г", 1  # Russian name tradition: Howard -> Говард, Harry -> Гарри
    while i < len(s):
        for lat, cyr in _TRANSLIT_MULTI:
            if s.startswith(lat, i):
                out += cyr
                i += len(lat)
                break
        else:
            out += _TRANSLIT_ONE.get(s[i], s[i])
            i += 1
    return out[:1].upper() + out[1:]


def _cyrillize_names(csv):
    """'Kupitman the Screaming Healer, Todd Howard' -> 'Купитман, Тодд Ховард'.
    Russian Whisper never outputs Latin, so Latin NPC names can't bias or match."""
    if not csv:
        return csv
    out = []
    for entry in csv.split(","):
        e = entry.strip()
        if search(r"[A-Za-z]", e):
            e = sub(r"\s+(the|of|from)\s+.*$", "", e, flags=2)  # drop epithets
            e = sub(r"[A-Za-z]+", lambda m: _translit_word(m.group(0)), e)
        if e:
            out.append(e)
    return ", ".join(out)


# Stock phrases Russian Whisper emits on silence/noise
_HALLUCINATIONS = {
    "и другие",
    "и многое другое",
    "и так далее",
    "субтитры создавал dimatorzok",
    "продолжение следует",
    "спасибо за просмотр",
    "редактор субтитров асинкевич корректор аегорова",
}

# Noun case endings a declined name can carry after its stem ("" = unchanged)
_CASE_ENDINGS = {
    "", "а", "я", "у", "ю", "е", "и", "ы", "ь", "ом", "ем", "ём", "ой", "ей",
    "ою", "ею", "ам", "ям", "ах", "ях", "ов", "ев", "ём", "ии", "ия", "ию",
}


class FasterWhisperApi:
    def __init__(
        self,
        listen="127.0.0.1",
        port=9876,
        base_url="/api/v0",
        faster_whisper_config={},
        transformations={},
    ):
        """
        Initialize the API and Faster Whisper configuration
        """
        self.app = Flask(__name__)
        self.blueprint = Blueprint("api", __name__, url_prefix=base_url)

        self.listen = listen
        self.port = port

        self.transformations = transformations

        self.model_cache_dir = faster_whisper_config.get(
            "model_cache_dir", "/tmp/whisper-cache"
        )
        self.model = faster_whisper_config.get("model", "base")
        self.device = faster_whisper_config.get("device", "auto")
        self.device_index = faster_whisper_config.get("device_index", 0)
        self.compute_type = faster_whisper_config.get("compute_type", "int8")
        self.beam_size = faster_whisper_config.get("beam_size", 5)
        self.translate = faster_whisper_config.get("translate", False)
        self.language = faster_whisper_config.get("language", None)
        if not self.language:
            self.language = None
        self.hotwords = faster_whisper_config.get("hotwords", None)
        if not self.hotwords:
            self.hotwords = None

        # Recognition engine: "whisper" (local faster-whisper model) or
        # "gigaam" (Sber GigaAM v3 HTTP service; the Whisper model is not loaded)
        self.engine = str(faster_whisper_config.get("engine", "whisper")).lower()
        self.gigaam_url = faster_whisper_config.get(
            "gigaam_url", "http://127.0.0.1:8026/transcribe"
        )

        # Static proper-noun dictionary for fuzzy post-correction (one entry
        # per line; multi-word entries contribute their capitalized words)
        self._morph = _MorphAnalyzer() if _MorphAnalyzer else None
        print(f"Dictionary-word guard: {'on' if self._morph else 'OFF (pip install pymorphy3 pymorphy3-dicts-ru)'}")
        self.static_lexicon = {}
        self._lex_buckets = {}
        lex_file = faster_whisper_config.get("lexicon_file")
        if lex_file and exists(lex_file):
            try:
                with open(lex_file, encoding="utf-8") as fh:
                    for line in fh:
                        for token in line.replace(",", " ").replace("-", " ").split():
                            t = token.strip(" .,'’")
                            if len(t) >= 4 and t[:1].isupper() and t[1:].islower():
                                self.static_lexicon.setdefault(t.lower(), t)
                for low, orig in self.static_lexicon.items():
                    self._lex_buckets.setdefault(len(low), []).append((low, orig))
                print(f"Loaded {len(self.static_lexicon)} lexicon tokens from {lex_file}")
            except Exception as exc:
                print(f"Failed to load lexicon file {lex_file}: {exc}")

        self.save_audio = faster_whisper_config.get("debug", {}).get("save_audio")
        if self.save_audio:
            self.save_path = faster_whisper_config.get("debug", {}).get("save_path")

        if self.save_audio:
            if not exists(self.save_path):
                makedirs(self.save_path)

        @self.blueprint.route("/transcribe", methods=["POST"])
        def transcribe():
            try:
                f = request.files["audio_file"]
            except Exception:
                return {
                    "message": "Request data did not contain an 'audio_file' in its files"
                }, 400

            try:
                rec = Recognizer()
                with AudioFile(f) as source:
                    audio = rec.record(source)

                assert isinstance(audio, AudioData)
                data = audio.get_wav_data(convert_rate=16000)
                if self.save_audio:
                    runtime = time()
                    makedirs(f"{self.save_path}/{runtime}")
                    with open(f"{self.save_path}/{runtime}/audio.wav", "wb") as fh:
                        fh.write(data)

            except Exception:
                return {
                    "message": "The 'audio_file' must contain valid WAV audio data"
                }, 400

            request_hotwords = _cyrillize_names(request.form.get("hotwords", "").strip())
            request_lexicon = _cyrillize_names(request.form.get("lexicon", "").strip())
            return self.perform_faster_whisper_recognition(
                audio, request_hotwords, request_lexicon
            )

        self.app.register_blueprint(self.blueprint)

    def start(self):
        """
        Initialize the WhisperModel (including downloading the model files) and start the API
        """
        if self.engine == "gigaam":
            print(f"Engine: GigaAM at {self.gigaam_url} (Whisper model not loaded)")
        else:
            print("Initializing WhisperModel instance")
            self.whisper_model = WhisperModel(
                self.model,
                device=self.device,
                device_index=self.device_index,
                compute_type=self.compute_type,
                download_root=self.model_cache_dir,
            )

        print("Starting API")
        self.app.run(debug=False, host=self.listen, port=self.port)

    @staticmethod
    def _levenshtein(a, b, cutoff):
        if abs(len(a) - len(b)) > cutoff:
            return None
        prev = list(range(len(b) + 1))
        for i, ca in enumerate(a, 1):
            cur = [i]
            best = i
            for j, cb in enumerate(b, 1):
                cur.append(min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (ca != cb)))
                best = min(best, cur[j])
            if best > cutoff:
                return None
            prev = cur
        return prev[-1] if prev[-1] <= cutoff else None

    def _lev(self, a, b, cutoff):
        if _RF_LEV is not None:
            d = _RF_LEV.distance(a, b, score_cutoff=cutoff)
            return d if d <= cutoff else None
        return self._levenshtein(a, b, cutoff)

    def _is_dict_word(self, wl):
        return self._morph is not None and self._morph.word_is_known(wl)

    @staticmethod
    def _name_tokens(source):
        # multi-word entries ("Таверна Спящий великан") contribute their
        # individual capitalized words to the correction dictionary
        out = {}
        for entry in (source or "").replace("-", " ").split(","):
            for token in entry.strip().split():
                t = token.strip(" .,'’")
                if len(t) >= 4 and t[:1].isupper():
                    out.setdefault(t.lower(), t)
        return out

    def _fuzzy_fix_names(self, text, hotwords, lexicon=""):
        """Snap near-miss proper nouns to known names (unstressed-vowel typos etc.)"""
        # near: recent/nearby NPC names (hotwords) — the only targets allowed to
        # replace a real dictionary word; dyn adds the whole playthrough lexicon
        near = self._name_tokens(hotwords)
        dyn = dict(near)
        for k, v in self._name_tokens(lexicon).items():
            dyn.setdefault(k, v)
        if not dyn and not self.static_lexicon:
            return text
        def is_declined(wl, nl):
            # Russian case endings replace/append the last 1-2 letters, so a
            # word sharing the stem with a known name is that name, declined.
            # The added tail must be a real case ending: "Скульвара" is the
            # name declined, "Скульвард" is a mishearing to correct.
            if wl == nl or (wl.startswith(nl) and wl[len(nl):] in _CASE_ENDINGS):
                return True
            i, m = 0, min(len(wl), len(nl))
            while i < m and wl[i] == nl[i]:
                i += 1
            return i >= len(nl) - 1 and len(nl) - i <= 1 and wl[i:] in _CASE_ENDINGS

        from re import finditer
        out = text

        # merge pass: ASR sometimes splits an unknown name into two plain words
        # ("аванчный зел" -> "Аванчнзел"); try joining adjacent word pairs
        tokens = list(finditer(r"[А-ЯЁа-яёA-Za-z']{3,}", out))
        for i in range(len(tokens) - 1, 0, -1):
            a, b = tokens[i - 1], tokens[i]
            if not out[a.end(): b.start()].isspace():
                continue
            joined = (a.group(0) + b.group(0)).lower()
            if len(joined) < 8:
                continue
            both_real = self._is_dict_word(a.group(0).lower()) and self._is_dict_word(b.group(0).lower())
            best = None
            pool = list(near.items())
            if not both_real:
                for ln in range(len(joined) - 2, len(joined) + 3):
                    pool.extend(self._lex_buckets.get(ln, ()))
            for low, orig in pool:
                d = self._lev(joined, low, 2)
                if d is not None and (best is None or d < best[0]):
                    best = (d, orig)
            if best:
                out = out[: a.start()] + best[1] + out[b.end():]

        for m in reversed(list(finditer(r"[А-ЯЁA-Z][а-яёa-z]{3,}", out))):
            word = m.group(0)
            wl = word.lower()
            L = len(wl)
            real_word = self._is_dict_word(wl)
            # GigaAM capitalizes what it takes for a name. A dictionary word
            # capitalized mid-sentence ("Ну чё, Скелет, ты...") is almost always
            # a garbled nearby name (Скульвар) — check that before the lexicon,
            # which may list the plain word as a lore token too.
            before = out[: m.start()].rstrip(" «\"'(")
            if real_word and before and before[-1] not in ".!?…—-:":
                if any(is_declined(wl, low) for low in near):
                    continue  # "Лидию": a nearby name in another case, keep it
                # Addressed by name ("Ну чё, Скелет, ты", "Эй, Скелет!"): GigaAM
                # writes plain vocatives in lowercase ("привет, брат"), so a
                # capitalized one is a name and may be garbled well past 40%.
                after = out[m.end(): m.end() + 1]
                vocative = before[-1] == "," and after in (",", "!", "?", ".", "")
                nb = self._nearby_match(wl, near, 0.65 if vocative else 0.4)
                if nb:
                    out = out[: m.start()] + nb + out[m.end():]
                    continue
            # dynamic names first: on equal distance they win over the lexicon
            cands = list(dyn.items())
            for ln in range(max(4, L - 3), L + 4):
                cands.extend(self._lex_buckets.get(ln, ()))
            # exact or declined form of a known name: leave untouched
            if any(low[0] == wl[0] and is_declined(wl, low) for low, _ in cands):
                continue
            # a real Russian word ("Джокер", "Туман") is only ever swapped for a
            # nearby NPC's name one letter away, never for random lore
            if not real_word:
                # The player mostly talks to/about the NPCs around them, and GigaAM
                # garbles names harder than Whisper did ("Скулер" for Скульвар,
                # "Садьер" for Саадия). A nearby name with the same opening wins
                # over a closer but unrelated lore word ("Скелет", "Брадин").
                nb = self._nearby_match(wl, near)
                if nb:
                    out = out[: m.start()] + nb + out[m.end():]
                    continue
            if real_word:
                cands = list(near.items())
            best = None
            for low, orig in cands:
                if real_word:
                    limit = 1
                elif len(low) >= 9:
                    limit = 3
                elif min(L, len(low)) < 6:
                    limit = 1
                else:
                    limit = 2
                d = self._lev(wl, low, limit)
                if d is not None and (best is None or d < best[0]):
                    best = (d, orig)
            if best:
                out = out[: m.start()] + best[1] + out[m.end():]

        # names heard in lowercase ("тот говар"): non-dictionary words only, and
        # only towards nearby NPC names, so ordinary speech is never touched
        if near and self._morph is not None:
            for m in reversed(list(finditer(r"(?<![А-ЯЁа-яё])[а-яё]{4,}", out))):
                wl = m.group(0)
                if self._is_dict_word(wl):
                    continue
                declined = next((orig + wl[len(low):] for low, orig in near.items()
                                 if wl.startswith(low) and wl[len(low):] in _CASE_ENDINGS), None)
                if declined:  # "толфдиру" -> "Толфдиру", keep the case ending
                    out = out[: m.start()] + declined + out[m.end():]
                    continue
                best = self._nearby_match(wl, near)
                if not best:  # strict fallback: names garbled in the first letters
                    close = None
                    for low, orig in near.items():
                        d = self._lev(wl, low, 2 if len(low) >= 7 else 1)
                        if d is not None and (close is None or d < close[0]):
                            close = (d, orig)
                    best = close[1] if close else None
                if best:
                    out = out[: m.start()] + best + out[m.end():]
        return out

    def _nearby_match(self, wl, near, ratio=0.4):
        """Closest nearby NPC name for a non-dictionary word, or None.

        Same first two letters, then up to ~40% of the letters may differ
        (at least 2) — enough for "скулер"->Скульвар, "садьер"->Саадия,
        "купитмат"->Купитман, while unrelated words stay far outside it.
        """
        best = None
        for low, orig in near.items():
            if len(low) < 4 or low[:2] != wl[:2]:
                continue
            if min(len(wl), len(low)) < 0.6 * max(len(wl), len(low)):
                continue  # "брат" is not a garbled "Бренуин"
            limit = max(2, round(ratio * max(len(wl), len(low))))
            d = self._lev(wl, low, limit)
            if d is not None and (best is None or d < best[0]):
                best = (d, orig)
        return best[1] if best else None

    def perform_faster_whisper_recognition(
        self, audio_data, request_hotwords="", request_lexicon=""
    ):
        """
        Perform recognition on {audio_data} with model
        """
        print("Performing recognition on audio data")

        # Merge static config hotwords with per-request ones (request last: the
        # tokenizer keeps the tail when the prompt budget overflows)
        hotwords = self.hotwords or ""
        if request_hotwords:
            hotwords = (hotwords + ", " if hotwords else "") + request_hotwords
        if not hotwords:
            hotwords = None
        else:
            # An open comma list makes the decoder continue the enumeration ("и ...")
            hotwords = hotwords.rstrip(" ,.") + "."

        t_start = time()
        wav_bytes = audio_data.get_wav_data(convert_rate=16000)
        wav_stream = BytesIO(wav_bytes)
        audio_array, sampling_rate = sf_read(wav_stream)
        audio_array = audio_array.astype(float32)

        if self.engine == "gigaam":
            resp = _requests.post(
                self.gigaam_url,
                files={"audio_file": ("audio.wav", wav_bytes, "audio/wav")},
                timeout=60,
            )
            resp.raise_for_status()
            text = resp.json().get("text", "").strip()
            language, language_probability = "ru", 1.0
            duration = len(audio_array) / float(sampling_rate)
        else:
            segments, info = self.whisper_model.transcribe(
                audio_array,
                beam_size=self.beam_size,
                language=self.language,
                task="translate" if self.translate else "transcribe",
                hotwords=hotwords,
                vad_filter=True,
                vad_parameters=dict(min_silence_duration_ms=500),
                condition_on_previous_text=False,
            )
            text = " ".join(segment.text for segment in segments).strip()
            language, language_probability = info.language, info.language_probability
            duration = info.duration

        # Leftover hotword-list continuation: "и Анкано..." at the very start
        text = sub(r"^[Ии]\s+(?=[А-ЯЁ])", "", text)
        if sub(r"[^\wа-яё ]", "", text.lower()).strip() in _HALLUCINATIONS:
            text = ""

        # Perform transformations on text
        if 'lower' in self.transformations:
            text = text.lower()
        if 'casefold' in self.transformations:
            text = text.casefold()
        if 'upper' in self.transformations:
            text = text.upper()
        if 'title' in self.transformations:
            text = text.title()
        for tr in self.transformations:
            if not isinstance(tr, list):
                continue
            if search(tr[0], text):
                _text = text
                text = sub(tr[0], tr[1], text)
                print(f'Transforming "{tr[0]}" -> "{tr[1]}": pre "{_text}", post "{text}"')

        text = self._fuzzy_fix_names(text, hotwords, request_lexicon)

        t_end = time()
        t_run = t_end - t_start

        result = {
            "text": text,
            "language": language,
            "language_probability": language_probability,
            "sample_duration": duration,
            "runtime": t_run,
        }

        print(f"Result: {result}")
        return result


def parse_args():
    """
    Parse CLI arguments/environment variables (configuration file path)
    """
    p = ArgParser()
    p.add(
        "-c",
        "--config",
        env_var="RFW_CONFIG_FILE",
        help="Configuration file path",
        required=True,
    )
    options = p.parse_args()
    return options


def parse_config(configfile):
    """
    Parse YAML configuration into {config} dictionary
    """
    with open(configfile, "r") as fh:
        config = safe_load(fh)

    return config


def start_api():
    """
    Parse arguments, grab configuration, and initialize and start the API
    """
    faster_whisper.utils._MODELS = {
        "tiny.en": "Systran/faster-whisper-tiny.en",
        "tiny": "Systran/faster-whisper-tiny",
        "base.en": "Systran/faster-whisper-base.en",
        "base": "Systran/faster-whisper-base",
        "small.en": "Systran/faster-whisper-small.en",
        "small": "Systran/faster-whisper-small",
        "medium.en": "Systran/faster-whisper-medium.en",
        "medium": "Systran/faster-whisper-medium",
        "large-v1": "Systran/faster-whisper-large-v1",
        "large-v2": "Systran/faster-whisper-large-v2",
        "large-v3": "Systran/faster-whisper-large-v3",
        "large": "Systran/faster-whisper-large-v3",
        "distil-large-v2": "Systran/faster-distil-whisper-large-v2",
        "distil-medium.en": "Systran/faster-distil-whisper-medium.en",
        "distil-small.en": "Systran/faster-distil-whisper-small.en",
        "distil-large-v3": "Systran/faster-distil-whisper-large-v3",
        "large-v3-turbo": "mobiuslabsgmbh/faster-whisper-large-v3-turbo",
        "turbo": "mobiuslabsgmbh/faster-whisper-large-v3-turbo",
        "numbat-base-skyrim-en":"Numbat/faster-skyrim-whisper-base.en"
    }
    
    options = parse_args()
    config = parse_config(options.config)
    api = FasterWhisperApi(
        **config["daemon"],
        faster_whisper_config=config["faster_whisper"],
        transformations=config.get("transformations", {}),
    )
    api.start()


# Main entrypoint
if __name__ == "__main__":
    start_api()
