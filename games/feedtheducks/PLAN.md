# Feed the Ducks: plan, rules as implemented, decisions

Source: FEEDTHEDUCKS_EN.pdf (Néstor Romeral Andrés, rules and artwork 2011, revisions by Nathan Morse), BGG 112532.
Studio project: `feedtheducks` (the owner must create it). Licence: ask Nestor to register (BGG 112532).
Inputs live in `design/` (rulebook, board art `board.jpg`, product photos, the solitaire puzzle concept PDF).

## Rules as implemented

* Board: 61 hexagons (columns of 5, 6, 7, 8, 9, 8, 7, 6, 5), the portrait orientation of the printed board
  (flat-top hexagons, columns A to I left to right, spaces numbered down each column: E5 is the centre).
* Players 2 to 4, one colour each in seat order: White, Black, Red, Blue (seat 1 = White and starts; no colour
  preference). Ducks per colour: 12 / 8 / 6. 24 ducks always fill the 24 edge spaces.
* Setup, option "Players place the ducks" (default): in table order starting with White, each player places ANY duck
  (any colour that has ducks left) on a free edge space, 24 times. After 24 placements (a multiple of the player
  count) the first player drops the first crumb.
* Setup, option "Random setup": the ducks are dealt at random on the edge, never 3 or more neighbours of the same
  colour in a row on the ring (the rulebook's "no more than 2 ducks of the same colour grouped together").
* Turn: drop the breadcrumb on an empty space. First turn: any empty space. After that: an empty space that is not on
  any of the six straight lines radiating from the crumb's previous space, and not that space itself.
  Then every duck on the six lines moves straight toward the crumb as far as it can: the nearest one ends next to
  the crumb, the next one behind it and so on (order kept, one duck per space, ducks already packed do not move).
  The six lines never share a cell, so the result does not depend on the order of the moves.
* End: checked for EVERY colour after each crumb. 2 and 4 players: all the ducks of one colour form one connected
  group (neighbours on the 6 directions). 3 players: at least 7 of 8 in one group; option "Goal (3 players)" =
  all 8 (the rulebook's advanced variant). One finished colour wins. Several at once: the player who dropped the
  crumb chooses the winner among them (state ChooseWinner; the mover may choose themselves if they are among them).
* 4 players: individual game (the rulebook has no teams).
* Score = size of the player's largest group (live, via `playerScore`). At the end the winner gets aux 1. If the
  chosen winner has a smaller group than another finisher (3 players: 7 against 8), the winner's score is raised to
  that value so BGA's ranking follows the rulebook's choice (nothing else in the ranking is invented).
* Zombie: random legal crumb (setup: random free edge space and a random available colour; choosing a winner: itself
  if it finished, else the first finisher).
* Progression: 0 to 10 % during the setup, then the best group over its goal (squared), with a slow creep so the bar
  never sticks.

## Decisions (topic, decision, confidence)

| Topic | Decision | Confidence |
| :--- | :--- | :--- |
| Dropping the crumb on the space it already occupies | Illegal ("take the breadcrumb and place it on an empty space"). If it were legal it would be a free pass: after one move the ducks on the lines are packed, so a repeat moves nothing. | medium |
| What the crossed-out spaces of the rulebook picture are | Exactly the empty spaces on the six lines of the previous crumb. Occupied spaces are not legal anyway. The space under the yellow dot carries no cross in the picture, we forbid it for the reason above. | high |
| Number of legal spaces | Always at least 12: 37 spaces are empty, at most 24 line spaces + the crumb's own space are forbidden. A turn can never be stuck (tested for every previous position). | high (proved) |
| Placement in the setup | Any colour, any player, as the text says ("placing any duck on an edge space"); the game shows one button per colour still available. | high |
| Random setup, "grouped" | Read as: no run of 3 or more same-colour ducks around the ring (also across the wrap). Dealt by rejection sampling. | medium |
| Turn order | Table order (the rulebook says counterclockwise; BGA has no direction). White (seat 1) starts. | high |
| Setup order | Starts with seat 1, goes round the table; 24 is a multiple of 2, 3 and 4, so seat 1 also starts the crumb phase. | high |
| Win check | After every crumb and for all colours, so a player can win on an opponent's turn (tested). A group already complete at the end of the setup is only noticed after the first crumb. | medium |
| Same-colour group | Neighbours on any of the 6 hexagon directions. | high |
| Simultaneous winners | The crumb player picks, may pick themselves. Equal groups keep equal scores; the pick gets aux 1. | high |
| Square pond option | NOT implemented. The puzzle PDF only describes a solitaire on an n x n grid (ducks on the non-corner edge cells, "ducks on the same row or column" move) with no size table, no illegal-crumb rule, no goal for 2 to 4 players. Inventing those would be inventing rules. | decision for the owner |
| Last-move marks | Newest crumb tinted with a double ring and trails, older ones dashed; `players - 1` of them (reviewer checklist D). | n/a |

## Questions for Nestor

1. May the breadcrumb stay on the same space for the next turn? (We say no.)
2. In the random setup, does "no more than 2 ducks of the same colour grouped together" mean runs of at most 2
   around the edge (our reading), or any group of three?
3. If a group is already complete after the setup (possible when players place the ducks themselves), should the
   game end before the first crumb? (We check after each crumb only.)
4. Does he want a square-pond variant on BGA? Please send the rules (size per player count, forbidden spaces, goal).
5. The rulebook says "7 (all but one)" for 3 players: we also offer his advanced variant (all 8) as an option.

## Interface

* Pond = the real board art (`bga/img/board.jpg`, cropped from design/board.jpg by `design/make_assets.py`, the
  geometry was fitted to the art: all 61 outlines match). Table background = the printed board's grass.
* Ducks drawn as SVG (one flat silhouette per colour, thin outline, a mark on the wing per colour for colour-blind
  players, preference 100). Ducks face the way they swim.
* Desktop: pointing at a space shows the preview (yellow arrows like the rulebook, ghost ducks at the destinations,
  the ducks that move faded) and a click drops the crumb. Phones: tap stages and previews, blue "Drop the breadcrumb
  on E5" confirms, red Cancel clears.
* Greyed spaces with a cross = forbidden; dots = legal. Last move: tinted spaces + double ring on the crumb.
* Splash ripples and sliding ducks 0.5 s, off with prefers-reduced-motion, in replays and instant mode.
* Player panel: duck icon and "group n of goal" (or "n to place" during the setup); tapping it outlines that colour's
  largest group. "?" opens a rules popup with icons. Final result list under the board and the winning group outlined.
* Sounds: splash, duck, win (`design/make_sounds.py`), preference 101.

## Tests

* `php games/feedtheducks/tests/rules_test.php`: geometry, 6 directions x (lone, edge, adjacent, packing), random boards,
  illegal spaces against an independent line test, groups and winners for 2/3/4 players, random setup (180 seeds),
  both setups and every refusal on the BGA stub, end of game, ChooseWinner, zombie, fuzz of whole games through the real
  `act*` methods.
* `tests/make_fixtures.php` writes REAL server data to `tests/harness/fixtures.json`; `tests/harness/frame.html`
  (375 and 1280 px) checks the client against it: layout, 43 px tap targets at 375, flows, previews against an
  independent movement rule, animations, finished-game reload.
* Not tested here: PHP inside the real framework. A live Studio table is needed (2 and 4 players, refresh mid-turn,
  zombie, replay).

## Nestor's answers (2026-10-27)
1. The crumb can never stay on its own space ("place it on an empty space ... not on any of the six lines radiating from the breadcrumb's previous location"). As implemented.
2. Random setup: no run of more than 2 same-colour ducks around the edge. As implemented.
3. A finished group right after setup is impossible because of (2). Checking only after a crumb is correct.
4. 4 players: each for themselves. As implemented.
5. Hex board only; square is only for the solo mobile puzzle. No square variant on BGA.
