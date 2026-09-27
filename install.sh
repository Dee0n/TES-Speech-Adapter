#!/bin/bash
# TES-Speech-Adapter installer for DwemerDistro (CHIM).
#
# Run INSIDE the DwemerAI4Skyrim3 WSL distro as root, e.g. from Windows:
#   wsl -d DwemerAI4Skyrim3 -- bash /path/to/install.sh [/mnt/x/path/to/Skyrim/Data]
#
# The optional argument is your Skyrim Data directory (for building the full
# game lexicon from BSA archives). Re-run this script after every CHIM
# "Update" — updates git-reset the patched files.
set -u

RFW=/home/dwemer/remote-faster-whisper
HERIKA=/var/www/html/HerikaServer
PIP=/home/dwemer/python-stt/bin/pip
PY=/home/dwemer/python-stt/bin/python3
SRC="$(cd "$(dirname "$0")" && pwd)"

if [ ! -d "$RFW" ] || [ ! -d "$HERIKA" ]; then
    echo "ERROR: DwemerDistro layout not found ($RFW / $HERIKA)."
    echo "Install the LocalWhisper component from the CHIM launcher first."
    exit 1
fi

echo "[1/9] Python dependencies (rapidfuzz, pymorphy3, ctranslate2>=4.6 for RTX 50xx, cuBLAS 12, lz4, requests)"
"$PIP" install -q rapidfuzz pymorphy3 pymorphy3-dicts-ru 'ctranslate2>=4.6' nvidia-cublas-cu12 lz4 requests

echo "[2/9] Whisper server + Russian configs"
cp "$SRC/server/remote_faster_whisper.py" "$RFW/"
cp "$SRC/configs/"config-Large-GPU-RU*.yaml "$RFW/"
if ! grep -q 'site-packages/nvidia' "$RFW/start.sh"; then
    # '#' delimiter: the replacement itself contains a '|' pipe.
    sed -i 's#^\#\?lib_path=.*#lib_path="$( find /home/dwemer/python-stt/lib/python3.11/site-packages/nvidia -maxdepth 3 -type d -name lib 2>/dev/null | paste -sd: )"#' "$RFW/start.sh"
fi

echo "[3/9] HerikaServer patches (dynamic hotwords, lexicon, XTTS RU punctuation, Gemini 3 reasoning)"
for p in "$SRC"/patches/herika-*.patch; do
    if patch -p1 -N --dry-run -d "$HERIKA" < "$p" >/dev/null 2>&1; then
        patch -p1 -N -d "$HERIKA" < "$p"
    else
        echo "  ! $(basename "$p") did not apply cleanly (already applied, or upstream changed)."
        echo "  ! Check manually: $p"
    fi
done
# Database tweaks (e.g. enable NPCs taking gold from the player's inventory)
runuser -u dwemer -- psql --no-password -U dwemer -d dwemer -q -f "$SRC/settings/chim_settings.sql" \
    || echo "  ! settings/chim_settings.sql failed (is PostgreSQL running?)"

