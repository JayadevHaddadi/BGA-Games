# People: owner decisions and playtest notes

## Playtest 2026-10-21 (done)
1. Status bar: removed the transparent override on `#page-title`; the bar now has a solid paper background and dark text.
2. Humans per district: a grey person icon with the count big in the middle (sized from the board width, readable on phones).
3. Bulldozer "how many": `bga.dialogs.multipleChoice` popup with buttons 1..max (closing it leaves the bribe); a "Humans: N" side button reopens it. The inline take row is gone; Undo/Skip stay.
4. Bulldozer icon on the board, in the Bulldozer button and in the log.
5. Factory icon (sawtooth roof and chimney, as the physical piece) in the four player colours, with the colour-blind mark (pref 100).
6. Police icon (riot shield with star) on the Police button, as the shield marker and in the log.
7. 2 and 3 players: only the game area is shown (`cropBoard` in Game.js: 5x5 and 5x6 window onto the 6x6 grid). RULES UNCHANGED: the fence roadblocks are still created in setup (`Rules::fenceEdges`) and still come out of the 12-roadblock supply (`roadblocks = 12 - fence`, decision D14); they are only not drawn. Edges on the area border stay non-playable.
8. Roadblocks: red and white striped barrier across the street.
9. Player panel: bitcoin and factory icons with counts, about twice the size.
Icons: `design/make_icons.py` builds `bga/img/icons.png` (one sprite sheet, 9 x 2 cells); `make_assets.py` calls it. `bga/img/` holds 3 files.
Harness: `frame.html` runs the 2, 3 and 4 player boards and merges the reports; the script flow covers the popup, the cropped board and the icon sizes.

## Owner decisions (2026-10-21)
- Bribe step buttons: bribes grey, Undo red, **Skip blue** (owner choice, overrides the usual red-for-skip convention; do not change back). Six buttons in the bribe step are accepted by the owner.
