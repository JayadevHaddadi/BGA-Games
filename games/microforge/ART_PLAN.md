# Little Commanders — Art Plan

Two outputs from one art set: **(A) IRL prototype/print** and **(B) BGA digital**. Draw once as flat vector (SVG) so both reuse it.

## Style rules (so it passes BGA review AND prints well)
- Flat matte fills, thin dark outline, storybook toy look. No gloss, shadows, glow, emoji.
- Each faction = own colour AND own silhouette (colour-blind safe, BGA pref 100).
- Pieces readable at 32px (BGA mobile) and 15mm (IRL figures).
- Kid-made world: toy-box wood, cardboard, crayon, bedroom-floor playmat. Mechs are scary-cute.

## 1. Factions (6) — the asymmetric identity
Per faction we need: commander portrait, bot, mech, colour + symbol, faction icon.
Red Rocket (dragon mech) · Blue Tide (crab) · Green Garden (beetle) · Yellow Spark (tower) · Purple Night (owl) · Orange Junkyard (scrap giant).
Open: do factions get a small rule perk (e.g. 1 starting tweak) or art only?

## 2. Board tiles (IRL cardboard hexes / BGA SVG)
| Item | Count | Notes |
|---|---|---|
| Grey empty tile | ~25% of 37 | plain floor/rug |
| Green iron-mine tile | ~50% | ore/iron look |
| Blue crystal tile | ~25% | crystal cluster |
| Level markers | levels 1-3+ | ring/centre tile art differs (centre = 6 paths) |
| Building slot marks | 0-2 per tile | printed sockets |
| Path edges | per tile | roads/bridges on hex edges; must match both tiles |
| Home tile (iron) | 6 | one per faction colour edge/banner |
| **Port tiles** | 6-8 | yes needed. Outside land, joined by path to 2 land tiles. Each shows its good + price (-2 sell cheaper / +3 pays more). Art: toy dock, bathtub harbour, etc. |
| Sea/table edge | 1 | themed background (BGA container class) |

Tile count: 19 (2-3p) + 37 (4-6p) share tiles: print 37 + 6-8 ports. Double-sided where possible (land type front/back to randomise).

## 3. Pieces / figures
| Item | Count | IRL | BGA |
|---|---|---|---|
| Bots | 20 x 6 factions (120) | small meeples/standees | flat SVG silhouette per faction |
| Mechs | 6 x 6 (36) | bigger figures/standees | same, bigger |
| Buildings: Extractor, Factory, Guard Tower, Dock | per tile socket | wooden/cardboard tokens | SVG, neutral colour + owner via tile |
| Iron | ~60 | cubes | icon |
| Crystal | ~40 | gems | icon |
| Credits | many (1/5/10) | coins | counter icon |
| VP | 5/player track | tokens | score counter |
| Extractor-used marker | per extractor | flip token | state on SVG |

IRL cheap path: print flat standees (acrylic/cardboard) first, 3D figures later.

## 4. Cards
- **Mission cards: 40** (20 L1 + 20 L2, 10+10 drawn). Each: name (themed), condition icons, VP (1/2), level back. Needs a card-back per level and an icon vocabulary (hold tile, own mech, own factory, etc.).
- Optional later: faction reference cards, event cards (not in rules now).

## 5. Boards
- **Player board (x6, one per faction)**: supply slots for 20 bots + 6 mechs, iron/crystal/credit storage, faction art + colour, turn-start reminder (+10 Credits, extractors reset), build cost strip (Extractor 1, Factory 2, Tower 2; Factory: 1 iron -> 2 bots, +1 crystal -> mech), combat strip (push 2x, kill 3x, tower +1), move cost strip (1,3,6,10). IRL yes; BGA = player panel only (compact, no duplicate info).
- **Market board (x1)**: price tracks Iron 1-10, Bots 1-10, Crystal 2-20, Mechs 2-20 (a marker per good, prices move per item traded) + mission row (5 face-up slots) + deck spots. BGA: top banner trading UI + mission panel.
- **Reference sheet** per player (move cost, combat, trading). BGA: GAMEHELP.

## 6. BGA-specific assets
- `img/`: gameplay sprites only (tiles, pieces, buildings, resource icons, mission icons).
- `metadata_assets/`: box 280x280, icon 50x50 (+500), banner 1386x400 (NO text), publisher 280x280, display images 1000x750, title 2000x2000.
- Sounds: place/build, move, attack, fail, push, trade, mission, VP (ogg + mp3, quiet).
- Colourblind symbols: faction silhouettes (+ CSS pref `microforge_cb_on`).

