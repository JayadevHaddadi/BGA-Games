# Little Commanders (games/microforge) design review

STATUS: final (wrapped up early on request; Advanced-rules price-recovery test was NOT completed).
Evidence tags: [SIM] = measured in headless simulation of the real Game.php (greedy scripted policies, 480-3200 games per cell, many more earlier); [CODE] = from reading code; [REASON] = judgement, untested. Caveat: sim opponents do not adapt or defend well, so combat numbers are upper bounds for aggression; human play will differ.

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


## 5. Last results (SIM)
- "Miner" policy (build 3 Extractors next to Ports, extract each turn for 1 Credit, sell at the fixed price) beats expanders in Basic at every count: 77/83/73/72/67% (2-6p; fair 50/33/25/20/17) under current rules and 79/83/71/72/59% under rec. Selling raw goods at the fixed price with no fee or limit is a money machine, like the bot arbitrage.
- Sell price = 50% of buy price (iron 1, crystal 2, bot 2, mech 5; rounded down) neutralises miner and arbitrage: miner 52/34/27/28/24% (cur_half) and 47/37/29/29/23% (rec_half). Buy-only (no selling at all) overshoots: miner 15-30%, i.e. extraction becomes useless. So halve the sell price rather than ban selling.
- Advanced (L2), after dead-mission fix: only 66-83% of games finish within 100 turns/player-cap, 2p seat 1 wins 66%. Cause (SIM symptom + CODE): prices only move with trades and never recover, so costs ratchet up. A recovery rule was drafted but not tested.
- Leader at 60% of the game wins 82% (2p) but only 41-54% (3-6p): little runaway at 3-6p, strong snowball at 2p. Greedy bots take about 2.4 actions per turn; credits are mostly spent (about 8 at mid game), so Credits are the constraint; resources/buildings are not used by the best greedy line.
- Winner correlation: claims 0.66-0.73; building/production counts about 0.

## 6. Balancing issues per player count
| Players | Findings |
|---|---|
| 2 | [SIM] Seat 1 wins 61% even with equal turns; +2 starting Credits for seat 2 gives 49/51. Mech raider wins 74-79% today (rec: 49%). Leader snowballs (82%). [SIM] Map 19 tiles is cramped: raiders end with 3.6 tiles vs 4.4 for expanders. |
| 3 | [SIM] Immediate-end gives seat 1 53% (vs 33% fair); equal turns fixes it (32/35/33). Raider 40-44% today, 33% under rec. Kingmaking is possible at the attack choice [REASON]. |
| 4 | [SIM] Fair with equal turns (21/25/26/28, mild late-seat edge). Raiders lose (13-18% vs 25%) so war is not worth it; fin/miner dominate unless sell price is fixed. |
| 5 | [SIM] Fair seats; 10 rounds = 50 turns [REASON: long downtime for a game with almost no interaction]. VP target 4 gives 8.8 rounds. |
| 6 | [SIM] Fair with equal turns; raiders 14-19%; 60 turns per game today, 53 with VP 4. [REASON] Downtime and a crowded 37-tile map (6 tiles each). Only 75% of games finish today (dead missions). |
General: [SIM] elimination is rare in mixed tables (0.02-0.2 players per game) but 0.4-1.3 in all-raider tables, and an eliminated player has no way back [CODE: no pieces means no control, no actions].

## 7. Bugs and defects found
1. [CODE+SIM] 'ports' missions (3 cards) can never be met: Port tiles cannot be entered, and the condition checks the Port tile's owner. 'double_tiles 2' is nearly unreachable.
2. [CODE] pushOptions ignores roads and blocked edges and lets pushed pieces flee onto Port tiles (which then become "controlled", an accidental way to satisfy ports missions).
3. [CODE] Game ends mid-turn at 5 VP; gameinfos tie-break text (Credits, mechs) is unreachable. An attack declared that turn is never resolved.
4. [CODE] README.md is a stale concept doc (dice-free tactics cards, 2x N ports, Guard Towers); rulebook v0.3 and GAMEHELP match the engine. Guard Tower is removed in the engine but still mentioned in comments/ALPHA_CHECKLIST.
5. [CODE] Advanced batch pricing (whole batch at the old price) is exploitable; a per-item price fixes it (tested as advfix).
6. [CODE] The JS duplicates combatOutcome; any combat change must be made in both Game.php and Game.js.
7. [CODE] A mission can be bought without meeting it (fee lost); a UI that previews "met / not met" is essential.

