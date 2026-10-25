# BGA Studio games: agent instructions

The entry point for every agent in this repository. Read it fully. Then read, on demand:

| File | When |
| :--- | :--- |
| [BGA_REFERENCE.md](BGA_REFERENCE.md) | Writing code: framework API, preferred patterns with snippets, Studio gotchas, metadata image specs |
| [BGA_REVIEWER_FEEDBACK.md](BGA_REVIEWER_FEEDBACK.md) | Before any UI work and before any review request: the reviewer's checklist |
| [LICENSING_TRACKER.md](LICENSING_TRACKER.md) | Before starting a game and before a review request: licence status and contacts |
| `games/<slug>/PLAN.md`, `NOTES.md` | Working on that game: its rules decisions and owner decisions |

## 0. How to report to the user

Short and action-oriented. No essays, no design discussion, no code walkthroughs. Every report has three parts:
1. **Changed**: short bullets, so the user knows what to test.
2. **Decisions for the user**: in **bold**.
3. **Pending**: what is blocked or waiting.

## 1. Implement a game from A to Z

"Implement this game completely" means all of the phases below, in order, without stopping to ask, except for the
stop conditions in phase 0. Collect rule questions in `PLAN.md` and report them at the end; do not block on them.

**Inputs the user provides**: the rulebook (PDF or images) and art in `games/<slug>/`, and a Studio project `<slug>`
(created on studio.boardgamearena.com). Everything else is the agent's job.

| Phase | Do | Done when |
| :--- | :--- | :--- |
| 0. Inputs | Read the rulebook completely (`pdftotext -layout`, or look at the page images). Find the BGG id. Check `LICENSING_TRACKER.md`. | **Stop and ask** if the rulebook cannot be read, or no Studio project exists. A missing licence row is not a stop: add the row as NOT CONFIRMED. |
| 1. Scaffold | `python3 tools/bga.py new <slug> --name "..." --bgg <id> --publisher "..." --publisher-bgg <id> --players <min> <max>` | Folder, `metadata.json`, sync target and workflow option exist. |
| 2. Rules plan | `games/<slug>/PLAN.md`: rules as implemented, a decision table (topic, decision, confidence) for every point the rulebook leaves open, the end-game trigger and **every component that scores at game end**. | Every "open" point has a decision. No invented tie-breakers. |
| 3. Pure rules | `bga/modules/php/Rules.php` (no framework calls) + `tests/rules_test.php` (CLI checks for every action, every edge case, every rulebook example, and the geometry drawn as concrete squares). | `php tests/rules_test.php` passes. |
| 4. Server | `Game.php` (setup, `getAllDatas`, helpers), one class per state in `States/`, `dbmodel.sql`, `stats.jsonc`, options and preferences. Follow section 3 and [BGA_REFERENCE.md](BGA_REFERENCE.md) "Server patterns". | Every action validates its input; every active state has `zombie()`; progression is never stuck at 0. |
| 5. Client | `modules/js/Game.js` + `<slug>.css`. Tap-to-stage with Confirm/Undo, highlights of legal targets from the state args, one `notif_<name>` per notification that changes the board, log lines that say who did what, an end-of-game scoring breakdown. | Works for the active player, the other players and a spectator. |
| 6. Look and feel | The game's own art and palette (crop it in `design/make_assets.py`), a themed table background on the whole page, colour-blind symbols (pref 100), sounds from confirmed events only (pref 101). Read [BGA_REVIEWER_FEEDBACK.md](BGA_REVIEWER_FEEDBACK.md) first. | The checklist in that file passes. |
| 7. Browser harness | Copy `games/gardensofenceladus/tests/harness/` (mock `bga`, `frame.html` for true phone widths). Script the main flows, the refusals and the layout measurements. Run headless Chrome at 375 and 1280 px. | 0 JS errors, every tap target >= 32 px at 375 px, board centre offset 0 px, no horizontal scroll, every flow passes. |
| 8. Wiki and metadata | `bga/GAMEHELP.wiki` (MediaWiki markup, not Markdown). `metadata.json` (description, zombieModeLevel=1, >=2 tags, sliders 1-5, designers), `python3 tools/bga.py assets <slug>` and `gmm <slug>`. Uploading metadata images to Studio is not needed (check `Ignore` on Studio builds; images are uploaded once when deploying to production, then synced). All image assets MUST be generated and ready in `bga/metadata_assets/`. | `GMM_FILLIN.md` exists; all metadata images exist in `bga/metadata_assets/` at the right sizes. |
| 9. Gate | `python3 tools/bga.py check <slug>` | 0 FAIL. Every WARN is fixed or explained in the report. |
| 10. Ship | Commit, `git pull --rebase origin main`, push to `main`. The push deploys every changed game to Studio. | The Actions run is green, or `python tools/sync.py <slug>` was run. |
| 11. Report | Section 0 format. List what was verified (tests, harness numbers, check result), the open rule decisions from `PLAN.md`, and what still needs a live Studio table. | |

