# Gardens of Enceladus: rules, decisions and task list

Designer: Néstor Romeral Andrés (nestorgames). Rulebook: `GARDENSOFENCELADUS_B_EN.pdf` ("Updated rules", © 2018, edits by Nathan Morse). BGG: https://boardgamegeek.com/boardgame/253793/gardens-of-enceladus
BGA project: `gardensofenceladus` (local: `games/gardensofenceladus/bga/`).

## 1. Rules as implemented

### Material and setup
- 10 x 10 garden. 95 flowers in 5 colours (19 each) and 5 peaks fill all 100 cells at setup (random, one per cell).
- Peaks: random, but the non-peak cells must stay one orthogonally connected region (no fenced section). Re-drawn until valid.
- 10 special action spaces sit on the strip above the garden. The 10th (the flower icon) holds the grey wildflower.
- Carrying tube starts empty. Paid flowers go into it (rulebook footnote 2). Tube action draws from it.
- Martians: 5 types (Bot, Ali, Marty, Bob, Robby). Each player takes one type (2 players with the variant: two types each).
- Start squares: outside the garden, orthogonally next to a perimeter cell, on the left, right or bottom sides. The top side is the special action strip, so it is excluded.
- Each player places their Martian in turn order, choosing a type and a free start square.
- Starting player: first seat. Turn order: BGA seat order (clockwise).

### Turn (in this order)
1. **Special actions (optional, any number).** Pay 3 flowers of one colour from your reserve (moved to the tube). Each special action is usable once per game. After use it is covered with a grey counter. Multiple specials per turn are allowed, and each payment can use a different colour.
2. **Mandatory move.** Your Martian moves in a straight orthogonal line to a flower, passing over other Martians and flowers but never over peaks. Pick the flower you land on. Then pick one orthogonally adjacent flower (choice if several, nothing if none).
3. **Soliflowers.** Pick every flower with no orthogonally adjacent flower (diagonals do not count), including any already on the board at setup.

### Special actions (rulebook icons, left to right)
1. Tube: pick 3 random flowers from the tube (payment set aside, then returned to the tube). Needs at least 3 in the tube.
2. Jump: a peak orthogonally adjacent to your Martian. Land on the cell directly beyond it. Not onto a Martian or a peak. A flower there goes to your reserve.
3. Diagonal: move your Martian any distance diagonally. Not over peaks, not onto a Martian or a peak. A flower on the destination goes to your reserve.
4. Pick any flower from the board.
5. Swap your Martian with any one peak or flower.
6. Swap any one peak with any one flower.
7. Swap any two flowers.
8. Move any one flower to any empty cell.
9. Move any one peak to any empty cell.
10. Wildflower: pick the grey wildflower. Stays available until it is taken (no counter).

### End of game
- At your mandatory move, if your Martian cannot reach a flower, the game ends immediately after the scoring phase. Special actions are optional, so you may use them to make a move first.
- Only the active player is checked (step 2 of their turn).

### Scoring
- Count each colour in your reserve (5 colours, including zeros). Sort ascending. That is your 5-digit score. The best hand wins.
- Comparison: lexicographic on the sorted tuple (equivalent to the 5-digit number when every count is below 10). Ties are shared.
- Grey wildflower: counts as any one colour, chosen to give the best score (declaration is automatic, since it is a dominant choice).
- Cooperative variant: team score = lowest player's score.

### Variants (game options)
- Game mode: Competitive (default) or Cooperative.
- Martians: 1 per player (default), or 2 per player for 2 players. Both Martians can move. All Martian-based specials in one turn must use the Martian you move that turn.
- Combinatorial: tube action disabled at setup (covered with a counter).
- Hidden score: **deferred** (see section 3).

## 2. Implementation decisions (where the rulebook is open)

| # | Topic | Decision | Confidence |
|---|---|---|---|
| D1 | "Game case" vs "game tube" | Same container: paid flowers go into the tube, the tube action draws from it | Medium: footnote 2 supports it |
| D2 | Wildflower as payment | Not allowed (no colour until declared at scoring) | Medium |
| D3 | Wildflower colour | Auto: best declaration (dominant, no hidden information) | High |
| D4 | Jump landing | Exactly two cells, the peak must be adjacent | Medium |
| D5 | Diagonal move | Any distance, not over peaks, may pass over Martians | Medium |
| D6 | Martian-based specials from the start square | Not allowed (Martian must be on the garden) | Medium |
| D7 | Score compare with counts of 10+ | Lexicographic tuple; BGA counter uses a base-21 key | High |
| D8 | Tie | Shared victory | High (no tie-break in rules) |
| D9 | Move destination | Any flower in the line of sight (not only the nearest) | Medium: rule says "a space occupied by a flower" |
| D10 | Adjacent pick | Chosen by the player among orthogonal neighbours | High |
| D11 | Soliflower sweep | After every mandatory move, all soliflowers (any colour, any position) | High |
| D12 | Peak placement | Random with a connectivity check; no player agreement step | Medium |
| D13 | Martian type | Chosen during setup (cosmetic, no effect on play) | High |

