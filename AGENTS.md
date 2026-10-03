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
1. **Audio**:
   * Ship real sound files in `<game>/bga/sounds/` (both `.ogg` and `.mp3`, short, quiet) and play them with `this.bga.sounds.play('<filename-without-ext>')`. This respects the player's BGA volume/mute settings. **Do not** build custom Web Audio synths or in-game mute buttons.
   * Sounds fire only for **confirmed game events** (stone placed, result). **Never** on hover, `mouseenter`, or tap-to-stage (Ian/BGA: *"Remove hover-triggered sounds"*, guideline D.4).
2. **Status Bar Guidance**:
   * Always provide dynamic, informative titles in `this.bga.statusBar.setTitle(...)` guiding the active player.

---

## 🧭 4b. Reviewer Feedback Checklist (BGA reviewer "Ian" — applies to EVERY game)

Ian reviews each submission against the same UX/UI points. Check all of these **before** requesting Alpha/Beta review. (Source: Yavalath review, Oct 2026 — "the same main UX/UI issues raised on the other recent submissions also apply here".)

| # | Reviewer point | Guideline | Rule for us |
| :-- | :--- | :--- | :--- |
| 1 | "Generic / AI-generated-looking UI" | C.3 | No pill badges, gradient chips, glossy shine ellipses, emoji in buttons/labels (`✔ ↺ 🔊`), looping pulse/glow animations, or rounded "card with big drop shadow" boards. Use the game's physical look (wood, felt, paper, real piece shapes), flat/matte pieces, and plain text. |
| 2 | Mobile layout & touch-target sizing | C.4, E.3 | Board fills ~100% of the available width (tight `viewBox`, no big padding inside the SVG). Every tappable cell/piece ≥ 32px at a 375px viewport — **measure it** (`getBoundingClientRect`) at 375px for the largest board variant. Use tap-to-stage + Confirm/Undo so a mis-tap is recoverable. No controls in the play area smaller than 32px; put actions in the BGA action bar. |
| 3 | Hover-triggered sounds | D.4 | No sound on hover or on tentative/staged actions. Native `bga.sounds` files only (see §4.C). |
| 4 | Thematic treatment / background | C.5 | Give the play area a themed background (CSS gradients are enough, e.g. walnut table) and a board that looks like the real one (Yavalath = hexagonal board, not a rounded square). |
| 5 | Credits inside gameplay area | — | **Never** show designer/publisher/developer text in the board area or play area. Credits go in `gameinfos.jsonc` (`publisher`, etc.) and the Game Metadata Manager (designers, artists, publisher). Rules reminders are fine; credits are not. |
| 6 | Button colors | C.3 | Primary = blue, Undo/Pass/Cancel = red (`'alert'`), side actions = `'secondary'`. Only ONE copy of each action (no duplicate swap/undo button inside the play area). |

Extra rules learned with this review:
* `:hover` styles must be wrapped in `@media (hover: hover)` so they don't stick on touch screens; mouse-only ghost previews are attached only when `matchMedia('(hover: hover)')` matches.
* Respect `prefers-reduced-motion`; avoid infinite CSS animations on board elements.
* Test the UI in the browser pane at 375px and desktop width with a small local harness before pushing (mock `bga`, `_`, `gamedatas`).

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

These two fields **MUST** be filled in the BGA Web Interface via the **Game Control Panel / GMM**:
1. Open Control Panel at: `https://boardgamearena.com/controlpanelgames?game=<game>` (or *Manage Game* $\rightarrow$ *Game Metadata Manager*). Note: do NOT use `studio.boardgamearena.com/gamemetadatamanager` as that URL does not exist.
2. **Description**: Enter a 1–2 paragraph English description of the game, theme, and objective.
3. **Zombie Mode Level**: Select **Level 1** (or *"Turn-based / complete support handled by game engine"*).
4. **Characteristics**: Set sliders/values (1–5) for `Complexity`, `Luck`, `Strategy`, `Diplomacy` only in GMM (do NOT include these keys in `gameinfos.jsonc`, as modern BGA rejects them as deprecated).
5. **Tags**: Add relevant tags (e.g., *Abstract strategy*, *Hexagonal grid*, *Animals*).
6. **BGG ID**: Note that BGG ID is **NOT editable in the Control Panel UI**. It is read directly from `"bgg_id": <ID>` inside `gameinfos.jsonc`. Whenever you get a warning that BGG ID is missing, set it directly in `gameinfos.jsonc`!
7. Click **Save** in the Control Panel, then return to *Manage Game* and click **Request PRIVATE ALPHA status** (or Public Alpha).

