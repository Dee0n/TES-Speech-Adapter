"""Fill empty CHIM NPC profiles from CHIM's own vanilla biographies (bio_templates).

In a localized game CHIM looks the biography up by the Russian name and finds
nothing, so vanilla NPCs are played from their name alone. This maps the NPC's
runtime refid -> base EditorID (tools/esm_npc_map.py) -> bio_templates entry
and copies only fields that are still empty. Safe to run repeatedly (cron).

Usage: fill_bios.py <npc_map.tsv> [--dry-run]
"""
import re
import subprocess
import sys

FIELDS = ["npc_static_bio", "personality", "relationships", "occupation",
          "skills", "speechstyle", "goals", "appearance"]


def psql(sql, *args):
    cmd = ["psql", "--no-password", "-U", "dwemer", "-d", "dwemer", "-t", "-A",
           "-F", "\x1f", "-R", "\x1e", "-v", "ON_ERROR_STOP=1"]
    for i, a in enumerate(args):
        cmd += ["-v", f"p{i}={a}"]
    out = subprocess.run(cmd + ["-c", sql] if not args else cmd, input=None if not args else sql,
                         capture_output=True, text=True, check=True).stdout
    return [r.split("\x1f") for r in out.split("\x1e") if r.strip()]


def norm(s):
    return re.sub(r"[^a-z0-9]", "", s.lower())


def main(map_path, dry):
    edid_of = {}
    for line in open(map_path, encoding="utf-8"):
        rid, edid = line.rstrip("\n").split("\t")
        edid_of.setdefault(rid, edid)

    templates = {}
    cols = ", ".join(FIELDS)
    for row in psql(f"SELECT npc_name, {cols} FROM bio_templates"):
        templates[norm(row[0])] = dict(zip(FIELDS, row[1:]))
    keys = list(templates)

    def find(edid):
        n = norm(edid)
        if n in templates:
            return n
        pref = [k for k in keys if k.startswith(n) and len(n) >= 4]     # Ulfberth -> ulfberth_war-bear
        if len(pref) == 1:
            return pref[0]
        tail = norm(edid.split("_")[-1])                                  # BYOHUrchin_Lucia -> lucia
        if tail != n and tail in templates:
            return tail
        return None

    filled = 0
    for row in psql(f"SELECT id, npc_name, upper(coalesce(refid,'')), {cols} FROM core_npc_master"):
        npc_id, name, refid = row[0], row[1], row[2]
        current = dict(zip(FIELDS, row[3:]))
        empty = [f for f in FIELDS if not current[f].strip() or current[f].strip() in ("null", "{}", "[]")]
        if not empty or refid not in edid_of:
            continue
        key = find(edid_of[refid])
        if not key:
            continue
        updates = {f: templates[key][f] for f in empty if templates[key][f].strip()}
        if not updates:
            continue
        filled += 1
        print(f"{name} ({edid_of[refid]} -> {key}): {', '.join(updates)}")
        if dry:
            continue
        sets = ", ".join(f"{f} = :'p{i}'" for i, f in enumerate(updates))
        psql(f"UPDATE core_npc_master SET {sets} WHERE id = {int(npc_id)}", *updates.values())
    if filled or dry:  # stay quiet on idle cron runs
        print(f"{'would fill' if dry else 'filled'}: {filled} NPC(s)")


if __name__ == "__main__":
    main(sys.argv[1], "--dry-run" in sys.argv)
