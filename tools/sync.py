#!/usr/bin/env python3
"""
Board Game Arena (BGA) Universal Delta-Sync Tool
Synchronizes local game files to remote BGA Studio SFTP repository.

Usage:
    python tools/sync.py pushfighttest
    python tools/sync.py mandala
    python tools/sync.py lordsofscotland
    python tools/sync.py pushfighttest --dry-run
"""

import os
import sys
import json
import time
import posixpath
import argparse
import paramiko

# Root workspace directory
ROOT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))

# Known game targets mapping: target_alias -> (local_subpath, remote_folder)
TARGET_MAP = {
    "pushfight": ("games/pushfight/bga", "pushfight"),
    "pushfighttest": ("games/pushfight/bga", "pushfighttest"),
    "mandala": ("games/mandala/bga-prod", "mandala"),
    "lordsofscotland": ("games/lordsofscotland/bga", "lordsofscotland"),
    "lordsofscotlandtest": ("games/lordsofscotland/bga", "lordsofscotland"),
    "omega": ("games/omega/bga", "omega"),
    "omegatest": ("games/omega/bga", "omegatest"),
    "yavalath": ("games/yavalath/bga", "yavalath"),
    "yavalathtest": ("games/yavalath/bga", "yavalath"),
    "kiln": ("games/KILN/bga", "kiln"),
    "sugargliders": ("games/sugargliders/bga", "sugargliders"),
    "sugargliderstest": ("games/sugargliders/bga", "sugargliders"),
    "gardensofuranus": ("games/gardensofuranus/bga", "gardensofuranustest"),
    "gardensofuranustest": ("games/gardensofuranus/bga", "gardensofuranustest"),
    "nestorgamesgp": ("games/nestorgamesgp/bga", "nestorgamesgptest"),
    "nestorgamesgptest": ("games/nestorgamesgp/bga", "nestorgamesgptest"),
}

def load_credentials():
    if os.environ.get("BGA_SFTP_PASSWORD"):
        return {
            "host": os.environ.get("BGA_SFTP_HOST", "1.studio.boardgamearena.com"),
            "port": int(os.environ.get("BGA_SFTP_PORT", 2022)),
            "username": os.environ.get("BGA_SFTP_USERNAME", "JayadevHaddadi"),
            "password": os.environ["BGA_SFTP_PASSWORD"],
        }
    config_paths = [
        os.path.join(ROOT_DIR, "tools", "sftp.config.json"),
        os.path.join(ROOT_DIR, "games", "mandala", "bga-prod", "sftp.json"),
    ]
    for p in config_paths:
        if os.path.exists(p):
            with open(p, "r", encoding="utf-8") as f:
                return json.load(f)
    raise FileNotFoundError("Could not find SFTP configuration in tools/sftp.config.json")

def connect_sftp(host, port, username, password, max_retries=3, timeout=10):
    for attempt in range(1, max_retries + 1):
        try:
            print(f"Connecting to {host}:{port} as {username} (attempt {attempt}/{max_retries})...")
            transport = paramiko.Transport((host, port))
            transport.banner_timeout = timeout
            transport.connect(username=username, password=password)
            sftp = paramiko.SFTPClient.from_transport(transport)
            return transport, sftp
        except Exception as e:
            print(f"Connection attempt {attempt} failed: {e}")
            if attempt < max_retries:
                time.sleep(3)
    raise ConnectionError(f"Failed to connect to {host}:{port} after {max_retries} attempts.")

def rmtree_remote(sftp, remote_path):
    """Recursively delete a remote directory and all its contents over SFTP."""
    import stat
    try:
        for attr in sftp.listdir_attr(remote_path):
            p = posixpath.join(remote_path, attr.filename)
            if stat.S_ISDIR(attr.st_mode):
                rmtree_remote(sftp, p)
            else:
                try:
                    sftp.remove(p)
                except Exception as e:
                    print(f"Warning: Could not remove remote file {p}: {e}", flush=True)
        sftp.rmdir(remote_path)
        print(f"[PRUNE DIR] Successfully removed obsolete remote directory: {remote_path}", flush=True)
    except Exception as e:
        print(f"Warning: Could not remove remote directory {remote_path}: {e}", flush=True)

