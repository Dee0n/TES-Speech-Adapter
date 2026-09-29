"""Build a "name / EditorID -> runtime FormID" index of the whole MO2 load order.

Roadmap stage B ("индекс данных игры"): the Narrator and the quest/god layers need
real IDs for NPCs, placed actors, cells, locations, worlds, quests (with stages),
items, spells, factions, weathers, explosions, leveled NPC lists - vanilla and mods.

- Load order: MO2 profile loadorder.txt + plugins.txt ('*' = enabled) + implicit
  masters (base game, Creation Club files from Skyrim.ccc that exist and are not
  disabled in plugins.txt). Light plugins (.esl or ESL flag) get FE xxx IDs.
- Files: MO2 virtual file system - the highest-priority enabled mod that has the
  file wins (overwrite > mods top of modlist.txt > game Data).
- Names: inline FULL (UTF-8, else cp1251) or, for localized plugins, the
  <plugin>_russian.strings table (loose file, else the plugin's own BSA, else
  "Skyrim - Interface.bsa"); English table as fallback.
- Overrides: plugins are read in load order, the last one wins per FormID.

Usage (Windows Python, pip install lz4):
  game_index.py <Skyrim SE dir> <MO2 profile name> <out.tsv>
Writes: formid <TAB> kind <TAB> editor_id <TAB> name <TAB> plugin <TAB> extra_json <TAB> name_lc <TAB> editor_id_lc
(lower-case keys are made here: the CHIM database has a C locale, where lower() ignores Cyrillic)
"""
import json
import os
import re
import struct
import sys
import zlib

import lz4.frame

BASE_MASTERS = ["skyrim.esm", "update.esm", "dawnguard.esm", "hearthfires.esm", "dragonborn.esm"]
KINDS = {
    b"NPC_": "npc", b"CELL": "cell", b"WRLD": "world", b"LCTN": "location", b"QUST": "quest",
    b"ARMO": "item", b"WEAP": "item", b"MISC": "item", b"ALCH": "item", b"BOOK": "item",
    b"INGR": "item", b"KEYM": "item", b"AMMO": "item", b"SCRL": "item", b"SLGM": "item",
    b"SPEL": "spell", b"FACT": "faction", b"WTHR": "weather", b"EXPL": "explosion",
    b"LVLN": "leveled_npc", b"OTFT": "outfit", b"PERK": "perk",
}
# Top-level groups worth entering (ACHR placed actors live under CELL / WRLD).
WANTED_TOP = set(KINDS)
CELL_CHILD_GROUPS = (6, 8, 9, 10)


# ---------------------------------------------------------------- MO2 files
def mo2_files(game_dir, profile):
    """Map lower-case relative path (root plugins, bsa, strings/*) -> real path."""
    mo2 = os.path.join(game_dir, "MO2")
    sources = [os.path.join(game_dir, "Data")]
    modlist = os.path.join(mo2, "profiles", profile, "modlist.txt")
    enabled = [l[1:].strip() for l in open(modlist, encoding="utf-8-sig") if l.startswith("+")]
    sources += [os.path.join(mo2, "mods", m) for m in reversed(enabled)]  # low -> high priority
    sources.append(os.path.join(mo2, "overwrite"))
    files = {}
    for src in sources:
        if not os.path.isdir(src):
            continue
        for name in os.listdir(src):
            full = os.path.join(src, name)
            low = name.lower()
            if os.path.isfile(full) and low.endswith((".esm", ".esp", ".esl", ".bsa")):
                files[low] = full
            elif low == "strings" and os.path.isdir(full):
                for s in os.listdir(full):
                    files["strings/" + s.lower()] = os.path.join(full, s)
    return files


def load_order(game_dir, profile, files):
    prof = os.path.join(game_dir, "MO2", "profiles", profile)
    order = [l.strip() for l in open(os.path.join(prof, "loadorder.txt"), encoding="utf-8-sig")
             if l.strip() and not l.startswith("#")]
    listed = {}
    for l in open(os.path.join(prof, "plugins.txt"), encoding="utf-8-sig"):
        l = l.strip()
        if l and not l.startswith("#"):
            listed[l.lstrip("*").lower()] = l.startswith("*")
    ccc = os.path.join(game_dir, "Skyrim.ccc")
    cc = {l.strip().lower() for l in open(ccc, encoding="utf-8-sig")} if os.path.exists(ccc) else set()
    active = []
    for name in order:
        low = name.lower()
        if low not in files:
            continue
        if low in BASE_MASTERS or listed.get(low) or (low in cc and low not in listed):
            active.append(name)
    return active