## 7. Order of work (feel first, polish later)
1. Lock the 6 factions (themes + colours + silhouettes).  <- we are here (lineup concept exists)
2. Flat piece sheet: 6 bots + 6 mechs at 32px, check silhouettes.
3. Tile set (grey / mine / crystal / centre / home / port) flat SVG.
4. Building + resource icons.
5. Paper prototype: print tiles, standees, 10 mission cards, a sheet player board -> **play with real people**.
6. Mission card art + faction art + player/market boards.
7. BGA integration + metadata images.

## 8. Art direction (updated)
- Commanders are **kids (~7 yrs)**, not toddlers: **big round heads** (head about 1/3 of body), huge eyes, freckles, gap teeth, scraped knees, mismatched hand-me-down costumes.
- Painterly, gentle, hand-painted gouache/watercolour mood. Avoid anything that reads as generic AI/stock-fantasy: give every faction handmade quirks (cardboard, tin, crayon marks, patches) and keep one consistent paper/cardboard texture.
- Final game pieces and tiles stay flat vector for print and BGA; painted art is for cover, portraits, mission cards and the box.

## 9. Colour theme
| Role | Colour | Where |
|---|---|---|
| Brand / title | Plum purple `#6d3a8c` | title, Level 2 cards, box accent, rulebook headings |
| Secondary | Teal `#2f7f79` | Level 1 cards, rulebook subheads |
| Paper | Cream `#f4ead7` | card and page background |
| Ink | Dark aubergine `#2b2233` | outlines and text |
| Iron / Crystal / Empty / Sea | `#a9d07c` / `#9fd0e6` / `#c3c6cc` / `#6f95b0` | tiles (same as the BGA code) |
| Factions | Red `#c0392b`, Blue `#2980b9`, Green `#27ae60`, Yellow `#e1b12c`, Purple `#8e44ad`, Orange `#d35400` | pieces, home banners, player boards (same as `PLAYER_COLORS` in `Game.php`) |

Faction purple (`#8e44ad`) is close to brand purple; use brand purple for chrome only and always show the faction silhouette next to a faction colour.

## 10. Faction powers (DRAFT, untested)
Option "Faction powers": **Off** = all symmetric (default for the first paper tests), **Minor** = small perk, **Major** = stronger power. Balance only after the symmetric game is fun.
| Faction | Minor perk | Major power |
|---|---|---|
| Red Rocket | Your first attack each turn gets +2 attack power | Dragon: your Mechs attack an adjacent tile without walking in (no return step) |
| Blue Tide | Ports: buy 1 Credit cheaper and sell 1 Credit higher than the Port shows | Crab: Port tiles count as land for your movement along the sea |
| Green Garden | Your first Extractor use each turn costs 0 Credits | Beetle: Extractors give 2 tokens per use |
| Yellow Spark | One piece per turn gets its first step free | Tower: your Mechs may jump between tiles holding your Guard Towers for a flat 1 Credit |
| Purple Night | Your Guard Towers cost 1 Iron | Owl: tiles with your Guard Tower can be pushed but never killed |
| Orange Junkyard | Factories turn 1 Iron into 3 Bots | Scrap giant: you may build a Mech from 1 Iron + 2 Bots instead of Iron + Crystal |

## Open design notes (not art)
- Fun not found yet: test a stripped version on paper first (centre-out land grab, move cost, push/kill, missions) before adding trading/ports/factions.
- Candidates to cut for the first paper test: ports, building variety, trading quantities.

## 11. Theme options under discussion (name: "Little Bharat" candidate)
| Option | Idea | Concept art | Notes |
|---|---|---|---|
| A. Little gods | 6 child-like deities (Ganesha, Rama, Shiva, Kali, Lakshmi, Sarasvati) with animal-companion mechs | `concept_art/generated/bharat_little_gods_thumb.jpg` | Risky: worshipped figures fighting in a war game; could hurt BGA approval/reviews. Consult Indian designers before going this way. |
| B. Festival kids + animal companions (recommended) | Original kids (Holi, monsoon, tulsi garden, Diwali, stargazer, bazaar/cricket) with animal mechs (lion, elephant, peacock, tiger, owl, bull) | `concept_art/generated/bharat_festival_kids_thumb.jpg` | Same mechanics, own identity, no religious risk. |
| D. Regions of India | 6 regions as factions: South, North-West, North-Central, North-East, Central-West, Central-East | `concept_art/generated/bharat_regions_thumb.jpg` | Even coverage, avoids picking favourite states; each region gets a landscape, outfit, animal mech and perk. |
| C. Indian states | Faction per state, e.g. Kerala (backwater, boats, elephant), Tamil Nadu (temple towns, kolam), Andhra, Karnataka (Mysore dasara), Rajasthan, Bengal... | not drawn yet | Strong flavour and perk ideas (spices, textiles, ports); must treat states evenly and avoid stereotypes. Can combine with B: each kid comes from a state. |
