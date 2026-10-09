#!/usr/bin/env python3
"""Builds the realm's client torrent (LAUNCHER_TORRENT_DIR/client.torrent) from a clean WoW folder.

    python3 tools/make-client-torrent.py "/srv/wow-launcher/client/World of Warcraft 3.3.5a" \
        /srv/wow-launcher/torrents/client.torrent

The torrent is named after the folder, and that name is also the folder players get and the path
the web seed serves, so keep the folder in LAUNCHER_CLIENT_DIR under exactly this name.

Left out on purpose:
- Files the launcher or the game writes to (realmlist.wtf, WTF, Cache, Logs, Interface, ...).
  If they were in the torrent, a player's copy would stop matching after the first launch and
  Portalkeeper would stop sharing it.
- Realm patches (patch-4.MPQ and up, patch-A.MPQ ... in Data or a locale folder). Those come from
  the realm folder through their own torrents, so they can change without a new client torrent.

The torrent is private (peers only come from the realm's tracker) and has no announce URL or web
seed: the portal adds both for each player. Needs only Python 3.
"""

import argparse
import hashlib
import os
import re
import sys

PIECE_LENGTH = 4 * 1024 * 1024  # ~4,300 pieces for a 17 GB client

SKIP_DIRS = {"wtf", "cache", "logs", "interface", "screenshots", "errors", ".portalkeeper"}
SKIP_FILES = {"realmlist.wtf", "config.wtf", "thumbs.db", "desktop.ini", ".ds_store"}
SKIP_SUFFIXES = (".log", ".tmp", ".bak")
# Stock 3.3.5a ships patch.MPQ, patch-2.MPQ, patch-3.MPQ (and patch-<locale>[-2|-3].MPQ).
REALM_PATCH = re.compile(r"^patch-(?:[a-z]{2}[A-Z]{2}-)?(?:[4-9]|[A-Za-z])\.mpq$", re.IGNORECASE)


def bencode(value):
    if isinstance(value, int):
        return b"i%de" % value
    if isinstance(value, str):
        value = value.encode("utf-8")
    if isinstance(value, bytes):
        return b"%d:%s" % (len(value), value)
    if isinstance(value, list):
        return b"l" + b"".join(bencode(item) for item in value) + b"e"
    if isinstance(value, dict):
        items = sorted((key.encode("utf-8") if isinstance(key, str) else key, item) for key, item in value.items())
        return b"d" + b"".join(bencode(key) + bencode(item) for key, item in items) + b"e"
    raise TypeError(type(value))


def client_files(root):
    found = []
    for directory, dirs, files in os.walk(root):
        dirs[:] = sorted(d for d in dirs if d.lower() not in SKIP_DIRS and not d.startswith("."))
        for name in sorted(files):
            lower = name.lower()
            if lower in SKIP_FILES or lower.endswith(SKIP_SUFFIXES) or name.startswith(".") or REALM_PATCH.match(name):
                continue
            path = os.path.join(directory, name)
            if os.path.islink(path) or not os.path.isfile(path):
                continue
            found.append(os.path.relpath(path, root).split(os.sep))
    return sorted(found, key=lambda parts: "/".join(parts))


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("client", help="the clean client folder (its name becomes the torrent name)")
    parser.add_argument("output", help="where to write the .torrent, e.g. .../torrents/client.torrent")
    args = parser.parse_args()

    root = os.path.abspath(args.client)
    if not os.path.isfile(os.path.join(root, "Wow.exe")):
        sys.exit(f"{root} has no Wow.exe; point this at the client folder itself.")
    name = os.path.basename(root.rstrip(os.sep))

    files = client_files(root)
    total = sum(os.path.getsize(os.path.join(root, *parts)) for parts in files)
    pieces = bytearray()
    buffer = bytearray()
    done = 0
    for parts in files:
        with open(os.path.join(root, *parts), "rb") as handle:
            while True:
                chunk = handle.read(PIECE_LENGTH - len(buffer))
                if not chunk:
                    break
                buffer += chunk
                done += len(chunk)
                if len(buffer) == PIECE_LENGTH:
                    pieces += hashlib.sha1(buffer).digest()
                    buffer.clear()
                    print(f"\r  hashing {done * 100 // max(total, 1):3d}%", end="", file=sys.stderr, flush=True)
    if buffer:
        pieces += hashlib.sha1(buffer).digest()
    print("\r  hashing 100%", file=sys.stderr)

    info = {
        "name": name,
        "piece length": PIECE_LENGTH,
        "pieces": bytes(pieces),
        "private": 1,
        "files": [{"length": os.path.getsize(os.path.join(root, *parts)), "path": parts} for parts in files],
    }
    torrent = {"created by": "make-client-torrent.py", "info": info}
    os.makedirs(os.path.dirname(os.path.abspath(args.output)), exist_ok=True)
    with open(args.output, "wb") as handle:
        handle.write(bencode(torrent))

    print(f"{args.output}: {name!r}, {len(files)} files, {total / 1024 ** 3:.2f} GB")
    print(f"info hash {hashlib.sha1(bencode(info)).hexdigest()}")
    for parts in files:
        print("  " + "/".join(parts))


if __name__ == "__main__":
    main()
