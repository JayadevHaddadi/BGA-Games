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
| gardensofenceladus | Antigravity | 2-5 | yes | 2p and 5p: load, refresh, autoplay, quit pass | 2026-10-19 | Replay was flaky: Studio sometimes has not written the game archive yet ("Unable to find game archive"); the runner retries 3 times |
| people | Antigravity | 2-4 | yes | 2p: load, express start, autoplay (100%), end ranking pass (Table #987374, 0 JS errors) | 2026-10-19 | |
| microchip | Antigravity | 2-5 | yes | 2p: load, express start, autoplay (100%), end screen pass (Table #986374, 0 JS errors) | 2026-10-19 | Fixed state 99 -> 90 EndScore collision |
| redd | Antigravity | 2-3 | yes | 3p: load, express start, autoplay (100%), end scoring pass (Table #989374, 0 JS errors) | 2026-10-19 | Generated full asset set, verified favorite_colors_support |
| hipposandcrocodiles | Antigravity | 2 | yes | 2p: load, express start, autoplay (100%), end screen pass (Table #986774, 0 JS errors) | 2026-10-19 | Full implementation from A-Z; 190 rules checks pass, responsive harness passes |
| all other games | | | no | not run | | add the slug to `tools/e2e/games.json` to enable |

How to update: after reading a CI result, change the row (result, date, one line of notes). If a scenario fails, the
fix belongs to the game's owner; the runner itself is `tools/e2e/studio_play.py` (selectors and texts are at its top).
