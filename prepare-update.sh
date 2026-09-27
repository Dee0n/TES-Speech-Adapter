#!/bin/bash
# Run BEFORE a CHIM update. The launcher refuses to update HerikaServer while
# it has local changes ("Your local changes ... would be overwritten").
# This stashes them (recoverable with `git stash list`); after the update,
# re-run install.sh to apply the adapter patches again.
#
#   wsl -d DwemerAI4Skyrim3 -u root -- bash /home/dwemer/TES-Speech-Adapter/prepare-update.sh
set -u
HERIKA=/var/www/html/HerikaServer

if [ -z "$(git -C "$HERIKA" status --porcelain --untracked-files=no)" ]; then
    echo "HerikaServer has no local changes — ready to update."
    exit 0
fi
git -C "$HERIKA" stash push -m "tes-adapter before CHIM update $(date +%F_%H%M)"
echo "Local changes stashed. Now run the CHIM update, then install.sh."
