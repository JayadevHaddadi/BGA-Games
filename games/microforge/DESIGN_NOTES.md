# Design notes (ideas kept out of the rules for now)

## Triangular movement (parked)
Original rule: a piece pays n(n+1)/2 Credits for n total steps in a turn (1, 3, 6, 10), minus what it already paid. Effect: moving slowly is cheaper, so long marches are discouraged and forward hubs matter. Current rule is linear (1 Credit per step) for teachability. To bring it back: `Game::coinsForSteps` / `stepsFromCoins` in `Game.php` and `selCost` / `stepsFromCoins` / `moveCost` in `Game.js`. Good candidate for an Advanced-side option ("Forced march costs more").

## Market options (see chat summary; kept here)
- A. Fixed ports: no price tracks, each port prints its own fixed buy/sell price.
- B. One moving price (iron): ports apply a fixed printed bonus on top.
- C. Current: four price tracks (iron, bots, crystal, mechs) that move per item traded.