## 8. Recommendations
### Must do
1. Replace dead missions (ports x3, double_tiles 2) and fix pushOptions (roads, no Ports). [SIM+CODE] Finish rate 79-96% to 98-100%.
2. Play out the round after someone reaches 5 VP (all players get equal turns); tie-break VP, then Credits, then mechs+bots, as gameinfos already says. [SIM] Removes the 3-6p seat bias (3p 53/28/19 to 32/35/33).
3. 2p: seat 2 starts with 2 Credits. [SIM] 61/39 to 49/51.
4. Sell price = half the buy price, rounded down, for every good (iron 1, crystal 2, bot 2, mech 5). [SIM] Kills arbitrage (94-100% win to 14-22%) and mining (67-83% to 23-52%). Keep fixed buy prices.
5. Combat: removal needs 3x defender power and removes the defender (no push, no retreat step). [SIM] 2p raider 74-79% to 49-54%, 3p 40-44% to 33-45%; also deletes the ChoosePush state, the retreat UI and the flee bugs. Mirror it in Game.js.
6. Advanced is not releasable as is: either hide the option until prices recover over time and the seat bias is checked, or ship Basic only for alpha. [SIM]
### Should try
7. Add two conflict missions (hold an enemy Home; strongest army) and raise decks to 14 per level so 6p cannot run dry. [SIM] Raiders at 4-6p move from 8-14% to 11-25%, still not above fair, so war stays optional; consider a VP-bearing reward for taking a Home. 
8. VP target 4 at 5-6 players (5 at 2-4). [SIM] 8.8 vs 10.1 rounds, seats flat, lead changes 1.5-1.9. 
9. Respawn: a player with no pieces gets 1 free Bot on their Home at turn start. [REASON; elimination is rare in sim but lethal in all-aggressive tables]
10. BGA: default option set (Basic, finish round), mission "met / not met" preview, a turn summary log at 5-6p, consider a faster default clock at 5-6p. [REASON]
### Experimental
11. Teams for 4 (2v2) and 6 (3v3 or 2x3) with shared VP and no friendly fire; reduces downtime planning but untested. [REASON]
12. Cheaper Level 2 missions or 3 VP for L2 (they are only ~20% of claims). [SIM observation, fix untested]
13. Triangular movement as the Advanced option (already in DESIGN_NOTES), plus per-round price recovery. [REASON]
14. Asynchronous-friendly: since nothing interacts until contact, a simultaneous planning phase for turns 1-3 at 5-6p. [REASON]

## 9. Critique of the proposals
- The 50% sell spread is blunt: it makes trade almost useless as income, leaving tile income as the only engine; the buy side (iron 3, bot 5) then matters. Risk: game feels like "buy bots, hold tiles, buy missions". Mitigation: leave Extractors worth building by letting extracted tokens count toward token missions (already ~10% of claims).
- The 3x kill rule helps 2p but at 4-6p war was already unattractive; it may make aggression even rarer. The conflict missions only partly offset this. Playtesting with real players is needed, since the sim opponents do not defend well.
- +2 Credits for seat 2 and VP 4 are tuned against greedy policies; re-check after any mission or income change.
- Not simulated: price recovery for Advanced (patch attempt not verified), teams, respawn, async phases.

## 10. Reproduction
Scratchpad only (not in the repo): lc/sim.php, smart2.php, smart3.php, run3.php, mkvar.py (variants), par.sh, tab.py. Variant list: cur, rec, rec_half, rec_home2, rec_s1/sh/s2p/2p2, rec_vp4, rec_adv, cur_half, rec_buyonly.
