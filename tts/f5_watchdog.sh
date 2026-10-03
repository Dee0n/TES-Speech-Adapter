#!/bin/bash
# Starts the F5-TTS RU server if nothing is listening on its port (cron, every minute).
R=/home/dwemer/f5-tts
PORT=8025
[ -f "$R/disabled" ] && exit 0
curl -s -m 3 -o /dev/null "http://127.0.0.1:$PORT/" && exit 0
pgrep -f "[f]5_server.py" >/dev/null && exit 0   # still loading the model
cd "$R" || exit 1
setsid nohup "$R/venv/bin/python" "$R/f5_server.py" --port $PORT >> "$R/server.log" 2>&1 < /dev/null &
