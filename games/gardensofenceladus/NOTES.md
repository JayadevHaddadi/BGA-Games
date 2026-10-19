# Gardens of Enceladus: owner notes

## Owner playtest feedback (2026-10-19), to fix
1. Option labels show raw keys in the table settings: `gameoptions_16189_Tube action (random flowers)` / `gameoptions_16189_Available (Standard)`. Option names/values must be plain translatable strings.
2. Martian type: no choice; assign a random type to each player.
3. With one Martian, don't make the player select it first: the move targets are active right away. Selecting a Martian only with two Martians.
4. Mobile: tapping a special action must explain it (name + effect) and say whether it is affordable (and why not).
5. Remove the reserve shown below the board; the player panel is enough.
6. Paying for a special action: a popup offering only the colours the player can pay with (3 of one colour).
7. Status bar shows the active player's reserve.
8. Score: the huge number (e.g. 1031699, base-21 encoding) is unreadable. Show the hand as sorted counts (e.g. 3-4-5-5-6) in the panel and the end screen; keep any encoding only internal for ranking.
Everything else: OK.
