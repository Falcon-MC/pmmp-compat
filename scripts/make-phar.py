#!/usr/bin/env python3
"""Packs a folder into a .phar, as Poggit builds plugins, without needing PHP.

Usage: scripts/make-phar.py <folder> <output.phar>

The files are stored uncompressed, with a SHA-1 signature: PHP refuses an unsigned phar by default.
"""
import hashlib
import os
import struct
import sys
import time
import zlib

STUB = b"<?php __HALT_COMPILER(); ?>\r\n"
MANIFEST_API = b"\x11\x10"
HAS_SIGNATURE = 0x00010000
SIGNATURE_SHA1 = 0x0002
FILE_PERMISSIONS = 0o644


def collect(folder):
    files = []
    for root, _, names in os.walk(folder):
        for name in sorted(names):
            path = os.path.join(root, name)
            relative = os.path.relpath(path, folder).replace(os.sep, "/")
            with open(path, "rb") as handle:
                files.append((relative.encode(), handle.read()))
    files.sort()
    return files


def pack(files):
    now = int(time.time())
    entries = b""
    for name, data in files:
        crc = zlib.crc32(data) & 0xFFFFFFFF
        entries += struct.pack("<I", len(name)) + name
        entries += struct.pack("<IIIIII", len(data), now, len(data), crc, FILE_PERMISSIONS, 0)

    alias = b""
    body = struct.pack("<I", len(files)) + MANIFEST_API + struct.pack("<I", HAS_SIGNATURE)
    body += struct.pack("<I", len(alias)) + alias + struct.pack("<I", 0) + entries
    archive = STUB + struct.pack("<I", len(body)) + body
    for _, data in files:
        archive += data
    signature = hashlib.sha1(archive).digest()
    return archive + signature + struct.pack("<I", SIGNATURE_SHA1) + b"GBMB"


def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__.strip().splitlines()[2])
    folder, output = sys.argv[1], sys.argv[2]
    with open(output, "wb") as handle:
        handle.write(pack(collect(folder)))


if __name__ == "__main__":
    main()
