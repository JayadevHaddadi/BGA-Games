# Kiln: owner decisions and conventions

Moved here from the root AGENTS.md (Oct 2026). These are decisions by the owner or the designer (Néstor Romeral
Andrés) for Kiln only. The general rules they came with are in `AGENTS.md` and `BGA_REVIEWER_FEEDBACK.md`.

## Owner decisions (do not undo)
- **Pieces keep their glossy gradient and bevel look** (an exception to the flat-matte rule). Other surfaces follow
  the C.3 rules: no shadows, no looping glow, panels as matte paper/clay, highlights with `outline`.
- **Mobile**: below about 720 px the root gets `kiln_narrow` (same table arrangement, tighter gutters; the 2-player
  score track becomes a strip on top), scaled from the measured natural size to about 100 % of the width.
  **Do not stack all opponents in one column** (`kiln_compact` was rejected as looking bad).
- **Colour-blind glyphs**: the physical game's tile letters (`kiln_tiles.svg`): K = blue, I = green, L = yellow,
  N = red, X = black. Glyph filled with a much darker shade of the tile colour, **no outline**, about 42 % of the tile,
  as an inline SVG data-URI `background-image`. The real `I` is a plain rounded bar (no serifs: check
  `kiln_board_nestorgames3.png`). Shown on tiles, warehouse cells, the outer tile, player dots, score discs, and as a
  big glyph next to each name in the player panel (`setupPanelGlyphs()`, hidden by `.kiln_cb_off`).
- **Push arrows**: hollow block arrows (thin outline, shaft half the width, head full width), inline SVG `.kiln_arrow_svg`.

## Seat-relative board (designer rule)
- The shared kiln is rotated `-seat * 90deg` per viewer (seat 0 = south as stored, 1 = west, 2 = north, 3 = east), so
  each player sees it from their side. Slot ids, arrows and server logic stay absolute.
- Each tile (and the outer tile) is counter-rotated with `--kiln-face` so its letter faces the viewer. Opponent mats
  (`.kiln_rot_90/180/270 .kiln_wh_cell`) counter-rotate their cells the same way.
- The copied shape must match what the viewer sees: `Game::normalizeShapeForPlayer()` rotates the absolute group by
  the player's seat (`rotateShapeForSeat`; seat 1: (x, y) → (y, −x), 2: (−x, −y), 3: (−y, x)) before warehouse
  placement and validation. Test every seat with the harness (`?seat=0..3`).

## Turn rules (designer)
- Copying the largest group is **mandatory** whenever it fits (`actSkipPlacement` throws if any anchor exists).
- If it cannot fit: **Fix the Mess** (primary; only with the table option on and painted cells in the warehouse)
  erases 1 painted cell (`actEraseCell`). If the shape then fits, the player must place it; otherwise the turn goes on
  to selling or ends. **Skip Placement** ends the placement without erasing. One erase per push (`fix_mess_used`,
  reset in `actPushTile`). The status bar is the only message; no in-board popups.
- Then the player may pass on selling (`Pass (Keep Tiles)`), plus `Confirm end of turn` when nothing is sellable.
- Bug found once: the skip-erase button sent `actSkipFix` while the action is `actSkipFixMess`.

## Preferences
100 Colour-blind support (on), 101 Sound effects (on), 102 Confirm placement (ask; `0` places on tap,
`askConfirmPlacement()`), 103 Tooltips (on; `applyTooltipPreference()` also removes BGA's tooltip boxes),
104 Confirm end of turn (ask; `0` ends the turn automatically when nothing can be sold).

## Implementation pointers
- Sounds: `KilnSoundController` (`isMuted()` reads pref 101; 300 ms throttle; silent in replay/instantaneous mode).
- Tooltips: `setupTooltips()` (push and sell arrows, colour letters, tied groups); action-button texts in `buttonTip(id)`.
- Touch placement: `stagePlacement()` / `confirmPlacement()` (tap shows the ghost, blue Confirm, red Undo).
- Centring: 2p/3p/4p all use 220 px left and right columns; the empty seat is a spacer. Full-page texture:
  `body.kiln_page_bg` (dark brick wall from CSS gradients, low contrast).
- Measured tap targets at 375 px: kiln tiles and arrows 41 px, warehouse cells 34 px, sell arrows 34 px.
