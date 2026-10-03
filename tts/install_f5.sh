#!/bin/bash
# F5-TTS (Russian) with Russian Skyrim dub reference voices, served on port 8025
# in the XTTS API format CHIM's xtts-fastapi driver speaks.
#
# Model:  Misha24-10/F5-TTS_RUSSIAN (v4_winter), stress marks via RUAccent.
# Voices: 132 Russian dub clips per Skyrim voice type, from Yukio Connor's
#         "F5_Adapter (XTTS) (RU)" (Google Drive link in his CHIM video).
#         Game audio is not redistributed in this repo; it is downloaded here.
#
# Run as root (install.sh calls it); idempotent.
set -u
SRC="$(cd "$(dirname "$0")" && pwd)"
R=/home/dwemer/f5-tts
VOICES_GDRIVE_ID=1cyO-61pfPO3EaC1gHjQnwv2m_auqc3dX
as_dwemer() { runuser -u dwemer -- bash -c "$1"; }

as_dwemer "mkdir -p $R/models $R/voices"
if [ ! -x "$R/venv/bin/python" ] || ! "$R/venv/bin/python" -c 'import f5_tts, ruaccent' 2>/dev/null; then
    echo "  Creating $R venv (torch cu128 + F5-TTS + RUAccent, several GB, one-time)..."
    as_dwemer "python3 -m venv $R/venv \
        && $R/venv/bin/pip install -q --upgrade pip setuptools wheel \
        && $R/venv/bin/pip install -q torch==2.8.0 torchaudio==2.8.0 --index-url https://download.pytorch.org/whl/cu128 \
        && $R/venv/bin/pip install -q --no-build-isolation antlr4-python3-runtime==4.9.3 transformers_stream_generator \
        && $R/venv/bin/pip install -q f5-tts ruaccent fastapi uvicorn soundfile gdown py7zr" \
        || { echo "  ! F5-TTS install failed"; exit 1; }
fi

as_dwemer "$R/venv/bin/python - <<'EOF'
from huggingface_hub import hf_hub_download
for f in ['F5TTS_v1_Base_v4_winter/model_212000.safetensors', 'F5TTS_v1_Base/vocab.txt']:
    hf_hub_download('Misha24-10/F5-TTS_RUSSIAN', f, local_dir='$R/models')
EOF"

if [ "$(ls $R/voices/*.wav 2>/dev/null | wc -l)" -lt 100 ]; then
    echo "  Downloading Russian dub reference voices..."
    as_dwemer "cd /tmp && $R/venv/bin/python -m gdown -q $VOICES_GDRIVE_ID -O f5_adapter_ru.7z \
        && $R/venv/bin/python -c \"import py7zr; py7zr.SevenZipFile('f5_adapter_ru.7z').extractall('f5_adapter_ru')\" \
        && find f5_adapter_ru -path '*voices*' -name '*.wav' -exec cp {} $R/voices/ \; \
        ; rm -rf f5_adapter_ru f5_adapter_ru.7z"
fi
echo "  voices: $(ls $R/voices/*.wav 2>/dev/null | wc -l)"

cp "$SRC/f5_server.py" "$R/f5_server.py"
cp "$SRC/f5_watchdog.sh" "$R/watchdog.sh"
chmod +x "$R/watchdog.sh"
chown dwemer:dwemer "$R/f5_server.py" "$R/watchdog.sh"
( crontab -u dwemer -l 2>/dev/null | grep -v 'f5-tts/watchdog.sh'; echo '* * * * * /home/dwemer/f5-tts/watchdog.sh >/dev/null 2>&1' ) | crontab -u dwemer -
pkill -f '[f]5_server.py' 2>/dev/null || true   # watchdog restarts it with the new code