### C. Warnings You Can Safely Ignore for Private Alpha
* **"There is no registered licence linked to the BGG id"**: Safe to ignore for Private Alpha. BGA allows developer playtesting in Private Alpha before the publisher officially signs off on `boardgamearena.com/gamepublishers`.
* **"The game has 1 tags set..."**: Informational notification only.

### D. Mandatory Metadata Image Asset Specifications (GMM & Alpha)
Always store these promotional images in `<game>/bga/metadata_assets/` (**never** in `img/` to prevent table load delays). Produce and keep them ready for upload in the BGA Control Panel / GMM:

| Asset Type | Standard Dimensions | Format & Rules |
| :--- | :--- | :--- |
| **Box Image** | `280x280` px | PNG or JPG. Square game box cover thumbnail. |
| **Icon** | `50x50` px | PNG (transparent or solid). Also keep a `500x500` px high-res version. |
| **Banner** | `1386x400` px | JPG or PNG. **CRITICAL: Must NOT contain any text or logos** (BGA dynamically overlays title & player rankings). |
| **Publisher Logo** | `280x280` px | PNG (transparent background preferred). |
| **Display Images** | `1000x750` px (or height 400–760px) | JPG or PNG. In-game screenshot / setup preview. Width must be $\le 1.5 \times \text{height}$. |
| **Title Image** | `2000x2000` px | JPG or PNG. Ultra high-res hero/cover artwork for the game presentation page. |




---

## 🎨 6. Alpha Reviewer Feedback: "Generated-Looking UI" Rejections (READ BEFORE ANY UI WORK)

**Source**: BGA reviewer (Ian) rejected Sugar Gliders for Public Alpha, citing the *same* issues already raised on earlier games. Quote: *"Feedback given on previous projects should be applied to subsequent adaptations... generic / AI-generated-looking UI patterns; insufficient adaptation to the specific identity of the game; mobile and responsive presentation requiring more care; an interface that feels generated rather than deliberately designed."* Abstract/simple games are NOT an excuse.

**Rule: Before requesting any alpha review, walk the full checklist at https://en.doc.boardgamearena.com/BGA_Studio_Guidelines and the list below. Never ship a UI pass without it.**

### A. Anti-"AI look" rules
* **No emoji as UI icons** (🎯💤🚫🍃🏆📖 etc.). Use SVG/PNG icons drawn from the game's own art/components, with `aria-label`s.
* **No custom themed modals/dialogs, gradient "card" widgets, pill badges, or glassmorphism.** Use BGA native elements: status bar, tooltips (`bga.gameui.addTooltip`), game wiki (`GAMEHELP`), standard log.
* **No decorative/themed fonts**; use the default BGA font. No pure flat/gradient backgrounds — use a subtle textured playmat, slightly blurred, low-contrast so components stay the focus.
* **No custom header bars, badges or settings UI.** Settings (sound, animation speed) go in BGA's standard preferences menu (`userPreferences`), not custom buttons on the board.
* **No in-game logos or marketing text** on the play area. No "Cruxy Rules Guide"-style branding.
* **Take the identity from the game's components**: board art, piece shapes, palette from the physical game. Extra colors come from components, never tinted UI buttons.

### B. Layout & panels
* Center the play area; leave "no-action" margins around the board. Fluid layout, no fixed widths. Fully playable at 100% scale; never rely on zoom.
* Player panels: compact, no redundant info, no titles/settings. Don't put always-needed info only there.
* Group related info (resources/scores) in one place. Provide jump-links/anchors for vertical mobile layouts.
* **No automatic popups mid-game**; popups only for tutorials, skippable, click-outside-to-close. Rules belong in the wiki/tooltips.

