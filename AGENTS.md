# Board Game Arena (BGA) Studio — Master Agent Guidelines & Gotchas

This file is the single source of truth for all AI agents working in this repository across all games. Follow these workflows, best practices, and rules strictly.

---

## 🚀 1. Deployment Workflow: Commit & Push

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

