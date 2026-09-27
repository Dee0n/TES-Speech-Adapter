#!/bin/bash
# Restart remote-faster-whisper if it died. No-op when the service is
# disabled (conf.sh option 0 removes the config.yaml symlink).
[ -f /home/dwemer/remote-faster-whisper/config.yaml ] || exit 0

# GigaAM backend (engine: gigaam in config.yaml) runs as its own service.
if grep -q '^ *engine: *gigaam' /home/dwemer/remote-faster-whisper/config.yaml \
   && [ -x /home/dwemer/gigaam/venv/bin/python ] \
   && ! pgrep -f '[g]igaam/server.py' >/dev/null; then
    cd /home/dwemer/gigaam && setsid nohup venv/bin/python /home/dwemer/gigaam/server.py \
        >> /home/dwemer/gigaam/server.log 2>&1 < /dev/null &
fi

pgrep -f '[r]emote_faster_whisper.py' >/dev/null && exit 0
bash /home/dwemer/remote-faster-whisper/start.sh