def sync_directory(sftp, local_dir, remote_dir, dry_run=False, force=False):
    uploaded = 0
    skipped = 0

    if not dry_run:
        try:
            sftp.mkdir(remote_dir)
        except IOError:
            pass

    remote_attrs = {}
    if not dry_run:
        try:
            for a in sftp.listdir_attr(remote_dir):
                remote_attrs[a.filename] = a
            print(f"[REMOTE INSPECT] Files in '{remote_dir}': {list(remote_attrs.keys())}", flush=True)
        except IOError:
            pass

    # Automatically prune obsolete or legacy template files from remote SFTP if not in local_dir
    system_files = {"_ide_helper.php", "bga-framework.d.ts", ".", "..", "misc", ".svn"}
    for rem_name in list(remote_attrs.keys()):
        if rem_name in system_files:
            continue
        l_path_check = os.path.join(local_dir, rem_name)
        if not os.path.exists(l_path_check):
            rem_path = posixpath.join(remote_dir, rem_name)
            action = "[DRY-RUN PRUNE]" if dry_run else "[PRUNE]"
            print(f"{action} Removing obsolete remote item: {rem_path}", flush=True)
            if not dry_run and sftp:
                try:
                    sftp.remove(rem_path)
                    del remote_attrs[rem_name]
                except Exception:
                    try:
                        rmtree_remote(sftp, rem_path)
                        del remote_attrs[rem_name]
                    except Exception as e:
                        print(f"Warning: Could not remove remote {rem_path}: {e}", flush=True)

    # _ide_helper.php and bga-framework.d.ts are auto-generated by BGA Studio itself for every
    # project and are read-only on the server (SFTP write returns permission denied) — never try
    # to push them.
    ignore_set = {".git", ".vscode", ".gitignore", "node_modules", "sftp.json", "sftp.config.json", ".DS_Store", "__pycache__", "_ide_helper.php", "bga-framework.d.ts", "src-disabled", "LICENCE_BGA"}

    for item in sorted(os.listdir(local_dir)):
        if item in ignore_set or item.endswith(".pyc"):
            continue

        l_path = os.path.join(local_dir, item)
        r_path = posixpath.join(remote_dir, item)

        if os.path.isdir(l_path):
            u, s = sync_directory(sftp, l_path, r_path, dry_run=dry_run, force=force)
            uploaded += u
            skipped += s
        else:
            l_stat = os.stat(l_path)
            needs_upload = True

            if not force and not dry_run and item in remote_attrs:
                r_stat = remote_attrs[item]
                if r_stat.st_size == l_stat.st_size and int(r_stat.st_mtime) >= int(l_stat.st_mtime):
                    needs_upload = False

            if needs_upload:
                action = "[DRY-RUN UPLOAD]" if dry_run else "[SYNC]"
                print(f"{action} {item} -> {r_path} ({l_stat.st_size} bytes)", flush=True)
                if not dry_run:
                    try:
                        sftp.remove(r_path)
                    except IOError:
                        pass
                    sftp.put(l_path, r_path)
                uploaded += 1
            else:
                skipped += 1

    return uploaded, skipped

def main():
    parser = argparse.ArgumentParser(description="BGA Multi-Game Universal SFTP Sync Tool")
    parser.add_argument("target", help=f"Game target to sync. Supported: {', '.join(TARGET_MAP.keys())} or custom <local_dir>")
    parser.add_argument("remote_dir", nargs="?", default=None, help="Remote destination directory (optional if target is known)")
    parser.add_argument("--dry-run", action="store_true", help="Preview files that would be uploaded without connecting/uploading")
    parser.add_argument("--force", action="store_true", help="Force upload all files regardless of remote mtime/size")

    args = parser.parse_args()
    target_key = args.target.lower()

    if target_key in TARGET_MAP:
        local_rel, remote_base = TARGET_MAP[target_key]
        local_base = os.path.join(ROOT_DIR, local_rel)
    else:
        local_base = os.path.abspath(args.target)
        remote_base = args.remote_dir or os.path.basename(local_base)

    if not os.path.exists(local_base):
        print(f"Error: Local directory does not exist: {local_base}")
        sys.exit(1)

    print(f"=== BGA Universal Sync: {target_key.upper()} ===")
    print(f"Local:  {local_base}")
    print(f"Remote: {remote_base}")
    print(f"Mode:   {'DRY-RUN (No Upload)' if args.dry_run else 'LIVE SFTP UPLOAD'}{' [FORCE]' if args.force else ''}\n")

    cfg = load_credentials()
    host = cfg.get("host", "1.studio.boardgamearena.com")
    port = int(cfg.get("port", 2022))
    username = cfg["username"]
    password = cfg["password"]

    t0 = time.time()

    if args.dry_run:
        print("[DRY-RUN] Simulating upload without SFTP connection...")
        uploaded, skipped = sync_directory(None, local_base, remote_base, dry_run=True, force=args.force)
        print(f"\n[DRY-RUN COMPLETE] Would upload {uploaded} files ({skipped} unchanged) in {time.time() - t0:.2f}s.")
        return

    transport, sftp = connect_sftp(host, port, username, password)
    try:
        # Pre-purge known forbidden vendor skeletons if present remotely (e.g. composer autoload triggering $GLOBALS HAL error)
        vendor_remote = posixpath.join(remote_base, "modules", "vendor")
        try:
            rmtree_remote(sftp, vendor_remote)
        except Exception:
            pass

        uploaded, skipped = sync_directory(sftp, local_base, remote_base, dry_run=False, force=args.force)
        print(f"\n[SUCCESS] Synced {uploaded} file(s) ({skipped} unchanged) in {time.time() - t0:.2f}s!")
    finally:
        sftp.close()
        transport.close()

if __name__ == "__main__":
    main()
