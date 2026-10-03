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
    b"LVLN": "leveled_npc", b"OTFT": "outfit", b"PERK": "perk", b"ENCH": "enchantment",
    b"KYWD": "keyword", b"MGEF": "effect",
}
# Stats for the goal agent's find_item ("the best light thief armour" must be ranked on the
# numbers the game really uses - Requiem rewrites most of them, the last override wins).
ARMOR_TYPES = {0: "light", 1: "heavy", 2: "clothing"}
WEAPON_TYPES = {0: "hand", 1: "sword", 2: "dagger", 3: "waraxe", 4: "mace", 5: "greatsword",
                6: "battleaxe", 7: "bow", 8: "staff", 9: "crossbow"}
# Item records whose DATA subrecord starts with the base gold value (uint32) followed by
# the weight (float). Used to give the Narrator real prices: on 2026-10-01 an innkeeper
# charged 1 septim for an ale and then invented "2 septims" out of thin air, because
# nothing anywhere told the model what an ale actually costs.
ITEM_VALUE_TYPES = {
    b"ARMO", b"WEAP", b"MISC", b"ALCH", b"BOOK", b"INGR", b"KEYM", b"AMMO", b"SCRL", b"SLGM",
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
        effects = []
        if typ in ITEM_VALUE_TYPES:
            extra["rec"] = typ.decode()
        for t, v in subrecords(body):
            if t == b"KWDA" and typ in (b"ARMO", b"WEAP", b"ALCH", b"AMMO", b"BOOK", b"MISC"):
                kws = [runtime(k) for k in struct.unpack_from(f"<{len(v) // 4}I", v)]
                extra["kw"] = [f"{k:08X}" for k in kws if k is not None]
            elif t == b"EITM" and len(v) >= 4 and typ in (b"ARMO", b"WEAP"):
                ench = runtime(struct.unpack_from("<I", v)[0])
                if ench is not None:
                    extra["ench"] = f"{ench:08X}"
            elif t == b"BOD2" and typ == b"ARMO" and len(v) >= 8:
                slots, atype = struct.unpack_from("<II", v)
                extra["slots"] = [30 + b for b in range(32) if slots >> b & 1]
                extra["armor"] = ARMOR_TYPES.get(atype, str(atype))
            elif t == b"DNAM" and typ == b"ARMO" and len(v) >= 4:
                extra["ar"] = struct.unpack_from("<i", v)[0] / 100
            elif t == b"DNAM" and typ == b"WEAP" and len(v) >= 8:
                extra["wtype"] = WEAPON_TYPES.get(v[0], str(v[0]))
                extra["speed"] = round(struct.unpack_from("<f", v, 4)[0], 2)
            elif t == b"EFID" and len(v) >= 4:
                eff = runtime(struct.unpack_from("<I", v)[0])
                effects.append({"e": f"{eff:08X}" if eff is not None else ""})
            elif t == b"EFIT" and len(v) >= 12 and effects:
                mag, _area, dur = struct.unpack_from("<fII", v)
                effects[-1].update({"m": round(mag, 1), "d": dur})
        if effects and typ in (b"ALCH", b"ENCH", b"INGR", b"SCRL", b"SPEL"):
            extra["fx"] = effects
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
            elif t == b"ENIT" and typ == b"ALCH" and len(v) >= 4:
                # ALCH's DATA is only the 4-byte WEIGHT float (found 2026-10-01: REQ_Drink_*/
                # REQ_Food_* read as 1050253722 = 0.27f); the gold value is the first int32 of ENIT.
                extra["value"] = struct.unpack_from("<I", v)[0]
                if len(v) >= 8:
                    aflags = struct.unpack_from("<I", v, 4)[0]
                    if aflags & 0x20000:
                        extra["poison"] = True
                    elif aflags & 0x2:
                        extra["food"] = True
            elif t == b"DATA" and typ in ITEM_VALUE_TYPES and typ != b"ALCH" and len(v) >= 4:
                # First uint32 of DATA is the base gold value for the other item types here
                # (WEAP packs value before damage, SLGM before the soul ref).
                extra["value"] = struct.unpack_from("<I", v)[0]
                if len(v) >= 8 and typ in (b"ARMO", b"WEAP"):
                    extra["weight"] = round(struct.unpack_from("<f", v, 4)[0], 1)
                if len(v) >= 10 and typ == b"WEAP":
                    extra["dmg"] = struct.unpack_from("<H", v, 8)[0]
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
        if prev and isinstance(prev[4], dict):
            # an override without DATA keeps the earlier value/stages (extra merged, new wins)
            extra = {**prev[4], **extra}
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
    # keyword FormIDs -> EditorIDs (ArmorLight, VendorItemPoison...), effect FormIDs -> names
    for rid, row in out.items():
        extra = row[4]
        if not isinstance(extra, dict):
            continue
        if "kw" in extra:
            extra["kw"] = [out[int(k, 16)][1] for k in extra["kw"] if int(k, 16) in out and out[int(k, 16)][1]]
        for fx in extra.get("fx", []):
            eff = out.get(int(fx["e"], 16)) if fx.get("e") else None
            if eff:
                fx["n"] = " ".join((eff[2] or eff[1]).replace("\\", "/").split())
    with open(out_path, "w", encoding="utf-8", newline="\n") as fh:
        for rid in sorted(out):
            kind, edid, nm, plugin, extra = out[rid]
            clean = lambda s: " ".join(s.replace("\\", "/").split())
            fh.write(f"{rid:08X}\t{kind}\t{clean(edid)}\t{clean(nm)}\t{clean(plugin)}\t{json.dumps(extra, ensure_ascii=False).replace(chr(92), chr(92) * 2)}\t{name_key(clean(nm))}\t{clean(edid).lower()}\n")
    print(f"{len(out)} records -> {out_path}", file=sys.stderr)


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2], sys.argv[3])