Live-table tests are automated by `tools/e2e/studio_play.py` (setup: `reports/e2e_setup.md`). **Read and update `reports/e2e_status.md`** (per-game results, shared with the other agents). A game is tested nightly once its slug is in `tools/e2e/games.json`; no per-game code is needed (Studio's own debug panel plays to the end).

**Game page display images must be photos of the real physical game** (2 to 10), never screenshots, templates or generated art. Rule, sources and per-game audit: `reports/display_images.md`.

A live Studio table is the only test of the PHP inside the framework. Nothing here runs it, so say so in the report.
After the first deploy, the user (or an agent with a browser) plays one game at 2 players and at the maximum player
count: refresh mid-turn, let a player quit (zombie), finish the game, open the replay.

Reference implementations: **Gardens of Enceladus** (rules + tests + harness + tooling), **People** (staging that keeps
the choice until the server accepts, sound guard, colour-blind marks), **Kiln** (tooltips, preferences, seat-relative
board), **Yavalath** (`fitContainerToScreen`).

## 2. Repository and Studio

* Remote: `https://github.com/JayadevHaddadi/BGA-Games`. The local folder is `Mandala-helper` (`d:\GitHub\Mandala-helper`
  on Windows, `/home/jayadevhaddadi/GitHub/Mandala-helper` on Linux). **Do not rename it**: agent chat histories are bound to its path.
* Each game lives in `games/<slug>/`; the BGA project files are in `games/<slug>/bga/` (`bga-prod/` for Mandala).
  `TARGET_MAP` in `tools/sync.py` maps folders to Studio projects and is the list of games.
* **Always commit and push straight to `main`** (no feature branches, no PRs) unless the user says otherwise.
  Run `git pull --rebase origin main` before pushing: other sessions push to the same branch.
* One session per game, and several sessions share the Linux checkout. Commit your own changes to shared files
  (`tools/sync.py`, the workflow, root docs) right away: an uncommitted edit there blocks every other session's pull.
  Never revert or stash another session's uncommitted work without the user's say.
* **Deploy**: a push to `main` runs `.github/workflows/bga-deploy.yml`, which syncs every game folder changed anywhere
  in the pushed commits (`tools/sync.py --changed`). Manual run: Actions → BGA Studio Deployer → pick a game or `all`.
  Fast lane: `python tools/sync.py <slug>` (seconds).
* **Sync mirrors**: files missing locally are **deleted** on Studio (except `_ide_helper.php`, `bga-framework.d.ts`,
  `misc/`, `LICENCE_BGA`). Mandala is upload-only (`NO_PRUNE`): its Studio copy is shared with other developers, so
  **download the Studio copy and diff it against the repo before editing Mandala**.
* **Studio access** is plain SFTP, no browser: host `1.studio.boardgamearena.com`, port `2022`, user `JayadevHaddadi`.
  The password is in `tools/sftp.config.json` (git-ignored) or env `BGA_SFTP_PASSWORD` (GitHub secret). Never write it
  into a tracked file; ask the user if it is missing. The SFTP root has one folder per project (`*test` = sandbox).
  `_ide_helper.php` (server API) and `bga-framework.d.ts` (client API) are generated by BGA and read-only.
* Studio projects not in the repo yet: `gocaine` (has code, licence granted 2022), `blitzkrieg` (legacy template).
  Pull a project into `games/<slug>/bga/` and register it (`python3 tools/bga.py register <slug>`) before working on it.
  Studio project name vs. `game_name` (rule 25) is the usual reason a new project cannot be registered: create the
  project under the right name instead of renaming later.
* **Tools** (all in `tools/`):
  * `bga.py` with subcommands `new`, `register`, `assets`, `check` (slug or `all`), `gmm`, `sync`.
  * `sync.py <slug> [--dry-run] | all | --changed BASE HEAD`: SFTP deploy (the CI entry point).
  * `test_all.py [slug]`: every offline test (check + rules tests + browser harness at 375/1280 px). CI runs it on each
    push (`.github/workflows/tests.yml`). Accepted FAILs: `tools/known_fails.json`.
  * `e2e/studio_play.py` + `e2e/games.json`: live Studio table tests (`.github/workflows/e2e.yml`, see
    `reports/e2e_setup.md`, `reports/e2e_status.md`). `studio_table.py`: helper used by it.
  * `bga_assets.py`, `fill_gmm.py`, `upload_metadata_images.py`, `audit_metadata.py`, `generate_*_assets.py`,
    `synth_sounds.py`, `check_ports.py`: one-off asset and metadata helpers (status: `reports/metadata_status.md`).
  * `MIGRATE_CHATS.md`, `export_chats_to_windows.py`, `migrate_antigravity_chats.py`, `relink_project_chats.py`:
    moving agent chat histories between machines. Chat exports hold secrets and must never be committed.

## 3. Hard rules (each one cost us a failed build, a crash or a rejected review)

`python3 tools/bga.py check <slug>` enforces the ones marked ✓.

**Server (PHP 8, strict)**
1. `setupNewGame` inserts the players (colours from `gameinfos.player_colors`, then `reattributeColorsBasedOnPreferences`),
   calls `reloadPlayersBasicInfos()`, inits every stat it uses, activates the first player and returns the first state class.
2. ✓ Every `ACTIVE_PLAYER` / `MULTIPLE_ACTIVE_PLAYER` state has `public function zombie(int $playerId)`. Its return
   value is a redirect: in a multi-active state call `setPlayerNonMultiactive()` and return `null`.
3. ✓ Never redefine a framework `final` method in `Game.php` (`incStat`, `activeNextPlayer`, `getStat`, ...): fatal at
   load, invisible to `php -l`. Look names up in `_ide_helper.php`. (Microchip `incStat`, Enceladus `activeNextPlayer`.)
4. `PlayerStats::inc($name, $delta, $playerId)`: the value is the 2nd argument, the player the 3rd.
5. ✓ Every stat used in code exists in `stats.jsonc` for the right side (table or player). (Gardens of Io lap bonus.)
6. ✓ `dbmodel.sql`: `DROP TABLE IF EXISTS` before each `CREATE`; never a comment on a column line (BGA drops the line).
   In PHP use `DELETE FROM`, never `TRUNCATE` or other DDL. Handle schema changes in `upgradeTableDb()`.
7. ✓ Never write `player_score` / `player_score_aux` in SQL; use the `playerScore` / `playerScoreAux` counters.
8. PHP 8 turns an undefined variable into a fatal error: check every variable passed to a notification. `php -l` does not.
9. Per-turn setup that `onEnteringState` does must be idempotent and also called from `getArgs`, every action and
   `zombie()`: `getArgs()` can run first.
10. ✓ Translatable text: `clienttranslate('... ${arg} ...')` with arguments (recursive `['log' => ..., 'args' => ...]`
    for nested phrases), never `clienttranslate('a') . $b`. Every `UserException` has a translated reason.
11. Never invent rules or tie-breakers. Equal scores share the win unless the rulebook says otherwise. Final scoring
    collects everything still in play (Sugar Gliders lost a tile this way).

**Client (modern framework, `modules/js/Game.js`)**

12. ✓ `this.bga.notifications.setupPromiseNotifications({})` in `setup()`, and a `notif_<name>(args)` for every
    notification that changes the board. Without it the board only updates after F5.
13. ✓ Every `performAction('actX')` matches a `#[PossibleAction] function actX` of the state active at that moment
    ("This move is not authorized now"). Keep the staged choice and the buttons until the state changes: a refused
    action must leave a working Confirm.
14. ✓ Modern API only: `this.bga.actions.performAction`, `this.bga.playerPanels.getScoreCounter(id)` /
    `getElement(id)`, `this.bga.players.isCurrentPlayerActive()`. Never `ajaxcall`, `scoreCtrl`, `player_board_<id>`,
    `playerPanels.isCurrentPlayerActive`.
15. ✓ Sounds: files in the top-level `sounds/` (`.ogg` + `.mp3`), played with `this.bga.sounds.play(id)` from a helper
    that is **not** named `playSound` (the build flags the name). Only for confirmed events (notifications), never on
    hover or staging, silent in replays, off with preference 101. No Web Audio synths.
16. ✓ Buttons: blue `'primary'` = go, red `'alert'` = undo, pass, cancel (never `'danger'`), white `'secondary'` = side
    choice. At most 4, undo/cancel on the far right, one copy of each action (none inside the play area).

**Assets, layout and look** (details: [BGA_REVIEWER_FEEDBACK.md](BGA_REVIEWER_FEEDBACK.md))

17. ✓ `img/` holds gameplay art only (BGA preloads it, keep it small); box, banner, icon, title and displays go in
    `metadata_assets/`. `bgg_id` is set in `gameinfos.jsonc` (the Control Panel cannot edit it). Metadata images do
    not need to be uploaded to Studio (tick `Ignore` on Studio builds; images are uploaded once to production GMM,
    then synced); but all image files must always exist locally in `metadata_assets/`. The rest of GMM (description,
    zombieModeLevel=1, >=2 tags, characteristics sliders) must always be set in `metadata.json` / `GMM_FILLIN.md`.
18. ✓ `gameinfos.jsonc`: `"game_interface_width": { "min": 320 }`; no complexity/luck/strategy/diplomacy keys
    (Control Panel only). Option and preference ids 100 to 199.
19. ✓ No drop shadows, glow, looping animation, `transition: all`, emoji, pill badges, corners over 3 px, themed fonts
    or credits in the play area. `:hover` only inside `@media (hover: hover)`.
20. The board takes the full phone width (measured, not guessed), tap targets >= 32 px at 375 px, centred to 0 px,
    themed background on `body`. Geometry rules are drawn as concrete squares in a test before coding.
21. ✓ How-to-play text is MediaWiki markup in `bga/GAMEHELP.wiki`. No Markdown rules files.

**Process**

22. Feedback from the reviewer on one game applies to every game. Never request a review "to see": run the checklist
    in [BGA_REVIEWER_FEEDBACK.md](BGA_REVIEWER_FEEDBACK.md) first and list, per point, what changed.
23. Before a push: `python3 tools/bga.py check <slug>` (0 FAIL), rules tests, harness. Parse changed CSS (one stray
    brace fails BGA's build).
24. Game-specific owner decisions live in `games/<slug>/NOTES.md` or `PLAN.md`, not in this file.
25. The Studio project name must equal the game name lowercased with every non-alphanumeric character removed ("&" is simply dropped, e.g. Hippos & Crocodiles -> `hipposcrocodiles`). `bga.py check` FAILs otherwise.

## 4. Known pitfalls (quick list; each one has bitten us)

* **PossibleAction namespace**: each state class needs `use Bga\GameFramework\States\PossibleAction;` (as in every
  working game). A missing or wrong `use` gives "This move is not authorized now" with no other hint.
* **`statusBar.addActionButton` signature**: `this.bga.statusBar.addActionButton(label, handler, { color, disabled, id, tooltip })`
  with `color` one of `'primary'`, `'alert'`, `'secondary'`. The old positional `(label, id, handler, null, false, 'blue')`
  form of the legacy API does not apply.
* **DB strings in JS**: values from the database arrive as strings (`"3"`, `"0"`). Cast with `Number()` before comparing
  or adding; `"0"` is truthy-looking but `=== 0` fails.
* **`player_score` text**: the score is shown by the framework from the `player_score` column. Do not write it with
  SQL (rule 7); a custom text (ordering keys, hands) belongs in a separate panel element, not in the score counter.
* **Live scores**: update the score counters from notifications during the game (progression too), not only at the end,
  or the panel shows 0 until F5.
* **Studio project name** = game name lowercased with non-alphanumerics removed (rule 25), or `bga.py check` FAILs and
  Studio rejects the sync target.
* **Capture-phase key listeners vs BGA chat**: a `keydown` listener on `document` (capture) steals typing in the chat
  box. Ignore events whose target is an `input`, `textarea` or `[contenteditable]`.
* **End-of-game args crash**: `getArgs()` / `getAllDatas()` can run after the game has ended (final state, replay,
  spectator). Guard against missing rows and null current player; test the finished state in the harness.
* **Sync deletes**: the folder is mirrored (rule in section 2); a file missing locally disappears on Studio.
* **Never commit**: `tools/sftp.config.json`, `tools/bga_session.txt`, chat exports (`*chats_export.zip`), anything with
  a password. `.gitignore` covers them; check `git status` before `git add -A`.
