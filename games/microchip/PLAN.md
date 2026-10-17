# Microchip: rules, decisions and task list

Designer: Néstor Romeral Andrés (nestorgames). Rulebook: `Microchip.zip` → `MICROCHIP_EN.pdf` ("Rules and Rule book (c) 2022"). BGG: https://boardgamegeek.com/boardgame/371557/microchip
BGA project: `microchip` (local: `games/microchip/bga/`).

## 1. Rules as implemented

### Material
- 60 tiles in 5 colours (blue, red, green, yellow, pink), 12 per colour: 1 plus (4 connections), 3 tee (3), 2 corner (2), 2 straight (2), 4 end (1).
- 5 boards, each a 5 x 5 grid of coloured spaces. Each colour appears 5 times per board.
- The tile colour must match the colour of the space it is placed on.

### Setup
- 3 or 4 players: pool of 5 rows x 12 columns. 5 players: 6 x 10. 2 players: 5 x 10 (10 random tiles go back to the box).
- Tiles are dealt face up into the pool at random. Columns fill from the right (the draw column is the rightmost column that still has tiles).
- Each player receives one board. The boards are generated with the rulebook's recipe (centre of the inner 3 x 3 = special colour, etc.), one per player.

### Turn
- Players take turns clockwise (seat order). On your turn, draw one tile of your choice from the draw column.
- Between turns, and at any time while the game runs, every player may arrange their tiles on their own board (test arrangements, move, rotate, return to tray).
- When the pool is empty, every player builds their final microchip. Each player presses Finish when done. The game ends when all have finished.

### Placement legality (checked on every placement)
1. The space is empty and inside the board.
2. The tile colour equals the space colour.
3. For each side of the tile facing a placed tile: the connection on the tile equals the connection on the neighbour (both have a path or both have none).
4. No connection faces the board edge.
Open connections towards empty spaces are allowed while building.

### Scoring
- Microchip = a group of tiles connected through matching connections with no open connection (every path meets another tile).
- Chip value = sum of the connections of its tiles.
- Score = chip value − 2 × (tiles you hold that are not in the chip). Tiles not placed or not in the chip count as unused.
- If there are several microchips, the most valuable one counts (ties: the one with more tiles). No microchip: value 0.
- Ranking: highest score wins. Tie: the player whose microchip has more tiles wins (`playerScoreAux`). Still tied: shared win (the rulebook says "play again", which BGA cannot do; shared win is the closest thing).

## 2. Implementation decisions (where the rulebook is open)

| # | Topic | Decision | Confidence |
|---|---|---|---|
| D1 | Tile rotation | Allowed (4 orientations) by default. Game option 100 "Tile rotation": 2 = fixed as drawn. The rulebook says nothing; the photos show tiles in all directions | Medium: option lets the publisher settle it |
| D2 | Board layout | Generated per the rulebook recipe, one per player. The printed boards are not in the zip | High: the rulebook gives the recipe |
| D3 | Pool columns | Rightmost non-empty column is the draw column. Column-major fill | High |
| D4 | Turn vs arranging | Drawing is turn-based. Arranging is free for all players at any time (multi-active state) | High: rulebook says so |
| D5 | Illegal placements | Rejected by the server (not allowed to stage). Open paths facing empty spaces are allowed while building | High |
| D6 | "Biggest" microchip | The most valuable closed microchip counts, ties by tile count | Medium |
| D7 | Unused tiles | Every tile not in the scored chip costs 2, including tiles placed outside it | Medium: example in rulebook is consistent |
| D8 | Tie | Tile count of the chip, then shared win | Medium |
| D9 | Game end | Pool empty, then every player presses Finish. No timer | Medium: "a few minutes" in rulebook |
| D10 | Zombie (player left) | Their tile in hand (if drawing) is drawn for them, then they are out. Their tiles count as unused | Medium |
| D11 | Solo puzzle, championship | Not implemented in v1 | - |

## 3. Task list
- [x] Read rulebook, decide rules (this file)
- [x] `modules/php/Rules.php` pure rules + `tests/rules_test.php` (1482 checks)
- [x] Game.php, dbmodel.sql, states (Play, EndScore). Not run on a live BGA table yet (no framework here)
- [x] Game.js UI: pool, own board, tray, staging with Confirm, other boards (`tests/harness`: 27 turn + 12 build checks at 375 and 1280)
- [x] Colourblind symbols (pref 100), layout on phones, measured tap targets (draw tiles 55px, board spaces 65px at 375)
- [x] Art: tiles in SVG/CSS; `design/make_assets.py` for metadata images; `design/display.py` for the two display screenshots
- [x] GAMEHELP.wiki (MediaWiki), metadata.json, LICENSING_TRACKER.md row (licence NOT CONFIRMED)
- [x] `python3 tools/bga.py check microchip` with 0 FAIL (1 WARN: licence)
- [ ] Commit and push to `main`
- [ ] Sounds: none yet (no sound files). Optional for alpha.
- [ ] Live table test on BGA Studio: draw, arrange, finish, end scoring, zombie leave
