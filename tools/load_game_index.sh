#!/bin/bash
# Load the TSV from tools/game_index.py into public.tes_game_index (replaces it).
# Usage (inside the distro): load_game_index.sh /path/to/game_index.tsv
set -e
TSV="$1"
[ -f "$TSV" ] || { echo "usage: $0 game_index.tsv"; exit 1; }
cp "$TSV" /tmp/tes_game_index.tsv
chmod 644 /tmp/tes_game_index.tsv
runuser -u dwemer -- psql --no-password -U dwemer -d dwemer -v ON_ERROR_STOP=1 -q <<'SQL'
BEGIN;
DROP TABLE IF EXISTS public.tes_game_index;
CREATE TABLE public.tes_game_index (
    formid text PRIMARY KEY,          -- runtime FormID, 8 hex digits
    kind text NOT NULL,               -- npc, actor, cell, world, location, quest, item, spell, ...
    editor_id text NOT NULL DEFAULT '',
    name text NOT NULL DEFAULT '',    -- in-game (Russian) name
    plugin text NOT NULL DEFAULT '',  -- last plugin that defines/overrides the record
    extra jsonb NOT NULL DEFAULT '{}', -- actor: base, cell; quest: stages
    name_lc text NOT NULL DEFAULT '',  -- lower-case keys from game_index.py (C locale lower()
    editor_id_lc text NOT NULL DEFAULT '' -- does not fold Cyrillic)
);
\copy public.tes_game_index (formid, kind, editor_id, name, plugin, extra, name_lc, editor_id_lc) FROM '/tmp/tes_game_index.tsv' WITH (FORMAT text)
CREATE INDEX IF NOT EXISTS tes_game_index_name ON public.tes_game_index (name_lc);
CREATE INDEX IF NOT EXISTS tes_game_index_edid ON public.tes_game_index (editor_id_lc);
COMMIT;
SELECT kind, count(*) FROM public.tes_game_index GROUP BY 1 ORDER BY 2 DESC;
SQL
rm -f /tmp/tes_game_index.tsv
