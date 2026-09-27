"""Merge Russian aliases (settings/oghma_ru_aliases.tsv) into CHIM's oghma catalog.

Appends to the existing comma-separated aliases, skipping ones already there,
so it is idempotent and survives catalog refreshes when re-run by install.sh.

Usage: apply_oghma_aliases.py <oghma_ru_aliases.tsv>
"""
import subprocess
import sys


def psql(sql, *params):
    cmd = ["psql", "--no-password", "-U", "dwemer", "-d", "dwemer", "-t", "-A", "-q",
           "-F", "\x1f", "-R", "\x1e", "-v", "ON_ERROR_STOP=1"]
    for i, p in enumerate(params):
        cmd += ["-v", f"p{i}={p}"]
    return subprocess.run(cmd, input=sql, capture_output=True, text=True, check=True).stdout


def main(path):
    ru = {}
    for line in open(path, encoding="utf-8"):
        topic, _, aliases = line.rstrip("\n").partition("\t")
        vals = [a.strip() for a in aliases.split(",") if a.strip()]
        if vals:
            ru[topic] = vals
    current = {}
    for row in psql("SELECT topic, coalesce(aliases,'') FROM oghma").split("\x1e"):
        if row.strip():
            t, a = row.split("\x1f")
            current[t] = a
    changed = 0
    for topic, vals in ru.items():
        if topic not in current:
            continue
        have = [a.strip() for a in current[topic].split(",") if a.strip()]
        low = {a.lower() for a in have}
        add = [v for v in vals if v.lower() not in low]
        if not add:
            continue
        psql("UPDATE oghma SET aliases = :'p0' WHERE topic = :'p1'", ", ".join(have + add), topic)
        changed += 1
    print(f"oghma: Russian aliases merged into {changed} topic(s) ({len(ru)} in file)")


if __name__ == "__main__":
    main(sys.argv[1])
