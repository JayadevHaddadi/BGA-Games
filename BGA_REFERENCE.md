# BGA Studio reference: preferred patterns and gotchas

The code reference for this repository. Rules (the "never again" list) are in [AGENTS.md](AGENTS.md) section 3; the
review checklist is in [BGA_REVIEWER_FEEDBACK.md](BGA_REVIEWER_FEEDBACK.md). Working examples: Gardens of Enceladus,
People and Microchip (the newest games, built from `tools/bga.py new`).

Official docs: [Studio](https://en.doc.boardgamearena.com/Studio), [Guidelines](https://en.doc.boardgamearena.com/BGA_Studio_Guidelines),
[UX guidelines](https://bga.li/mRdx). The exact API of the framework version on Studio is in each project's
`_ide_helper.php` (server) and `bga-framework.d.ts` (client), readable over SFTP.

## 1. Layout of a game

```
games/<slug>/
  PLAN.md               rules as implemented, decision table, task list
  NOTES.md              owner decisions (optional)
  metadata.json         Control Panel text (python3 tools/bga.py gmm <slug> writes GMM_FILLIN.md from it)
  design/make_assets.py art crops into bga/img/, metadata images via tools/bga_assets.py
  tests/rules_test.php  CLI checks of Rules.php
  tests/harness/        mock bga + frame.html (true phone widths), run in headless Chrome
  bga/                  the Studio project (synced by tools/sync.py)
    gameinfos.jsonc gameoptions.jsonc gamepreferences.jsonc stats.jsonc dbmodel.sql GAMEHELP.wiki <slug>.css
    modules/php/Game.php  modules/php/Rules.php  modules/php/States/*.php  modules/js/Game.js
    img/ (gameplay art, preloaded)  sounds/ (.ogg + .mp3)  metadata_assets/ (Control Panel images)
```

Studio projects are named `<game>`; a sandbox is `<game>test` (never a short stem such as `pushtest`: the namespace,
CSS prefix and file names must match the final id). Release unused slots.

## 2. Server patterns (PHP 8)

### Setup (official template order)

```php
protected function setupNewGame($players, $options = [])
{
    $gameinfos = $this->getGameinfos();
    $colors = $gameinfos['player_colors'];          // list exactly the piece colours if pieces are per colour
    $values = [];
    foreach ($players as $pid => $player) {
        $values[] = vsprintf("(%s, '%s', '%s')", [$pid, array_shift($colors), addslashes($player['player_name'])]);
    }
    static::DbQuery("INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES " . implode(',', $values));
    $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
    $this->reloadPlayersBasicInfos();

    $this->playerScore->initDb(array_map('intval', array_keys($players)), 0);
    $this->tableStats->init(['turns_number'], 0);    // every name exists in stats.jsonc ("table")
    $this->playerStats->init(['turns_number'], 0);   // every name exists in stats.jsonc ("player")
    $this->globals->set('my_flag', 0);              // per-game flags: globals, not extra tables

    // ... initial game situation ...
    $this->activeNextPlayer();
    return PlayerTurn::class;
}
```

If pieces are drawn per player colour, set `"player_colors"` to exactly those colours (or set
`favorite_colors_support` to false and map colours yourself, as People does).

### State classes

One class per state in `modules/php/States/`, named as the client registers it (`bga.states.register('PlayerTurn', ...)`).
Ids are unique (`check` verifies). `GameState` exposes `$this->game`, `$this->gamestate`, `$this->notify`,
`$this->globals`, `$this->tableStats`, `$this->playerStats`, `$this->playerScore`, `$this->playerScoreAux`.

```php
class PlayerTurn extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 10, type: StateType::ACTIVE_PLAYER);
    }

    public function getArgs(): array          // legal choices for the client highlights; may run before onEnteringState
    {
        return ['targets' => Rules::targets($this->game->getBoard())];
    }

    #[PossibleAction]                         // parameters map by name from performAction(name, {...})
    public function actMove(int $r, int $c, int $activePlayerId, array $args): string
    {
        if (!in_array([$r, $c], $args['targets'], true)) {
            throw new UserException(clienttranslate('That square is not reachable.'));
        }
        // ... apply, save, notify ...
        $this->game->giveExtraTime($activePlayerId);
        $this->game->activeNextPlayer();
        return NextPlayer::class;             // a GAME state that checks the end condition, then returns PlayerTurn
    }

    public function zombie(int $playerId): string   // play a legal move (zombie level 1), never stall the table
    {
        $args = $this->getArgs();
        return $this->actMove($args['targets'][0][0], $args['targets'][0][1], $playerId, $args);
    }
}
```

* An action can return `null` to stay in the same state (multi-active building, Microchip).
* `StateType::GAME` states do their work in `onEnteringState(): string|int` and return the next state (99 = game end).
* **Multi-active**: activate in `onEnteringState()` with `$this->gamestate->setPlayersMultiactive($ids, EndScore::class, true)`;
  a player who is done calls `setPlayerNonMultiactive($pid, EndScore::class)`; its `zombie()` does the same and returns
  `null` (a returned class is a redirect). Whole-game multi-active states keep every clock running: prefer an
  `ACTIVE_PLAYER` state for the turn when other players' actions can wait.
* **Undo**: `undoSavepoint()` when the turn starts, and an `actUndo` that calls `undoRestorePoint()` (Gardens of Io
  `actUndoSpecial`). A savepoint without an undo action is dead code.
* **Final methods**: the `Table` methods marked `final` in `_ide_helper.php` (`incStat`, `activeNextPlayer`,
  `getStat`, `giveExtraTime`, `notifyAllPlayers`, ...) cannot be redefined in `Game.php`. Pick another name.

### Notifications and log

```php
$this->notify->all('stonePlaced', clienttranslate('${player_name} places a ${colour_name} stone on ${coord}'), [
    'player_id' => $pid,
    'player_name' => $this->game->getPlayerNameById($pid),
    'colour_name' => $this->game->colourName($colour),   // a clienttranslate() string
    'coord' => 'C4',                                       // same coordinates as the board labels
    'i18n' => ['colour_name'],
    'r' => $r, 'c' => $c,                                  // data for notif_stonePlaced
]);
```

* Nested translatable phrases: pass `['log' => clienttranslate('left side, row ${row}'), 'args' => ['row' => 3]]` as
  the argument value. Never `clienttranslate('row ') . $n`.
* Every automatic action (soliflowers, forced moves, end trigger) gets a log line. Group small simultaneous events.
* Private information: filter it in `getAllDatas($currentPlayerId)` and send it with `$this->notify->player($pid, ...)`.

### Scores, statistics, progression

* Scores: `$this->playerScore->set($pid, $n)` (also `inc`). Tie-breaker: `playerScoreAux` plus
  `tie_breaker_description` in `gameinfos.jsonc`. Without a rulebook tie-breaker, equal scores share the win.
* When the score is not a plain number (Enceladus: five sorted counts), store an order-preserving key and show the
  real value in the breakdown and the panel.
* `PlayerStats::inc(name, delta, playerId)` / `set(name, value, playerId)`; `TableStats::inc(name, delta)`.
* `getGameProgression()` returns 0 to 100 and moves during the game (reviewers check it).

### Database

* `dbmodel.sql`: `DROP TABLE IF EXISTS` then `CREATE TABLE`, comments on their own lines only.
* Studio keeps the database across table restarts: new columns need `upgradeTableDb($from_version)` (and an
  `ensureSchema()` if you add columns during development).
* Never `TRUNCATE` or DDL in PHP (HAL rejects it): `DELETE FROM`. Never touch `player_score` in SQL.
* Send boards to the client as 2-D arrays of codes, not joined strings.

## 3. Client patterns (`modules/js/Game.js`)

```js
class PlayerTurnState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterTurn(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.bga.states.register('PlayerTurn', new PlayerTurnState(this));
    }
    setup(gamedatas) {
        // build the DOM inside this.bga.gameArea.getElement(), then:
        this.bga.notifications.setupPromiseNotifications({});
    }
    notif_stonePlaced(args) { /* update the board from args; sounds here */ }
}
```

* **Staging**: a tap stages a choice (highlight it, show a blue Confirm and a red Undo); Confirm sends one
  `performAction`. Clear the stage in `onLeavingState`, not after `performAction`, so a refused action leaves a working
  Confirm. Highlight only what the state args allow; a wrong tap explains why in the status bar.
* **Status bar**: `this.bga.statusBar.setTitle(text)`; tell the active player what to do and the others who is playing.
  Buttons: `addActionButton(label, fn, { color: 'primary' | 'alert' | 'secondary', disabled, id, tooltip })`.
* **Player state**: `this.bga.players.isCurrentPlayerActive()`, `getActivePlayerId()`, `getCurrentPlayerId()`.
  `this.bga.playerPanels` is the sidebar DOM only (`getElement(id)`, `getScoreCounter(id).toValue(n)`).
* **Player panels**: append one compact line to `getElement(id)` (colour swatch with its symbol, counts). Never repeat
  player names, scores or turn order inside the play area.
* **Tooltips**: `this.bga.gameui.addTooltip(nodeId, text, '')` after the node exists (Kiln `setupTooltips()`), and the
  `tooltip` option of action buttons. Translatable, adding information, on every icon and control.
* **Sounds** (helper name must not be `playSound`):
  ```js
  sfx(id) {
      const g = this.bga?.gameui;
      if (g?.isReplayMode?.() || g?.instantaneousMode) return;              // replays fire many notifications
      if (Number(this.bga?.userPreferences?.get?.(101) ?? 1) === 0) return; // preference 101 "Sound effects"
      const now = Date.now(); this.lastSound = this.lastSound || {};
      if (now - (this.lastSound[id] || 0) < 300) return;                    // no stacking
      this.lastSound[id] = now;
      try { this.bga.sounds.play(id); } catch (e) { /* optional */ }
  }
  ```
* **Preferences** (`gamepreferences.jsonc`, ids 100 to 199): 100 Colour-blind support (`cssPref: <game>_cb_on`,
  default on; all symbol CSS under `.<game>_cb_on`), 101 Sound effects. Optional, as Kiln has them: 102 Confirm
  placement (ask / place on tap), 103 Tooltips, 104 Confirm end of turn. Read with `this.bga.userPreferences.get(id)`.
* **Hover previews**: attach only when `matchMedia('(hover: hover)').matches`; put `:hover` CSS inside
  `@media (hover: hover)`. Touch devices use tap-to-stage.
* **End of game**: a breakdown (per player, per scoring item) from a `finalScoring` notification; no instant number.

### Layout

* Phones: BGA wraps the play area in padded containers. Widen the game root to the viewport and pull it to the left
  edge, then size the board from the measured width (Enceladus `fitLayout()` / `fitContainerToScreen()`):
  ```js
  fitContainerToScreen(root) {
      root.style.width = ''; root.style.marginLeft = '';
      if (window.innerWidth > 800) return;
      root.style.width = `${document.documentElement.clientWidth || window.innerWidth}px`;
      root.style.marginLeft = `${-root.getBoundingClientRect().left}px`;
  }
  // cell = Math.min(64, Math.floor(width / (cellsAcross + 2 * sideFraction)))  (Enceladus: 32 px at 375 px)
  ```
  Re-run from a `ResizeObserver`. No `@media` rules with `transform: scale()` (they stack with BGA's zoom).
* SVG boards: a tight `viewBox` from the real cell extents, no padding inside the SVG.
* Centre: balance side columns with equal spacers; measure the board centre against `#game_play_area` (0 px at 375
  and 1280 px, every player count).
* `gameinfos.jsonc`: `"game_interface_width": { "min": 320 }`.

### Page background (whole screen)

```css
html:has(body.<game>_page_bg), body.<game>_page_bg { min-height: 100vh; background: <subtle texture>; }
body.<game>_page_bg #page-content, body.<game>_page_bg #ebd-body, body.<game>_page_bg #game_play_area,
body.<game>_page_bg #game_play_area_background, body.<game>_page_bg .bga-game-area { background: transparent !important; }
```
Add the class in `setup()` with `document.body.classList.add('<game>_page_bg')`.

## 4. Testing without Studio

* **Rules**: `php games/<slug>/tests/rules_test.php` (pure `Rules.php`, no framework). Pass literals to `&$array`
  parameters through a variable first.
* **PHP on Windows**: no system PHP. Download the portable NTS zip from `https://downloads.php.net/~windows/releases/`
  (check the sha256 from `releases.json`), unzip into the session scratchpad, put it on `PATH`.
* **Browser harness**: serve the game folder (`python -m http.server 8765 --directory games/<slug>`), then
  `chrome --headless=new --virtual-time-budget=5000 --dump-dom "http://127.0.0.1:8765/tests/harness/frame.html?mode=turn&w=375"`
  and read the JSON in `<pre id="out">`. Headless Chrome will not go below ~500 px, which is why `frame.html` loads
  the page in a sized iframe. Taps go through `elementFromPoint`, so a covering layer fails the flow.
  Windows Chrome: `C:\Program Files\Google\Chrome\Application\chrome.exe`.
* **CSS**: BGA's build parses CSS with postcss; check braces (`check` does) and parse after large edits.
* **Gate**: `python3 tools/bga.py check <slug>` (or `check all`).

## 5. Studio gotchas

| Symptom | Cause | Fix |
| :--- | :--- | :--- |
| "Loading game art (10%)" then error | A JS exception in `setup()` or the first `onEnteringState`, or a PHP error in `getAllDatas` | Browser console (F12), Network tab |
| `A zombie function is needed for state class ...` | Active state without `zombie()` | Add it |
| `Cannot override final method ...Table::x()` | `Game.php` redefines a framework final method | Rename the method |
| `incStat: Unknown player id: 1` | `playerStats->inc(name, playerId, 1)` | Value 2nd, player 3rd |
| `Unknown column ... in 'field list'` | Schema changed, database kept across restarts | `DROP TABLE IF EXISTS`, Express Start |
| `Key column ... doesn't exist` | Comment on a column line in `dbmodel.sql` | Comment on its own line |
| `This move is not authorized now` | JS action name has no `act*` method in the active state | Match the names (`check` does) |
| Board only updates after F5 | `setupPromiseNotifications` missing or no `notif_` handler | Add them |
| `Wrong formatted data from main BGA website (GS1)` | "Spectate" on a Studio table | Use the red Studio bar "Play as ..." or a second account |
| Build warning "Global function playSound is deprecated" | Any identifier named `playSound` | Rename (`sfx`) |
| HAL `$GLOBALS` error | A `modules/vendor` folder on Studio | `sync.py` removes it |
| "Impossible to create release directory" after "Committed revision" | BGA server side | Wait a minute, build again |
| Table creation slow at 10% | Big images in `img/` | Metadata images go to `metadata_assets/` |

Table lifecycle: JS, CSS and PHP changes need F5; `dbmodel.sql` or setup changes need Express Start / a new table.

## 6. Alpha request: Control Panel (Game Metadata Manager)

Open `https://boardgamearena.com/controlpanelgames?game=<game>` (not `studio.../gamemetadatamanager`). Paste from
`GMM_FILLIN.md`:
* **Blocking**: Description (1 to 2 English paragraphs) and Zombie mode level (Level 1).
* Characteristics (complexity, luck, strategy, diplomacy) and tags: Control Panel only, never in `gameinfos.jsonc`.
* BGG id: read from `gameinfos.jsonc` `bgg_id`, not editable in the panel.
* Safe to ignore for Private Alpha: "no registered licence linked to the BGG id", "the game has 1 tags set".
* Then Manage game → Request PRIVATE ALPHA. Only after [BGA_REVIEWER_FEEDBACK.md](BGA_REVIEWER_FEEDBACK.md) passes.

### Metadata images (`bga/metadata_assets/`, produced by `tools/bga_assets.py`)

| Asset | File | Size | Rules |
| :--- | :--- | :--- | :--- |
| Box | `box_280x280.png` | 280 × 280 | Transparent background. Games without a box (nestorgames cotton cases): a logo-based stand-in |
| Icon | `icon_50x50.png` (+ `icon_500x500.png`) | 50 × 50 | No border, readable at 16 px, the most iconic piece |
| Banner | `banner_1386x400.jpg` and `banner_1920x556.jpg` | both | **No text or logo** (BGA overlays the title); keep the centre clean |
| Publisher | `publisher_280x280.png` | 280 × 280 | Transparent; check the logo file (nestorgames' is white on black: `bga_assets.publisher_logo` converts it) |
| Display | `display_1.jpg`, `display_2.jpg` | height 400 to 760, width ≤ 1.5 × height (1000 × 750) | Real screenshots (harness `index.html?clean=1`) |
| Title | `title_2000x2000.png` | 2000 × 2000 | Hero art for the game page |

Two sources disagreed on the banner (1386 × 400 vs 1920 × 556); the tool produces both, upload whichever the panel asks.
Crop gameplay art from the clean source (a board without logo, title or icon strip). Scans with text stay out of
`img/` and out of banners. Look at every logo file before using it.

## 7. Words for UI requests

| Term | Meaning | Code |
| :--- | :--- | :--- |
| Status bar / action bar | The bar at the top with the prompt and buttons | `this.bga.statusBar`, `#pagemaintitletext`, `#generalactions` |
| Player panel | A player's card in the right sidebar | `this.bga.playerPanels.getElement(id)` |
| Score counter | The number in the panel | `this.bga.playerPanels.getScoreCounter(id)` |
| Game play area | The container of the whole game | `#game_play_area`, `this.bga.gameArea.getElement()` |
| Board scaler | The wrapper sized from the measured width | `fitLayout()` |
| Log | The history in the sidebar | server `notify` messages |
| Tooltip | BGA's hover/press help bubble | `this.bga.gameui.addTooltip()` |
