#!/usr/bin/env python3
"""
Relink imported Antigravity conversations to the native Linux workspace and project.
Patches conversation databases, conversation_summaries.db, and agyhub_summaries_proto.pb.
"""

import os
import sys
import sqlite3

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

def relink_all():
    app_data = os.path.expanduser("~/.gemini/antigravity")
    db_path = os.path.join(app_data, "conversation_summaries.db")
    conversations_dir = os.path.join(app_data, "conversations")
    agyhub_path = os.path.join(app_data, "agyhub_summaries_proto.pb")
    
    ref_conv_id = "eef72821-6ad1-4829-9a9f-c9b183845e33"
    ref_db_file = os.path.join(conversations_dir, f"{ref_conv_id}.db")
    
    # 1. Extract reference metadata from eef72821
    ref_conn = sqlite3.connect(ref_db_file)
    ref_traj_data = ref_conn.cursor().execute("SELECT data FROM trajectory_metadata_blob WHERE id = 'main'").fetchone()[0]
    ref_conn.close()
    
    ref_traj_fields = parse_pb(ref_traj_data)
    ref_field1 = [val for fn, wt, val in ref_traj_fields if fn == 1][0]
    ref_field7 = [val for fn, wt, val in ref_traj_fields if fn == 7][0]
    ref_field18 = [val for fn, wt, val in ref_traj_fields if fn == 18][0]
    
    db_conn = sqlite3.connect(db_path)
    db_conn.row_factory = sqlite3.Row
    cur = db_conn.cursor()
    
    ref_summary_data = cur.execute("SELECT raw_summary FROM conversation_summaries WHERE conversation_id = ?", (ref_conv_id,)).fetchone()[0]
    ref_summary_fields = parse_pb(ref_summary_data)
    ref_field4 = [val for fn, wt, val in ref_summary_fields if fn == 4][0]
    ref_field9 = [val for fn, wt, val in ref_summary_fields if fn == 9][0]
    
    linux_workspace_uri = '["file:///home/jayadevhaddadi/GitHub/Mandala-helper"]'
    linux_project_id = ref_field18.decode('utf-8')
    
    print(f"Reference Project ID: {linux_project_id}")
    print(f"Reference Workspace:  {linux_workspace_uri}\n")
    
    # Target conversations to relink
    imported_cids = [
        "6761547d-bdfe-4178-bcce-26cf431fb3c3", # BGA Games
        "304c2341-4b42-46d5-b5d5-6094f98a8329", # Omega
        "422567e6-34cc-4140-a32b-77f0ea0ad8e8", # KILN
        "932cac21-262f-4580-9414-f939d66cbe3c", # Sugar Gliders
        "54f08613-6d09-468d-b849-1b18df264090", # Push Fight
        "a61b97ac-42b3-4b3d-ae77-f2ef520fdc97", # Mandala BGA
        "b47c0bde-29ec-4ce2-8f11-c03b7ca5bc0b", # Lords of Scotland
        "36bc8ee4-0274-48e0-a068-7a02ae58b58e", # Yavalath
    ]
    
    for cid in imported_cids:
        # A. Update conversations/<cid>.db
        c_db_file = os.path.join(conversations_dir, f"{cid}.db")
        if os.path.exists(c_db_file):
            c_conn = sqlite3.connect(c_db_file)
            c_cur = c_conn.cursor()
            t_row = c_cur.execute("SELECT data FROM trajectory_metadata_blob WHERE id = 'main'").fetchone()
            if t_row:
                t_fields = parse_pb(t_row[0])
                new_t_fields = []
                for fn, wt, val in t_fields:
                    if fn == 1:
                        new_t_fields.append((1, 2, ref_field1))
                    elif fn == 7:
                        new_t_fields.append((7, 2, ref_field7))
                    elif fn == 18:
                        new_t_fields.append((18, 2, ref_field18))
                    else:
                        new_t_fields.append((fn, wt, val))
                new_t_data = serialize_pb(new_t_fields)
                c_cur.execute("UPDATE trajectory_metadata_blob SET data = ? WHERE id = 'main'", (new_t_data,))
                c_conn.commit()
            c_conn.close()
            print(f"Updated conversation DB: {cid}")
            
        # B. Update conversation_summaries.db
        s_row = cur.execute("SELECT raw_summary FROM conversation_summaries WHERE conversation_id = ?", (cid,)).fetchone()
        if s_row and s_row[0]:
            s_fields = parse_pb(s_row[0])
            new_s_fields = []
            for fn, wt, val in s_fields:
                if fn == 4:
                    new_s_fields.append((4, 2, ref_field4))
                elif fn == 9:
                    new_s_fields.append((9, 2, ref_field9))
                elif fn == 17:
                    # Update subfields of 17
                    sub_fields = parse_pb(val)
                    new_sub = []
                    for s_fn, s_wt, s_val in sub_fields:
                        if s_fn == 1:
                            new_sub.append((1, 2, ref_field1))
                        elif s_fn == 7:
                            new_sub.append((7, 2, ref_field7))
                        elif s_fn == 18:
                            new_sub.append((18, 2, ref_field18))
                        else:
                            new_sub.append((s_fn, s_wt, s_val))
                    new_s_fields.append((17, 2, serialize_pb(new_sub)))
                else:
                    new_s_fields.append((fn, wt, val))
            new_s_data = serialize_pb(new_s_fields)
            cur.execute("""
                UPDATE conversation_summaries 
                SET workspace_uris = ?, project_id = ?, raw_summary = ?
                WHERE conversation_id = ?
            """, (linux_workspace_uri, linux_project_id, new_s_data, cid))
            print(f"Updated summary DB: {cid}")
            
    db_conn.commit()
    
    # C. Rebuild agyhub_summaries_proto.pb
    all_rows = cur.execute("SELECT raw_summary FROM conversation_summaries ORDER BY last_modified_time DESC").fetchall()
    agyhub_out = bytearray()
    for (r_sum,) in all_rows:
        if r_sum:
            agyhub_out.append(0x0a) # Field 1 (key = 1 << 3 | 2)
            agyhub_out.extend(encode_varint(len(r_sum)))
            agyhub_out.extend(r_sum)
            
    with open(agyhub_path, "wb") as f:
        f.write(agyhub_out)
    print(f"\nRebuilt agyhub_summaries_proto.pb ({len(agyhub_out)} bytes, {len(all_rows)} conversations)")
    
    db_conn.close()
    print("\n[SUCCESS] Relink completed successfully!")

if __name__ == "__main__":
    relink_all()
