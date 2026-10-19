# Studio E2E tests: setup status (2026-10-19)

**Status: built, self-test passes, NOT yet run against Studio.** The runner could not log in (no web password on this machine), so every Studio page-structure guess is unverified.

## What exists
- `tools/e2e/studio_play.py`: Playwright runner. Logs in, opens a table with Express start, then runs scenarios: `load`, `refresh` (reload mid-game, state must not change), `autoplay` (Studio debug menu, `debug_playToEnd`, wait for the end screen), `zombie` (a player quits, game must still finish), `replay`. Catches JS console errors, page errors, BGA error text (Unexpected error, Fatal error, Wrong formatted data, ...), a stuck game, and writes `report.json`, `report.md`, numbered screenshots and DOM dumps into `tools/e2e/out/<game>-<n>p/`.
  - `--probe`: logs in, opens a table, dumps the page structure. Use this first.
  - `--selftest`: checks the runner itself without logging in (passes).
- `debug_playToEnd()` in `games/gardensofenceladus/bga/modules/php/Game.php` (pilot game). It uses the framework's `$this->debug->playUntil(...)`, which drives the game with each active player's `zombie()`. **Limitation:** this tests the server state machine, zombie handling, end scoring and replay, not the client UI. Real clicks are not generic across games; a per-game "play a few real moves" step is the next addition.
- `.github/workflows/e2e.yml`: runs after a successful "BGA Studio Deployer" for the games the push changed (diff of `HEAD~1..HEAD`; the nightly run covers the rest), nightly at 02:00 UTC for all games in `tools/e2e/games.json`, or by hand. One table at a time (shared test accounts). 2 players and max players per game. Uploads screenshots and reports as an artifact. On failure it opens or updates one issue per game labelled `e2e-failure` (step, error text, run link). Without the secret it logs a warning and skips, so nothing fails or spams until you add it.
- `tools/e2e/games.json`: games enabled for E2E (only `gardensofenceladus` so far).

## What the user must do
1. **Add the repo secret `BGA_STUDIO_PASSWORD`** (Settings > Secrets and variables > Actions). Optional: `BGA_STUDIO_USERNAME` (default `JayadevHaddadi0`).
2. **Decision:** may I try the SFTP password (`tools/sftp.config.json`) as the web login? I did not, because it was given for SFTP only. Otherwise put the web password in git-ignored `tools/bga_web.json` as `{"username": "JayadevHaddadi0", "password": "..."}`.
3. After that, run `python3 tools/e2e/studio_play.py gardensofenceladus --probe` once. Fix the guesses at the top of `studio_play.py` (`SELECTORS`, `*_TEXT`): login form, Express start button, player-count control, debug menu, Quit game, replay link.

## Risks
- GitHub's IP addresses may hit a BGA captcha or block at login. If so, run the same script from the Linux machine on a timer instead.
- Studio runs may take several minutes per game (autoplay timeout is 7 minutes).
- Express start's player-count control is a guess; if it is wrong the table uses the default count (the report notes it).

## Next
- Verify the live flow on Enceladus, then add `debug_playToEnd` to the other games (copy the 3-line method) and list them in `tools/e2e/games.json`.
- Add a per-game "real moves" step through each game's harness selectors.
