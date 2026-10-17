# Board Game Arena (BGA) Studio Modern Development Guide & Cheatsheet

A developer reference documenting critical architecture rules, framework conventions, and gotchas learned while developing games on the modern BGA OOP template.

---

## 1. Project Creation & Naming

| Rule | Rationale |
| :--- | :--- |
| **Always name test projects `<game>test`** (e.g. `pushfighttest` for *Push Fight*) | Keeps namespaces (`Bga\Games\pushfighttest`), CSS classes (`.pushfighttest_*`), and file names 1-to-1 aligned with the final production game ID. |
| **Never use short/generic stems** (e.g. avoid `pushtest`) | Migrating or refactoring `pushtest` $\rightarrow$ `pushfight` is dangerous because generic verbs (`push`, `pass`, `roll`) collide with standard language functions like PHP `array_push` or git commands. |
| **Delete/release unused test project slots** | BGA Studio limits active concurrent projects per developer. Releasing inactive slots keeps your dashboard clean and frees up slots. |

---

## 2. Server-Side State Machine & PHP Gotchas

### A. The Mandatory `zombie` Method
- **The Rule**: Every state class with `type: StateType::ACTIVE_PLAYER` (or `MULTIPLE_ACTIVE_PLAYER`) **MUST** implement a method with the exact signature:
  ```php
  public function zombie(int $playerId)
  ```
- **Gotcha**: If you name it `zombieTurn(int $activePlayerId)` or omit it, game creation will crash with:
  > `Fatal error during <game> setup: A zombie function is needed for state class \Bga\Games\<game>\States\<State>`
- **Standard 2-Player Implementation**:
  ```php
  public function zombie(int $playerId)
  {
      $allPlayers = array_keys($this->game->loadPlayersBasicInfos());
      $winnerId = ($allPlayers[0] === $playerId) ? $allPlayers[1] : $allPlayers[0];
      $this->bga->playerScore->set($winnerId, 1);
      $this->bga->playerScore->set($playerId, 0);
      return EndScore::class;
  }
  ```

### B. Modern Action Registration (`#[PossibleAction]`)
- Actions are methods on the State class tagged with the PHP 8 attribute `#[PossibleAction]`.
- Frontend calls `this.bga.actions.performAction("actMovePiece", { piece_id: 1, target_r: 2, target_c: 3 })`.
- Parameters are automatically mapped by name:
  ```php
  #[PossibleAction]
  public function actMovePiece(int $piece_id, int $target_r, int $target_c, int $activePlayerId, array $args)
  ```

---

## 3. Database Schema & Persistence Across Table Restarts

### The Gotcha
- BGA Studio **retains the same MySQL database instance** for your project across table restarts.
- `CREATE TABLE IF NOT EXISTS` in `dbmodel.sql` will **NOT** modify or add missing columns if the table already existed from a prior test run.
- If you modify columns, queries in `setupNewGame` will fail with:
  > `Unknown column '<column_name>' in 'field list'`

