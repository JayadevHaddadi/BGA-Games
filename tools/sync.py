#!/usr/bin/env python3
"""
Board Game Arena (BGA) Universal Delta-Sync Tool
Synchronizes local game files to remote BGA Studio SFTP repository.

Usage:
    python tools/sync.py <target>                 # one game (keys of TARGET_MAP below)
    python tools/sync.py <target> --dry-run       # list uploads without connecting
    python tools/sync.py all                      # every game (its main slot)
    python tools/sync.py --changed BASE HEAD      # every game with files changed between two commits (CI uses this)

The remote folder becomes a mirror of the local folder: files missing locally are DELETED on Studio
(except the files BGA owns: _ide_helper.php, bga-framework.d.ts, misc/, LICENCE_BGA).
Targets in NO_PRUNE (Mandala, whose Studio copy is the team's source of truth) are upload-only.
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
    "gardensofuranus": ("games/gardensofuranus/bga", "gardensofuranus"),
    "gardensofuranustest": ("games/gardensofuranus/bga", "gardensofuranus"),
    "nestorgamesgp": ("games/nestorgamesgp/bga", "nestorgamesgp"),
    "nestorgamesgptest": ("games/nestorgamesgp/bga", "nestorgamesgp"),
    "microforge": ("games/microforge/bga", "microforge"),
    "microforgetest": ("games/microforge/bga", "microforgetest"),
    "gardensofmars": ("games/gardensofmars/bga", "gardensofmars"),
    "gardensofmarstest": ("games/gardensofmars/bga", "gardensofmars"),
    "gardensofio": ("games/gardensofio/bga", "gardensofio"),
    "gardensofenceladus": ("games/gardensofenceladus/bga", "gardensofenceladus"),
    "microchip": ("games/microchip/bga", "microchip"),
    "people": ("games/people/bga", "people"),
    # <bga:targets>  (tools/bga.py register inserts new games above this line)
}

# Upload-only targets: never delete remote files (the Studio copy is shared with other developers).
NO_PRUNE = {"mandala"}

# Targets that always upload every file.
ALWAYS_FORCE = {"lordsofscotland"}


def main_targets():
    """One target per local folder: the key that equals its remote folder, preferring non-test slots."""
    best = {}
    for key, (local, remote) in TARGET_MAP.items():
        score = (key == remote, not key.endswith("test"))
        if local not in best or score > best[local][0]:
            best[local] = (score, key)
    return {local: key for local, (score, key) in best.items()}


def changed_targets(base, head):
    """Targets whose local folder has files changed between two commits (a new branch falls back to HEAD~1)."""
    import subprocess
    if not base or set(base) == {"0"}:
        base = f"{head}~1"
    out = subprocess.run(["git", "diff", "--name-only", base, head], cwd=ROOT_DIR, capture_output=True, text=True)
    if out.returncode != 0:
        print(f"git diff {base} {head} failed: {out.stderr.strip()}; falling back to HEAD~1")
        out = subprocess.run(["git", "diff", "--name-only", f"{head}~1", head], cwd=ROOT_DIR, capture_output=True, text=True)
    files = [f.strip().lower() for f in out.stdout.splitlines() if f.strip()]
    targets = []
    for local, key in main_targets().items():
        prefix = local.rstrip("/").lower() + "/"
        if any(f.startswith(prefix) for f in files) and key not in targets:
            targets.append(key)
    return targets

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

def sync_directory(sftp, local_dir, remote_dir, dry_run=False, force=False, prune=True):
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
    system_files = {"_ide_helper.php", "bga-framework.d.ts", ".", "..", "misc", ".svn", "LICENCE_BGA"}
    for rem_name in list(remote_attrs.keys()):
        if rem_name in system_files:
            continue
        if not prune:
            if not os.path.exists(os.path.join(local_dir, rem_name)):
                print(f"[KEEP] {posixpath.join(remote_dir, rem_name)} is not in the local copy (upload-only target)", flush=True)
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
            u, s = sync_directory(sftp, l_path, r_path, dry_run=dry_run, force=force, prune=prune)
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
                    sftp.put(l_path, r_path, confirm=False)
                uploaded += 1
            else:
                skipped += 1

    return uploaded, skipped

def sync_target(target_key, remote_dir=None, dry_run=False, force=False):
    if target_key in TARGET_MAP:
        local_rel, remote_base = TARGET_MAP[target_key]
        local_base = os.path.join(ROOT_DIR, local_rel)
    else:
        local_base = os.path.abspath(target_key)
        remote_base = remote_dir or os.path.basename(local_base)

    if not os.path.exists(local_base):
        print(f"Error: Local directory does not exist: {local_base}")
        return False
    force = force or target_key in ALWAYS_FORCE
    prune = target_key not in NO_PRUNE

    print(f"=== BGA Universal Sync: {target_key.upper()} ===")
    print(f"Local:  {local_base}")
    print(f"Remote: {remote_base}")
    mode = 'DRY-RUN (No Upload)' if dry_run else 'LIVE SFTP UPLOAD'
    print(f"Mode:   {mode}{' [FORCE]' if force else ''}{'' if prune else ' [UPLOAD-ONLY]'}\n")

    t0 = time.time()
    if dry_run:
        print("[DRY-RUN] Simulating upload without SFTP connection...")
        uploaded, skipped = sync_directory(None, local_base, remote_base, dry_run=True, force=force, prune=prune)
        print(f"\n[DRY-RUN COMPLETE] Would upload {uploaded} files ({skipped} unchanged) in {time.time() - t0:.2f}s.")
        return True

    cfg = load_credentials()
    transport, sftp = connect_sftp(cfg.get("host", "1.studio.boardgamearena.com"), int(cfg.get("port", 2022)),
                                   cfg["username"], cfg["password"])
    try:
        # Pre-purge known forbidden vendor skeletons if present remotely (composer autoload triggers a HAL error)
        try:
            rmtree_remote(sftp, posixpath.join(remote_base, "modules", "vendor"))
        except Exception:
            pass
        uploaded, skipped = sync_directory(sftp, local_base, remote_base, dry_run=False, force=force, prune=prune)
        print(f"\n[SUCCESS] Synced {uploaded} file(s) ({skipped} unchanged) in {time.time() - t0:.2f}s!")
    finally:
        sftp.close()
        transport.close()
    return True


def main():
    parser = argparse.ArgumentParser(description="BGA Multi-Game Universal SFTP Sync Tool")
    parser.add_argument("target", nargs="?", help=f"Game target: {', '.join(TARGET_MAP.keys())}, 'all', or a custom <local_dir>")
    parser.add_argument("remote_dir", nargs="?", default=None, help="Remote destination directory (optional if target is known)")
    parser.add_argument("--dry-run", action="store_true", help="Preview files that would be uploaded without connecting/uploading")
    parser.add_argument("--force", action="store_true", help="Force upload all files regardless of remote mtime/size")
    parser.add_argument("--changed", nargs=2, metavar=("BASE", "HEAD"), help="Sync every game changed between two commits")
    args = parser.parse_args()

    if args.changed:
        targets = changed_targets(*args.changed)
        print(f"Changed games: {', '.join(targets) or 'none'}")
    elif args.target and args.target.lower() == "all":
        targets = list(main_targets().values())
    elif args.target:
        targets = [args.target.lower()]
    else:
        parser.error("give a target, 'all' or --changed BASE HEAD")

    failed = []
    for key in targets:
        try:
            if not sync_target(key, args.remote_dir if len(targets) == 1 else None, dry_run=args.dry_run, force=args.force):
                failed.append(key)
        except Exception as e:
            print(f"[ERROR] {key}: {e}")
            failed.append(key)
    if failed:
        print(f"\nFailed: {', '.join(failed)}")
        sys.exit(1)


if __name__ == "__main__":
    main()
