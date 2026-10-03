"""House purchase data from the load order (last override wins).

- GLOB HP* (house prices) and HD* (furnishing prices), with every plugin that sets them;
- INFO records of the steward dialogue whose TIF fragment buys a furnishing: the VMAD
  script properties give the HD* global, the DecorateMarker to enable and the old marker
  to disable (TIF__*.psc: removeitem(gold, HDxxx.value); decoratemarker.enable();
  oldmarker.disable()).

Usage (Windows Python): house_data.py <Skyrim SE dir> <MO2 profile> <out.json>
"""
import json
import struct
import sys
import zlib

import game_index as gi


def parse_vmad(v, runtime):
    """-> list of (script name, {prop: value}) for the scripts attached to the record."""
    try:
        ver, objfmt, nscripts = struct.unpack_from("<hhH", v, 0)
        off = 6
        scripts = []
        for _ in range(nscripts):
            ln = struct.unpack_from("<H", v, off)[0]
            name = v[off + 2:off + 2 + ln].decode("latin1")
            off += 2 + ln
            off += 1  # status
            nprops = struct.unpack_from("<H", v, off)[0]
            off += 2
            props = {}
            for _ in range(nprops):
                ln = struct.unpack_from("<H", v, off)[0]
                pname = v[off + 2:off + 2 + ln].decode("latin1")
                off += 2 + ln
                ptype = v[off]
                off += 2  # type + status
                if ptype == 1:  # object
                    if objfmt == 1:
                        fid = struct.unpack_from("<I", v, off)[0]
                    else:
                        fid = struct.unpack_from("<I", v, off + 4)[0]
                    off += 8
                    rid = runtime(fid)
                    props[pname] = f"{rid:08X}" if rid is not None else None
                elif ptype == 2:
                    ln = struct.unpack_from("<H", v, off)[0]
                    props[pname] = v[off + 2:off + 2 + ln].decode("latin1")
                    off += 2 + ln
                elif ptype == 3:
                    props[pname] = struct.unpack_from("<i", v, off)[0]
                    off += 4
                elif ptype == 4:
                    props[pname] = struct.unpack_from("<f", v, off)[0]
                    off += 4
                elif ptype == 5:
                    props[pname] = bool(v[off])
                    off += 1
                else:
                    return scripts  # arrays: not used by these fragments
            scripts.append((name, props))
        return scripts
    except (struct.error, IndexError):
        return []


def main(game_dir, profile, out_path):
    files = gi.mo2_files(game_dir, profile)
    order = gi.load_order(game_dir, profile, files)
    prefix_of, full_i, light_i = {}, 0, 0
    for name in order:
        with open(files[name.lower()], "rb") as fh:
            head = fh.read(12)
        light = name.lower().endswith(".esl") or bool(struct.unpack_from("<I", head, 8)[0] & 0x200)
        if light:
            prefix_of[name.lower()] = (True, 0xFE000000 | (light_i << 12))
            light_i += 1
        else:
            prefix_of[name.lower()] = (False, full_i)
            full_i += 1
    globs, infos = {}, {}
    for name in order:
        data = open(files[name.lower()], "rb").read()
        hsize = struct.unpack_from("<I", data, 4)[0]
        masters = [gi.text(v).lower() for t, v in gi.subrecords(data[24:24 + hsize]) if t == b"MAST"]

        def runtime(fid, masters=masters, name=name):
            idx = fid >> 24
            owner = masters[idx] if idx < len(masters) else name.lower()
            pre = prefix_of.get(owner)
            if pre is None:
                return None
            light, value = pre
            return (value | (fid & 0xFFF)) if light else ((value << 24) | (fid & 0xFFFFFF))

        def walk(off, end):
            while off < end:
                typ = data[off:off + 4]
                size = struct.unpack_from("<I", data, off + 4)[0]
                if typ == b"GRUP":
                    label = data[off + 8:off + 12]
                    gtype = struct.unpack_from("<i", data, off + 12)[0]
                    if gtype == 0 and label not in (b"GLOB", b"DIAL"):
                        off += size
                        continue
                    walk(off + 24, off + size)
                    off += size
                    continue
                flags, fid = struct.unpack_from("<II", data, off + 8)
                body = data[off + 24:off + 24 + size]
                off += 24 + size
                if typ not in (b"GLOB", b"INFO"):
                    continue
                if flags & 0x40000:
                    try:
                        body = zlib.decompress(body[4:])
                    except zlib.error:
                        continue
                rid = runtime(fid)
                if rid is None:
                    continue
                subs = list(gi.subrecords(body))
                if typ == b"GLOB":
                    edid = next((gi.text(v) for t, v in subs if t == b"EDID"), "")
                    if edid.startswith(("HP", "HD")) and len(edid) > 4:
                        val = next((struct.unpack_from("<f", v)[0] for t, v in subs if t == b"FLTV"), None)
                        g = globs.setdefault(edid, {"formid": f"{rid:08X}", "history": []})
                        g["value"] = val
                        g["history"].append([name, val])
                else:
                    vmad = next((v for t, v in subs if t == b"VMAD"), None)
                    if vmad is None:
                        continue
                    for sname, props in parse_vmad(vmad, runtime):
                        hd = [k for k in props if k.upper().startswith("HD") or k.lower() == "decoratemarker"]
                        if sname.upper().startswith("TIF_") and hd:
                            infos[f"{rid:08X}"] = {"script": sname, "plugin": name, "props": props}

        walk(24 + hsize, len(data))
    json.dump({"globals": globs, "furnishings": infos}, open(out_path, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    for k in sorted(globs):
        print(k, globs[k]["formid"], globs[k]["value"], [h for h in globs[k]["history"]])
    print(len(infos), "furnishing fragments")
    for rid, i in sorted(infos.items(), key=lambda x: sorted(x[1]["props"])[0]):
        print(rid, i["script"], i["plugin"], i["props"])


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2], sys.argv[3])
