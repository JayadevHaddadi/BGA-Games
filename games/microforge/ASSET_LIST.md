# Little Commanders — Full Asset List (IRL game + BGA)

Status: **done** = file exists and is usable, **plain** = plain placeholder version exists, **todo** = not started.
Generated files live in `design/` (rebuild with `python3 design/build_design.py`).

## A. Print / physical game
| # | Asset | Count | Size / format | Status |
|---|---|---|---|---|
| 1 | Box: front (cover) | 1 | art + title; box size TBD | concept only (Canva link, textless) |
| 2 | Box: back (blurb, components, player count, age, time, 3-4 screenshots) | 1 | | todo |
| 3 | Box: spine + sides | 1 | | todo |
| 4 | Box insert (trays for tiles, figures, cards, tokens) | 1 | | todo |
| 5 | Rulebook | 1 | A4/A5 PDF, ~8-12 pp final | plain draft (`rulebook/rulebook.tex` -> PDF) |
| 6 | Quick-start / one-page turn summary | 1 | | todo (quick reference exists at end of rulebook) |
| 7 | Land tiles | 37 (1 centre, 6 L2, 12 L3, 18 L4) | hex, flat-top, SVG | plain (`bga/img/tiles/land_*.svg`), art slot per tile |
| 8 | Home tiles (colour banner each) | 6 | hex | plain |
| 9 | Port tiles | 12 (8 distinct good/discount combos) | hex | plain |
| 10 | Tile back (if tiles are hidden/shuffled) | 1 design | | todo, optional |
| 11 | Mission cards | 40 (20 L1, 20 L2) | 63x88 mm + 3 mm bleed, SVG | plain (`design/missions/`) |
| 12 | Mission card backs | 2 | same | plain (L1, L2) |
| 13 | Player boards (one per faction) | 6 | A4 or 21x30 cm cardboard | todo (see C) |
| 14 | Market board (4 price tracks + mission row + deck spots) | 1 | 30x45 cm | todo |
| 15 | Reference sheet (turn, move cost, combat, trade) | 6 (or on player board) | | todo |
| 16 | Bots | 120 (20 per faction) | meeples/standees, 15-20 mm | concept (flat sheet) |
| 17 | Mechs | 36 (6 per faction) | bigger standees/figures, 30-40 mm | concept |
| 18 | Buildings: Extractor, Factory, Guard Tower | 5 each x 6 players = 90 | tokens/small figures | todo |
| 19 | Docks | 6 | tokens | todo |
| 20 | Iron tokens | ~60 | cubes/discs | todo |
| 21 | Crystal tokens | ~40 | gems | todo |
| 22 | Credit coins | ~120 (value 1/5/10) | | todo |
| 23 | Price markers | 4 | | todo |
| 24 | VP markers | 6 sets | | todo |
| 25 | Extractor-used markers (or flip token) | 30 | | todo |
| 26 | Faction standee bases / stands | 6 colours | | todo |
| 27 | Cloth/neoprene playmat (toy-box floor) | 1 | optional | todo |

## B. Illustration (feeds both print and BGA)
| # | Asset | Count | Status |
|---|---|---|---|
| 1 | Cover scene (kids attacking with bots and mechs) | 1 | concept |
| 2 | Commander portraits | 6 | concept (lineup v2) |
| 3 | Bot designs | 6 | concept |
| 4 | Mech designs | 6 | concept |
| 5 | Building art (Extractor, Factory, Tower, Dock) | 4 | todo |
| 6 | Tile art (empty / iron / crystal / centre / home / port) | 6 types x 2-3 variants | concept |
| 7 | Mission card art | 40 | todo (slots ready) |
| 8 | Faction symbol/emblem (colour-blind icon) | 6 | todo |
| 9 | Resource icons (iron, crystal, credit, VP, bot, mech) | 6 | todo |
| 10 | Playmat/table background | 1 | todo |

## C. Player board content (physical) — must teach the basic rules
The board should let a new player play without the rulebook: a turn-order strip (1 Income +10 Credits, Extractors refresh; 2 Build / make / move / trade / buy a mission in any order; 3 End turn, attacks resolve), icon-based cost tables, and the win condition (5 VP, VP only from missions). Layout is for when we get to it. Contents:
Supply slots for 20 Bots + 6 Mechs + 5 each of 3 buildings; Iron/Crystal/Credit storage area; faction art and symbol; build cost strip; move cost strip (1-3-6-10); combat strip (power, 2x push, 3x kill, tower +1); turn reminder (+10 Credits, Extractors refresh); faction power text.

## D. BGA digital
| # | Asset | Notes | Status |
|---|---|---|---|
| 1 | Tile sprites | reuse SVG tiles, flat | plain |
| 2 | Piece sprites: 6 bots + 6 mechs | readable at 32 px, distinct silhouette per faction | todo |
| 3 | Building + resource icons | | todo |
| 4 | Mission card SVGs | reuse `design/missions/` | plain |
| 5 | Colour-blind symbols (pref 100) | CSS, default ON | todo |
| 6 | Sounds (ogg + mp3, `sounds/`) | build, extract, move, attack, push, fail, trade, mission, win | todo |
| 7 | Themed table background (class on game area) | cardboard/toy-box, low contrast | todo |
| 8 | Metadata: box 280x280 | | `metadata_assets/` has old concept |
| 9 | Metadata: icon 50x50 (+500x500) | | todo |
| 10 | Metadata: banner 1386x400, NO text | | todo |
| 11 | Metadata: publisher logo 280x280 | | todo |
| 12 | Metadata: display images 1000x750 | needs real screenshots | todo |
| 13 | Metadata: title image 2000x2000 | | todo |
| 14 | `GAMEHELP` rules text | from the rulebook | todo |
| 15 | BGG listing images (cover, 3-5 screenshots) | wait for name decision | todo |

## E. Not art, but needed before launch
Faction power rules and balance tests; final name check on BGG; licence/ownership note for BGA alpha; GMM description + zombie level.
