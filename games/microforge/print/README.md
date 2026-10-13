# Print and play (4 players, black and white, A4)

`little_commanders_print.pdf` (13 pages) is generated, do not edit it. Rebuild after any rule or art change:

    python3 design/build_design.py && python3 design/print_sheets.py

Print at 100% ("actual size"). Pages:

| Page | Content |
|---|---|
| 1 | How to print, setup, turn summary |
| 2-3 | **Ready-made 4 player board**, left half on page 2 and right half on page 3 (A4 portrait, 34 mm hexagons): 33 land tiles, 4 homes, 8 ports, balanced and connected, coordinates outside. Cut out the hexagons, lay them back as printed. Tiles marked `2P 3P` (and `home 1/2/3`) are the ones a 2-3 player game uses |
| 4 | 6 spare ports (only needed for 2-3 players), with the coordinate they go to printed under each |
| 5 | Bots and Mechs of 4 players, Docks, price markers |
| 6 | Shared Extractors and Factories, Credit coins, iron, crystal, VP |
| 7 | Market, A5 x 2: Basic side (top) and Advanced side (bottom) |
| 8-9 | 4 commander boards, A5, Advanced side, two per page |
| 10-13 | 36 mission cards (Guard Tower and centre-hex cards are left out, they cannot be played) |

Marks: player 1 = triangle, 2 = circle (grey), 3 = square, 4 = diamond (grey). The board layout is a fixed, searched set-up
(`design/print_board.py`); BGA deals its own random board, with the same home and port spots.
