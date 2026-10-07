# Private Alpha checklist (BGA slot `microforge`)

Goal: friends play on the main BGA site without creating Studio accounts. Source: AGENTS.md section 5 + a check of this repo (2026-10-06).

## A. Blockers on the BGA website (only you can do these)
1. **Game Metadata Manager** (`https://boardgamearena.com/controlpanelgames?game=microforge`)
   - **Description** (draft below), **Zombie mode level = 1**, characteristics (Complexity/Luck/Strategy/Diplomacy sliders), tags (e.g. Abstract strategy, Area control, Hexagonal grid, Economic, Children).
   - Upload images from `bga/metadata_assets/` (placeholders, see C).
2. **BGG id**: `gameinfos.jsonc` has `"bgg_id": 0`. AGENTS.md says to set it there when BGA warns. An unreleased game has no BGG page; if BGA blocks on it you need a BGG entry first (needs a settled name). The missing licence warning is safe to ignore for Private Alpha.
3. Save, go to **Manage game**, press **Request PRIVATE ALPHA status**. Then add your friends as testers in Manage game (I cannot verify that screen; check the BGA doc if the option is not obvious).

Draft GMM description:
> Little Commanders is a game of toy armies on a floor of hexagon tiles for 2 to 6 players. Each kid commander starts with a Dock, a Guard Tower and three cute little Bots. Hold tiles, dig up iron and crystal, build factories to make Bots and big Mechs, trade at ports, and complete Mission cards. Move onto an enemy tile with enough strength and its defenders must retreat. The first commander to collect enough Victory Points from Missions wins.

## B. Code and files: status
| Item | Status |
|---|---|
| No `ajaxcall`, `scoreCtrl`, `player_board_` DOM, `gameui.playSound` | OK (grep clean) |
| `zombie()` on every active state | OK (PlayerTurn, ChoosePush) |
| `ensureSchema()` / `upgradeTableDb()` | OK |
| Stats (`stats.jsonc`) | OK |
| `gameinfos.jsonc` (players 2-6, interface min 320) | OK; deprecated `is_beta` / `is_sandbox` removed, `exception_on_warning: true` set; `bgg_id` 0 (see A2) |
| Sounds in `bga/sounds/` (ogg + mp3), played only from confirmed notifications | Done today (placeholders from `design/make_sounds.py`) |
| Metadata images in `bga/metadata_assets/` | Placeholders done today (see C) |
| `GAMEHELP.wiki` | Done today (short rules) |
| Missions in the panel, tiles, icons from the SVG set | Done |
| Colour-blind preference (pref 100, one silhouette per faction) | NOT done. Reviewers will ask; not a Private Alpha blocker. Needs the faction art first |
| Game options / preferences files | Not needed for Private Alpha |
| PHP never executed before | Now run locally: `php games/microforge/tests/smoke.php` (real Game.php on SQLite, warnings as exceptions, all 4 rules levels x 2/4/6 players). BGA itself is still untested |
| Game option `Rules` (`gameoptions.jsonc` id 100: Basic / +Production / +Mechs / Full) | Done |

## C. Metadata images (all placeholders, regenerate with `python3 design/make_metadata.py`)
`box_280x280.png`, `icon_50x50.png` / `icon_500x500.png`, `banner_1386x400.jpg` (no text), `publisher_280x280.png`, `display_1000x750.jpg`. Still missing: `title` 2000x2000 and real in-game screenshots for the display images (take them once the UI is final). Final art from the theme decision replaces these.

## D. Known gameplay problems to fix before inviting friends
- (fixed) "Rich Land" missions: 4 land tiles now hold two resources.
- Market exploit: with batch pricing, buy 10 / sell 10 loops print money. Agreed rules (one use per post per turn, 1 Credit) still leave it if prices move only after a batch. Needs per-item price steps or fixed port prices.
- (done) Once-per-turn: Extractor, Factory, Dock and Port each work once per turn for 1 Credit.
- The Basic game exists as game option 1 (default). Numbers are untested.
- Small touch targets: building sockets are about 13px at phone width (tiles are 42px+). Needs a tap-to-open-slot-menu pass before reviewers see it.

## E. After approval
Each push to `main` redeploys to Studio; testers see the version on the production site only after BGA's alpha release process. Always restart the table after schema changes.
