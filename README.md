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
| Gardens of Uranus / Mars / Io | `games/gardensofuranus`, `gardensofmars`, `gardensofio` (each `/bga`) | same names | Mars and Io in Private Alpha; Uranus in development. Io is in nightly E2E |
| nestorgames GP | `games/nestorgamesgp/bga` | `nestorgamesgp` | In development |
| MicroForge (game name Little Commanders) | `games/microforge/bga` | `microforge`, `microforgetest` | In development; `bga.py check` FAILs on the project-name rule |
| Gardens of Enceladus | `games/gardensofenceladus/bga` | `gardensofenceladus` | Implemented, not yet played on Studio |
| People | `games/people/bga` | `people` | Implemented, not yet played on Studio |
| Microchip | `games/microchip/bga` | `microchip` | Implemented, not yet played on Studio |
| Red | `games/red/bga` | `red` | Implemented, in nightly E2E (licence not confirmed) |
| Hippos and Crocodiles | `games/hipposcrocodiles/bga` | `hipposcrocodiles` | Implemented, in nightly E2E (licence not confirmed) |
| Pent-Up (3D) | `games/pentup/bga` | `pentup` | Implemented, in nightly E2E |
| Vault (nestorgames) | `games/vaultnestorgames/bga` | `vaultnestorgames` | Implemented, first live run pending |
| GoCaine, Blitzkrieg | not in the repo yet | `gocaine`, `blitzkrieg` | On Studio only |

Per-game status of record: [LICENSING_TRACKER.md](LICENSING_TRACKER.md) (licences and stage) and
[reports/e2e_status.md](reports/e2e_status.md) (live results). Rulebooks for the backlog (Seven, Taiji, Pent-Up, Counterplays, Stack-22, Flink, Manalath, Onager) are in their
folders under `games/`.

## How deploy, tests and E2E work

* **Deploy**: a push to `main` runs `.github/workflows/bga-deploy.yml`, which uploads every game folder changed in the
  pushed commits to Studio over SFTP (`tools/sync.py --changed`). The Studio copy mirrors the folder: files missing
  locally are deleted there (Mandala is upload-only). Fast lane: `python3 tools/sync.py <slug>`.
* **Offline tests**: `python3 tools/test_all.py [slug]` runs, per game, `bga.py check` (PHP lint, JS syntax, reviewer
  rules, `tests/*_test.php`) and the browser harness in headless Chrome at 375 and 1280 px. CI:
  `.github/workflows/tests.yml`. Accepted FAILs: `tools/known_fails.json`.
* **Live E2E**: `tools/e2e/studio_play.py` opens real Studio tables (load, refresh, autoplay, zombie, replay) after a
  deploy and nightly for the slugs in `tools/e2e/games.json`. Results: `reports/e2e_status.md`; setup:
  `reports/e2e_setup.md`. Needs the secret `BGA_STUDIO_PASSWORD`.
* **What nothing here tests**: the PHP inside the real framework (only a live table does), and look and feel on a phone.

## Tools

```bash
python3 tools/bga.py new <slug> --name "Game" --bgg <id>   # scaffold + register a game
python3 tools/bga.py check <slug>                          # pre-push gate (0 FAIL); "check all" for every game
python3 tools/bga.py assets <slug>                         # art crops and Control Panel images
python3 tools/bga.py gmm <slug>                            # Control Panel text from metadata.json
python3 tools/sync.py <slug> [--dry-run]                   # deploy one game now (mirrors the folder to Studio)
python3 tools/test_all.py [slug]                           # all offline tests
```

SFTP credentials live in `tools/sftp.config.json` (git-ignored); CI uses the `BGA_SFTP_PASSWORD` secret.
Repo health notes and open decisions: [reports/repo_health.md](reports/repo_health.md).
