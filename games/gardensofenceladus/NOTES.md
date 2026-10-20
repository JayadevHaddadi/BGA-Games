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

### Status of the feedback above (2026-10-19)
- 1: not code; press "Reload game options configuration" in Studio.
- 2 done: types dealt at random in setup (stored in global `martian_assign`, 2 each in the 2-Martian variant); `actPlaceMartian(row, col)` only.
- 3 done: with one usable Martian the move targets are highlighted and tappable at once; selection only with two.
- 4 done: BGA tooltip on hover plus an info box above the garden on tap (name, effect, can/cannot use and why).
- 5 done: reserve under the board removed.
- 6 done: payment choice strip shows only colours with 3+ flowers; one colour is auto-selected.
- 7 done: status bar shows the own reserve (flower icons with counts) on your turn.
- 8 done: panel shows "Hand: 3-4-5-5-6" (the numeric score counter is hidden by CSS); end breakdown and end log line show the sorted hands. The integer key is unchanged. Not changeable: BGA's own end-of-game score table still shows the integer.

## Round 2 (2026-10-20)
Owner feedback after round 1, all in `Game.js`, the CSS, `Rules::handText` and the harness.
1. Full width on mobile: done. The side squares (start squares outside the garden) only take room while a Martian stands outside the garden (placement, and until each Martian's first move); after that the garden is 10 cells wide, 98.7% of a 375 px screen. Table padding is 0 on phones; `fitContainerToScreen` pulls the root to the screen edge. Harness checks the 95% share. Not done: while a Martian is still outside, the board keeps its side margins (the start squares must be visible).
2. VP back as the hand in digits ("00122", "34566"): done. Rule: digits glued together; if any count is 10 or more, all counts are separated by dashes ("3-4-5-10-12"). Shown in the panel next to the BGA star, in the end breakdown and in the end log line (`Rules::handText`, tested). The integer key still feeds BGA's score. BGA's own end-of-game ranking table still shows the integer.
3. Reserve ascending (panel and status bar): done.
4. Wildflower on its own row (panel always, status bar when held): done.
5. Flowers and numbers twice as big (panel 32 px / 24 px font, status bar 36 px / 28 px): done. Panel items stack flower over number so five colours fit the panel width.
6. Tube below the special actions with per-colour counts, updated by `tubeChanged`: done.
7. Payment choice directly below the special actions (above the info box and the tube): done.
8. Used special actions covered by a disc in the user's colour with their Martian, as in Gardens of Io: done (`used_by` was already stored and sent; `counter.png` is no longer used).

## Designer feedback (2026-10-20, Nestor / nestorgames)
1. Background too dark, Martians invisible while placing: done. The table is now pale icy blue-grey (`design/make_assets.py`, `table_background`), all play-area text and panels switched to dark ink on light panels. Every Martian sits on a round disc of the opposite tone (dark disc behind the white types bot and marty, pale disc behind the black types ali, bob and robby) ringed in its owner's colour.
2. Perimeter cells: start squares are now quiet pale tiles that fill exactly the side margin (7% of the board), each with a small arrow into the garden; shown while any Martian stands outside, tappable only during placement. No dashed boxes.
3. Faded special actions: availability now has one source, `Rules::specialBlocker` (reason: used, no_pay, tube, outside, no_target), sent as `specials[slot].reason`; the client text and the faded state both come from it (the client no longer re-derives conditions). Causes fixed: (a) the payment reserve change was only notified when flowers were picked, so after swaps/moves/jumps onto an empty square the client kept the old reserve and showed payment colours the server refused, with a stale status bar; `reserveChanged` is now always sent after a payment, and `myPayable()` uses the server list on your turn; (b) swap peak and flower counted a flower with a Martian on it as usable, then refused it; (c) the client had its own copy of the conditions (reserve, tube, Martian position) that could disagree with the server. New rules tests (section 8b) cover each condition. Martian specials (jump, diagonal, swap Martian) stay blocked while the Martian is still on a start square (decision D6).
4. Active Martian highlight: the active player's Martians get a ring in their colour: solid and thick for the active player, dashed for the other players.
5. Landing vs adjacent: landing = solid amber tint and outline (preview), solid blue with the Martian silhouette once chosen; adjacent flower = dashed teal ring with a small ring (preview), dashed blue after choosing. Last move: landing = tinted square with a double ring, adjacent = small dashed ring; with 3+ players the last (players - 1) moves are kept, older ones dashed (`last_moves` global, sent in `getAllDatas` and in `martianMoved`).
6. Sounds: files were valid (.ogg and .mp3, 0.05 to 0.34 s, no clipping) but the special sound was a 50 ms click and every notification of a turn played at once (move + pick + special overlapped). Regenerated with `design/make_sounds.py` (soft sine tones, 0.24 to 1.1 s, peak 0.3) and `sfx()` now queues sounds 220 ms apart (still confirmed notifications only, silent in replay, preference 101).
