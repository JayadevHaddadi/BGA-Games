# Live Studio E2E: status (read before testing, update after a run)

Shared by every agent and the user. CI runs `tools/e2e/studio_play.py` against real Studio tables after each deploy
and nightly (02:00 UTC) for the games enabled in `tools/e2e/games.json`. Results: Actions > Studio E2E (job summary,
artifact `e2e-<run id>` with screenshots) and GitHub issues labelled `e2e-failure` (one per game, updated on each failure).
Run by hand: Actions > Studio E2E > Run workflow (game slug or `all`; `probe` only dumps the page structure).
Locally: needs env `BGA_STUDIO_PASSWORD` (never a file, never a chat).

Scenarios per game at 2 players and at the maximum: **load** (table opens, no error text, no JS error),
**refresh** (reload mid-game, same state), **autoplay** (Studio's zombie-play button to the end of the game),
**replay** (Replay game from the end screen), **zombie** (a player quits, own table).
Not covered: real clicks, look and feel, phone layout, rules correctness. Those are the offline harness and a human.

| Game | Owner | Players | In CI | Last live result | Date | Notes |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| gardensofenceladus | Antigravity | 2-5 | yes | PASS 2p, 5p (replay skipped: no Studio archive) | 2026-10-19 | |
| people | Antigravity | 2-4 | yes | PASS 2p (all), 4p (replay skipped) | 2026-10-19 | |
| microchip | Antigravity | 2-5 | yes | PASS 2p, 5p, all scenarios | 2026-10-19 | |
| red | Antigravity | 2-3 | yes | PASS 3p (all), 2p (replay skipped) | 2026-10-19 | |
| hipposcrocodiles | Antigravity | 2 | yes | PASS 2p, all scenarios incl. replay | 2026-10-19 | replay load error fixed by Antigravity |
| gardensofio | | 2-5 | yes | PASS 2p, 5p, all scenarios | 2026-10-21 | lap-bonus crash fixed (3f565ed); debug_playToEnd added |
| pentup | Claude | 2 | yes | PASS 2p (replay skipped: no Studio archive) | 2026-10-23 | new game, 3D client |
| vaultnestorgames | Antigravity | 2 | yes | Pending first live run | 2026-10-24 | Initial implementation complete, 0 FAIL check, harness pass |
| feedtheducks | Claude | 2-4 | no (paused) | Not run: Studio project not created yet | 2026-10-27 | new game; move the slug from `paused` to `enabled` in games.json once the project exists |
| all other games | | | no | not run | | add the slug to `tools/e2e/games.json` to enable |

How to update: after reading a CI result, change the row (result, date, one line of notes). If a scenario fails, the
fix belongs to the game's owner; the runner itself is `tools/e2e/studio_play.py` (selectors and texts are at its top).
