# People: rules, decisions and task list

Designer: Néstor Romeral Andrés (nestorgames). Rulebook: `PEOPLE_EN.pdf` (© 2026). Theme: a tribute to Soylent Green.
BGA project: `people` (local: `games/people/bga/`).

## 1. Rules as implemented

### Board and game area
- 6 x 6 board of districts. Each district holds humans (meeples). Edges between orthogonal districts can hold a factory or a roadblock, never both.
- Game area (rows 0 to 5 from the top, columns 0 to 5 from the left):
  - Small (2 players): rows 1-5, columns 0-4 (5 x 5). Fenced on its top and right sides, 10 roadblocks.
  - Medium (3 players): rows 1-5, columns 0-5 (5 rows x 6 columns). Fenced on its top side, 6 roadblocks.
  - Large (4 players): the whole board (6 x 6). No fence.
- Fence roadblocks sit on every edge between an area district and a district outside the area. The rest (12 minus the fence) go to the supply.

### Setup
- 72 humans placed at random on the area districts, each district gets at least one. Pool outside: 8 humans (never on the board, used by "Let more people in").
- Bulldozer on a random area district. Shield in the supply (not on the board) until first deployed.
- Factories per player: 2 players 15, 3 players 14, 4 players 13. Colours by seat: red, blue, yellow, green.
- 10 bitcoins per player.
- First player: random (the rulebook's "last to have eaten a human" has no game mechanic).

### Turn
1. **Place a factory** (mandatory). Tap a free edge between two area districts (no factory, no roadblock). Confirm to place.
2. **Bribe** (optional, once per turn, costs 1 bitcoin). Four options, or skip:
   - **Police (shield)**: pick an area district with humans, not the bulldozer's. It sends 1 human to each open adjacent district (no roadblock on the edge). If the district has fewer humans than open neighbours, the player picks which neighbours receive one.
   - **Sweep (bulldozer)**: the player chooses how many humans the bulldozer takes (1 to 5, at most the humans in its district), then moves straight (up, down, left or right) `k >= 1` districts, `k <= humans taken`, stopping before a roadblock or the area edge. The player chooses how many humans to drop on each district it drives through, at least 1 each, all the taken humans dropped.
   - **Roadblock**: place one roadblock from the supply on a free edge between two area districts.
   - **More people**: pick the top-left district of a 2 x 2 block inside the area. Each of the four districts receives one human from the pool. Needs at least 4 humans in the pool.
3. **End of turn**: the next player starts.

### End of game
- Checked at the start of a player's turn. If that player has no legal factory placement (none left, or no free edge), the game ends.
- Score: each factory scores the product of the humans on its two districts. Plus unspent bitcoins. Highest total wins. Equal totals share the win (the rulebook says "play again"; a shared win is the closest BGA equivalent).

## 2. Implementation decisions (where the rulebook is open)

| # | Topic | Decision | Confidence |
|---|---|---|---|
| D1 | Game-area position | Bottom-left for small and medium, read from the setup figure (the grey outside row and column) | High |
| D2 | Bulldozer collects | The player chooses 1 to 5, at most the humans in its district. Designer ruling (Néstor, 2026-10-20) | High |
| D3 | Bulldozer drops | The player chooses how many to drop on each district driven through, at least 1 on every one, summing to the count taken. Designer ruling 2026-10-20. The UI default is 1 each and the rest on the last | High |
| D4 | Bulldozer must move | `k >= 1`, and `k <= count taken` (every district on the way needs a human). Designer ruling 2026-10-20 | High |
| D5 | Bulldozer carry between turns | All collected humans are dropped; none are carried. Designer ruling 2026-10-20 | High |
| D6 | Shield target | Any district with humans except the bulldozer's (the rulebook does not forbid the current district) | Medium |
| D7 | Shield with fewer humans | The player chooses which open neighbours receive one human | High (rule text) |
| D8 | Bribe once a turn | After any bribe the turn ends; "skip" ends it too | High |
| D9 | Humans in the bulldozer at game end | None are held between turns, so none are lost | Medium |
| D10 | Humans outside the area | Never on the board. Only the pool of 8 | High |
| D11 | Tie | Shared win (no tie-break in the rules) | High |
| D12 | Start player | Random | Medium |
| D13 | Shield position | Cosmetic only: the shield has no rule effect | High |
| D14 | Roadblocks in the fence | Count as roadblocks for supply and blocking | High |

## 3. Task list

### A. Rules and checks
- [x] Read rulebook, setup figure, game-area layouts
- [x] `bga/modules/php/Rules.php`: geometry, moves, bribes, scoring
- [x] `tests/rules_test.php`: CLI checks for every action and the examples in the rulebook

### B. Server
- [x] `Game.php`, `States/PlaceFactory.php`, `States/Bribe.php`, `States/NextPlayer.php`, `States/EndScore.php`
- [x] `dbmodel.sql`, `stats.jsonc`, `gameoptions.jsonc`, `gamepreferences.jsonc`, `gameinfos.jsonc` (bgg_id 467193 is PROVISIONAL, see section 4)

### C. Client
- [x] `modules/js/Game.js`: board, staging, bribe options, notifications, scoring breakdown (scripted tap test in tests/harness)
- [x] `people.css`: themed page background, matte pieces, no shadows, reduced motion, >=32px targets
- [x] Sounds in `sounds/` (copies of the Enceladus confirmed-event sounds under people_* names; ffmpeg not installed)

### D. Art, wiki, metadata
- [x] `design/make_assets.py`: board crop (no text), factory sprites (metadata only), metadata images, display images from design/screens
- [x] `GAMEHELP.wiki` in MediaWiki markup
- [x] `metadata.json`, `GMM_FILLIN.md`, `LICENSING_TRACKER.md` row (licence NOT CONFIRMED)

### E. Verify and ship
- [x] Rules tests, `php -l`, `node --check`
- [x] `tools/bga.py check people` (0 FAIL; 1 WARN: licence not confirmed)
- [x] Browser check at 375 px and 1280 px (tests/harness: 32 px minimum targets, 0 px centre offset, no overflow)

## 4. Open items

- **D2-D5** (bulldozer): settled by the designer on 2026-10-20 (player chooses the count and the drops). Zombie turns skip the bribe.
- **bgg_id 467193** is PROVISIONAL: from a BGG-sourced aggregator (BoardMatch), not checked on boardgamegeek.com.
- **Licence**: NOT CONFIRMED in LICENSING_TRACKER.md.
- Start player is random and a player with no factory (or no empty edge) ends the game, as planned.
