"""Map placed-NPC runtime ref IDs to their base NPC EditorIDs from the game's ESMs.

CHIM keys its vanilla NPC biographies (bio_templates) by English name
("hulda", "aela_the_huntress"). In a localized game the NPC is "Хульда", so
CHIM never finds the biography. The ESM ties the placed actor (ACHR, the refid
CHIM stores) to its base NPC_ record, whose EditorID is English ("Hulda").

Usage: esm_npc_map.py <Skyrim Data dir> <out.tsv>
Writes: runtime_refid <TAB> base_editor_id
"""
import os
import struct
import sys
import zlib

# Standard load order of the base game masters (runtime index = position).
MASTERS = ["Skyrim.esm", "Update.esm", "Dawnguard.esm", "HearthFires.esm", "Dragonborn.esm"]


def records(data):
    """Yield (type, formid, flags, body) for every record, walking nested groups."""
    stack = [(0, len(data))]
    off = 24 + struct.unpack_from("<I", data, 4)[0]  # skip TES4 header
    end = len(data)
    while off < end:
        typ = data[off:off + 4]
        size = struct.unpack_from("<I", data, off + 4)[0]
        if typ == b"GRUP":
            off += 24  # descend into the group; its contents follow directly
            continue
        flags, fid = struct.unpack_from("<II", data, off + 8)
        body = data[off + 24:off + 24 + size]
        if flags & 0x40000:
            try:
                body = zlib.decompress(body[4:])
            except zlib.error:
                body = b""
        yield typ, fid, body
        off += 24 + size


def subrecords(body):
    i = 0
    while i + 6 <= len(body):
        t = body[i:i + 4]
        sz = struct.unpack_from("<H", body, i + 4)[0]
        yield t, body[i + 6:i + 6 + sz]
        i += 6 + sz


def masters_of(data):
    size = struct.unpack_from("<I", data, 4)[0]
    return [v.split(b"\0")[0].decode("latin-1") for t, v in subrecords(data[24:24 + size]) if t == b"MAST"]


def runtime_id(fid, file_masters, file_name):
    """Translate a file-local FormID to the runtime ID in the standard load order."""
    idx = fid >> 24
    owner = file_masters[idx] if idx < len(file_masters) else file_name
    if owner not in MASTERS:
        return None
    return (MASTERS.index(owner) << 24) | (fid & 0xFFFFFF)


def main(data_dir, out_path):
    base_edid = {}   # runtime NPC_ id -> EditorID
    placed = {}      # runtime ACHR id -> runtime NPC_ id
    for name in MASTERS:
        path = os.path.join(data_dir, name)
        if not os.path.exists(path):
            continue
        data = open(path, "rb").read()
        mst = masters_of(data)
        n_npc = n_achr = 0
        for typ, fid, body in records(data):
            if typ == b"NPC_":
                for t, v in subrecords(body):
                    if t == b"EDID":
                        rid = runtime_id(fid, mst, name)
                        if rid is not None:
                            base_edid[rid] = v.split(b"\0")[0].decode("latin-1")
                            n_npc += 1
                        break
            elif typ == b"ACHR":
                for t, v in subrecords(body):
                    if t == b"NAME" and len(v) >= 4:
                        rid = runtime_id(fid, mst, name)
                        bid = runtime_id(struct.unpack_from("<I", v)[0], mst, name)
                        if rid is not None and bid is not None:
                            placed[rid] = bid
                            n_achr += 1
                        break
        print(f"{name}: {n_npc} NPC_, {n_achr} ACHR", file=sys.stderr)
    with open(out_path, "w", encoding="utf-8") as fh:
        for rid, bid in sorted(placed.items()):
            if bid in base_edid:
                fh.write(f"{rid:08X}\t{base_edid[bid]}\n")
        for bid, edid in sorted(base_edid.items()):  # CHIM sometimes stores the base id
            fh.write(f"{bid:08X}\t{edid}\n")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
