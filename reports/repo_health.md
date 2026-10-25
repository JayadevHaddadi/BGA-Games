# Repo health audit (2026-10-25)

Scope: docs and hygiene only. No game code under `games/*/bga` was touched.

## Verified
- `python3 tools/bga.py check all`: 0 FAIL everywhere except `mandala` (4, accepted in `tools/known_fails.json`) and `microforge` (2).
- `python3 tools/test_all.py`: all games ok except `microforge` (check FAIL: Studio project `microforge` vs `game_name` "Little Commanders"; harness ok).
- No `__pycache__`, `.pyc`, `sftp.config.json`, `bga_session.txt` or chat-export zip is tracked (all git-ignored and untracked). `git status` was clean.

## Changed
- AGENTS.md: tool list updated (real subcommands, test_all, e2e), stale "hipposcrocodiles not in repo" removed, new section 4 "Known pitfalls" (PossibleAction `use`, addActionButton signature, DB strings in JS, player_score, live scores, project name, capture-phase key listeners vs chat, end-of-game args crash, sync deletes, never-commit list). All rules kept.
- README.md: games table completed (Red, Hippos, Pent-Up, Vault), deploy / tests / E2E section, pointers to the state-of-record files.
- TESTING_STRATEGY.md: stale Hippos "ready for implementation / hc.zip" line fixed; E2E pointer added.
- LICENSING_TRACKER.md (edit only): Hippos row now "Implemented, in nightly E2E".
- reports/2026-10-19_status.md: marked as a historical snapshot (old slug `redd`).
- reports/e2e_setup.md: "only gardensofenceladus" replaced by a pointer to games.json.
- .gitignore: added `*chat*export*.zip`, `*chats*.zip`, `.env`, `.env.*`.

## Owner decisions / findings (nothing below was deleted)
1. **Secrets in public git history.** `antigravity_chats_export.zip` (45 MB) and `this_chat_export.zip` were committed once (untracked in 323d22d) and are still in history of the PUBLIC repo. The .gitignore says chat exports contain secrets. Decide: rotate any password that may appear in chats (Studio SFTP/web) and optionally purge history (`git filter-repo`, force push; coordinate with all agents).
2. **Contradiction in .gitignore**: it ignores `tools/MIGRATE_CHATS.md`, `export_chats_to_windows.py`, `migrate_antigravity_chats.py`, `relink_project_chats.py`, but they ARE tracked (and referenced by AGENTS.md). Either `git rm --cached` them or drop the ignore lines.
3. **microforge FAIL**: rename the Studio project or change `game_name` (rule 25); also `bgg_id` missing (accepted in known_fails.json). No `games/microforge/metadata.json` exists.
4. **Large tracked files (>2 MB)**: `microforge/print/little_commanders_print*.pdf` (13+7 MB, 21 MB in history), `sugargliders/art/SG_board.ai` (15 MB) and `art/svg/SG_board.svg` (14 MB), `gardensofenceladus/image.png` (8 MB), several 3-5 MB JPG/PNG (gardensofuranus/assets, nestorgamesgp/assets, gardensofmars, gardensofio, microchip_fondo.png, people/PEOPLE.jpg). Consider moving source art out of git (Drive / LFS). Note: sync mirrors whole game folders only under `bga/`, so these do not hit Studio.
5. **Possible duplicates / working material (left in place)**: `games/pushfight/` PDFs twice (root and `prototype/`); `games/people/image*.png`, `People_*.stl`; `games/pentup/design/pentup_tiles.stl`; `Nestor Images/` (11 JPGs, also copied into game folders); `BGA studio License status 5th october 2026.txt` (raw export in repo root); `opencode.json` (model config, root); `docs/` (GitHub Pages redirect to the Little Commanders sandbox).
6. **Root clutter**: consider moving `BGA studio License status ...txt` into `reports/` or `docs`-free location.
7. **tools/ one-offs**: `generate_{omega,yavalath,lordsofscotland}_assets.py`, `fill_gmm.py` (superseded by `bga.py gmm`?), `upload_metadata_images.py`, `check_ports.py`, `audit_metadata.py` look like one-offs overlapping `bga.py assets/gmm`; not removed, confirm which are obsolete.
8. Ignored local files present (fine): `tools/sftp.config.json`, `tools/bga_session.txt`, `tools/e2e/out/`, `.claude/`, `__pycache__` dirs.
9. `reports/metadata_status.md` (403 lines) was not audited line by line; may carry outdated per-game statuses.