# ---------------------------------------------------------------- strings
def bsa_strings(path, wanted):
    """Yield (lower file name, bytes) for strings/<name> in a BSA v104/105."""
    with open(path, "rb") as fh:
        buf = fh.read()
    if buf[:4] != b"BSA\x00":
        return
    version, folder_off, flags, folder_count, _file_count = struct.unpack_from("<IIIII", buf, 4)
    total_fname_len = struct.unpack_from("<I", buf, 28)[0]
    fr = 24 if version >= 105 else 16
    folders = []
    off = folder_off
    for _ in range(folder_count):
        if version >= 105:
            _h, count, _p1, offset, _p2 = struct.unpack_from("<QIIII", buf, off)
        else:
            _h, count, offset = struct.unpack_from("<QII", buf, off)
        folders.append((count, offset))
        off += fr
    recs = []
    for count, offset in folders:
        off = offset - total_fname_len
        ln = buf[off]
        folder = buf[off + 1:off + 1 + ln].rstrip(b"\0").decode("cp1252", "ignore").lower()
        off += 1 + ln
        for _ in range(count):
            _h, size, data_off = struct.unpack_from("<QII", buf, off)
            recs.append([folder, size, data_off, None])
            off += 16
    if not flags & 0x2:
        return
    pos = off
    for r in recs:
        end = buf.index(b"\0", pos)
        r[3] = buf[pos:end].decode("cp1252", "ignore").lower()
        pos = end + 1
    for folder, size, data_off, name in recs:
        if folder != "strings" or name not in wanted:
            continue
        comp = bool(flags & 0x4)
        if size & 0x40000000:
            comp = not comp
        size &= 0x3FFFFFFF
        p = data_off
        if flags & 0x100:
            p += 1 + buf[p]
            size -= 1 + buf[data_off]
        data = buf[p:p + size]
        if comp:
            data = data[4:]
            try:
                data = lz4.frame.decompress(data)
            except Exception:
                data = zlib.decompress(data)
        yield name, data


def parse_strings(data):
    out = {}
    count = struct.unpack_from("<I", data, 0)[0]
    base = 8 + count * 8
    for i in range(count):
        sid, offset = struct.unpack_from("<II", data, 8 + i * 8)
        end = data.find(b"\0", base + offset)
        if end >= 0:
            out[sid] = data[base + offset:end].decode("utf-8", "replace")
    return out


def strings_for(plugin, files):
    stem = os.path.splitext(plugin)[0].lower()
    for lang in ("russian", "english"):
        want = f"{stem}_{lang}.strings"
        if "strings/" + want in files:
            return parse_strings(open(files["strings/" + want], "rb").read())
        bsas = [k for k in files if k.endswith(".bsa") and (k == stem + ".bsa" or k.startswith(stem + " - "))]
        if stem in [m[:-4] for m in BASE_MASTERS]:
            bsas.append("skyrim - interface.bsa")
        for b in bsas:
            if b in files:
                for _n, data in bsa_strings(files[b], {want}):
                    return parse_strings(data)
    return {}


# ---------------------------------------------------------------- plugins
def subrecords(body):
    i = 0
    while i + 6 <= len(body):
        t = body[i:i + 4]
        sz = struct.unpack_from("<H", body, i + 4)[0]
        yield t, body[i + 6:i + 6 + sz]
        i += 6 + sz


def text(raw):
    raw = raw.split(b"\0")[0]
    try:
        return raw.decode("utf-8")
    except UnicodeDecodeError:
        return raw.decode("cp1251", "replace")


