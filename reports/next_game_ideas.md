# Next games to implement on BGA: recommendation (25 Oct 2026)

Research only. Written from the repo (LICENSING_TRACKER.md, the BGA licence list dated 5 Oct 2026, `games/*`), the nestorgames rulebooks (`https://nestorgames.com/rulebooks/<NAME>_EN.pdf`, all fetched and skimmed) and BoardGameMatcher mirrors of BGG data.

## Honest limits of this research

- **"Already on BGA?" could not be verified for anything.** BGA's gamelist and gamepanel pages are JS-rendered; WebFetch returns only a loading stub, and search engines index no BGA game pages for these titles. Known: Taiji is on BGA (Nestor, 23 Sep, per tracker). Every other "No evidence" below means "I found nothing", not "confirmed absent". Cheap proof: Jayadev opens boardgamearena.com/gamelist logged in and searches each title, or asks Nestor.
- BGG is blocked (HTTP 403/401), so ratings come from boardgamematcher.com mirrors and are on BGG's 5-point-style scale (3.6/5 = about 7.2/10), with very small sample sizes (4 to 42 ratings). Treat them as "niche, cult abstract", not as demand signals. The BGA licence list shows ratings on a 10-point scale (Seven 7.3, Counterplays 7.3, Stack-22 7.4, Line or Colour 7.0, Taiji 6.4, Omega 7.1).
- nestorgames.com is "temporarily closed" to retail; its product list is only the "old stock" page (`https://nestorgames.com/legacy.html`) plus rulebook PDFs. The full catalogue (~100 titles incl. many abstracts, Hex sets, puzzles) is not enumerable from there, so the table covers the titles I could find rulebooks or pages for, not every Nestor title.
- Effort estimates are my judgement for one developer working with agents, from rulebook text, not from reading full rules.

## Licence picture (key finding)

- **Licensed on BGA's list already** (Eclipse Editorial / Néstor Romeral Andrés): Seven (2024-07-24), Counterplays, Stack-22 (2024-09-05), Pent-Up (2024-09-05, in progress), Line or Colour (2025-03-27), plus Taiji/Omega, Sugar Gliders, Kiln, nestorgames GP, Gardens of Uranus, Vault.
- **Not on the BGA list** (Nestor must add them at boardgamearena.com/gamepublishers; same status as Mars/Io/Enceladus/Microchip/People/Red/Hippos): Puppy Park, Naja, Asterion, Firenze, Halloween, Flink, Onager, Squava, Taigo, Nanku, Ni-Ju, Dice Matrix, Hexdominoes, Blinq, Cannon, Manalath (Dieter Stein design, not Nestor's), Amazons and ConHex (Nestor editions of other designers' games; inquiry already sent).
- Practical consequence: Nestor has been quick to approve and the paperwork is a one-line ask, so unlicensed-on-list is a small risk for Nestor-designed games. Only the first three-to-four picks below could start with no paperwork at all.

## (a) Ranked top 8

Scale: effort = developer days for a reviewer-ready alpha, given the existing repo tooling.