echo "[4/9] TES lexicon"
cp "$SRC/lexicon/tes_lexicon_ru.txt" "$HERIKA/stt/"
if [ $# -ge 1 ] && [ -d "$1" ]; then
    echo "  Building full lexicon from $1 ..."
    "$PY" "$SRC/lexicon/extract_tes_lexicon.py" "$1" "$RFW/tes_lexicon_full_ru.txt" | tail -1
else
    echo "  (no Data dir given — skipping full BSA lexicon; curated file still active)"
fi

echo "[5/9] Watchdog (auto-restart whisper if it dies)"
cp "$SRC/watchdog/watchdog.sh" "$RFW/"
chmod +x "$RFW/watchdog.sh"
( crontab -u dwemer -l 2>/dev/null | grep -v 'watchdog.sh'; echo '* * * * * /home/dwemer/remote-faster-whisper/watchdog.sh >/dev/null 2>&1' ) | crontab -u dwemer -
grep -q 'service cron start' /etc/wsl.conf 2>/dev/null || printf '\n[boot]\ncommand = service cron start\n' >> /etc/wsl.conf
service cron start 2>/dev/null || true

echo "[6/9] Activate Russian config and restart service"
ln -sf "$RFW/config-Large-GPU-RU-int8.yaml" "$RFW/config.yaml"
chown -R dwemer:dwemer "$RFW" 2>/dev/null || true
pkill -f 'remote_faster_whisper[.]py' 2>/dev/null || true

echo "[7/9] CHIM-MCP fix (SSE /message route; enable flag ownership)"
MCP=/home/dwemer/CHIM-MCP
if [ -d "$MCP/src" ]; then
    # The component installer fails with "Permission denied" if this flag is root-owned.
    [ -f /home/dwemer/.mcp_enabled ] && chown dwemer:dwemer /home/dwemer/.mcp_enabled
    if patch -p1 -N --dry-run -d "$MCP" < "$SRC/patches/chimmcp-sse-message.patch" >/dev/null 2>&1; then
        patch -p1 -N -d "$MCP" < "$SRC/patches/chimmcp-sse-message.patch"
        chown -R dwemer:dwemer "$MCP/src"
        runuser -u dwemer -- bash -c "cd $MCP && npm run build >/dev/null 2>&1" \
            && echo "  rebuilt; restart the distro (or CHIM-MCP) to load it" \
            || echo "  ! npm run build failed in $MCP"
    else
        echo "  (already applied, or upstream changed — check $SRC/patches/chimmcp-sse-message.patch)"
    fi
else
    echo "  (CHIM-MCP not installed — skipped)"
fi

echo "[8/9] GigaAM v3 speech recognition service (engine: gigaam in the RU configs)"
GIGA=/home/dwemer/gigaam
if [ ! -x "$GIGA/venv/bin/python" ]; then
    echo "  Creating $GIGA venv (torch cu128 + GigaAM, several GB, one-time)..."
    runuser -u dwemer -- bash -c "mkdir -p $GIGA && python3 -m venv $GIGA/venv \
        && $GIGA/venv/bin/pip install -q --upgrade pip setuptools wheel \
        && $GIGA/venv/bin/pip install -q torch==2.8.0 torchaudio==2.8.0 --index-url https://download.pytorch.org/whl/cu128 \
        && $GIGA/venv/bin/pip install -q 'gigaam @ git+https://github.com/salute-developers/GigaAM.git' fastapi uvicorn python-multipart soundfile" \
        || echo "  ! GigaAM install failed — set 'engine: whisper' in $RFW/config.yaml to fall back"
fi
cp "$SRC/server/gigaam_server.py" "$GIGA/server.py"
chown dwemer:dwemer "$GIGA/server.py"
pkill -f '[g]igaam/server.py' 2>/dev/null || true   # watchdog restarts it with the new code

echo "[9/9] F5-TTS with Russian Skyrim dub voices (port 8025)"
bash "$SRC/tts/install_f5.sh"

echo "[10/10] Vanilla NPC biographies for localized names (refid -> EditorID -> bio_templates)"
BIO=/home/dwemer/.local/share/tes-adapter
runuser -u dwemer -- mkdir -p "$BIO"
if [ $# -ge 1 ] && [ -d "$1" ]; then
    runuser -u dwemer -- python3 "$SRC/tools/esm_npc_map.py" "$1" "$BIO/npc_map.tsv"
fi
if [ -s "$BIO/npc_map.tsv" ]; then
    ( crontab -u dwemer -l 2>/dev/null | grep -v 'fill_bios.py'; echo "*/2 * * * * python3 $SRC/tools/fill_bios.py $BIO/npc_map.tsv >> $BIO/fill_bios.log 2>&1" ) | crontab -u dwemer -
    runuser -u dwemer -- python3 "$SRC/tools/fill_bios.py" "$BIO/npc_map.tsv"
else
    echo "  (no Data dir given and no saved map — pass your Skyrim Data dir to enable)"
fi
echo
echo "Done. The watchdogs start the services within a minute."
echo "Last steps (manual, once) in the CHIM web UI:"
echo "  STT: Configuration -> STT -> 'Local Whisper' (URL http://127.0.0.1:9876/api/v0/transcribe)."
echo "  TTS: add an XTTS connector, URL http://127.0.0.1:8025, language ru, voice by voicetype,"
echo "       and select it in your profile."