## 3. Deferred (and why)
- **Hidden score variant.** A real hidden reserve needs per-player data filtering in `getAllDatas` and private notifications. The current build shows reserves publicly, which the standard rules require. Left for a later pass.

## 4. Task list

### A. Rules and checks
- [x] Read rulebook, board, action icons, other Gardens games
- [x] `bga/modules/php/Rules.php`: pure geometry, moves, specials, soliflowers, scoring key
- [x] `tests/rules_test.php`: CLI checks for every special action and edge case

### B. Server
- [x] `Game.php`: setup (types, peaks, flowers, tube, options), getAllDatas, helpers, undo savepoints
- [x] `States/PlaceMartian.php` (setup, one per player)
- [x] `States/PlayerTurn.php` (specials, move, pick adjacent, game-end check, zombie)
- [x] `States/NextPlayer.php`, `States/EndScore.php` (breakdown, scores, zombie)
- [x] `dbmodel.sql`, `stats.jsonc`, `gameoptions.jsonc`, `gamepreferences.jsonc`, `gameinfos.jsonc`

### C. Client
- [x] `modules/js/Game.js`: board, specials strip, staging (tap-to-stage + Confirm/Undo), notifications, scoring breakdown, `getGameProgression`
- [x] `gardensofenceladus.css`: themed page background, matte pieces, no shadows, reduced motion, >=32px targets
- [x] Sounds: `sounds/gen_*.ogg|mp3` (copied from the Gardens of Io/Mars files, renamed; ffmpeg not installed)
- [x] Notifications registered (`setupPromiseNotifications`), `playSound` not used anywhere

### D. Art (`design/make_assets.py`)
- [x] Board texture cropped from `image.png` (no text), 1400px
- [x] Special action icons cropped from `enceladus_actions.png` (10 icons)
- [x] Flowers: blue, red, yellow, green, magenta (from Gardens of Mars sprites, magenta recoloured) + grey wildflower
- [x] Martians: 5 silhouettes (reused from Gardens of Mars, black/white per rulebook)
- [x] Peak sprite, grey counter sprite
- [x] Table background (`img/table.jpg`, subtle space texture, not in the board area)
- [x] Metadata assets: box 280, icon 50 and 500, banner 1386x400 (no text), title 2000, publisher 280, display 1000x750 x2

### E. Wiki and metadata
- [x] `bga/GAMEHELP.wiki` in MediaWiki markup (no Markdown)
- [x] `metadata.json`: description, designers, artists, publisher, tags, characteristics, BGG id
- [x] `GMM_FILLIN.md` generated from `metadata.json` (copy-paste for the Control Panel)
- [x] Licence tracker row (licence status not confirmed: flagged, not assumed)

### F. Tooling (the "one command per game" system)
- [x] `tools/bga.py new` (replaces the planned new_game.py): scaffold folder, gameinfos, TARGET_MAP, workflow entries, metadata.json
- [x] `tools/bga.py check`: greps (playSound, ajaxcall, scoreCtrl, box-shadow, emoji), PHP and JS lint, rules tests, metadata sizes, zombie methods, notification handlers, stats, SQL, sync/workflow wiring
- [x] `tools/sync.py` and `.github/workflows/bga-deploy.yml`: add `gardensofenceladus` (via `bga.py register`, marker-based)
- [x] Update `BGA_DEVELOPER_CHEAT_SHEET.md` and `AGENTS.md` with the new workflow and gaps found

### G. Verify and ship
- [x] Rules tests pass; PHP lint clean
- [x] Browser harness at 375px and 1280px (Chrome headless), tap-target measurement
- [x] Commit and push to `main`, then deploy (fast-lane or workflow)
- [x] Summary to user: what was verified, what is pending (Studio test table, licence)

## 5. Status at the end of the build

Verified here:
- Rules: `php tests/rules_test.php`: 101 checks pass (every special action, soliflowers, scoring, random garden).
- Lint: `php -l` on every PHP file; `node --check` on Game.js.
- Checker: `python3 tools/bga.py check gardensofenceladus`: 0 FAIL, 1 WARN (licence not confirmed).
- Browser harness (Chrome headless, real Game.js, mock bga): 375 px cell 32 px, 390 px cell 33 px, 1280 px cell 58 px;
  board centre offset 0 px at all widths; no horizontal scroll; move, special payment, undo, refusal and placement flows pass.

Not verified here (needs BGA Studio):
- The PHP states run inside the BGA framework. Nothing here executes them. The first real test is a Studio table.
- Error strings raised by Rules.php are plain English (not wrapped for translation yet).

Decisions to confirm with the user:
- D1 to D13 in section 2 (the rulebook's open points).
- Licence status: Gardens of Enceladus is not in the licence tracker. Not assumed granted.
- Metadata characteristics and tags are PROPOSALS in metadata.json.
- Artist credit: the rulebook names no artist, so `artists` is empty.

Deferred:
- Hidden score variant (see section 3).
- Colour-blind preference (pref 100): not added. Every flower carries its own centre symbol (circle, square, triangle, diamond, star), and the colour name is shown in the reserve and the breakdown, so no information depends on colour alone.
