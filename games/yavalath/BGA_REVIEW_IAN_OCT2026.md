# Yavalath — BGA review feedback (Ian, Oct 2026)

## Issues and fixes

| # | Ian's point | What was wrong | Fix |
| :-- | :--- | :--- | :--- |
| 1 | Generic / AI-looking UI (C.3) | Green/red/grey pill badges, emoji in buttons (`✔ ↺ 🔊`), glossy stones with shine ellipse, pulsing/rotating glow rings, rounded card board with big shadow | Pills removed (one plain rules line instead). Matte ivory / ebony / lacquered-red stones with a turned-edge ring. Static last-move and staged rings. No looping animations; `prefers-reduced-motion` respected. Button labels are plain text ("Confirm move", "Undo"). |
| 2 | Mobile layout and touch targets (C.4, E.3) | Board SVG had a 620px square with large empty margins, so hexes shrank on phones. Header buttons were about 26px tall. Duplicate Swap button in the play area. | Tight, hexagon-shaped board that fills the screen width (ResizeObserver scaler). All in-area buttons removed (Swap, Confirm and Undo live only in the BGA action bar). Hex hit areas measured at 375px wide: Compact 46×53px, Standard 37×42px, Five-not-four 30.5×35px. |
| 3 | Hover-triggered sounds (D.4) | Custom Web Audio synth, with a sound on tap-to-stage and its own mute button | Real `sounds/yav_place`, `yav_win`, `yav_lose` (`.ogg` + `.mp3`) played through `bga.sounds`. They fire only on confirmed events, never on hover or staging. The custom mute button is gone (BGA's own sound settings apply). |
| 4 | Thematic background (C.5) | Flat beige rounded square | Walnut-grain table background, maple hexagonal board with wood grain and recessed cells, like the real board. |
| 5 | Credits in gameplay area | "Invented by Cameron Browne & Ludi, Published by nestorgames" under the board | Removed from the play area. Publisher is in `gameinfos.jsonc`. **Add designer, artist and credits in the Game Metadata Manager.** |

## What to test (Studio, after the deploy finishes)

Desktop:
1. Start a 2-player table. Board is a wooden hexagon on a walnut background. No credits text or pill badges anywhere in the play area.
2. Hover cells: ghost stone appears, **no sound**. Click a cell: stone appears faded with a dashed ring, **no sound**. The action bar shows blue "Confirm move" and red "Undo" on the right.
3. Confirm: soft placement sound plays once. Opponent's move also plays it once. Last-move ring is static and persists after F5.
4. Make a 3-in-a-row (lose) and a 4-in-a-row (win). Highlights are static green/red and the lose/win sound plays once, not doubled.
5. Pie Rule option on: Player 2 sees "Swap Colors (Pie Rule)" **only** in the action bar (secondary/white), and swap works.
6. BGA's sound toggle (top bar) mutes the effects.

Mobile (real phone or browser devtools at 375×812, portrait and landscape):
7. Board fills the screen width with no horizontal scroll, in all 3 board sizes (Compact, Standard, Five-not-four).
8. Tapping a cell stages the stone (no hover leftovers). Tapping a nearby cell moves it. Confirm/Undo work with a thumb.
9. Landscape phone: the whole board is visible without cropping.
10. 3-player game: elimination plays the lose sound once and play continues.

## Draft reply to Ian

> Thanks Ian, I've gone through each point and pushed an update to the Studio `yavalath` project:
>
> - **Generic/AI look:** removed the badge pills, emoji labels, glossy stones and looping glow animations. The board is now a wooden hexagon (like the physical game) with matte ivory/ebony/red stones and static markers.
> - **Mobile / tap targets:** the board now fills the screen width. All controls in the play area are gone and Confirm/Undo/Swap live only in the action bar (blue/red/white per C.3). Cells measure 46px (Compact), 37px (Standard) and ~31×35px (the largest 91-cell variant) at 375px wide. The move is also staged, so a mis-tap can be undone before confirming.
> - **Sounds:** hover sounds are gone. Sounds are now real files in `sounds/`, played through `bga.sounds` only for confirmed moves and results, so they follow the player's BGA sound settings.
> - **Theme/background:** walnut table background with a maple hex board.
> - **Credits:** removed from the play area. They'll be in the Game Metadata Manager / game info only.
>
> Could you take another look when you have a moment?

## Pending / needs you

- **Add designer (Cameron Browne), publisher and credits in the GMM**. BGA reads these from there, not from the play area.
- If Ian objects to the ~31px width on the Five-not-four board at 375px, the fallback is to drop the 5/6 variant on screens narrower than 380px, or add pinch-zoom.
