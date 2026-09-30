#!/usr/bin/env python3
"""
Antigravity Chat Migration Tool
Enables exporting conversations from Windows and importing them seamlessly into Linux (or vice versa).
"""

import os
import sys
import glob
import json
import shutil
import sqlite3
import zipfile
import argparse
from pathlib import Path

def find_antigravity_dir():
    """Locate the Antigravity app data directory across platforms."""
    candidates = []
    
    if sys.platform == "win32":
        user_profile = os.environ.get("USERPROFILE", "")
        app_data = os.environ.get("APPDATA", "")
        candidates.extend([
            os.path.join(user_profile, ".gemini", "antigravity"),
            os.path.join(app_data, "Antigravity"),
            os.path.join(user_profile, ".gemini", "antigravity-cli"),
        ])
    else:
        home = str(Path.home())
        candidates.extend([
            os.path.join(home, ".gemini", "antigravity"),
            os.path.join(home, ".config", "antigravity"),
            os.path.join(home, ".gemini", "antigravity-cli"),
        ])
        
    for path in candidates:
        if os.path.exists(path) and (
            os.path.exists(os.path.join(path, "conversation_summaries.db")) or
            os.path.exists(os.path.join(path, "conversations"))
        ):
            return os.path.abspath(path)
            
    # Default fallback
    if sys.platform == "win32":
        return os.path.join(os.environ.get("USERPROFILE", ""), ".gemini", "antigravity")
    return os.path.join(str(Path.home()), ".gemini", "antigravity")

def export_chats(output_zip, source_dir=None):
    source_dir = source_dir or find_antigravity_dir()
    if not os.path.exists(source_dir):
        print(f"Error: Antigravity directory not found at: {source_dir}")
        sys.exit(1)

    print(f"=== Antigravity Chat Exporter ===")
    print(f"Source Directory: {source_dir}")
    print(f"Output Archive:   {output_zip}\n")

    conversations_dir = os.path.join(source_dir, "conversations")
    brain_dir = os.path.join(source_dir, "brain")
    db_file = os.path.join(source_dir, "conversation_summaries.db")

    with zipfile.ZipFile(output_zip, "w", zipfile.ZIP_DEFLATED) as zf:
        if os.path.exists(db_file):
            print("Packing conversation_summaries.db...")
            zf.write(db_file, "conversation_summaries.db")
        else:
            print("Warning: conversation_summaries.db not found!")

        if os.path.exists(conversations_dir):
            conv_files = glob.glob(os.path.join(conversations_dir, "*"))
            print(f"Packing {len(conv_files)} conversation state file(s)...")
            for f in conv_files:
                rel = os.path.relpath(f, source_dir)
                zf.write(f, rel)

        if os.path.exists(brain_dir):
            print("Packing brain transcripts & artifacts...")
            count = 0
            for root, dirs, files in os.walk(brain_dir):
                for file in files:
                    full_p = os.path.join(root, file)
                    rel = os.path.relpath(full_p, source_dir)
                    zf.write(full_p, rel)
                    count += 1
            print(f"Packed {count} artifact/transcript file(s).")

    print(f"\n[SUCCESS] Successfully exported to: {output_zip}")
    print("Transfer this zip file to your target computer.")

