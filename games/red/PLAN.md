# Red: rules, decisions and task list

Designer: Néstor Romeral Andrés (nestorgames), 2011. Revisions by Nathan Morse. Rulebook: `RED_EN.pdf`. BGG: https://boardgamegeek.com/boardgame/112118/red
BGA project: `red`. Local files: `games/red/bga/`.

## 1. Rules as implemented

### Material
- 30 small tiles: 6 types, 5 of each. 6 large tiles: 6 types, 1 each. 36 tiles in total.
- A type is a (background, circle) pair of two different colours from black, white and red. Six pairs.
- A small tile covers one grid cell. A large tile covers a 2 x 2 block of cells.

### Setup
- All 36 tiles go into the pool, face up.
- Player colours: 3 players take White, Black and Red by seat order. Turn order: White, then Black, then Red.
- 2 players (2-player variant): White and Black are the players. Red is the blocker colour and its tiles are placed by whoever is on turn.

### Placement
- A tile goes on the grid. Coordinates are absolute: the first tile sits at (0, 0), x grows to the right, y grows down. A large tile's (x, y) is its top-left cell.
- The first tile must be small (White cannot play a large tile first).
- Every later tile must touch at least one small tile orthogonally. Corners do not count.
- A large tile may not touch another large tile orthogonally.
- A small tile may touch large tiles freely.
- 2-player variant: the player must place a tile of a different type from the one the opponent just placed, if any such placement exists. Type = size + background + circle.

### Scoring (at game end)
- For each player colour X:
  - Border group: the largest group of tiles with background X, connected orthogonally. Value = sum of tile points (small 1, large 2).
  - Circle group: the same for circle colour X.
  - Score = border group value × circle group value.
- Example from the rulebook: red border group 6, red circle group 4, score 24. The rulebook's own example reproduces with this rule.
- In a 2-player game the blocker colour is not scored.

### End of game
- The game ends when the pool is empty or no tile in the pool can be placed anywhere.

## 2. Implementation decisions (where the rulebook is open)

| # | Topic | Decision | Confidence |
|---|---|---|---|
| D1 | Large tile size | 2 x 2 cells, anchor is the top-left cell | High (rulebook: "large counts double", picture shows 2x size) |
| D2 | First tile | Must be small. White is always first, so this is the same as "White cannot play a large first" | High |
| D3 | Group connectivity | Orthogonal adjacency between tiles that share the property. Value = sum of points | High (reproduces the rulebook example) |
| D4 | Turn order in 2 players | Alternate between the two players. Each may place any tile, including Red | Medium: the rulebook does not say |
| D5 | 2-player "different type" rule | Enforced whenever a different-type placement exists. Otherwise any tile | High ("if possible") |
| D6 | Tie for first | Designer rule (Néstor): 3 players, exactly two tie for first -> the THIRD player wins (BGA score raised to top + 1, logged). Three-way tie = shared draw. 2 players: ties are ties | High |
| D7 | Face-down pool variant | Not implemented in this version | Open: see section 3 |
| D8 | Live scores | The score counter shows the current border × circle product for each player during the game | Medium (public information, helps players) |
| D9 | Scoring breakdown | One log line per player at game end (border, circle, product) | High |
| D10 | Board labels | Absolute (x, y) at the board edges. The log uses the same coordinates | High |

## 3. Open questions for the owner

1. **Tie-break.** Resolved by the designer: if two players tie for first, the third player wins (see D6).
2. **2-player turn order and colours.** The rulebook does not say who places the blocker colour or who takes which player colour. The game uses White and Black as players and alternates turns.
3. **Face-down variant.** The rulebook offers placing the pool face down. Implementing it needs a design for drawing a hidden tile and checking that it can be placed. Not in this version.
4. **Licence.** Red is not in the BGA licence export of 5 October 2026. Confirm the licence with nestorgames before publisher sign-off.

## 4. Task list

- [x] Scaffold (`tools/bga.py new red`, sync target and deploy block)
- [x] Rules as pure PHP (`bga/modules/php/Rules.php`) with CLI tests (`tests/rules_test.php`)
- [x] Server: setup, PlayerTurn (place), NextPlayer, EndScore, notifications, stats
- [x] Client: SVG board fitted to the width, tap-to-stage, Confirm and Cancel, pool, colourblind symbols, sounds
- [x] Preferences: 100 colourblind symbols, 101 sound effects, 102 confirm placement
- [ ] Browser harness at 375 px and desktop, centring and tap sizes
- [ ] Artwork: metadata images (box, icon, banner, publisher, display, title)
- [ ] GAMEHELP.wiki (MediaWiki)
- [ ] `python3 tools/bga.py check red` with no FAIL
- [ ] Licence row in `LICENSING_TRACKER.md`, AGENTS.md mapping row