### C. Action bar & feedback
* ≤ 4 buttons; main action centered; cancel/undo far right, visually separated. Never replace board interactions with buttons. Don't stick custom buttons next to BGA built-in controls. Hide never-relevant buttons; grey out temporarily unavailable ones.
* Prefer one "Restart turn"/timed confirm over per-action Undo; don't layer Undo+Reset+Confirm.
* Highlight valid targets and **preview consequences before commit**. Every failed action shows a short plain-text reason (shake/tooltip). Pair color with icon/text.
* **Game log**: every action says *who* did *what* with icons (e.g. "Marianna jumped to [space] and ate [tile] (+2)"), incl. automatic/forced actions; group tiny events.
* Animations 0.5s (max 0.8s), purposeful, no looping/bouncing/glow decoration, batch repeats. **Exception (functional, allowed)**: a gentle ring/pulse on the current player's own piece *only while it is their turn*, stopping afterwards and disabled under `prefers-reduced-motion`. Scoring end: step-by-step with `displayScoring`, show breakdown.
* Show state with text/icon, not color alone (e.g. a "Zz" label on the player panel for torpor). Don't add always-visible counters/badges below the board that players won't read; put rare info in tooltips or the status bar.
* Calibrate the hex-grid centre/size against each board art variant (full and compact) with screenshots; no visible gap/offset.
* Sound: below BGA default, short, never the only cue.

### D. Accessibility & mobile
* Colorblind-safe: every player color/piece also has a unique shape/symbol/outline. WCAG AA (4.5:1) contrast for text and icons. Outline player-color names.
* Tap targets ≥ 32px (aim 40–44px) with spacing; no tight icon clusters.
* All text translatable (`_()`); labels on every interactive element.
* **Test at 320–400px portrait and landscape phones, not just desktop**, before every push touching UI. Take screenshots with the pre-installed Chromium/Playwright at 360×740, 390×844, 820×1180, 1440×900 and review them honestly against the checklist.
* Refresh/reconnect must restore exact state (server is source of truth).

### E. Game-logic lessons (rules correctness)
* **Final scoring must include everything still "in play"** (e.g. Sugar Gliders: the tile each glider sits on is collected at game end; missing it declared the wrong winner). Before finishing any game, trace the end-game trigger and list every component that should be scored/collected, then log each collection.
* **Don't invent tie-breakers.** Equal points = tie between those players (shared victory) unless the official rules say otherwise. Remove unofficial tie-break options when the publisher/designer objects.

### G. Kiln review (Ian, Oct 2026) — 5 repeat findings, now standing rules
1. **Generic / AI-looking stylesheet (C.3)**: no looping `animation`/glow/pulse, no `box-shadow` drop shadows, no gradient "gloss" on pieces, no rounded white cards (radius <= 3px), no emoji or glyph-badges (🏆 ⟳) in labels, no custom CSS tooltips (use `title` / `bga` tooltips). Pieces = flat matte colours; panels = matte paper/clay of the game's materials. Highlights use `outline`, not glow.
2. **Thematic background (C.5)**: put a themed class on `bga.gameArea.getElement()` (Kiln: dark brick wall via CSS gradients, low contrast). Never a flat dark gradient.
3. **Hover sounds (D.4)**: sounds only from server notifications (confirmed events). Never in `mouseenter`, click-to-select, stage or undo-click handlers. Ship real `sounds/*.ogg|mp3` and call `bga.sounds.play(id)`; no Web Audio synths. Mouse-only previews are attached only if `matchMedia('(hover: hover)')`; all `:hover` CSS lives inside `@media (hover: hover)`.
4. **Mobile width (C.4)**: below ~720px container width switch to a one-column `kiln_compact` layout (no side gutters, track strip on top, no rotated seats) and scale the *measured* natural size to ~100% of the width. Do not hard-code a base width.
5. **Tap targets (E.3)**: measure with Playwright at 375px (`getBoundingClientRect`) for 2p AND 4p. Kiln result: kiln tiles/arrows 41px, warehouse cells 34px, sell arrows 34px.

### F. Process
* Every new game starts from this section, not from a generic template. Re-check the checklist at the end of each UI task and state in the commit/summary which items were verified.
* Push directly to `main` (no feature branches/PRs) unless the user says otherwise.
