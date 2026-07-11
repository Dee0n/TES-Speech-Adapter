#!/bin/bash
# Restart remote-faster-whisper if it died. No-op when the service is
# disabled (conf.sh option 0 removes the config.yaml symlink).
[ -f /home/dwemer/remote-faster-whisper/config.yaml ] || exit 0
pgrep -f '[r]emote_faster_whisper.py' >/dev/null && exit 0
bash /home/dwemer/remote-faster-whisper/start.sh
