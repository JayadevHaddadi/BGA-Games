# BGA-Games

Board games for [Board Game Arena Studio](https://studio.boardgamearena.com), one folder per game, deployed to Studio
on every push to `main`. Remote: `https://github.com/JayadevHaddadi/BGA-Games` (local folder: `Mandala-helper`).

## Start here

| File | What it is |
| :--- | :--- |
| [AGENTS.md](AGENTS.md) | Instructions for coding agents: the A-to-Z playbook for a new game and the hard rules |
| [BGA_REFERENCE.md](BGA_REFERENCE.md) | Framework patterns, testing without Studio, Studio gotchas, Control Panel and image specs |
| [BGA_REVIEWER_FEEDBACK.md](BGA_REVIEWER_FEEDBACK.md) | The reviewer checklist every game must pass before a review request |
| [LICENSING_TRACKER.md](LICENSING_TRACKER.md) | Licence status, credits and contacts per game |

## Games

| Game | Folder | Studio project | State |
| :--- | :--- | :--- | :--- |
| Mandala | `games/mandala/bga-prod` | `mandala` | Released (legacy template; Studio copy is shared, upload-only) |
| Yavalath | `games/yavalath/bga` | `yavalath` | Public Alpha |
| Omega | `games/omega/bga` | `omega`, `omegatest` | Alpha requested, reviewer feedback |
| Kiln | `games/KILN/bga` | `kiln` | Alpha requested, reviewer feedback |
| Sugar Gliders | `games/sugargliders/bga` | `sugargliders` | Alpha requested, reviewer feedback |
| Push Fight | `games/pushfight/bga` | `pushfight`, `pushfighttest` | Licence in discussion |
| Lords of Scotland | `games/lordsofscotland/bga` | `lordsofscotland` | Blocked on the designer's permission |
| Gardens of Uranus / Mars / Io | `games/gardensofuranus`, `gardensofmars`, `gardensofio` | same names | In development |
| nestorgames GP | `games/nestorgamesgp/bga` | `nestorgamesgp` | In development |
| MicroForge | `games/microforge/bga` | `microforge`, `microforgetest` | In development |
| Gardens of Enceladus | `games/gardensofenceladus/bga` | `gardensofenceladus` | Implemented, not yet played on Studio |
| People | `games/people/bga` | `people` | Implemented, not yet played on Studio |
| Microchip | `games/microchip/bga` | `microchip` | Implemented, not yet played on Studio |
| Hippos and Crocodiles, Redd | not in the repo yet | `hipposandcrocodiles`, `redd` | Blank Studio templates |
| GoCaine, Blitzkrieg | not in the repo yet | `gocaine`, `blitzkrieg` | On Studio only |

Rulebooks for the backlog (Seven, Taiji, Pent-Up, Counterplays, Stack-22, Flink, Manalath, Onager) are in their
folders under `games/`.

## Tools

```bash
python3 tools/bga.py new <slug> --name "Game" --bgg <id>   # scaffold + register a game
python3 tools/bga.py check <slug>                          # pre-push gate (0 FAIL); "check all" for every game
python3 tools/bga.py assets <slug>                         # art crops and Control Panel images
python3 tools/bga.py gmm <slug>                            # Control Panel text from metadata.json
python tools/sync.py <slug> [--dry-run]                    # deploy one game now (mirrors the folder to Studio)
```

SFTP credentials live in `tools/sftp.config.json` (git-ignored); CI uses the `BGA_SFTP_PASSWORD` secret.
`tools/MIGRATE_CHATS.md` explains how to move Antigravity chat histories between machines.
