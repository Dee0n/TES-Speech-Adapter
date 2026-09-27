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

echo "[1/7] Python dependencies (rapidfuzz, pymorphy3, ctranslate2>=4.6 for RTX 50xx, cuBLAS 12, lz4)"
"$PIP" install -q rapidfuzz pymorphy3 pymorphy3-dicts-ru 'ctranslate2>=4.6' nvidia-cublas-cu12 lz4

echo "[2/7] Whisper server + Russian configs"
cp "$SRC/server/remote_faster_whisper.py" "$RFW/"
cp "$SRC/configs/"config-Large-GPU-RU*.yaml "$RFW/"
if ! grep -q 'site-packages/nvidia' "$RFW/start.sh"; then
    sed -i 's|^#\?lib_path=.*|lib_path="$( find /home/dwemer/python-stt/lib/python3.11/site-packages/nvidia -maxdepth 3 -type d -name lib 2>/dev/null | paste -sd: )"|' "$RFW/start.sh"
fi

echo "[3/7] HerikaServer patches (dynamic hotwords, lexicon, XTTS RU punctuation, Gemini 3 reasoning)"
for p in "$SRC"/patches/herika-*.patch; do
    if patch -p1 -N --dry-run -d "$HERIKA" < "$p" >/dev/null 2>&1; then
        patch -p1 -N -d "$HERIKA" < "$p"
    else
        echo "  ! $(basename "$p") did not apply cleanly (already applied, or upstream changed)."
        echo "  ! Check manually: $p"
    fi
done

echo "[4/7] TES lexicon"
cp "$SRC/lexicon/tes_lexicon_ru.txt" "$HERIKA/stt/"
if [ $# -ge 1 ] && [ -d "$1" ]; then
    echo "  Building full lexicon from $1 ..."
    "$PY" "$SRC/lexicon/extract_tes_lexicon.py" "$1" "$RFW/tes_lexicon_full_ru.txt" | tail -1
else
    echo "  (no Data dir given — skipping full BSA lexicon; curated file still active)"
fi

echo "[5/7] Watchdog (auto-restart whisper if it dies)"
cp "$SRC/watchdog/watchdog.sh" "$RFW/"
chmod +x "$RFW/watchdog.sh"
( crontab -u dwemer -l 2>/dev/null | grep -v 'watchdog.sh'; echo '* * * * * /home/dwemer/remote-faster-whisper/watchdog.sh >/dev/null 2>&1' ) | crontab -u dwemer -
grep -q 'service cron start' /etc/wsl.conf 2>/dev/null || printf '\n[boot]\ncommand = service cron start\n' >> /etc/wsl.conf
service cron start 2>/dev/null || true

echo "[6/7] Activate Russian config and restart service"
ln -sf "$RFW/config-Large-GPU-RU-int8.yaml" "$RFW/config.yaml"
chown -R dwemer:dwemer "$RFW" 2>/dev/null || true
pkill -f 'remote_faster_whisper[.]py' 2>/dev/null || true

echo "[7/7] CHIM-MCP fix (SSE /message route; enable flag ownership)"
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
echo
echo "Done. The watchdog starts the service within a minute."
echo "Last step (manual): in the CHIM web UI open Configuration -> STT and"
echo "select 'Local Whisper' (URL http://127.0.0.1:9876/api/v0/transcribe)."
