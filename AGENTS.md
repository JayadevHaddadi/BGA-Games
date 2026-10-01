# Board Game Arena (BGA) Studio — Master Agent Guidelines & Gotchas

This file is the single source of truth for all AI agents working in this repository across all games. Follow these workflows, best practices, and rules strictly.

---

## ⚡ Agent Communication Standard: Ultra-Concise & Action-Oriented

The user wants clear, minimal updates. **DO NOT write long essays, internal design discussions, or verbose code explanations.**
Every response must follow this strict structure:
1. **What was fixed/changed**: Short bullet points so the user knows what to test.
2. **Action items or decisions for the user**: Highlighted clearly in **bold** or callout boxes.
3. **What is pending**: Brief list (if anything is blocked or waiting).

---

## 🚀 1. Deployment Workflow: Commit & Push

* **Repository Identity**:
  * **Remote Repository**: `https://github.com/JayadevHaddadi/BGA-Games`
  * **Local Directory Path**: Keep as `Mandala-helper` (`/home/jayadevhaddadi/GitHub/Mandala-helper` or `d:\GitHub\Mandala-helper`). **Do NOT rename the local folder**, so that all active Antigravity conversation IDs, SQLite indices, and workspace URI bindings stay synchronized.

Every change should be committed and pushed to `main`. The GitHub Actions deployer (`.github/workflows/bga-deploy.yml`) will automatically detect modified game directories and deploy them to BGA Studio over SFTP.

* **Commit & Push**:
  ```bash
  git add .
  git commit -m "feat(<game>): description of change"
  git push origin main
  ```
* **Instant Local Fast-Lane (Optional)**: If you need instant 5-second delta-sync to Studio without waiting for the GitHub Actions runner:
  ```bash
  python tools/sync.py <target>
  ```

---

## 🎮 2. Game Target Mapping Reference

| Game | Local Directory | Remote BGA Studio Slot | Fast-Lane Sync Command |
| :--- | :--- | :--- | :--- |
| **Omega** | `games/omega/bga/` | `omega` (prod) / `omegatest` | `python tools/sync.py omega` |
| **Yavalath** | `games/yavalath/bga/` | `yavalath` | `python tools/sync.py yavalath` |
| **Lords of Scotland** | `games/lordsofscotland/bga/` | `lordsofscotland` | `python tools/sync.py lordsofscotland` |
| **Push Fight** | `games/pushfight/bga/` | `pushfighttest` / `pushfight` | `python tools/sync.py pushfighttest` |
| **Mandala** | `games/mandala/bga-prod/` | `mandala` | `python tools/sync.py mandala` |
| **Kiln** | `games/KILN/bga/` | `kiln` | `python tools/sync.py kiln` |
| **Sugar Gliders** | `games/sugargliders/bga/` | `sugargliders` | `python tools/sync.py sugargliders` |
| **Gardens of Uranus** | `games/gardensofuranus/bga/` | `gardensofuranustest` / `gardensofuranus` | `python tools/sync.py gardensofuranus` |
| **nestorgames GP** | `games/nestorgamesgp/bga/` | `nestorgamesgptest` / `nestorgamesgp` | `python tools/sync.py nestorgamesgp` |

---

## ⚠️ 3. Critical Mistakes to Avoid (Lessons Learned & Gotchas)

### ❌ Mistake 1: Empty `player` table in `setupNewGame`
* **What happens**: Table creation fails with a fatal error or blank board because BGA expects the game to populate the `player` table.
* **The Rule**: `setupNewGame($players, $options = [])` **MUST**:
  1. Insert players into MySQL `player` table (`INSERT INTO player (player_id, player_color, player_name)...`).
  2. Call `$this->reloadPlayersBasicInfos()`.
  3. Initialize stats with `$this->tableStats->init(...)` and `$this->playerStats->init(...)`.
  4. Activate the first player with `$this->gamestate->changeActivePlayer($firstPlayerId)`.
  5. Return the initial state class name: `return PlayerTurn::class;`.

### ❌ Mistake 2: Missing `zombie(int $playerId)` in Active States
* **What happens**: Game crashes during table setup with:
  > `Fatal error: A zombie function is needed for state class \Bga\Games\<game>\States\<State>`
* **The Rule**: Every State class with `type: StateType::ACTIVE_PLAYER` (or `MULTIPLE_ACTIVE_PLAYER`) **MUST** implement:
  ```php
  public function zombie(int $playerId): string
  ```

