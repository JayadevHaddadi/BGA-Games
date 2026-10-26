# Little Commanders (games/microforge) design review

STATUS: partial (written early so progress survives an interruption); refined below as the work continues.

## 1. Rules as implemented (check for misreadings)
Source: bga/modules/php/Game.php (the engine is authoritative; README.md is a stale concept doc, rulebook v0.3 matches the engine).
- 2-6 players. Board: 19 land tiles (2-3 players) or 37 (4-6). Each non-centre tile has exactly one blocked edge. Home tiles and the centre are fully open. The centre can be crossed but not stopped on. 2 Port tiles per player outside the land.
- Start: 0 Credits, 3 Bots, a Dock, 2 iron on your Home. First player gets their income before turn 1.
- Turn (no action limit, any order): income = 3 + 1 per land tile you control (a tile is controlled while one of your pieces stands on it), then: Build (iron on the tile pays: Extractor 1, Factory 2; shared pool of 3 of each per player), Extract (once per Extractor per turn, 1 Credit = 1 token), Manufacture (once per Factory per turn, 1 Credit: 1 iron -> 2 Bots, or 1 iron + 1 crystal -> 1 Mech), Move (1 Credit per step for the whole group; resources only travel with a Bot at both ends), Trade (Dock: iron+bots; Port next to a held tile: all 4 goods in Basic, 2 in Advanced; unlimited quantity, no fee), buy ONE Mission, undo. Attacks declared by walking in are resolved when the turn ends.
- Prices Basic: iron 3, crystal 4, bot 5, mech 10, same for buying and selling. Advanced: start the same, move 1 per item after the whole batch (batch is priced at the old price), one direction per good per turn.
- Missions: 5 face up. Pay 5 + 1 per mission already bought; if the condition holds right now you get 1 VP (Level 1) or 2 VP (Level 2), otherwise the Credits are lost. Deck = 10 random L1 over 10 random L2. First to 5 VP wins immediately, mid-turn (no equal-turns rule; tie-break text in gameinfos is unreachable).
- Combat: bot 1, mech 5. Attacker total >= 2x defender power pushes (defender chooses the retreat tile), >= 3x kills; otherwise the attackers return. Piece with nowhere to go dies. Player with no pieces cannot control any tile or do anything (no recovery path).

## 2. Method
Real Game.php run headless on the shared SQLite stub (tests/stubs.php), policies in the scratchpad (lc/sim.php, smart2.php): random, turtle, expand (grab tiles, buy bots, buy missions when the condition is met), indus (factory economy), raider/mraider/indraid (bots/mechs attack), arb/fin (market arbitrage), mixed tables with one special seat rotating. Variants are patched copies of Game.php (lc/mkvar.py). Thousands of games per configuration; "finish" = the round is completed after someone reaches 5 VP (tie-break VP then Credits).

## 3. First findings (simulation unless marked)
1. DEAD MISSIONS (code reading + sim): the 'ports' missions (3 of 40 cards) can never be met, because the engine counts ports whose own tile is held and a Port tile cannot be entered. 'double_tiles 2' is almost unreachable. Replacing them lifts finish rate (games ending before the 100-turn cap) from 79-96% to 98-100% at 3-6p Basic and from ~45-70% to ~90% in Advanced.
2. First-player advantage (sim): immediate end at 5 VP gives seat 1 about 60/40 at 2p, 53/28/19 at 3p, 33/23/23/21 at 4p. Completing the round (equal turns, tie-break Credits) removes it at 3-6p (3p 38/28/34, 4p 26/../30, 6p 19/../15); 2p stays 55-62% for seat 1 and needs +1 starting Credit for seat 2.
3. Game is a non-interactive mission race (sim): greedy "expand" (move, buy bots, buy missions) wins by round ~10 with 0 attacks, 0 buildings. corr(win, claims)=0.66-0.73; combat/building usage has ~0 correlation with winning. Level 2 (2 VP) missions are ~20% of claims.
4. Market arbitrage (sim): iron(3) -> Factory (1 Credit) -> 2 bots sold at 5 = +6 Credits per Factory per turn. With bots/mechs not sellable the money pump disappears (arb policy falls from 65-70% to 4-22% win share); a 60% sell spread is NOT enough (still 65-70%), a 40% spread is.
5. Combat (sim): at 2p a mech raider wins 77-79% against an expander in every variant tried (strongly dominant); at 3p 35-51%; at 4-6p it loses (8-14% vs fair 17-25%). So war is overpowered at 2p and a tax at 4-6p. Adding conflict missions (enemy home held, strongest army) makes 4-6p raiding fair (16-19%) but leaves 2p at 77%. Push 3x/kill 4x makes raiding lose everywhere (38% at 2p, 22% at 3p, 4-11% at 4-6p).

## 4. Interim results (large runs, 480-3200 games per cell, "finish the round" on)
Variants: cur = current rules + finish-round; rec = fixed dead missions + 14-card decks + conflict missions (hold an enemy Home, largest army) + bots/mechs not sellable + defenders need 3x power to be removed (push 3x = kill 3x).
- Arbitrage player ("fin": iron -> Factory -> sell bots) wins 94-100% of tables against greedy expanders at every player count under current rules (finishes in ~8 rounds instead of 10.5). Under rec it wins 6-16%.
- Mech raider vs expanders (fair = 50/33/25/20/17%): current 74/40/13/15/18%; rec 49/34/15/13/-%. Raiding is a 2p killer today and a tax at 4-6p even after fixes.
- 2p seat 1 wins 61% even with finish-round; giving seat 2 +2 starting Credits gives 49/51 (3200 games). At 3-6p finish-round alone gives flat seats (3p 32/35/33, 6p 15/17/17/17/16/18).
- VP target 4 at 4-6p: 8.8 rounds instead of 10.1, seats stay flat.
(Details and the remaining Advanced / miner experiments follow in the final version of this file.)
