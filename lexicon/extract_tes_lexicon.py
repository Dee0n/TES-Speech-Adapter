#!/usr/bin/env python3
"""Extract Russian proper-noun lexicon from Skyrim SE BSA/strings files.

Parses BSA v104/105 archives, pulls *_russian.strings entries (record FULL
names: NPCs, locations, items, spells, books, quests), filters proper-noun-ish
strings and writes a deduplicated lexicon file.
"""
import lz4.frame
import os
import re
import struct
import sys

DATA = sys.argv[1] if len(sys.argv) > 1 else "/mnt/g/Games/RfaD SE/Data"
OUT = (
    sys.argv[2]
    if len(sys.argv) > 2
    else "/home/dwemer/remote-faster-whisper/tes_lexicon_full_ru.txt"
)


def read_bstring(buf, off):
    ln = buf[off]
    return buf[off + 1: off + 1 + ln], off + 1 + ln


def bsa_extract_strings(path):
    """Yield (name, data) for Strings/*.strings files inside a BSA."""
    with open(path, "rb") as fh:
        buf = fh.read()
    if buf[:4] != b"BSA\x00":
        return
    version, folder_off, flags, folder_count, file_count = struct.unpack_from(
        "<IIIII", buf, 4
    )
    names_embedded = bool(flags & 0x100)
    compressed_default = bool(flags & 0x4)
    fr_size = 24 if version >= 105 else 16
    # folder records
    folders = []
    off = folder_off
    for _ in range(folder_count):
        if version >= 105:
            fhash, count, _pad1, offset, _pad2 = struct.unpack_from("<QIIII", buf, off)
        else:
            fhash, count, offset = struct.unpack_from("<QII", buf, off)
        folders.append((count, offset))
        off += fr_size
    total_fname_len = struct.unpack_from("<I", buf, 28)[0]
    # file records per folder (offset includes total_fname_len)
    files = []
    for count, offset in folders:
        off = offset - total_fname_len
        fname, off = read_bstring(buf, off)  # folder name (bzstring)
        folder = fname.rstrip(b"\x00").decode("cp1252", "ignore").lower()
        for _ in range(count):
            fh_, size, data_off = struct.unpack_from("<QII", buf, off)
            files.append([folder, size, data_off, None])
            off += 16
    # file name block
    if flags & 0x2:
        pos = off
        for rec in files:
            end = buf.index(b"\x00", pos)
            rec[3] = buf[pos:end].decode("cp1252", "ignore").lower()
            pos = end + 1
    for folder, size, data_off, name in files:
        if not name or not folder.startswith("strings"):
            continue
        if not name.endswith("_russian.strings"):
            continue
        comp = compressed_default
        real_size = size
        if size & 0x40000000:
            comp = not comp
            real_size = size & 0x3FFFFFFF
        pos = data_off
        if names_embedded:
            nm, pos = read_bstring(buf, pos)
            real_size -= len(nm) + 1
        data = buf[pos: pos + real_size]
        if comp:
            orig_len = struct.unpack_from("<I", data, 0)[0]
            payload = data[4:]
            try:
                data = lz4.frame.decompress(payload)
            except Exception:
                try:
                    import zlib
                    data = zlib.decompress(payload)
                except Exception:
                    continue
        yield name, data


def parse_strings(data):
    """Parse .strings (null-terminated directory format)."""
    if len(data) < 8:
        return
    count, str_size = struct.unpack_from("<II", data, 0)
    dir_end = 8 + count * 8
    base = dir_end
    for i in range(count):
        _sid, offset = struct.unpack_from("<II", data, 8 + i * 8)
        pos = base + offset
        end = data.find(b"\x00", pos)
        if end == -1:
            continue
        yield data[pos:end].decode("utf-8", "ignore")


BAD_WORDS = re.compile(r"[<>%\[\]{}=_/\\|@#$^&*+~`\"]|\d")
CYR_NAME = re.compile(r"^[А-ЯЁ][а-яёА-ЯЁ' .,-]+$")


def is_proper_name(s):
    s = s.strip()
    if not (3 <= len(s) <= 48):
        return False
    if BAD_WORDS.search(s):
        return False
    if not CYR_NAME.match(s):
        return False
    if s.count(" ") > 3 or s.endswith((".", ",")):
        return False
    return True


def main():
    entries = {}
    sources = []
    for fn in os.listdir(DATA):
        if fn.lower().endswith(".bsa"):
            sources.append(os.path.join(DATA, fn))
    total_raw = 0
    for bsa in sources:
        try:
            for name, data in bsa_extract_strings(bsa):
                n = 0
                for s in parse_strings(data):
                    total_raw += 1
                    s = s.strip()
                    if is_proper_name(s):
                        entries[s] = True
                        n += 1
                if n:
                    print(f"{os.path.basename(bsa)} :: {name}: +{n}")
        except Exception as e:
            print(f"skip {bsa}: {e}", file=sys.stderr)
    # loose strings files (mods)
    for root in [os.path.join(DATA, "Strings")]:
        if not os.path.isdir(root):
            continue
        for fn in os.listdir(root):
            if fn.lower().endswith("_russian.strings"):
                with open(os.path.join(root, fn), "rb") as fh:
                    data = fh.read()
                n = 0
                for s in parse_strings(data):
                    total_raw += 1
                    if is_proper_name(s.strip()):
                        entries[s.strip()] = True
                        n += 1
                print(f"loose {fn}: +{n}")
    names = sorted(entries)
    with open(OUT, "w", encoding="utf-8") as fh:
        fh.write("\n".join(names))
    print(f"TOTAL raw strings: {total_raw}, lexicon entries: {len(names)} -> {OUT}")


if __name__ == "__main__":
    main()
