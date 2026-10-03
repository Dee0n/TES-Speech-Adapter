#!/bin/bash
# Re-apply the local HerikaServer core patches after a CHIM "Update" (it does
# `git reset --hard origin/aiagent`, which silently drops them - happened 2026-10-04 00:17:
# herikaQueueGodCommands vanished, so god commands and house sales queued nothing).
# Usage (root, inside the distro): bash tools/restore_core.sh
set -e
cd /var/www/html/HerikaServer
G="git -c safe.directory=* -c user.name=tes -c user.email=tes@local"
if grep -q 'function herikaQueueGodCommands' functions/functions.php; then echo "core patches already present"; exit 0; fi
if $G rev-parse -q --verify tes-local >/dev/null && $G merge --ff-only tes-local 2>/dev/null; then
    echo "restored by fast-forward to tes-local"
else
    # upstream moved: replay the saved commits on top of it
    $G am -3 /home/dwemer/TES-Speech-Adapter/patches/herika-core-local.mbox || { $G am --abort; echo "CONFLICT: upstream changed the same code - resolve by hand"; exit 1; }
    $G branch -f tes-local HEAD
    echo "restored by replaying patches on the new upstream"
fi
for f in $($G diff --name-only origin/aiagent HEAD | grep '\.php$'); do php -l "$f" > /dev/null || echo "LINT FAIL $f"; done
chown -R dwemer:www-data functions lib main.php processor connector prompts 2>/dev/null || true
