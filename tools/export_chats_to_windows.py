#!/usr/bin/env python3
"""
Export Antigravity chats from Linux back to Windows in a 1-step zip package.
Preserves all custom titles, message history, brain artifacts, and planning files.
Rewrites Linux paths to Windows drive paths and Windows project ID automatically.
"""

import os
import sys
import shutil
import sqlite3
import argparse
import zipfile

def parse_pb(data):
    fields = []
    pos = 0
    while pos < len(data):
        key = 0
        shift = 0
        while True:
            b = data[pos]
            pos += 1
            key |= (b & 0x7f) << shift
            shift += 7
            if not (b & 0x80):
                break
        field_num = key >> 3
        wire_type = key & 0x7
        if wire_type == 0:
            val = 0
            shift = 0
            while True:
                b = data[pos]
                pos += 1
                val |= (b & 0x7f) << shift
                shift += 7
                if not (b & 0x80):
                    break
            fields.append((field_num, wire_type, val))
        elif wire_type == 2:
            length = 0
            shift = 0
            while True:
                b = data[pos]
                pos += 1
                length |= (b & 0x7f) << shift
                shift += 7
                if not (b & 0x80):
                    break
            chunk = data[pos:pos+length]
            pos += length
            fields.append((field_num, wire_type, chunk))
        elif wire_type == 1:
            chunk = data[pos:pos+8]
            pos += 8
            fields.append((field_num, wire_type, chunk))
        elif wire_type == 5:
            chunk = data[pos:pos+4]
            pos += 4
            fields.append((field_num, wire_type, chunk))
        else:
            raise ValueError(f"Unknown wire type {wire_type}")
    return fields

def encode_varint(val):
    res = bytearray()
    while True:
        b = val & 0x7f
        val >>= 7
        if val:
            res.append(b | 0x80)
        else:
            res.append(b)
            break
    return bytes(res)

def serialize_pb(fields):
    out = bytearray()
    for field_num, wire_type, val in fields:
        key = (field_num << 3) | wire_type
        out.extend(encode_varint(key))
        if wire_type == 0:
            out.extend(encode_varint(val))
        elif wire_type == 2:
            out.extend(encode_varint(len(val)))
            out.extend(val)
        elif wire_type in (1, 5):
            out.extend(val)
    return bytes(out)

