# Your requests and where each stands (2026-10-06)

| # | You asked | Status | Where |
|---|---|---|---|
| 1 | 40 mission cards, plain, SVG, art placeholder, ready for print and BGA | Done | `design/missions/*.svg` (40 + 2 backs, art slot = `id="art-slot"`) |
| 2 | List of the 40 missions to inspect | Done | `design/missions_overview.html` (open in a browser) |
| 3 | How many tiles at maximum | Done: **37 land + 12 ports = 49** on the table (2-3p: 19 + 6 = 25); print set 37 + 6 home + 12 port = 55 | `design/tiles_overview.html` |
| 4 | Tiles as SVG for an overview | Done | `design/tiles/*.svg`, `design/board_layout.svg` |
| 5 | Kids with rounder, larger heads; Ghibli-like feel but not generic | Done (v2 concept) | `concept_art/generated/kids_v2_round_heads_thumb.jpg` (+ Canva link) |
| 6 | Draft the faction perks | Done (draft) | `ART_PLAN.md` section 10 |
| 7 | Next art (tile set) | Done (concept) + plain SVG tiles | `concept_art/generated/tile_set_concept_thumb.jpg`, `design/tiles/` |
| 8 | List of all assets for the whole game (rulebook, box, pieces, boards...) | Done | `ASSET_LIST.md` |
| 9 | Cover with the kids attacking | Done (concept, no title text) | `concept_art/generated/cover_art_textless_thumb.jpg` (+ Canva link) |
| 10 | Settle a colour theme | Proposed, needs your OK | `ART_PLAN.md` section 9 |
| 11 | Start the rulebook, plain, TeX preferred | Done | `rulebook/rulebook.tex`, `rulebook/rulebook.pdf` (4 pages) |
| 12 | Make this list and go through it | Done | this file |
| 13 | Earlier: faction perks possibly major, optional | Drafted as an option (Off / Minor / Major) | `ART_PLAN.md` section 10 |
| 14 | Earlier: BGA look, BGG page and name | Answered in chat | - |

Known gaps: saved images are small thumbnails (full resolution is only on the Canva links); the "Rich Land" missions cannot currently be completed (see `design/missions_overview.html`); nothing here has been committed.

---
# Round 2 list (from your messages about Private Alpha, movement, basic rules, boards, rulebook, icons)

| # | You asked | Status | Where |
|---|---|---|---|
| 1 | What is required for Private Alpha; aim for it asap | Done (list + prep). Remaining steps are on the BGA website | `ALPHA_CHECKLIST.md`; sounds, metadata images and GAMEHELP added |
| 2 | Movement linear, triangular kept in notes | Done | `Game.php`, `Game.js`, `DESIGN_NOTES.md` |
| 3 | "Not like homework": picture-led rulebook, icon board, fast turns, random tiles/missions | Partly: rulebook with figures, boards with icons done. Fast turns and random tiles/missions already in the game | `rulebook/rulebook.pdf`, `design/boards/` |
| 4 | Basic rules that are not boring, with a goal (missions) | Proposal written, needs your OK | `BASIC_GAME.md` |
| 5 | Explain the 2 market options again | Done (3 options, plainly) | `BASIC_GAME.md`, chat |
| 6 | Good basic strategies | Done (list) | `BASIC_GAME.md` |
| 7 | Player boards with basic + advanced side | Draft SVGs, 6 factions x 2 sides | `design/boards/`, `design/boards_overview.html` |
| 8 | A nice rulebook | Draft v0.2, 7 pages with figures | `rulebook/rulebook.pdf` |
| 9 | Consistent ICONS for everything, in the rulebook | Done, 29 icons | `design/icons/`, `design/icons_overview.html` |
| 10 | Everything defined as SVG so art can be swapped | Done: icons, tiles, roads, cards, boards are generated SVG; BGA reads the same files | `design/*.py`, `bga/img/` |

Not done / open: used-coin and once-per-turn rules (agreed, not coded), market exploit fix, colour-blind preference, Rich Land missions, final art for metadata.
