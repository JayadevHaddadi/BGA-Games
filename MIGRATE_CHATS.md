# Antigravity Chat Migration Guide (Windows $\rightarrow$ Linux / Remote Server)

This guide explains how to migrate your Antigravity chat histories and conversation states from your Windows computer to this Linux machine (or vice-versa).

---

## Where Antigravity Stores Conversations

On both platforms, Google Antigravity stores session data under the `.gemini` profile directory:

| Component | Windows Location | Linux Location |
| :--- | :--- | :--- |
| **App Data Root** | `%USERPROFILE%\.gemini\antigravity\` | `~/.gemini/antigravity/` |
| **Conversation State** | `%USERPROFILE%\.gemini\antigravity\conversations\*.db` | `~/.gemini/antigravity/conversations/*.db` |
| **Brain / Transcripts / Artifacts** | `%USERPROFILE%\.gemini\antigravity\brain\<conv_id>\` | `~/.gemini/antigravity/brain/<conv_id>/` |
| **Index Database** | `%USERPROFILE%\.gemini\antigravity\conversation_summaries.db` | `~/.gemini/antigravity/conversation_summaries.db` |

> [!IMPORTANT]
> **Why you shouldn't just overwrite files manually:**
> 1. `conversation_summaries.db` is an SQLite database. Copying it directly over will **wipe out** any conversations already on your Linux server.
> 2. `conversation_summaries.db` stores `workspace_uris` (e.g. `file:///C:/Users/.../Mandala-helper`) and `app_data_dir`. On Linux, these paths must be remapped to `file:///home/jayadevhaddadi/GitHub/Mandala-helper` so Antigravity links the conversations to your active project.

---

## Method 1: Automated Script (Recommended)

A migration script is provided in the repository at `tools/migrate_antigravity_chats.py`.

### Step 1: On Your Windows Machine (Export)

Open PowerShell or Command Prompt in the repository folder:

```powershell
python tools\migrate_antigravity_chats.py export
```

This will automatically locate your Antigravity folder and create a single archive:
`antigravity_chats_export.zip`.

*(If your Antigravity folder is in a custom path, you can specify it with `-s "C:\Path\To\.gemini\antigravity"`).*

### Step 2: Transfer the Zip File to the Linux Machine

Copy `antigravity_chats_export.zip` to the Linux server using any method:
* **SCP / SFTP**:
  ```bash
  scp antigravity_chats_export.zip jayadevhaddadi@<server-ip>:~/GitHub/Mandala-helper/
  ```
* Or upload it via Google Drive, Dropbox, local network share, or USB.

### Step 3: On This Linux Machine (Import & Merge)

In the repository on Linux, run:

```bash
python3 tools/migrate_antigravity_chats.py import antigravity_chats_export.zip
```

The script will:
1. Copy all conversation databases (`conversations/*.db`) without overwriting existing ones.
2. Copy all brain transcripts, artifacts, and scratch directories.
3. Merge records in `conversation_summaries.db`, updating `workspace_uris` to `file:///home/jayadevhaddadi/GitHub/Mandala-helper` and `app_data_dir` to `/home/jayadevhaddadi/.gemini/antigravity`.

Restart Antigravity Desktop or Antigravity CLI (`agy`), and all your chats from Windows will appear in your project sidebar!

---

## Method 2: Manual Migration (Without Script)

If you prefer doing it manually:

### 1. On Windows
Zip the three key items inside `%USERPROFILE%\.gemini\antigravity\`:
* `conversations\` (all `.db` files)
* `brain\` (all subfolders)
* `conversation_summaries.db`

### 2. On Linux
Copy the files into `~/.gemini/antigravity/`:
* Put all `.db` files from Windows into `~/.gemini/antigravity/conversations/`.
* Put all `<uuid>` folders from Windows into `~/.gemini/antigravity/brain/`.
* To merge the SQLite database without losing Linux chats, open terminal on Linux and run:
  ```bash
  sqlite3 ~/.gemini/antigravity/conversation_summaries.db
  ```
  Then run SQL:
  ```sql
  ATTACH DATABASE '/path/to/windows_conversation_summaries.db' AS win_db;

  INSERT OR REPLACE INTO conversation_summaries
  SELECT 
    conversation_id,
    title,
    preview,
    step_count,
    last_modified_time,
    '["file:///home/jayadevhaddadi/GitHub/Mandala-helper"]' AS workspace_uris,
    status,
    source,
    project_id,
    agent_name,
    parent_conversation_id,
    nesting_depth,
    battle_id,
    winning_conversation_id,
    not_fully_idle,
    killed,
    last_user_input_time,
    last_user_input_step_index,
    '/home/jayadevhaddadi/.gemini/antigravity' AS app_data_dir,
    raw_summary,
    group_id
  FROM win_db.conversation_summaries;

  DETACH DATABASE win_db;
  .quit
  ```

---

## Verifying the Migrated Chats

Once imported, you can verify from the command line:

```bash
# Check available conversations via SQLite
sqlite3 ~/.gemini/antigravity/conversation_summaries.db "SELECT conversation_id, title FROM conversation_summaries;"

# Resume any migrated conversation with the CLI
agy --conversation <CONVERSATION_ID>
```

Or simply launch the **Antigravity 2.0** desktop app — all migrated conversations will appear listed under the **Mandala-helper** project in the sidebar.