def export_for_windows(win_workspace_uri="file:///d%3A/GitHub/Mandala-helper", 
                       win_project_id="300f5154-469f-4706-b626-366b127e23e2",
                       output_zip="antigravity_chats_for_windows.zip"):
    app_data = os.path.expanduser("~/.gemini/antigravity")
    db_path = os.path.join(app_data, "conversation_summaries.db")
    conversations_dir = os.path.join(app_data, "conversations")
    brain_dir = os.path.join(app_data, "brain")

    if not os.path.exists(db_path):
        print(f"Error: Database {db_path} not found.")
        sys.exit(1)

    temp_export_dir = os.path.join("/tmp", "antigravity_win_export")
    if os.path.exists(temp_export_dir):
        shutil.rmtree(temp_export_dir)
    os.makedirs(os.path.join(temp_export_dir, "conversations"), exist_ok=True)
    os.makedirs(os.path.join(temp_export_dir, "brain"), exist_ok=True)

    # 1. Connect to Linux summaries DB to get project conversations
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()

    schema = cur.execute("SELECT sql FROM sqlite_master WHERE type='table' AND name='conversation_summaries'").fetchone()[0]

    # Find all conversations belonging to Mandala-helper
    rows = cur.execute("""
        SELECT * FROM conversation_summaries 
        WHERE workspace_uris LIKE '%Mandala-helper%'
        ORDER BY last_modified_time DESC
    """).fetchall()

    print(f"Found {len(rows)} conversations associated with Mandala-helper:")
    for r in rows:
        print(f"  - [{r['title'] or '(No Title)'}] {r['conversation_id']}")

    # Create target conversation_summaries.db for Windows
    target_db_path = os.path.join(temp_export_dir, "conversation_summaries.db")
    target_conn = sqlite3.connect(target_db_path)
    target_cur = target_conn.cursor()
    target_cur.execute(schema)

    win_workspace_json = f'["{win_workspace_uri}"]'
    encoded_win_uri = win_workspace_uri.encode('utf-8')
    encoded_win_pid = win_project_id.encode('utf-8')

    win_summaries_pb = []

    for r in rows:
        cid = r['conversation_id']
        title = r['title']

        # Copy and patch conversation DB
        src_c_db = os.path.join(conversations_dir, f"{cid}.db")
        dst_c_db = os.path.join(temp_export_dir, "conversations", f"{cid}.db")
        if os.path.exists(src_c_db):
            shutil.copy2(src_c_db, dst_c_db)
            c_conn = sqlite3.connect(dst_c_db)
            c_cur = c_conn.cursor()
            t_row = c_cur.execute("SELECT data FROM trajectory_metadata_blob WHERE id = 'main'").fetchone()
            if t_row and t_row[0]:
                t_fields = parse_pb(t_row[0])
                new_t_fields = []
                for fn, wt, val in t_fields:
                    if fn == 1:
                        new_t_fields.append((1, 2, encoded_win_uri))
                    elif fn == 18:
                        new_t_fields.append((18, 2, encoded_win_pid))
                    else:
                        new_t_fields.append((fn, wt, val))
                c_cur.execute("UPDATE trajectory_metadata_blob SET data = ? WHERE id = 'main'", (serialize_pb(new_t_fields),))
                c_conn.commit()
            c_conn.close()

        # Copy brain folder
        src_brain = os.path.join(brain_dir, cid)
        dst_brain = os.path.join(temp_export_dir, "brain", cid)
        if os.path.exists(src_brain):
            shutil.copytree(src_brain, dst_brain, dirs_exist_ok=True)

        # Patch raw_summary blob
        new_raw_summary = None
        if r['raw_summary']:
            s_fields = parse_pb(r['raw_summary'])
            new_s_fields = []
            for fn, wt, val in s_fields:
                if fn == 4:
                    new_s_fields.append((4, 2, encoded_win_uri))
                elif fn == 17:
                    sub_fields = parse_pb(val)
                    new_sub = []
                    for s_fn, s_wt, s_val in sub_fields:
                        if s_fn == 1:
                            new_sub.append((1, 2, encoded_win_uri))
                        elif s_fn == 18:
                            new_sub.append((18, 2, encoded_win_pid))
                        else:
                            new_sub.append((s_fn, s_wt, s_val))
                    new_s_fields.append((17, 2, serialize_pb(new_sub)))
                else:
                    new_s_fields.append((fn, wt, val))
            new_raw_summary = serialize_pb(new_s_fields)
            win_summaries_pb.append(new_raw_summary)

        # Build row dict for insertion
        row_dict = dict(r)
        row_dict['workspace_uris'] = win_workspace_json
        row_dict['project_id'] = win_project_id
        row_dict['raw_summary'] = new_raw_summary

        cols = list(row_dict.keys())
        placeholders = ', '.join(['?'] * len(cols))
        col_names = ', '.join([f'`{c}`' for c in cols])
        target_cur.execute(f"INSERT OR REPLACE INTO conversation_summaries ({col_names}) VALUES ({placeholders})", list(row_dict.values()))

    target_conn.commit()
    target_conn.close()
    conn.close()

    # Create agyhub_summaries_proto.pb
    target_agyhub = os.path.join(temp_export_dir, "agyhub_summaries_proto.pb")
    agyhub_out = bytearray()
    for r_sum in win_summaries_pb:
        agyhub_out.append(0x0a)
        agyhub_out.extend(encode_varint(len(r_sum)))
        agyhub_out.extend(r_sum)
    with open(target_agyhub, "wb") as f:
        f.write(agyhub_out)

    # Package into ZIP
    print(f"\nCompressing into {output_zip}...")
    with zipfile.ZipFile(output_zip, "w", zipfile.ZIP_DEFLATED) as zipf:
        for root, _, files in os.walk(temp_export_dir):
            for file in files:
                abs_path = os.path.join(root, file)
                rel_path = os.path.relpath(abs_path, temp_export_dir)
                zipf.write(abs_path, rel_path)

    shutil.rmtree(temp_export_dir)
    print(f"\n[SUCCESS] Exported {len(rows)} conversations with custom titles to: {output_zip}")
    print(f"On Windows, simply extract this zip into %USERPROFILE%\\.gemini\\antigravity\\")

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Export Antigravity chats for Windows")
    parser.add_argument("--win-path", default="file:///d%3A/GitHub/Mandala-helper", help="Windows workspace URI")
    parser.add_argument("--project-id", default="300f5154-469f-4706-b626-366b127e23e2", help="Windows Project UUID")
    parser.add_argument("--output", default="antigravity_chats_for_windows.zip", help="Output zip filename")
    args = parser.parse_args()
    export_for_windows(args.win_path, args.project_id, args.output)
