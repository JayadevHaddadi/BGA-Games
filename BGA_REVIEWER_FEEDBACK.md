# BGA Reviewer Feedback — Mandatory Rules for All Agents

Source: Ian (BGA Studio reviewer), reply to the Private Alpha requests for 4 games (Sugar Gliders named explicitly), Oct 2026. His tone shows patience wearing thin. **Result: not ready for Public Alpha. A broader presentation and UX pass is required before resubmission.**

Reviewer link he told us to follow: https://en.doc.boardgamearena.com/BGA_Studio_Guidelines

## What Ian said (his four recurring complaints)

1. Generic / AI-generated-looking UI patterns.
2. Insufficient adaptation to the specific identity of the game.
3. Mobile and responsive presentation needs more care.
4. The interface feels generated, not deliberately designed as a finished BGA adaptation.

## What Ian wants (the meta-rules)

- **Feedback from one game applies to ALL games.** He will not report the same UX/UI requirement separately for every project. Every new game and every resubmission must already include everything raised before.
- **Read the Studio Guidelines before requesting any review.** Do not request a review "to see what they say".
- **"Abstract/simple game" is not an excuse.** An abstract game still needs a deliberate, accessible, polished adaptation.
- **One clean resubmission, not iterative review requests.** Finish the full pass first.

## Rules for agents

1. Never request Private Alpha / Public Alpha / Beta until every item in the checklist below passes on desktop AND a real phone-width viewport.
2. Before touching UI on ANY game, re-read this file. Apply every item to every game, not just the one you were asked about.
3. If a UI choice feels like a default template (stock panels, gradients, emoji icons), replace it with something drawn from the actual game.

## Pre-submission checklist (from the Studio Guidelines)

**Layout and mobile**
- Play area centered on every device; margins around board for mobile scrolling/deselection.
- Board centred on the **screen**, not just inside the game box: side columns must balance (see the section below). Measure the offset, don't eyeball it.
- The table texture covers the **whole browser page** (behind BGA's header and page wrappers), not only the game box.
- Fluid layout, no fixed widths; fully usable at 100% zoom; do not rely on zoom buttons.
- Test portrait AND landscape at 320-390px wide; 4-player panels use at most 1/4 of a phone screen.
- Never hide game components behind popups; no automatic popups mid-game (tutorials only).
- Do not scatter related info across corners; don't keep always-needed info only on player panels.
- No in-game logos or branding unrelated to the game; no round numbers/settings/titles in player panels.

**Action bar and buttons**
- At most 4 buttons. Main action centered; cancel/undo/pass far right and visually separate.
- Blue = forward, red (`alert`) = cancel/undo/pass, white = optional, grey = disabled. Never tint buttons to match the theme.
- Hide actions that are never relevant; grey out ones that are temporarily unavailable.
- No custom buttons mixed with BGA built-ins; no "Round in progress" text in the bar.
- Prefer timed confirmation (5-8s) or a single Restart Turn over Undo-everywhere.

**Clarity and feedback**
- Highlight valid options; show consequences before the player commits.
- Every failed action says why (short plain-text error, shake/grey-out). Silence is never acceptable.
- Log every major action including automated ones, saying who did what, with icons/colors and alt-text; group small simultaneous actions into one line.
- Animations 0.5s regular, 0.8s max; batch repetitive updates; no decorative looping/bouncing/glowing.
- Sound: below BGA default volume, short, always paired with a visual cue.
- Tooltips add information (translatable); don't duplicate what's visible.

**Accessibility**
- Never rely on color alone: add shapes/symbols/patterns per piece color (colorblind-safe).
- Text contrast WCAG AA (4.5:1); outline player-color names so they read on light and dark.
- Tap targets at least 32x32px (aim 40-44px) with spacing; scroll rather than shrink.
- Every icon/button labeled for screen readers.
- Default BGA font only; all text translatable; no decorative/themed fonts; no text baked into images.

**Technical**
- Exact state restored after refresh (server is source of truth, including pending actions/selection).
- Replays flow without blocking popups; end game shows a scoring breakdown, not an instant final score.
- Errors never crash the game or expose stack traces.
- PHP: `act*` / `arg*` / `st*` prefixes, PSR-4 namespaces, validate all action input, no `@` suppression, no `.action.php`.

## Layout centring and full-screen table (Ian's Kiln feedback, Oct 2026)

Ian's words: "Can you center the board please?" and "Can you extend the background to all the screen?". Guideline A.1 (layout) asks for the whole game centred for large screens with a "no action" margin around it. Guideline C.5 (background) describes the background as the table the game lives on, so it covers the whole screen.

Rules for every game:
1. **Balance the side columns.** If a game has a side panel on one side only (score track, player mat, seat), reserve the same width on the other side with an empty spacer column. Otherwise the board drifts sideways. Use fixed equal side columns, not `auto`, when the content widths differ.
2. **Measure, don't eyeball.** The centre offset must be 0 px (± 2 px) at 1280 px wide and 375 px wide, for every player count the game supports. Check the bounding box of the central board against the centre of the game area (`#game_play_area`).
3. **Full-page texture.** Apply the themed background to `body` (add a class such as `<game>_page_bg` to `document.body` in setup) and make the BGA wrappers transparent (`#page-content`, `#ebd-body`, `#game_play_area`, `#game_play_area_background`). Do not put the texture only on the game box. Keep it subtle and low-contrast (C.5).
4. **Verify on screenshots.** Take a full-page screenshot at 1280 px and 375 px and confirm the background reaches the edges of the screen and the board is centred. Check that the wrapper selectors exist on the live BGA page before relying on them, since the harness is only a mock.

Reference implementation: Kiln (`games/KILN/bga/kiln.css`, `.kiln_page_bg`, balanced grid areas).

## Making it feel designed, not generated (our interpretation)

Ian's wording is subjective, so this is our reading of it. The guidelines forbid themed fonts and theme-tinted buttons, so identity has to come from everything else:
- Draw board, pieces, icons and log symbols from the actual game's look (real component art/SVGs, real iconography), not generic shapes.
- Use a subtle thematic background: slight texture/pattern with a touch of blur, never a flat color or busy detail.
- Game-specific log entries, tooltips, scoring breakdown and endgame presentation.
- Avoid the stock-template look: emoji as icons, uniform rounded cards with drop shadows, gradient headers, generic dashboards of stat tiles, symmetrical boilerplate layouts.
- Test on a real phone-width viewport and fix what looks cramped or accidental. Mobile care is called out explicitly.

## Reviewer approval criteria (forum thread "Alpha games guidelines for reviewers", Een, 2020)

Source: https://forum.boardgamearena.com/viewtopic.php?t=17325. This is what reviewers check before approving a game. It adds the following to the checklist above.

- **Faithful and complete**: ALL rules correctly implemented and ALL original game material present, nothing missing (include official variants/modes where practical).
- **Tooltips on ALL icons and UI elements** (not just some).
- **Interface clear and intuitive, centered horizontally on large screens, resizes properly for mobile.**
- **Playable start to finish without bugs**, in both turn-based and real-time modes (if applicable).
- **Replayable from start to end** (replay/archive mode works).
- **Game progression percentage must not freeze at zero** (implement `getGameProgression()`).
- **Game page complete**: correct details, English rules link, 3D game box visual for published games, appealing banner and presentation text. (Games released without a box, e.g. nestorgames cotton cases, need a sensible stand-in: logo-based box art.)
- **Quality bar**: must equal or beat comparable games already on BGA, and be playable immediately by someone who knows the rules.
- Reviewers play before approving (a minimum of 3 games played has been required since 2021, per forum notes).