### ❌ Mistake 3: Reversed `PlayerStats` Parameter Order
* **What happens**: BGA crashes with: `Unexpected error: incStat: Unknown player id: 1`.
* **The Rule**: In modern BGA framework:
  * `PlayerStats::inc(string $name, int|float $delta, int $player_id)` $\rightarrow$ **Delta is 2nd, PlayerId is 3rd!**
  * `PlayerStats::set(string $name, int|float|bool $value, int $player_id)` $\rightarrow$ **Value is 2nd, PlayerId is 3rd!**
  * Never pass `$playerId` as the 2nd argument.

### ❌ Mistake 4: Calling `playerPanels.isCurrentPlayerActive()`
* **What happens**: Uncaught `TypeError`. `playerPanels` only manages sidebar UI DOM elements.
* **The Rule**: In `Game.js`, always use defensive wrappers for player state:
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

### ❌ Mistake 5: Using `TRUNCATE TABLE` or DDL in PHP
* **What happens**: Rejected by BGA HAL automated code linter.
* **The Rule**: Always use `DELETE FROM <table>` instead of `TRUNCATE TABLE`. Prefix custom tables in `dbmodel.sql` with `DROP TABLE IF EXISTS`.

### ❌ Mistake 6: Dumping Branding Images into `img/`
* **What happens**: BGA automatically preloads all images in `img/` during table creation, causing 10% hang or slow loading.
* **The Rule**: Keep promotional/metadata images (`box.png`, `banner.jpg`, `icon.png`, `publisher.png`) in `metadata_assets/`. Only keep real gameplay sprites/textures in `img/`.

### ❌ Mistake 7: Schema Desync Across Restarts
* **The Rule**: Always implement `ensureSchema()` and `upgradeTableDb($from_version)` in `Game.php`. BGA keeps the same MySQL database instance across table restarts; column additions must be handled defensively.

### ❌ Mistake 8: Non-Standard Action Button Colors or Invalid Parameters
* **What happens**: Public Alpha / Beta review rejection (e.g. *"Color buttons arent good ! Pass and Skip and Undo should be red :)"*), or buttons silently falling back to white because an invalid color name was provided.
* **The Rule**: In modern BGA framework `this.bga.statusBar.addActionButton(label, callback, { color: ... })`:
  * **Blue (`'primary'`)**: Forward-moving actions, confirmations, turn progression (*Confirm, Play Card, Submit Move*).
  * **Red (`'alert'`)**: Negative, stopping, or reversing actions (*Pass, Skip, Undo, Clear, Cancel, Exit*). **NEVER** use `'danger'` or `'red'` in modern BGA — the parameter is `'alert'`.
  * **White / Secondary (`'secondary'`)**: Optional, non-critical, or side choices (*Standard Preset, Piece Selectors, Mode Toggles*).
  * **Grey**: Disabled actions (`disabled: true`).

### ❌ Mistake 9: Inline Comments in `dbmodel.sql`
* **What happens**: BGA setup fails with: `Key column '<column>' doesn't exist in table`.
* **The Rule**: Never put inline `--` or `/* */` comments on column definition lines in `dbmodel.sql`. BGA's Studio preprocessor regex strips the entire line if it contains an inline comment, dropping the column definition completely. Only use comments on their own separate lines.

### ❌ Mistake 10: Undefined Local Variables in Action Handlers / Notifications (PHP 8 Strict Mode)
* **What happens**: Fatal server error:
  > `Fatal error: Uncaught ErrorException: Undefined variable $<var> in .../modules/php/States/<State>.php on line <line>`
  In PHP 8.x on BGA production, PHP runs in strict error mode and converts undefined variable warnings into fatal `ErrorException`, immediately aborting the action and presenting "Server syntax error / Everything I do shows server error" to players.
* **The Rule**:
  1. Whenever editing PHP action handlers or refactoring notification payloads, **strictly audit every variable passed in `$this->notify->all(...)`**.
  2. If passing `'turn_args' => $turnArgs`, ensure `$turnArgs = $this->getArgs();` is called immediately before.
  3. Never rely on basic `php -l` alone to catch scoping errors: `php -l` only checks syntax (tokens/semicolons), NOT variable initialization.

---

## 📱 4. User Experience & Adaptive Design Standards (Official BGA UX Guidelines: https://bga.li/mRdx)

### A. Action Bar Button Conventions
1. **Traffic Light Color Scheme**:
   * Blue (`'primary'`) = Go / Advance.
   * Red (`'alert'`) = Stop / Pass / Skip / Undo / Cancel / Clear.
   * White (`'secondary'`) = Optional / Side action.
   * Grey = Unavailable / Disabled.
2. **Button Arrangement**:
   * Primary awaited action centered in the Action Bar.
   * Secondary or preset buttons adjacent to primary.
   * Cancel, undo, pass, or clear buttons positioned on the **far right**.
