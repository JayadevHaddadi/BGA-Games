# Omega — BGA Studio Implementation Notes

Status: **not started**. This file is a planning doc for whoever (human or agent) picks up the build. Source rulebook: `OMEGA_EN.pdf` in this folder.

## Licensing

Approved by nestorgames (Néstor Romeral Andrés) — see project memory `bga-studio-publisher-outreach`. He asked what art we need; we replied requesting vector/high-res art for the stones (4 colors) and board texture, and said we'll fall back to generic in-house models/icons if he doesn't have source files. Don't block the build waiting on art.

**Corner pieces are not needed digitally.** The physical game's 6 acrylic "corner" markers exist only to carve a smaller playing boundary out of one fixed oversized printed mat — a manufacturing workaround. On BGA we just render a hex grid at exactly the chosen size (5–10 per side) directly; there's no fixed mat to bound, so there's nothing to draw a "corner" onto. Nestor confirmed this himself (2026-09). Don't request corner-piece art or try to simulate them in the UI.

## Game summary

2–4 player abstract area-scoring game by Néstor Romeral Andrés (nestorgames, 2010), described by the designer as "a cross between Hex and Go." Played on a large hexagonal grid (physically adjustable from 5-hex to 10-hex side via 6 corner pieces). On your turn you place **one stone of every color currently in play** (not just your own) onto any free cells — so a 2-player game places 2 stones/turn, 4-player places 4 stones/turn. The game ends when a full round can no longer be played. Score per color = the *product* of the sizes of that color's connected stone-groups (not sum) — this multiplicative scoring is the whole tactical hook of the game and needs a dedicated, well-tested scoring function.

Notable optional rules: a pie rule (swap-color option) for 2p games, and a 2p tournament mode (play both colors, sum scores) — both are natural fits for BGA's game-options system.

## Core rules to get right

1. **Every turn places multiple stones** (one per color in play, all placed by the single active player) — this is unusual for a BGA turn structure. Model each "turn" as one action that places N stones atomically (N = player count), not N separate turns.
2. **Board size is a pre-game option**: 5 to 10 hex-per-side, chosen at table creation → maps directly to a BGA `gameoptions.jsonc` entry. Larger boards are explicitly flagged by the designer as advanced/high-score for 2p — could default to a mid-size (e.g. 7) rather than the max.
3. **Game-end condition** is a free-space threshold, not "board full": ends when there isn't enough free space left to complete one more full round (2p needs ≥4 free, 3p needs ≥9, 4p needs ≥16 — i.e. roughly `(players)²`). Implement this as an explicit check before each new round rather than assuming "board full = game over."
4. **Scoring**: for each color, flood-fill/union-find to find connected stone-groups, take each group's size, multiply all group sizes together per color. Highest product wins; ties broken in favor of the *last* of the tied players in turn order.
5. **Non-hexagonal board shapes**: the physical corner pieces can also carve out non-hexagonal outlines (e.g. a diamond) from the printed mat — not relevant digitally (see Licensing section above on corners). For v1, just render the standard hexagonal board at the chosen size; treat alternate outlines as a later enhancement, not a launch requirement.

## Suggested data model

- Hex grid keyed by axial/cube coordinate → `null | color`. Straightforward single-layer board (unlike Seven, no stacking).
- Union-find (disjoint set) per color, incrementally updated as stones are placed, to keep group-size lookups cheap instead of re-flood-filling the whole board every scoring check.
- Turn order is fixed by color (white, black, red, blue) — track "whose real turn it is" separately from "which color stone is currently being placed within that turn," since one player places all N colors' stones during their own turn.

## Open tasks before coding

- [ ] Decide default board size and whether to expose the full 5–10 range or a curated subset as the BGA game option.
- [ ] Confirm tie-break implementation ("last of the tied players wins" — need to define what "last" means precisely: last in turn order among those tied, presumably the player who would have moved last this round).
- [ ] Decide UI for a hex board of this size (up to a 10-side hex grid is a *lot* of cells) — will need efficient rendering (canvas/SVG rather than one DOM element per hex) given board sizes can run into hundreds of cells.
- [ ] Decide whether to support the pie rule and tournament mode as BGA game options at launch, or add later.

## Suggested project layout

Follow the modern template already used for `games/pushfight/bga/` and `games/lordsofscotland/bga/` (PHP 8 OOP + `#[PossibleAction]` attributes). See root `BGA_DEVELOPER_CHEAT_SHEET.md` for framework gotchas before starting.

```
games/omega/
├── OMEGA_EN.pdf               # official rulebook (already here)
├── IMPLEMENTATION_NOTES.md    # this file
└── bga/                       # to be created — Studio codebase (project name: omegatest)
```
