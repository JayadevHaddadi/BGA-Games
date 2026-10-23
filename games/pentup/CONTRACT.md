# Pent-Up: server/client contract

Both the PHP engine and the JS client follow this file exactly. Change it only together.

## Grid and coordinates

- Integer grid, `x` to the right, `y` down, `0..39` on both axes. Levels start at 1 (the table).
- A placed tile occupies 5 cells at one level.

## Shapes (base cells, x,y)

```
F: (1,0) (2,0) (0,1) (1,1) (1,2)
I: (0,0) (0,1) (0,2) (0,3) (0,4)
L: (0,0) (0,1) (0,2) (0,3) (1,3)
N: (1,0) (1,1) (0,2) (1,2) (0,3)
P: (0,0) (1,0) (0,1) (1,1) (0,2)
T: (0,0) (1,0) (2,0) (1,1) (1,2)
U: (0,0) (2,0) (0,1) (1,1) (2,1)
V: (0,0) (0,1) (0,2) (1,2) (2,2)
W: (0,0) (0,1) (1,1) (1,2) (2,2)
X: (1,0) (0,1) (1,1) (2,1) (1,2)
Y: (1,0) (0,1) (1,1) (1,2) (1,3)
Z: (0,0) (1,0) (1,1) (1,2) (2,2)
```

## Orientation `o` in 0..7

1. If `o >= 4`, mirror: `(x,y) -> (-x,y)`.
2. Rotate `o % 4` times by `(x,y) -> (-y,x)`.
3. Normalise: subtract min x and min y, so the bounding box starts at (0,0).

Cells of a placement = normalised cells + `(x,y)` anchor. Symmetric shapes produce duplicate cell
sets for different `o`; the server keeps only the lowest `o` per distinct cell set in `possibleMoves`,
but accepts any `o` whose cells match a legal placement.

## Legality

- Level 1 (table): all 5 cells free at level 1 and inside the grid. The very first tile of the game:
  anywhere. Afterwards: at least one cell edge-adjacent to a level-1 cell of another tile.
- Level L >= 2: all 5 cells free at level L, every cell is occupied at level L-1, and those
  supporting cells belong to at least 2 different tiles.
- Mandatory height (per tile): a shape may only be placed on the highest level at which THAT shape
  has any legal placement. So the legal moves of one turn can be on different levels for different
  shapes.
- Game end: all tiles placed. Ranking: most own tiles on the top level (highest level that has any
  tile); ties broken by the next level down, and so on.
- BGA score = own tiles on the top level. `player_score_aux` = lower levels encoded
  (count on top-1 * 10000 + count on top-2 * 100 + count on top-3), enough for tie display.

## Data to the client

`getAllDatas()`:
```
tiles:  [{id, player_id, color, shape, o, x, y, level}]   // placed tiles
hands:  {player_id: ["F","I",...]}                        // unplayed shapes
gridSize: 40
```
`color` is the player's colour name `white` / `black` (later `red`).

State `playerTurn` args:
```
possibleMoves: {"F": {"level": 2, "moves": [[o,x,y], ...]}, ...}   // only shapes still in hand
```
Action: `actPlace(shape: string, o: int, x: int, y: int)` (level is computed by the server).

Notification `tilePlaced`: `{player_id, player_name, tile: {id, player_id, color, shape, o, x, y, level}}`,
message `${player_name} places a tile on level ${level}`.
Notification at the end: `finalLevels`: `{levels: {level: {player_id: count}}}` for the end screen.