def index_plugin(name, path, prefix_of, files, out):
    data = open(path, "rb").read()
    hsize = struct.unpack_from("<I", data, 4)[0]
    localized = bool(struct.unpack_from("<I", data, 8)[0] & 0x80)
    masters = [text(v) .lower() for t, v in subrecords(data[24:24 + hsize]) if t == b"MAST"]
    table = strings_for(name, files) if localized else {}

    def runtime(fid):
        idx = fid >> 24
        owner = masters[idx] if idx < len(masters) else name.lower()
        pre = prefix_of.get(owner)
        if pre is None:
            return None
        light, value = pre
        return (value | (fid & 0xFFF)) if light else ((value << 24) | (fid & 0xFFFFFF))

    def full_name(v):
        if localized and len(v) == 4:
            return table.get(struct.unpack_from("<I", v)[0], "")
        return text(v)

    stack = []  # (group end, cell runtime id)
    cell = None
    off, end = 24 + hsize, len(data)
    while off < end:
        while stack and off >= stack[-1][0]:
            stack.pop()
            cell = stack[-1][1] if stack else None
        typ = data[off:off + 4]
        size = struct.unpack_from("<I", data, off + 4)[0]
        if typ == b"GRUP":
            label = data[off + 8:off + 12]
            gtype = struct.unpack_from("<i", data, off + 12)[0]
            if gtype == 0 and label not in WANTED_TOP:
                off += size
                continue
            if gtype in CELL_CHILD_GROUPS:
                cell = runtime(struct.unpack_from("<I", label)[0])
            stack.append((off + size, cell))
            off += 24
            continue
        if typ not in KINDS and typ != b"ACHR":
            off += 24 + size
            continue
        flags, fid = struct.unpack_from("<II", data, off + 8)
        body = data[off + 24:off + 24 + size]
        off += 24 + size
        if flags & 0x40000:
            try:
                body = zlib.decompress(body[4:])
            except zlib.error:
                continue
        rid = runtime(fid)
        if rid is None:
            continue
        edid, name_, extra = "", "", {}
        stages = []
        for t, v in subrecords(body):
            if t == b"EDID":
                edid = text(v)
            elif t == b"FULL":
                name_ = full_name(v)
            elif t == b"NAME" and typ == b"ACHR" and len(v) >= 4:
                base = runtime(struct.unpack_from("<I", v)[0])
                if base is not None:
                    extra["base"] = f"{base:08X}"
            elif t == b"INDX" and typ == b"QUST" and len(v) >= 2:
                stages.append(struct.unpack_from("<H", v)[0])
        if typ == b"ACHR":
            if cell is not None:
                extra["cell"] = f"{cell:08X}"
            out[rid] = ["actor", edid, name_, name, extra]
            continue
        if stages:
            extra["stages"] = sorted(set(stages))
        prev = out.get(rid)
        if prev and not name_:
            name_ = prev[2]  # an override without FULL keeps the earlier name
        out[rid] = [KINDS[typ], edid, name_, name, extra]


def name_key(name):
    """Lookup key: lower case, without RFAD category prefixes like '[Алкоголь] Эль'."""
    return re.sub(r"^\[[^\]]*\]\s*", "", name).lower()


def main(game_dir, profile, out_path):
    files = mo2_files(game_dir, profile)
    order = load_order(game_dir, profile, files)
    prefix_of, full_i, light_i = {}, 0, 0
    for name in order:
        path = files[name.lower()]
        with open(path, "rb") as fh:
            head = fh.read(12)
        light = name.lower().endswith(".esl") or bool(struct.unpack_from("<I", head, 8)[0] & 0x200)
        if light:
            prefix_of[name.lower()] = (True, 0xFE000000 | (light_i << 12))
            light_i += 1
        else:
            prefix_of[name.lower()] = (False, full_i)
            full_i += 1
    print(f"{len(order)} active plugins ({full_i} full, {light_i} light)", file=sys.stderr)
    out = {}
    for name in order:
        before = len(out)
        try:
            index_plugin(name, files[name.lower()], prefix_of, files, out)
        except Exception as exc:  # one broken plugin must not stop the index
            print(f"  ! {name}: {exc}", file=sys.stderr)
        print(f"  {name}: +{len(out) - before}", file=sys.stderr)
    # placed actors take their name from the (final) base record
    for rid, row in out.items():
        if row[0] == "actor" and not row[2]:
            base = out.get(int(row[4].get("base", "0"), 16))
            if base:
                row[2] = base[2]
                row[1] = row[1] or base[1]
    with open(out_path, "w", encoding="utf-8", newline="\n") as fh:
        for rid in sorted(out):
            kind, edid, nm, plugin, extra = out[rid]
            clean = lambda s: " ".join(s.replace("\\", "/").split())
            fh.write(f"{rid:08X}\t{kind}\t{clean(edid)}\t{clean(nm)}\t{clean(plugin)}\t{json.dumps(extra, ensure_ascii=False)}\t{name_key(clean(nm))}\t{clean(edid).lower()}\n")
    print(f"{len(out)} records -> {out_path}", file=sys.stderr)


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2], sys.argv[3])
