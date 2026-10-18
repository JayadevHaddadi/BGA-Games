# BGA review checklist (reviewer feedback, all games)

Read this before any UI work and before any review request. Code patterns for each point are in
[BGA_REFERENCE.md](BGA_REFERENCE.md); `python3 tools/bga.py check <slug>` automates the items marked ✓.

## Why this file exists

The BGA reviewer (Ian) declined Private/Public Alpha for Sugar Gliders, Yavalath, Kiln and Omega with the same
feedback, and his patience is wearing thin:

1. Generic / AI-generated-looking UI patterns.
2. Not adapted to the specific identity of the game.
3. Mobile and responsive presentation needs more care.
4. The interface feels generated, not deliberately designed as a finished BGA adaptation.

His meta-rules:
- **Feedback on one game applies to every game.** He will not repeat a point per project.
- **Read the [Studio Guidelines](https://en.doc.boardgamearena.com/BGA_Studio_Guidelines) before any review request.**
  Never request a review "to see what they say".
- **"Abstract/simple game" is no excuse.**
- **One clean resubmission**, not a series of requests. With it, write a reply that lists, per reviewer point, what
  changed and where.

## Checklist

### A. Layout and mobile (guidelines A.1, C.4, E.3)
- [ ] The whole game is centred on the screen with a "no action" margin: board centre offset 0 px (± 2) at 1280 and
      375 px, for every player count. Side columns balanced with equal spacers (Kiln: 220 px left and right).
- [ ] Phones: the board takes the full width, edge to edge (widen the root to the viewport, size from the measured
      width, tight `viewBox`). Desktop capped (about 700 px or the natural size).
- [ ] ✓ `game_interface_width.min` = 320. Fluid layout, no fixed widths, usable at 100 % zoom.
- [ ] Every tap target ≥ 32 × 32 px at 375 px (aim 40 to 44), **measured** with `getBoundingClientRect` for the
      largest board variant and every player count. Record the numbers in the commit. If geometry makes 32 px
      impossible (Omega radius 6: 13 cells across 375 px), say so to the reviewer.
- [ ] Portrait and landscape at 320 to 400 px. Four player panels use at most a quarter of a phone screen.
- [ ] No horizontal scroll.

### B. Panels and information (A.2, A.3)
- [ ] Never duplicate player info in the play area: no score bars, name chips, colour pips or turn-order badges on
      the board. Scores in the BGA score counter; persistent per-player info as one compact line in the player panel.
- [ ] Related information grouped in one place; always-needed information not only in a panel.
- [ ] No round numbers, settings or titles in the panels. No always-visible counters nobody reads (use tooltips).
- [ ] No automatic popups mid-game (tutorials only, skippable, click-outside closes). Never hide components behind a popup.
- [ ] Rules and tie-break text in `GAMEHELP.wiki` / `tie_breaker_description`, not on the board.
- [ ] No designer, publisher or developer credits in the play area (`gameinfos.jsonc` and the Control Panel).

### C. Action bar (A.2, C.3)
- [ ] ✓ Blue `primary` = go; red `alert` = undo, pass, skip, cancel, clear; white `secondary` = side choice; grey = disabled.
- [ ] At most 4 buttons; main action centred; undo/cancel on the far right. One copy of each action; never a
      duplicate button in the play area. No custom buttons next to BGA's own controls.
- [ ] Hide actions that are never relevant; grey out the temporarily unavailable ones.
- [ ] Prefer one "Restart turn" or a timed confirm over undo-everywhere. Tap-to-stage + Confirm makes mis-taps recoverable.
- [ ] Board interactions stay on the board (buttons do not replace them).

### D. Visual language (C.3, C.5)
- [ ] ✓ No drop shadows (`box-shadow`, `filter: drop-shadow`), glow halos, gloss ellipses, gradient chips, pill
      badges, glassmorphism, rounded "cards" (corners ≤ 3 px; only true circles use 50 %), `transition: all`.
- [ ] ✓ No looping animation. Animations 0.5 s (0.8 s max), purposeful, batched, and off under
      `prefers-reduced-motion`. Allowed exception: a gentle marker on the current player's own piece during their turn.
- [ ] ✓ No emoji or glyphs in labels and buttons (✓ ↺ 🔊 ⭐ 🏆). Icons come from the game's components, with `aria-label`.
- [ ] Default BGA font only. No text baked into images. No in-game logos or marketing text.
- [ ] Pieces and board look like the physical game: its shapes, palette and materials (wood, felt, paper, clay), flat
      matte pieces with a thin outline. A hex game gets a hexagonal board. Prefer the publisher's own glyphs and art,
      compared against the real board.
- [ ] Highlights use `outline` or a tint, not glow. Last-move markers are obvious (tinted cell + double ring); with
      3+ players mark the last `players - 1` moves, newest solid, older dashed. One-shot ping, no loop.
- [ ] Themed table background on the **whole page** (`body` class, BGA wrappers transparent), subtle and low
      contrast, never a flat colour. Verify on full-page screenshots at 1280 and 375 px.

### E. Feedback and log (C.1, C.2)
- [ ] Valid targets highlighted; consequences previewed before Confirm.
- [ ] Every refused action gives a short plain-text reason (status bar). Silence is never acceptable.
- [ ] Every action in the log says who did what, with the same coordinates as the board labels (label the axes);
      automatic and forced actions included; small simultaneous events grouped.
- [ ] Tooltips on every icon and control (BGA `addTooltip`, action-button `tooltip`), translatable, adding information.
- [ ] End of game: a step-by-step scoring breakdown, not an instant final number.

### F. Sound (D.4)
- [ ] ✓ Real files in `sounds/` (`.ogg` + `.mp3`), short and below BGA's default volume, through `bga.sounds.play`.
- [ ] Only for confirmed events (server notifications). Never on hover, `mouseenter`, staging or a local undo.
- [ ] ✓ Preference 101 "Sound effects" (default on); silent in replay and instantaneous mode; repeats throttled.
- [ ] Never the only cue: every sound has a visual counterpart.

### G. Accessibility (D.1 to D.3)
- [ ] Never colour alone: every player or piece colour has its own **silhouette** (triangle, square, plus, diamond;
      not hollow vs filled), on by default, preference 100 to switch it off. Win and lose highlights differ by line
      style, not only green vs red. Check the symbol at the smallest size used.
- [ ] Player-colour names outlined so they read on light and dark.
- [ ] Text and icon contrast WCAG AA (4.5:1). Every interactive element has a label. All text translatable.
- [ ] `:hover` only inside `@media (hover: hover)`; mouse-only previews only when `matchMedia('(hover: hover)')`.

### H. Technical (forum "Alpha games guidelines for reviewers")
- [ ] All rules and all original material implemented (official variants as options where practical).
- [ ] Playable start to finish without bugs, turn-based and real time; replay works from start to end.
- [ ] Refresh or reconnect restores the exact state (server is the source of truth, pending choices included).
- [ ] `getGameProgression()` moves during the game.
- [ ] Errors never crash the game or show stack traces; no `@` suppression; every action input validated.
- [ ] Game page complete: description, English rules link, box image, a banner without text, presentation text.
- [ ] Quality equal to or above comparable BGA games, playable at once by someone who knows the rules.
- [ ] Reviewers play at least 3 games before approving: make those games pleasant.

## Making it feel designed (our reading of Ian's words)

The guidelines forbid themed fonts and theme-tinted buttons, so identity comes from everything else: board, pieces,
icons and log symbols drawn from the real game; a textured table; game-specific log lines, tooltips and scoring
breakdown. Avoid the stock template look: emoji icons, uniform rounded cards with shadows, gradient headers,
dashboards of stat tiles. Then look at a phone-width screenshot and fix whatever looks cramped or accidental.

## Review history

| Date | Game | Points raised |
| :--- | :--- | :--- |
| Oct 2026 | Sugar Gliders (+3 others) | Generic look, identity, mobile, "generated" feel |
| Oct 2026 | Yavalath | Same UX/UI points; hexagonal board; credits in the play area; button colours; hover sounds |
| Oct 2026 | Kiln | Shadows, glossy cards, emoji, custom tooltips; background; hover sounds; mobile width; centre the board; background on the whole screen |
| Oct 2026 | Omega | Player info duplicated in the play area; CSS bans; full-width board on phones; sounds from files only |
