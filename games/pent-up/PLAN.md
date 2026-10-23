# Pent-Up on BGA (3D): plan

Rules: `PENTUP3_EN.pdf` (Néstor Romeral Andrés, 2003, revisions by Nathan Morse).
Official rulebook link: https://nestorgames.com/rulebooks/PENTUP_EN.pdf

## Studio project name

**`pentup`**. BGA project names are lowercase letters and digits only (no hyphen), so `pent-up` is not
valid. The display name in GMM is "Pent-Up". If Studio says `pentup` is taken, use `pentupgame`.
Repo folder stays `games/pent-up/`; the Studio code goes in `games/pent-up/bga/` and `tools/sync.py`
gets the mapping `pent-up -> pentup`.

## Rules to implement

- 2 players (white starts), 12 pentominoes each, all different. Free rotation and flipping.
- First tile: anywhere. Second: adjacent to it on the grid.
- Each turn place one unplayed tile, aligned to the grid, either on the table adjacent to a tile on
  the table, or on top of at least 2 tiles (any colour), with every square supported by the level
  directly below (flat, no overhang).
- **Mandatory highest level:** the tile must go on the highest level where it can legally go.
- End when all tiles are placed. Most tiles on the top level wins; ties go to the next level down.
- Options (later): 3 players (red set), advanced 3p scoring (level = points), misère (lowest level
  wins), Swiss variant (after placing your "+" tile, place another). 2v2 team variant last.

## Engine (PHP, server)

- State: `tile(player, shape, level, x, y, orientation)`; a sparse cell map `level -> (x,y) -> tile`.
  The table grid is unbounded in principle; clamp it to e.g. 30x30 around the first tile.
- Legal moves: for each unplayed shape x 8 orientations x anchor cells, test support and adjacency,
  then keep only the moves on the maximum legal level. Precompute and send them with the state
  (`args`), so the client only highlights valid placements. Worst case is a few thousand candidates;
  cheap in PHP.
- A player with no legal move: cannot happen on the table level (there is always room), so no pass.
- BGA score = tiles on the top level; tiebreak via `player_score_aux` encoding the lower levels.
- `zombie()`: random legal move. `debug_playToEnd()` using `$this->debug->playUntil()` for E2E.

## 3D client

- **Three.js**, bundled into the game's `modules/js/` (BGA does not allow loading code from a CDN).
  Orbit camera (rotate, zoom), tiles as extruded pentominoes with the indented border from the real
  set, soft shadows, wood-table background. Black and white tiles like the physical set.
- Placement UX: pick a tile from your tray, rotate/flip with buttons (and R/F keys), hover shows a
  ghost on the legal level snapped to the grid, green when legal; tap to place, confirm button on
  phones. Show level numbers on demand ("Show levels" toggle), and an X-ray slider that hides the
  levels above N.
- **Fallback:** a 2D top-down view (CSS, heights shown as numbers/shading) for weak devices and
  for replays/screenshots; a user preference toggles 2D/3D. Reviewers will check phone performance.
- Animations: tile flies from tray to place; end-of-game highlight of the winning level.

## Steps

1. User creates Studio project `pentup` (BGA Studio > Manage games > new project).
2. Agent A (Sonnet): PHP engine + `tests/` move-generator tests against the rulebook examples
   (the 1x5 + P example; Puzzle 1 reaching level 6 must be possible).
3. Agent B (Sonnet), in parallel: Three.js scene prototype from fixed sample positions.
4. Wire up, offline harness, deploy, add `pentup` to `tools/e2e/games.json`.
5. Assets: ask Néstor for real photos (display images, see `reports/display_images.md`), box image,
   and licence registration for Pent-Up.