3. **Button Count**:
   * Strictly $\le 4$ buttons on the Action Bar at all times to prevent mobile layout overflow.
4. **Touch & Click Targets**:
   * Interactive elements (buttons, cells, tokens, arrows) must be $\ge 32\text{px} \times 32\text{px}$ (ideal 40–44px).

### B. Responsive Viewport & Board Scaling
1. **Minimum Interface Width**:
   * Set to `320` in `gameinfos.jsonc` (the lowest supported value, maximizing mobile viewport sizing):
     ```jsonc
     "game_interface_width": { "min": 320 }
     ```
2. **Dynamic Board Scaler Pattern**:
   * Avoid hardcoded `@media (max-width: ...)` CSS rules that use `transform: scale(...)` on the board wrapper; these stack on top of BGA's viewport zoom and cause **double-scaling** (shrinking boards to < 40% screen width).
   * Wrap the board in a dedicated scaler element (`.game-board-scaler`) that dynamically updates its width and height (`width * scale`, `height * scale`) via JS (`ResizeObserver` + window `resize`), applying `transform: scale(scale)` with `transform-origin: top left`.
   * On mobile in portrait orientation, calculate `scale = availableWidth / baseWidth` so the board uses the **maximum available screen width** (~98%) with a minimal aesthetic padding.
   * On mobile in landscape, constrain `scale` by available viewport height (`availableHeight / baseHeight`) so the board never gets clipped vertically.
   * On desktop, cap `scale` at `1.0` (natural crisp board).

### C. Audio & Feedback
1. **Audio Levels**:
   * Web Audio synthesizer volumes must stay lower than BGA default (e.g. gain $\le 0.18$) to remain subtle and non-intrusive.
2. **Status Bar Guidance**:
   * Always provide dynamic, informative titles in `this.bga.statusBar.setTitle(...)` guiding the active player.

---

## 🚀 5. Transitioning to Alpha: Game Metadata Manager (GMM) & Pre-Flight Checklist

When requesting **PRIVATE ALPHA** status on BGA Studio (`https://studio.boardgamearena.com/manage?game=<game>`), BGA runs automated pre-flight checks. Follow these rules to pass cleanly.

### A. Deprecated JS Code Warnings (Clean Before Build)
1. **No `ajaxcall`**:
   * Never use `gameui.ajaxcall(...)`. Always use modern:
     ```javascript
     this.bga.actions.performAction(actionName, args);
     ```
2. **No `this.scoreCtrl`**:
   * Never access `this.scoreCtrl[pId]`. Always use the official BGA modern counter:
     ```javascript
     const counter = this.bga?.playerPanels?.getScoreCounter?.(pId);
     if (counter) counter.toValue(newScore);
     ```
3. **No Direct Player Board DOM Access**:
   * Never use `document.getElementById('player_board_${pId}')`. Always use:
     ```javascript
     const panel = this.bga?.playerPanels?.getElement?.(pId);
     ```

### B. Mandatory Web Metadata in GMM (Hard Blockers for Alpha)
If you see:
> `ERROR: Missing description in Game Metadata Manager`  
> `ERROR: Missing zombieModeLevel in Game Metadata Manager`  
> `Impossible to request PRIVATE ALPHA status: some mandatory game metadata is missing.`

These two fields **MUST** be filled in the BGA Studio Web Interface via the **Game Metadata Manager (GMM)**:
1. Open GMM at: `https://studio.boardgamearena.com/gamemetadatamanager?game=<game>` (or *Manage Game* $\rightarrow$ *Game Metadata Manager*).
2. **Description**: Enter a 1–2 paragraph English description of the game, theme, and objective.
3. **Zombie Mode Level**: Select **Level 1** (or *"Turn-based / complete support handled by game engine"*).
4. **Characteristics**: Set sliders/values (1–5) for `Complexity`, `Luck`, `Strategy`, `Diplomacy` only in GMM (do NOT include these keys in `gameinfos.jsonc`, as modern BGA rejects them as deprecated).
5. **Tags**: Add relevant tags (e.g., *Abstract strategy*, *Hexagonal grid*, *Animals*).
6. Click **Save** in GMM, then return to *Manage Game* and click **Request PRIVATE ALPHA status**.

### C. Warnings You Can Safely Ignore for Private Alpha
* **"There is no registered licence linked to the BGG id"**: Safe to ignore for Private Alpha. BGA allows developer playtesting in Private Alpha before the publisher officially signs off on `boardgamearena.com/gamepublishers`.
* **"The game has 1 tags set..."**: Informational notification only.


