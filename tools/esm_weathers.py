"""List EditorIDs and FormIDs of one record type (default WTHR) from an ESM.

Usage: esm_weathers.py <Skyrim.esm> [regex] [RECORD_TYPE]
"""
import re
import struct
import sys
import zlib

path, pat = sys.argv[1], re.compile(sys.argv[2] if len(sys.argv) > 2 else ".", re.I)
RT = (sys.argv[3] if len(sys.argv) > 3 else "WTHR").encode()
data = open(path, "rb").read()
off = 24 + struct.unpack_from("<I", data, 4)[0]
while off < len(data):
    typ = data[off:off + 4]
    size = struct.unpack_from("<I", data, off + 4)[0]
    if typ == b"GRUP":
        label = data[off + 8:off + 12]
        if struct.unpack_from("<i", data, off + 12)[0] == 0 and label != RT:
            off += size  # skip other top-level groups
        else:
            off += 24
        continue
    flags, fid = struct.unpack_from("<II", data, off + 8)
    body = data[off + 24:off + 24 + size]
    if typ == RT:
        if flags & 0x40000:
            body = zlib.decompress(body[4:])
        if body[:4] == b"EDID":
            n = struct.unpack_from("<H", body, 4)[0]
            edid = body[6:6 + n].split(b"\0")[0].decode("latin-1")
            if pat.search(edid):
                print(f"{fid:08X}\t{edid}")
    off += 24 + size
