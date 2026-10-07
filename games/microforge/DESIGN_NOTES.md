# Design notes (ideas kept out of the rules for now)

## Triangular movement (parked)
Original rule: a piece pays n(n+1)/2 Credits for n total steps in a turn (1, 3, 6, 10), minus what it already paid. Effect: moving slowly is cheaper, so long marches are discouraged and forward hubs matter. Current rule is linear (1 Credit per step) for teachability. To bring it back: `Game::coinsForSteps` / `stepsFromCoins` in `Game.php` and `selCost` / `stepsFromCoins` / `moveCost` in `Game.js`. Good candidate for an Advanced-side option ("Forced march costs more").

## Market options (see chat summary; kept here)
- A. Fixed ports: no price tracks, each port prints its own fixed buy/sell price.
- B. One moving price (iron): ports apply a fixed printed bonus on top.
- C. Current: four price tracks (iron, bots, crystal, mechs) that move per item traded.

## Income (changed 2026-10-07)
Was a flat 10 Credits per turn. Now 1 Credit per tile held (all rules levels), start 2 Credits. Constant `BASE_INCOME` in `Game.php` is unused; `playerIncome()` counts held tiles.

## Market exploit (open)
A trade is priced at today's price for every item, then the price moves. Buying 10 and selling 10 in one turn between a Dock and a Port still profits. Fix options: per-item price steps, fixed port prices (market option A/B in BASIC_GAME.md), or a quantity cap per trade.

## Single sources
- Tiles: `design/tileset.py` (data) + `design/build_design.py` (drawing) -> `bga/img/tiles/*.svg` and `bga/modules/php/tileset.php`.
- Player mats: `design/boards.py` -> `bga/img/boards/*.svg`.
- Icons: `design/icons.py` -> `design/icons/`, `bga/img/icons/`.
Run `python3 design/build_design.py` after changing any of them. Never edit generated files by hand.