### 1. Seven
- **Why**: licensed, art SVGs already in `games/seven/` (`seven.svg`, `seven_logo.svg`), implementation notes already written (`games/seven/IMPLEMENTATION_NOTES.md`). 2 players (3 and 2v2 variants), about 15 min, rated 3.6/5 (39 ratings) per [BoardGameMatcher](https://boardgamematcher.com/game/seven), BGA list 7.3. Fast, async-friendly, easy to explain.
- **Art potential**: medium. Colourful stacked tetrahexes look good in 3D-ish layering, but it is still an abstract. Counter the "generic UI" rejection with real isometric tile shading, level shadows and a strong palette.
- **Effort**: 4-5 days. Risk: the "must place on highest legal level" rule scope (open question in the notes), height-map rendering, mobile layout.
- **Bonus**: the engine is reusable. Stack-22 (pentahexes, same rules) and Counterplays (hexiamonds, same rules) are the same ruleset with different piece sets, so each follow-up costs about 1-2 days.

### 2. Puppy Park
- **Why**: cutest, least abstract game in the catalogue (dog-breed dual-hex tiles, art by Silvia Romeral Andrés, dog houses, bone). Race to empty your hand, matching breeds grants extra turns; 2 players (3-4 with expansion), about 30 min. 3.5/5 (4 ratings), [BoardGameMatcher](https://boardgamematcher.com/game/puppy-park). Matches the owner's taste for asset-rich, visually distinctive games and answers reviewer complaints about identity.
- **Licence**: not on list; ask Nestor. Rulebook: [PUPPYPARK_EN.pdf](https://nestorgames.com/rulebooks/PUPPYPARK_EN.pdf).
- **Effort**: 3-4 days (hex placement with matching checks, stacking, extra turns, expansion for 3-4 as player-count option). Risk: needs the dog art; if Nestor cannot supply vectors, drawing 7 breeds in house style is the cost.

### 3. Naja
- **Why**: strong theme (cobras, scarabs, bonfires), 9x9 board with a scarab per cell, place 4-segment snakes, enclosed-area majority scoring, 2 players (3-4 variant; expansion exists). 2018, about 30 min, 3.6/5 (16 ratings), [BoardGameMatcher](https://boardgamematcher.com/game/naja). Good visual identity (Egyptian look) and Nestor has high-res art.
- **Licence**: not on list. Rulebook: [NAJA_EN.pdf](https://nestorgames.com/rulebooks/NAJA_EN.pdf).
- **Effort**: 4-5 days (polyomino-like snake shapes, region/flood-fill scoring with ties, bonfire penalty). Risk: enclosure scoring clarity in the UI.

### 4. Asterion
- **Why**: the most thematic Nestor tile-layer: Minotaur in the labyrinth, prisoners and treasure chests, 2-4 players, competitive or cooperative flavour, revised rules available. Multiplayer, which BGA audiences prefer, and sells on theme. Rulebook: [ASTERION_EN.pdf](https://nestorgames.com/rulebooks/ASTERION_EN.pdf). Rating data not found.
- **Licence**: not on list.
- **Effort**: 6-8 days (rule text is longer, rotation actions, kill/escape resolution, scoring by chest). Risk: highest rules complexity in this list; confirm the revised rules are the final ones.

### 5. Firenze
- **Why**: colourful (green/pink/white marble tiles, black pyramid "lampredotto"), derived from Taiji and Omega so Jayadev already knows the family (Omega is done). 2-3 players, tile-laying. Rulebook: [FIRENZE_EN.pdf](https://nestorgames.com/rulebooks/FIRENZE_EN.pdf). No rating found.
- **Licence**: not on list.
- **Effort**: 4-5 days (sandwich detection, capture by pyramid, 3-player scoring). Risk: still an abstract; lesser-known, unrated.

### 6. Counterplays
- **Why**: licensed, same rules as Seven but on a triangular grid with 12 hexiamonds, two-sided asymmetric tiles; 2p, about 30 min, 3.6/5 (14 ratings), [BoardGameMatcher](https://boardgamematcher.com/game/counterplays). Best done right after Seven to reuse the stacking logic.
- **Effort**: 2-3 days after Seven (triangle-grid geometry is the new part). Risk: low demand, pure abstract.

### 7. Stack-22
- **Why**: licensed, "deepest" of the pile-omino family (22 pentahexes), 2p, about 30 min, 3.6/5 (7 ratings), [BoardGameMatcher](https://boardgamematcher.com/game/stack-22). The publisher warns it is not for casual players (branching factor over 3,000 on move two), so it appeals to a small hardcore audience.
- **Effort**: 1-2 days after Seven; mostly piece data and UI for 22 pieces per player. Risk: UI for picking 22 shapes on mobile, tiny audience. Better bundled with Seven as a "piece set" option (see below) than shipped alone.

### 8. Halloween (Nestor's card game)
- **Why**: 2-4 players, dark lords moving on a graveyard grid of face-down cards, claiming cards with headstones to recruit undead armies. Themed, multiplayer, seasonal hook. Rulebook: [HALLOWEEN_EN.pdf](https://nestorgames.com/rulebooks/HALLOWEEN_EN.pdf). No rating found (the BGG title "Halloween" collides with an unrelated 2023 game, so I could not look it up reliably).
- **Licence**: not on list. **Effort**: 4-6 days (card rules and hidden info not yet read in depth). Risk: uncertain rules depth; I only read the first page.

Honourable mentions: **Flink** (2p connection game, 3.8/5, 42 ratings, about 40 min, [FLINK_EN.pdf](https://nestorgames.com/rulebooks/FLINK_EN.pdf); abstract, rulebook already in `games/flink/`; rated best of the abstracts but least identity), **Onager** (3.8/5, 42 ratings, 2p, 20 min, rulebook in `games/onager/`; pure abstract), **Dice Matrix** (2p dice puzzle, tactile, [DICEMATRIX_EN.pdf](https://nestorgames.com/rulebooks/DICEMATRIX_EN.pdf)), **Line or Colour** (licensed 2025-03-27, BGA list 7.0; no rulebook in repo so I could not assess it: ask Nestor for it).

## Suggested sequence

1. Seven, then add Stack-22 and Counterplays as sets or sibling games using the same engine (three licensed games for about 8-10 days total).
2. Meanwhile, send Nestor one email: add Puppy Park, Naja, Asterion, Firenze (plus the already-pending Mars/Io/Enceladus/Microchip/People/Red/Hippos) at gamepublishers, and request art for Puppy Park.
3. Then Puppy Park, Naja, Asterion as the "identity" games.

## (b) All Nestor games considered

BGG column: rating on BoardGameMatcher's 5-point mirror (ratings count); BGA list column is the 10-point figure from the licence file. "n/a" = not found. "On BGA?" is unverified except where stated.

| Game | Players | Time | Type | Year | BGG (count) | Rulebook PDF | Licence on BGA list | On BGA? | Status in repo |
| :-- | :-- | :-- | :-- | :-- | :-- | :-- | :-- | :-- | :-- |
| Seven | 2 (3, 2v2) | ~15 min | Abstract tile stacking | 2013 | 3.6 (39); list 7.3 | Yes | Yes (2024-07-24) | No evidence | Notes + SVGs, not started |
| Counterplays | 2 | ~30 | Abstract tile stacking | 2017 | 3.6 (14); list 7.3 | Yes | Yes (2024-09-05) | No evidence | Rulebook only |
| Stack-22 | 2 | ~30 | Abstract tile stacking | 2018 | 3.6 (7); list 7.4 | Yes | Yes (2024-09-05) | No evidence | Rulebook only |
| Pent-Up | 2 | ~30 | Abstract tile stacking | n/a | list 7.3 | Yes | Yes (2024-09-05) | No evidence | In progress (excluded) |
| Line or Colour | n/a | n/a | n/a | n/a | list 7.0 | Not in repo | Yes (2025-03-27) | No evidence | Not started |
| Puppy Park | 2 (3-4 expansion) | ~30 | Light tile laying, animals | 2020 | 3.5 (4) | [Yes](https://nestorgames.com/rulebooks/PUPPYPARK_EN.pdf) | No | No evidence | Not started |
| Naja | 2 (3-4) | ~30 | Area majority, tile placement | 2018 | 3.6 (16) | [Yes](https://nestorgames.com/rulebooks/NAJA_EN.pdf) | No | No evidence | Not started |
| Asterion | 2-4 | n/a | Themed tile laying | n/a | n/a | [Yes](https://nestorgames.com/rulebooks/ASTERION_EN.pdf) | No | No evidence | Not started |
| Firenze | 2-3 | n/a | Tile laying, Taiji/Omega family | n/a | n/a | [Yes](https://nestorgames.com/rulebooks/FIRENZE_EN.pdf) | No | No evidence | Not started |
| Halloween (nestorgames) | 2-4 | n/a | Card/grid, themed | n/a | n/a | [Yes](https://nestorgames.com/rulebooks/HALLOWEEN_EN.pdf) | No | No evidence | Not started |
| Flink | 2 | ~40 | Abstract connection | 2016 | 3.8 (42) | Yes | No | No evidence | Rulebook only |
| Onager | 2 | ~20 | Abstract movement | 2012 | 3.8 (42) | Yes | No | No evidence | Rulebook only |
| Dice Matrix | 2 | n/a | Dice puzzle/abstract | n/a | n/a | [Yes](https://nestorgames.com/rulebooks/DICEMATRIX_EN.pdf) | No | No evidence | Not started |
| Ni-Ju | 2 | n/a | Abstract tile laying | 2016 | n/a | [Yes](https://nestorgames.com/rulebooks/NIJU_EN.pdf) | No | No evidence | Not started |
| Nanku | 2 | short | Abstract 3D line-4 | n/a | n/a | [Yes](https://nestorgames.com/rulebooks/NANKU_EN.pdf) | No | No evidence | Not started |
| Taigo | 2 | n/a | Abstract five-in-row tiles | n/a | n/a | [Yes](https://nestorgames.com/rulebooks/TAIGO_EN.pdf) | No | No evidence | Not started |
| Squava | 2 | short | Abstract (Yavalath simplification) | n/a | n/a | [Yes](https://nestorgames.com/rulebooks/SQUAVA_EN.pdf) | No | No evidence | Not started; overlaps Yavalath |
| Hexdominoes | 2+ | n/a | Dominoes variants | n/a | n/a | [Yes](https://nestorgames.com/rulebooks/HEXDOMINOES_EN.pdf) | No | No evidence | Not started |
| Blinq | n/a | n/a | n/a | n/a | n/a | Rulebook fetch returned no text | No | No evidence | Not assessed |
| Cannon | 2 | n/a | Abstract (nestorgames deluxe edition of another designer's game) | 2010 | n/a | [Yes](https://nestorgames.com/rulebooks/CANNON_EN.pdf) | No | No evidence | Not started |
| Amazons / ConHex | 2 | n/a | Abstract, other designers | n/a | n/a | Yes | Inquiry sent | No evidence | Backlog |
| Manalath | 2 | n/a | Abstract (Dieter Stein) | n/a | n/a | Yes | No | No evidence | Rulebook only |
| Taiji | 2 | n/a | Abstract | n/a | list 6.4 | Yes | Yes | **Yes, already on BGA** (Nestor, 23 Sep) | Skip |
| Omega, Kiln, Sugar Gliders, nestorgames GP, Gardens of Mars/Io/Enceladus/Uranus, People, Red, Microchip, Hippos & Crocodiles, Vault, Yavalath | - | - | - | - | - | - | - | - | Done or in progress (excluded) |

Sources for rows: [BoardGameMatcher Seven](https://boardgamematcher.com/game/seven), [Counterplays](https://boardgamematcher.com/game/counterplays), [Stack-22](https://boardgamematcher.com/game/stack-22), [Onager](https://boardgamematcher.com/game/onager), [Flink](https://boardgamematcher.com/game/flink), [Naja](https://boardgamematcher.com/game/naja), [Puppy Park](https://boardgamematcher.com/game/puppy-park), nestorgames [legacy.html](https://nestorgames.com/legacy.html) stock page, and the repo's `BGA studio License status 5th october 2026.txt`.

## BGA trends that inform the ranking (general knowledge, not verified this session)

BGA's most-played titles are short, 2-4 players, rules teachable in under two minutes, with a clear theme and async-friendly turns. Pure abstracts get a smaller but loyal audience (and fast reviewer approval is harder for "generic" boards). That argues for: themed games first (Puppy Park, Naja, Asterion, Halloween), and for packaging the abstract family (Seven + Stack-22 + Counterplays) as one piece-set-selectable experience instead of three thin listings. Solo/AI modes are rare and nice to have; none of these games has a solo mode in its rulebook.

## (c) Wildcards beyond Nestor (low confidence, not verified on BGA)

1. **Other Cameron Browne / Ludi-generated games** (for example Pentalath). Cameron already approved Yavalath conditionally ("credit me and Ludi") and replied the same day; a second approval is plausibly one email. Unverified: which are not yet on BGA, and whether he holds rights.
2. **Public-domain traditional games with strong visual identity** (a lesser-played Mancala or hunt game with a distinctive look, Hnefatafl-type). Licensing is trivial (the BGA list itself shows public-domain entries like Chutes and Ladders), but several are probably already on BGA and generic UI was the reviewers' complaint; only worthwhile with real art.
3. **Nestor-adjacent designers who publish through Eclipse Editorial** (the BGA list shows Eclipse Editorial also holds Tailits by Grant Fikes, licensed 2024-07-24, and others by Wentu Venturini and Breitenbach/Hagenauer). Since Nestor/Eclipse already work with BGA, licensing the sibling Eclipse titles may be easy, but I did not review those games.

## (d) Concrete next step for #1 (Seven)

Email Nestor (orders@nestorgames.com, reply in the existing thread). Memory rule: fill real values, no placeholders. Ask for:

1. Confirmation that the existing licence (granted 2024-07-24) covers Seven, Counterplays and Stack-22 on BGA, and that he is fine with all three being built on one engine.
2. For Seven, in vector form: the 7 tetrahex tile shapes in both colours (plus the red/blue second set for 3 players and 2v2), a clean hex board or table texture, logo, and a 2000x2000 title image or box-style art for the BGA required images (280x280 box, 50x50 icon, 1386x400 banner, 900x600 display; see `BGA_REFERENCE.md` / BGA asset specs).
3. The rules question blocking the legal-move generator: for "must place on the highest level" does the rule mean the highest level reachable by any legal move for that player at that moment, or by the specific piece chosen? Ask for a worked example, and whether the misere variant and the puzzles in the rulebook can ship as game options.
4. Add Puppy Park, Naja, Asterion and Firenze (and the pending Mars, Io, Enceladus, Microchip, People, Red, Hippos & Crocodiles) at boardgamearena.com/gamepublishers, and, for Puppy Park, vector breed art.
5. Line or Colour rulebook (licensed but not in the repo).
