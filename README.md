# Board Game Arena (BGA) Studio Multi-Game Workspace (`BGA-Games`) 🎲

A structured, modular multi-game repository for developing, testing, and deploying games to [Board Game Arena (BGA) Studio](https://studio.boardgamearena.com).
* **GitHub Repository**: `https://github.com/JayadevHaddadi/BGA-Games`
* **Local Directory**: `Mandala-helper/` (preserved for workspace/chat persistence)

---

## 📁 Repository Structure

```
BGA-Games/ (local folder: Mandala-helper/)
│
├── README.md                           # Master workspace overview (this file)
├── BGA_DEVELOPER_CHEAT_SHEET.md        # Central developer reference & gotchas
├── bga_credentials.md                  # Developer account reference & setup
│
├── tools/                              # Common development tools & scripts
│   ├── sync.py                         # Universal SFTP delta-sync tool for ANY game
│   └── sftp.config.json                # Shared SFTP credentials (git-ignored)
│
└── games/                              # All game projects grouped by title
    │
    ├── pushfight/                      # [Push Fight] (Modern PHP 8 OOP Template)
    │   ├── bga/                        # BGA Studio codebase (pushfighttest)
    │   │   ├── modules/php/            # Game.php, State Machine classes
    │   │   ├── modules/js/             # Game.js (Client engine & Web Audio)
    │   │   ├── metadata_assets/        # Release-ready Box, Banner, Title, Display art
    │   │   ├── stats.jsonc             # Game statistics
    │   │   └── gameinfos.jsonc         # Metadata & configuration
    │   └── prototype/                  # Standalone web prototype & official rules PDFs
    │
    ├── mandala/                        # [Mandala] (Legacy Dojo Template)
    │   ├── bga-prod/                   # Production BGA codebase (mandala)
    │   └── chrome-extension/           # Mandala Point Counter & HUD Chrome Extension
    │
    ├── lordsofscotland/                # [Lords of Scotland] (Upcoming Game)
    │   └── bga/                        # Studio sandbox codebase
    │
    └── omega/                          # [Omega] (nestorgames - Modern Hex Engine)
        └── bga/                        # Studio sandbox codebase (omegatest)
```

---

## 🚀 Universal BGA Sync Tool (`tools/sync.py`)

A single, fast delta-sync script replaces all individual sync scripts. It compares local and remote file modification times and sizes, uploading only modified files over SFTP in seconds.

### Quick Usage

```bash
# Push Fight (syncs games/pushfight/bga -> remote pushfighttest)
python tools/sync.py pushfighttest

# Omega Production (syncs games/omega/bga -> remote omega)
python tools/sync.py omega

# Mandala Production (syncs games/mandala/bga-prod -> remote mandala)
python tools/sync.py mandala

# Lords of Scotland (syncs games/lordsofscotland/bga -> remote lordsofscotland)
python tools/sync.py lordsofscotland

# Preview changes without uploading (Dry Run)
python tools/sync.py pushfighttest --dry-run
```

### Credentials Configuration
Place your SFTP login details in `tools/sftp.config.json` (already configured and git-ignored):
```json
{
    "host": "1.studio.boardgamearena.com",
    "port": 2022,
    "username": "YourUsername",
    "password": "YourPassword"
}
```

---

## 🎮 Active Games

### 1. Push Fight (`games/pushfight/`)
* **Framework**: Modern BGA OOP Template (PHP 8, ES6 modular JavaScript, pure CSS).
* **Features**:
  * 26-square board geometry with 3D raised rails and coordinate grid.
  * BFS flood-fill sliding movement (0–2 moves).
  * Mandatory King push chain resolution with side rail collision and off-board victory detection.
  * Metallic anchor locking token.
  * Built-in Web Audio API sound synthesis (tactile slides, bass push thud, anchor chime, victory fanfare).
  * Full table and player statistics tracking.
* **Metadata & Art**: Complete set of 2000×2000 title image, 1386×400 banner, 280×280 box, 50×50 icon, and 900×600 display photos in `metadata_assets/`.

### 2. Mandala (`games/mandala/`)
* **Framework**: Legacy Dojo / PHP template.
* **Components**:
  * `bga-prod/`: Production release code (the only codebase now — `bga-test`/`mandalatest` sandbox has been retired).
  * `chrome-extension/`: Standalone browser extension providing a live HUD, 18-card counting probabilities, opponent mystery card odds, and real-time river scoring.

### 3. Lords of Scotland (`games/lordsofscotland/`)
* **Framework**: Modern BGA OOP Template workspace ready for new development.

---

## 📖 Developer Documentation
* **[BGA_DEVELOPER_CHEAT_SHEET.md](file:///d:/GitHub/Mandala-helper/BGA_DEVELOPER_CHEAT_SHEET.md)**: Architectural gotchas, `PlayerStats` parameter order, zombie reflection rules, schema persistence, and the Game Metadata Manager guide.

---

## License
MIT License.