### Best Practice Solutions
1. In `dbmodel.sql`: Always prefix custom tables with `DROP TABLE IF EXISTS`:
   ```sql
   DROP TABLE IF EXISTS `piece`;
   CREATE TABLE IF NOT EXISTS `piece` (
     `piece_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
     `player_id` INT NOT NULL,
     ...
     PRIMARY KEY (`piece_id`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
   ```
2. **Never use `TRUNCATE TABLE` or DDL (`DROP`/`CREATE TABLE`) inside PHP**:
   - BGA's build linter (**HAL**) explicitly rejects `TRUNCATE TABLE`:
     > `truncate table causes performance issue in games and breaks transactions: it has been disallowed. Please use 'delete' instead.`
   - Always use standard DML:
     ```php
     static::DbQuery("DELETE FROM `piece`");
     ```

---

## 4. Client-Side (JS) API Conventions

### `this.bga.players` vs `this.bga.playerPanels`
This is a frequent pitfall in the modern BGA JavaScript framework:

| Object | Correct Purpose | Key Methods |
| :--- | :--- | :--- |
| **`this.bga.players`** | Player session state & active player queries | `isCurrentPlayerActive()`<br>`getActivePlayerId()`<br>`getCurrentPlayerId()` |
| **`this.bga.playerPanels`** | DOM sidebar indicators & custom counters | `getElement(playerId)`<br>`getElementByNo(playerNo)` |

> Never call `this.bga.playerPanels.isCurrentPlayerActive()` or `getActivePlayerId()`. `playerPanels` only manages sidebar UI divs and will throw an `Uncaught TypeError`.

### Defensive Helpers Pattern in `Game.js`
Always add safe helper wrappers to `Game.js` to handle framework version variations:
```javascript
isCurrentPlayerActive() {
    if (this.bga?.players && typeof this.bga.players.isCurrentPlayerActive === 'function') {
        return this.bga.players.isCurrentPlayerActive();
    }
    if (typeof gameui !== 'undefined' && typeof gameui.isCurrentPlayerActive === 'function') {
        return gameui.isCurrentPlayerActive();
    }
    return false;
}

getActivePlayerId() {
    if (this.bga?.players && typeof this.bga.players.getActivePlayerId === 'function') {
        return this.bga.players.getActivePlayerId();
    }
    if (typeof gameui !== 'undefined' && typeof gameui.getActivePlayerId === 'function') {
        return gameui.getActivePlayerId();
    }
    return null;
}
```

---

## 5. Demystifying "Loading game art (10%)" Crashes

When the BGA table preloader stops at:
> **Loading game art (10%)**  
> *Error loading the game! We have been notified.*

It almost **never** means an image is missing. In BGA's client lifecycle, 10% is the phase where:
1. `getAllDatas()` is fetched from PHP via AJAX. (Check browser Network tab for 500 error or PHP exceptions).
2. `setup(gamedatas)` is executed in `Game.js`.
3. The initial game state's `onEnteringState(args, isCurrentPlayerActive)` is called.

**If any uncaught JavaScript `TypeError` or `ReferenceError` occurs during step 2 or 3, the BGA loading lifecycle aborts and shows the 10% error modal.**  
*Fix*: Open DevTools Console (F12) to see the exact JS stack trace.

---

## 6. Development & Deployment Workflow

1. **Local Development**: Keep pure logic in standard files (`modules/php/Game.php`, `modules/php/States/*.php`, `modules/js/Game.js`, `gameinfos.jsonc`).
2. **Fast SFTP Delta Sync**: Use a Python sync script using `paramiko` that inspects file mtimes and sizes. Syncs take only 15–25 seconds over port 2022 instead of full manual re-uploads.
3. **Table Lifecycle on Studio**:
   - Schema changes $\rightarrow$ "Express Start" / "Restart a game" (triggers `dbmodel.sql` & `setupNewGame`).
   - JS / CSS changes $\rightarrow$ Press **F5** in the browser.
   - PHP logic changes during an active turn $\rightarrow$ Press **F5** (PHP reloads per request).

---

## 7. Statistics API (`stats.jsonc`) & Parameter Order Gotcha

In the modern BGA template, `TableStats` and `PlayerStats` have different method signatures:

### `PlayerStats` Parameter Order
```php
public function inc(string $name, int|float $delta, int $player_id, bool $updateTableStat = false): void
public function set(string $name, int|float|bool $value, int $player_id): void
```
- **Gotcha**: The 2nd argument is the **delta / value**, and the 3rd argument is the **`$player_id`**!
- If you accidentally call:
  ```php
  $this->playerStats->inc('moves_number', $activePlayerId, 1); // WRONG!
  ```
  BGA interprets `1` as the target `$player_id` and crashes with:
  > `Unexpected error: incStat: Unknown player id: 1`
- **Correct Usage**:
  ```php
  $this->playerStats->inc('moves_number', 1, $activePlayerId); // CORRECT!
  ```

### `TableStats` Parameter Order
```php
public function inc(string $name, int|float $delta = 1): void
public function set(string $name, int|float|bool $value): void
```

---

## 8. Studio "Spectate" Limitation (`masterNodeRequest` Crash)

- **The Error**:
  > `Wrong formatted data from main BGA website: (reference: GS1 ...)`  
  > `ModuleDbObject->masterNodeRequest('...', Array)`  
  > `Bga\GameFramework\GamePageView->generate_content(Array)`
- **The Cause**: Clicking "Spectate" on a Studio test table causes the gameserver (`GS1`) to query the central BGA master node (`tournoi.boardgamearena.com`) to look up external spectator authorization. Because test tables are sandboxed locally to the development gameserver, the master node returns empty/malformed data.
- **The Fix**: Do not use "Spectate" on Studio. Switch between player views using the top red Studio bar ("Play as [Player Name]") or open an Incognito window logged into the 2nd test account.

---

## 9. Game Box, Banner & Lobby Media: Game Metadata Manager

- **The Common Misconception**: Dropping `game_box.png` or `game_icon.png` into the `img/` folder does **not** make it appear in the BGA game list, lobby, or table preloader.
- **The Modern Standard**: Game branding media is managed through the **Game Metadata Manager**:
  - Studio URL: `https://studio.boardgamearena.com/controlpanelgames`
  - Production URL: `https://boardgamearena.com/controlpanelgames`
- **Required Asset Specifications**:
  - **Box**: `280x280` PNG with **transparent background** (3D view of box/board).
  - **Icon**: `50x50` PNG, **no border** (sharp distinctive game element).
  - **Banner**: `1920x556` JPG (panoramic header art).
  - **Publisher**: `280x280` PNG with transparent background.
- **Important `img/` Directory Rule**:
  Every image placed in `img/` is automatically preloaded into the browser when loading a table. Do not put non-interface media in `img/` or it will slow down table initialization.

---

## 10. Official BGA UX/UI Guidelines & Button Color Standards (Public Alpha Checklist)

Reference: [BGA Official UX/UI Guidelines](https://bga.li/mRdx)

### A. Action Bar Button Colors (Section C 3)
BGA strictly enforces a platform-wide "traffic light" button color convention:

| Role | Meaning | BGA Parameter | Notes / Examples |
| :--- | :--- | :--- | :--- |
| **Blue** | Forward-moving action, confirmation, progression | `{ color: 'primary' }` | `Confirm Placement`, `Play Card`, `End Turn` |
| **Red** | Stopping, negative, pass, skip, undo, cancel, clear | `{ color: 'alert' }` | `Pass`, `Skip to Push`, `Undo Moves`, `Clear All` |
| **White** | Optional, side choices, non-critical actions | `{ color: 'secondary' }` | `Standard Preset`, inactive toggle options |
| **Grey** | Disabled / unavailable in current context | `disabled: true` | Works for all 3 colors |

> ⚠️ **Framework Gotcha**: The modern BGA framework parameter for red buttons is **`'alert'`**, NOT `'danger'` or `'red'`. Passing `'danger'` will silently fail to apply red styling and fall back to default white/secondary.

### B. Button Ordering & Limits (Section A 2)
1. **Center**: Primary / forward-moving awaited action.
2. **Adjacent**: Secondary choices / presets / toggles.
3. **Far Right**: Negative / undo / pass / cancel / clear actions.
4. **Max 4 Buttons**: Never place more than 4 buttons on the Action Bar at any time to prevent wrapping / overflow on mobile screens.

### C. Touch Targets & Minimum Sizes (Section E 3)
- All interactive controls (buttons, tokens, clickable cells, directional arrows) must have a touch target of at least **`32px × 32px`** (ideal `40–44px` on mobile).

### D. Mobile Responsive Board Scaling (Section C 4)
1. **Interface Min Width**: In `gameinfos.jsonc`, configure `"game_interface_width": { "min": 320 }` so BGA doesn't force a zoomed-out 740px container on narrow mobile viewports.
2. **Board Scaler Pattern**: Avoid static `@media (max-width: ...)` rules with `transform: scale(...)` on the board wrapper; they multiply on top of BGA's viewport zoom and cause **double-scaling** (shrinking boards to < 40% screen width). Instead, use a dynamic scaler container with JavaScript (`ResizeObserver` + window `resize`) to scale the board to fill ~98% of the available screen width on mobile portrait.

---

## 11. Transitioning to Private Alpha: Game Metadata Manager (GMM) Requirements

When clicking **"Request PRIVATE ALPHA status"** on BGA Studio (`https://studio.boardgamearena.com/manage?game=<game>`), the system enforces two hard database requirements before status can change:

### A. The Two Mandatory GMM Fields (Hard Blockers)
1. **`description`**: A text description of the game in English.
2. **`zombieModeLevel`**: The zombie mode level selection.

> 💡 **How to solve**: Open the **Game Metadata Manager (GMM)** web interface via:
> `https://studio.boardgamearena.com/gamemetadatamanager?game=<game>`
> - Fill in the **Description** box.
> - Select **Zombie Mode Level** = `Level 1` (Turn-based / complete support handled by game engine).
> - Set game characteristics (Complexity, Luck, Strategy, Diplomacy) and tags.
> - Click **Save**, then re-request Private Alpha status.

### B. Warnings You Can Safely Ignore
- **"There is no registered licence linked to the BGG id"**: Normal for Private Alpha development. The publisher signs the digital agreement on `boardgamearena.com/gamepublishers` before public Beta.
- **"The game has 1 tags set..."**: Informational message.

### C. Clean Codebase Deployment Checklist (Linter & Strict PHP Rules)
- **Never use `gameui.ajaxcall`**: Use `this.bga.actions.performAction(...)`.
- **Never access `this.scoreCtrl`**: Use `this.bga.playerPanels.getScoreCounter(pId).toValue(...)`.
- **Never access `document.getElementById('player_board_${pId}')`**: Use `this.bga.playerPanels.getElement(pId)`.
- **Always audit notification payload variables**: On BGA production (PHP 8 strict mode), referencing an unassigned variable (e.g. `'turn_args' => $turnArgs` where `$turnArgs = $this->getArgs();` was omitted) throws a fatal `ErrorException`, immediately failing the player action. Always verify every variable in `$this->notify->all(...)` before release.

---

## 12. Reviewer UX/UI Feedback (BGA reviewer Ian) — Quick Reference

Full checklist: see **AGENTS.md §4b**. Summary of what gets flagged on submissions:

1. **Generic / AI-looking UI** (C.3): no pill badges, emoji in labels, glossy shine, looping glow/pulse animations, card-style rounded boards. Match the physical game.
2. **Mobile & tap targets** (C.4, E.3): board must fill the width; interactive cells ≥ 32px at 375px (measure it). Use stage + Confirm/Undo.
3. **Sounds** (D.4): real files in `sounds/` (`.ogg` + `.mp3`) played via `this.bga.sounds.play('name')`; never on hover or on staging a move. Generate with Python `wave` + `ffmpeg -c:a libvorbis` / `libmp3lame` if you have no assets.
4. **Theme/background** (C.5): themed table/background around a board that looks like the real one.
5. **No credits in the play area**: put them in `gameinfos.jsonc` and the GMM.
6. **No duplicate action buttons** inside the play area; use the action bar only.


---

## 13. New game in one pass: `tools/bga.py` (use this for every game)

```bash
python3 tools/bga.py new <slug> --name "Gardens of X" --bgg <id> --publisher nestorgames --publisher-bgg 9976 --players 2 5
python3 tools/bga.py assets <slug>     # games/<slug>/design/make_assets.py (art + metadata images)
python3 tools/bga.py check <slug>      # the pre-push and pre-review gate: no FAIL lines
python3 tools/bga.py gmm <slug>        # games/<slug>/GMM_FILLIN.md from metadata.json
python3 tools/bga.py sync <slug>       # fast-lane upload to the Studio project (needs tools/sftp.config.json)
python3 tools/bga.py register <slug>   # re-add the sync target and deploy block if they went missing (idempotent)
```

`new` writes the BGA files, `metadata.json`, a `design/make_assets.py` and the wiring in `tools/sync.py` and `.github/workflows/bga-deploy.yml` (both have `<bga:targets>` / `<bga:options>` / `<bga:deploy>` markers; `register` inserts above them).

### Checklist for a new game (what `check` enforces, and what you still do by hand)

1. **Read** the rulebook and the other games' READMEs. Write the rules decisions in `games/<slug>/PLAN.md` (rule, decision, confidence). Every "CHECK" in the rulebook is a decision to note.
2. **Rules as pure PHP** in `modules/php/Rules.php` (no framework calls). Test it from the command line: `tests/<slug>_test.php` (the `check` step runs every `tests/*_test.php`).
3. **Server**: `Game.php` (setup, `getAllDatas`, helpers), one state class per phase, `zombie()` in every active state, `dbmodel.sql` with `DROP TABLE IF EXISTS` before each table. `gameoptions.jsonc` / `gamepreferences.jsonc` ids 100 to 199.
4. **Client**: `modules/js/Game.js` with `setupPromiseNotifications`, one `notif_<name>` per server notification, tap-to-stage with Confirm and Undo. Sounds only from confirmed events (`sfx(id)` = `this.bga.sounds.play`), with `.ogg` and `.mp3` in `sounds/`.
5. **Layout** (see section 14): full-width root on phones, cell size from the width, 32 px tap targets at 375 px, no horizontal scroll.
6. **Art**: `design/make_assets.py` crops the board and sprites into `bga/img/` (small, BGA preloads it) and calls `tools/bga_assets.build_metadata(...)` for box, icon, banners, title, publisher and displays.
7. **Browser harness**: copy `games/gardensofenceladus/tests/harness/` (mock `bga`, `frame.html` for phone widths). Check centring (0 px offset), tap sizes and the main flows.
8. **Wiki**: `bga/GAMEHELP.wiki` in MediaWiki markup. Interpretations go in the wiki's notes section.
9. **Metadata**: fill `metadata.json` (description, designers, tags, licence). Mark proposals as PROPOSAL. Then `gmm` for the Control Panel sheet. Add a row to `LICENSING_TRACKER.md` and mark the licence status honestly.
10. `python3 tools/bga.py check <slug>` until it has 0 FAIL. Then commit and push to `main` (AGENTS.md section 1).

---

## 14. Gotchas found while building Gardens of Enceladus

| Topic | What happened | Rule |
| :--- | :--- | :--- |
| **Framework `final` methods** | A game-level `activeNextPlayer()` clashed with the framework's `final` method (fatal at load). | Look up names in the game's `_ide_helper.php` before adding a method. It lists the server API with signatures. `bga-framework.d.ts` is the **client** API only. |
| **Board as strings** | Joining colour names into one string (`'blue' . 'red'`) makes the client read it back wrong. | Send 2-D arrays of codes to the client. |
| **Scores with counts of 10 or more** | A 5-digit "sorted counts" score is ambiguous once a count reaches 10. | Compare the sorted tuple. Store the BGA score as the tuple read in base N (N above the largest count), so the ranking is exact. |
| **By-reference PHP calls** | Passing a literal array to a function with `array &$x` is a fatal error in tests. | Assign to a variable first. |
| **Turn-lock with two Martians** | The lock must be checked for every action that uses a Martian, not only for moves. | Check the lock in the action and in `usableMartianIndexes()`. |
| **Phone width** | A 375 px phone has about 320 px of game width after BGA's padding. A 10 x 10 board plus side squares then gives 28 px cells, below the 32 px target. | Widen the root to the viewport (see `fitContainerToScreen` in Yavalath) and size cells as `width / (10 + 2 x side)`. Side squares of 0.7 cell give 32 px at 375 px. Measure, don't guess. |
| **Headless screenshots** | Headless Chrome will not go narrower than about 500 px, so a 375 px test is not a 375 px test. | Load the page in a 375 px `<iframe>` (`tests/harness/frame.html`) and read the result from the parent. |
| **Stat names** | `tableStats`/`playerStats` calls with names missing from `stats.jsonc` only fail at runtime. | `check` compares the names used in PHP with `stats.jsonc`. |
| **Option ids** | Values inside an option (`"1"`, `"2"`) look like ids to a naive grep. | Read the top-level keys of the JSONC (`check` does this). |
| **Metadata image sizes conflict** | `AGENTS.md` 5.D asks for banner 1386x400; `BGA_METADATA_ASSETS_SPEC.md` asks for 1920x556. | Produce both (`tools/bga_assets.py` does). Keep the Control Panel upload consistent with whichever BGA shows. |
| **Publisher logos** | The nestorgames logo file is a white emblem on black. Used as-is it became a black square. | `tools/bga_assets.publisher_logo` turns brightness into alpha and recolours it. Look at every logo before using it. |
| **Scanned sources** | The board scan has a logo, title and icon strip. Only the clean grid (`image.png`) and the icon strip are usable as art. | Crop from the clean source. Keep the scan's text out of `img/` and out of banners (BGA overlays the title). |
