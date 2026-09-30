# Seven — BGA Studio Implementation Notes

Status: **not started**. This file is a planning doc for whoever (human or agent) picks up the build. Source rulebook: `SEVEN_EN.pdf` in this folder.

## Licensing

Approved by nestorgames (Néstor Romeral Andrés) — see project memory `bga-studio-publisher-outreach`. He asked what art we need; we replied requesting vector/high-res art for the 7 tetrahex shapes per color, and said we'll fall back to generic in-house models/icons if he doesn't have source files. Don't block the build waiting on art — start with generic hex tiles and swap in official art later if it arrives.

## Game summary

2-player (3p and 2v2 variants exist) abstract stacking game by Néstor Romeral Andrés (nestorgames, 2013). Each player has one full set of 7 distinct **tetrahexes** (4-hex polyhex shapes — there are exactly 7 free tetrahexes, hence the name) in their color. Players alternate placing one of their own tiles onto a shared hex grid according to placement rules, building upward in layers. When all 14 tiles (7 per player) are placed, whoever has more tiles on the topmost layer wins (ties broken by the next-highest layer, etc.).

## Core rules to get right (read the PDF closely before coding)

1. **Alignment**: every tile must align to a single implicit hex grid shared by both players (not floating/offset).
2. **Mandatory highest level**: a placed tile must go on the *highest level it can legally occupy*. This is the trickiest rule to implement correctly — re-read the worked example on page 1 of the rulebook (the "Level 2 Y forced" example) and consider checking the BGG rules forum for Seven before finalizing move-legality logic. Get this rule's exact scope confirmed (is it "highest level reachable by any legal move right now" or "highest level reachable by this specific piece shape") before writing the legal-move generator — the example implies the former, but verify.
3. **Table/support adjacency**: a tile must be placed either on the table and touching an existing placed piece (any color), or resting on top of at least 2 lower tiles.
4. **Flat & fully supported**: a tile occupies 4 hex cells, all at the *same* level, and every one of those 4 cells must already be filled up to level-1 (by table or a lower tile) — no overhangs.
5. **Misère variant**: lowest level wins instead of highest — worth exposing as a game option (BGA `gameoptions.jsonc`) rather than a separate game.
6. **3-player and 2v2 variants**: use a 2nd tile set in red/blue. Could be a `player count` option in BGA metadata; the 4-player free-for-all is explicitly *not* recommended by the designer (too chaotic) — don't implement it as a selectable mode.

## Suggested data model

- Represent the board as a **hex height-map**: a dict keyed by axial/cube hex coordinate → current top-occupied level (0 = empty/table). No need to store a full 3D voxel grid — since every tile lies flat, a cell's state is fully described by "what level is currently on top here" + "which tile/color owns that top layer."
- Separately store the list of **placed tiles**: `{tile_id, shape_id, orientation, color, level, cells:[4 hex coords]}` — needed for win-condition counting (per level, per color, count of *tiles* — not cells — occupying that level) and for rendering/undo.
- **Piece inventory per player**: which of the 7 shapes they still have unplaced (each shape appears exactly once per color per game — not multiple copies).
- Legal-move generation: for a candidate tile shape + orientation + anchor position, check rules 3+4 above to find its legal level(s), then apply rule 2 to filter to only the maximum.

## Open tasks before coding

- [ ] Precisely enumerate the 7 tetrahex shapes as hex-coordinate offsets (with their rotations, and note which shapes are chiral/need a "flip" — the rulebook says "some tiles are not symmetric and can be placed either side up").
- [ ] Nail down the exact scope of the "highest level" mandatory rule (see point 2 above) — this drives the whole move validator.
- [ ] Decide board bounds — the physical game is unbounded/table-limited; digitally we need a fixed hex grid large enough that legal placement is never blocked by our own grid edge (rulebook doesn't specify a board size since it's just "the table"). Suggest a generously sized hex grid (e.g. radius 10-12) rendered but mostly empty.
- [ ] Rendering: need an isometric or layered visual to show stack height clearly — this is the main UI challenge (BGA board games are normally 2D top-down; a stacking game needs some visual trick — consider showing per-cell height as a number/color-tint badge, plus a "peel back layers" or level-filter toggle, rather than true 3D).

## Suggested project layout

Follow the modern template already used for `games/pushfight/bga/` and `games/lordsofscotland/bga/` (PHP 8 OOP + `#[PossibleAction]` attributes), not the legacy Mandala Dojo template — this is a fresh game with no legacy code to carry forward. See root `BGA_DEVELOPER_CHEAT_SHEET.md` for the framework gotchas (zombie method, stats API parameter order, Game Metadata Manager asset specs, etc.) before starting.

```
games/seven/
├── SEVEN_EN.pdf              # official rulebook (already here)
├── IMPLEMENTATION_NOTES.md   # this file
└── bga/                      # to be created — Studio codebase (project name: seventest)
```
