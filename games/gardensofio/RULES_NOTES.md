# Gardens of Io: rules as implemented

Source: official rulebook from nestorgames (`https://nestorgames.com/rulebooks/GARDENSOFIO_EN.pdf`, © 2016 Néstor Romeral Andrés, edits by Nathan Morse).

Please check the items marked **CHECK** against your physical rulebook during testing.

## Components and setup

- Garden: 81 circles (9 x 9). Score track: 40 spaces around it, clockwise. One lap = 40 points.
- Track numbering: space 0 is the top-left corner, 1 to 9 run along the top row, 10 is the top-right corner, 11 to 19 down the right column, 20 the bottom-right corner, 21 to 29 along the bottom row, 30 the bottom-left corner, 31 to 39 up the left column.
- 60 flowers in 6 colours (10 each). **CHECK**: the rulebook does not name the colours. We use the flower art of the other garden games: blue, yellow, orange, purple, red and green. Each flower image has its own pattern and centre symbol, which is the colour-blind aid.
- Each player draws flowers at random from the bag: 2 players 27, 3 players 18, 4 players 14, 5 players 11. Unused flowers stay in the bag and are never planted.
- Each player gets a Martian type (Bot, Ali, Marty, Bob or Robby). The type does not matter for play, so it is assigned at random. The scoring Martian starts on the flying saucer next to space 0.
- Board art: the original board (`img/board.jpg`) and the special action counters (`img/specials.jpg`) are crops of `GOIO_BIG.jpg`. The publisher logo, title, designer name and flying saucer art are cropped out, because BGA guideline A.1 forbids in-game logos and branding and our own guidelines forbid credits in the play area. Credits belong in the game metadata.
- Trees: placed on empty circles by players 1, 2 and 3 in turn order (2 players: 1, 2, 1). Four and five player games still use 3 trees.
- Starting player: the first player in table order. **CHECK**: the rulebook says "any peaceful means".

## Turn

Each turn, in this order:

1. **Special action (optional).** Tap one of the five counters. Each counter works once in the whole game. Each player may use one special action per game (two in a 2-player game, both different). At most one special action per turn.
   - Swap a tree with a planted flower.
   - Swap two planted flowers.
   - Move a planted flower to another empty circle.
   - Move a tree to another empty circle.
   - Move your Martian 5 spaces forward. This is the only special action that scores (5 points). It also counts for the lap bonus.
2. **Plant.** From your Martian, a straight line runs diagonally or orthogonally into the garden. Plant one of your flowers on any empty circle on those lines, before the first tree. Flowers do not block lines.
   - Corner spaces have only the diagonal, because orthogonal lines from a corner run along the track (rulebook tip 6).
   - Side spaces have three lines: orthogonal and both diagonals.
   - If there is no empty circle on any line, skip to step 4 as if you had scored 1 point.
3. **Score.** Count the flowers of the same colour connected orthogonally to the one you planted, including it (diagonals do not connect). This is your score for the turn.
4. **Move.** Move your Martian forward by your score. Spaces with other Martians are passed over without being counted, and the Martian cannot end on an occupied space.
   - Crossing the start line between 0 and 1 completes a lap. We count this as stepping from space 40 onto space 41.
   - With the optional lap flower bonus enabled (game option), the player takes one flower from the board into their reserve. The flower can be of any colour that is not exhausted. This is the rulebook's optional rule in step 4 ("Optional (players must agree on the addition of this rule before the game starts): If your Martian just completed a lap, by crossing the start line between 0 and 1 ... take any one of the flowers already placed on the board except for those of a type that has already been exhausted ... and add it to your reserve"). **CHECK**: we treat the take as mandatory when an eligible flower exists.
5. **Extra turn.** If you planted the last flower of a colour, you take an extra turn.
   - **CHECK (important)**: "last" means all 10 flowers of that colour are on the board. Flowers still in the bag can never be planted, so some colours may never trigger this in a 2, 3, 4 or 5 player game.
   - If that planting empties your reserve, the game ends at the end of the turn and the extra turn is not taken.

Your Martian enters the track on space 0 on your first turn. **CHECK**: if space 0 is occupied, we place it on the next free space.

## End of the game

- **Reserve empty.** The game ends at the end of a turn in which a player has no flowers left in reserve.
- **Overtaking.** The game ends immediately if the leading Martian is a full lap (40 points) ahead of the last one. **CHECK (important)**: the rulebook says "if the leading player overtakes the player in last place". We read "overtakes" as lapping, because the leader is already ahead otherwise. This is only checked once every Martian is on the board.
- **Winner.** The Martian furthest along the track wins. Laps count, so the total points are the position. Ties cannot happen, because two Martians never share a space.
- **Cooperative variant (option).** The group score is the score of the lowest Martian. The rulebook asks the question "what is the highest score you can achieve?", and we score it that way.

## Options

- **Lap flower bonus (optional rule).** Off by default. The rulebook says players must agree before the game starts.
- **Game mode.** Competitive (default) or cooperative.

## Other interpretations

- Reserves are public information (flower counts shown on player panels), as the flowers are displayed in front of each player in the physical game.
- Turn order follows the BGA seat order, so it matches the table's clockwise order when seated clockwise.
- No tie-break is given by the rulebook, and none is needed in the competitive game.