def import_chats(input_zip, target_dir=None, target_workspace_uri=None):
    target_dir = target_dir or find_antigravity_dir()
    os.makedirs(target_dir, exist_ok=True)

    conversations_target = os.path.join(target_dir, "conversations")
    brain_target = os.path.join(target_dir, "brain")
    db_target = os.path.join(target_dir, "conversation_summaries.db")

    os.makedirs(conversations_target, exist_ok=True)
    os.makedirs(brain_target, exist_ok=True)

    temp_dir = os.path.join(target_dir, "_temp_import")
    if os.path.exists(temp_dir):
        shutil.rmtree(temp_dir)
    os.makedirs(temp_dir)

    print(f"=== Antigravity Chat Importer ===")
    print(f"Archive:          {input_zip}")
    print(f"Target Directory: {target_dir}")
    if target_workspace_uri:
        print(f"Target Workspace: {target_workspace_uri}")
    print()

    try:
        with zipfile.ZipFile(input_zip, "r") as zf:
            zf.extractall(temp_dir)

        # 1. Copy conversation files
        temp_conv = os.path.join(temp_dir, "conversations")
        imported_convs = 0
        if os.path.exists(temp_conv):
            for item in os.listdir(temp_conv):
                src = os.path.join(temp_conv, item)
                dst = os.path.join(conversations_target, item)
                if not os.path.exists(dst):
                    shutil.copy2(src, dst)
                    imported_convs += 1
        print(f"Imported {imported_convs} conversation database(s).")

        # 2. Copy brain directories
        temp_brain = os.path.join(temp_dir, "brain")
        imported_brains = 0
        if os.path.exists(temp_brain):
            for item in os.listdir(temp_brain):
                src = os.path.join(temp_brain, item)
                dst = os.path.join(brain_target, item)
                if not os.path.exists(dst):
                    if os.path.isdir(src):
                        shutil.copytree(src, dst)
                    else:
                        shutil.copy2(src, dst)
                    imported_brains += 1
        print(f"Imported {imported_brains} brain directory/directories.")

        # 3. Merge conversation_summaries.db
        temp_db = os.path.join(temp_dir, "conversation_summaries.db")
        if os.path.exists(temp_db):
            if not os.path.exists(db_target):
                shutil.copy2(temp_db, db_target)
                print("Created target conversation_summaries.db from export.")
            else:
                src_conn = sqlite3.connect(temp_db)
                dst_conn = sqlite3.connect(db_target)
                src_conn.row_factory = sqlite3.Row

                # Ensure target table exists with same schema
                schema = src_conn.execute("SELECT sql FROM sqlite_master WHERE type='table' AND name='conversation_summaries'").fetchone()
                if schema and schema[0]:
                    dst_conn.execute(schema[0])

                rows = src_conn.execute("SELECT * FROM conversation_summaries").fetchall()
                print(f"Merging {len(rows)} conversation summary rows...")

                merged_count = 0
                for row in rows:
                    row_dict = dict(row)
                    # Remap app_data_dir to target
                    row_dict["app_data_dir"] = target_dir

                    # Remap workspace URI if requested
                    if target_workspace_uri:
                        # Ensure it's stored as JSON list
                        if not target_workspace_uri.startswith("["):
                            target_uris = json.dumps([target_workspace_uri])
                        else:
                            target_uris = target_workspace_uri
                        row_dict["workspace_uris"] = target_uris

                    columns = list(row_dict.keys())
                    placeholders = ", ".join(["?" for _ in columns])
                    col_names = ", ".join([f"`{c}`" for c in columns])
                    query = f"INSERT OR REPLACE INTO `conversation_summaries` ({col_names}) VALUES ({placeholders})"
                    dst_conn.execute(query, list(row_dict.values()))
                    merged_count += 1

                dst_conn.commit()
                src_conn.close()
                dst_conn.close()
                print(f"Successfully merged {merged_count} conversation summary entries into target DB.")

        print(f"\n[SUCCESS] Chat migration completed!")
        print("Restart Antigravity or Antigravity CLI (agy) to see your migrated chats.")

    finally:
        if os.path.exists(temp_dir):
            shutil.rmtree(temp_dir)

def main():
    parser = argparse.ArgumentParser(description="Antigravity Chat Migration Tool")
    subparsers = parser.add_subparsers(dest="command", required=True)

    export_parser = subparsers.add_parser("export", help="Export conversations to a zip file")
    export_parser.add_argument("-o", "--output", default="antigravity_chats_export.zip", help="Path to output zip file")
    export_parser.add_argument("-s", "--source", default=None, help="Custom Antigravity app data source path")

    import_parser = subparsers.add_parser("import", help="Import conversations from an exported zip file")
    import_parser.add_argument("archive", help="Path to exported zip file")
    import_parser.add_argument("-t", "--target", default=None, help="Custom Antigravity target directory")
    import_parser.add_argument("-w", "--workspace", default="file:///home/jayadevhaddadi/GitHub/Mandala-helper", help="Target workspace URI to link chats to")

    args = parser.parse_args()
    if args.command == "export":
        export_chats(args.output, args.source)
    elif args.command == "import":
        import_chats(args.archive, args.target, args.workspace)

if __name__ == "__main__":
    main()
