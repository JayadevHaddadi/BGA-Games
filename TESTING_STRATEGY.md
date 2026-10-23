# Testing Strategy & Coordination Guide

This document defines the unified testing strategy across development agents and environments for BGA Studio games in this repository.

---

## 🎯 Division of Responsibilities

1. **Claude Agent (Feature & Core Implementation)**:
   * Rules architecture (`PLAN.md`, `Rules.php`).
   * State machine (`States/*.php`, `Game.php`, `dbmodel.sql`).
   * Initial UI scaffolding (`Game.js`, CSS, sound events).
   * Pure rules CLI tests (`tests/rules_test.php`).

2. **Testing Agent (Verification, Browser QA & Studio Sync)**:
   * **Gate Checks**: Strict verification against BGA reviewer rules (`python3 tools/bga.py check <slug>`).
   * **Local Headless Browser Harness** (`tests/harness/`): Automated responsive testing at **375px mobile** and **1280px desktop** using headless Chrome.
   * **BGA Studio Deployment & Integration**: Delta syncing (`python tools/sync.py <slug>`), server-side state testing, and multi-player live play verification.

---

## 🧪 Testing Pyramid & Workflow

```
       ▲
      / \     Live BGA Studio Verification (Multiplayer, State Transition, DB)
     /---\    -----------------------------------------------------------------
    /     \   Headless Browser Harness (375px & 1280px, Tap Targets >= 32px)
   /-------\  -----------------------------------------------------------------
  /         \ Gate Linter: python3 tools/bga.py check (CSS, i18n, HAL rules)
 /-----------\ -----------------------------------------------------------------
/             \ Pure PHP Rules Unit Tests: php tests/rules_test.php (CLI, 0 BGA deps)
```

### Level 1: Pure Rules (`php tests/rules_test.php`)
* **Requirement**: 100% of game mechanics, edge cases, scoring arithmetic, and rulebook examples must be verified without framework dependencies.
* **Pass criteria**: `0 failures`.

### Level 2: Gate Linter (`python3 tools/bga.py check <slug>`)
* Checks 50+ compliance rules that fail BGA reviewer approval:
  * No `box-shadow` or drop shadows.
  * No `transition: all`.
  * No emoji in game UI text.
  * No hover effects outside `@media (hover: hover)`.
  * Proper MediaWiki syntax in `GAMEHELP.wiki` (no Markdown).
  * Zombie handling in all active states.
  * Valid translatable text with `${arg}` interpolation.
* **Pass criteria**: `0 FAIL`.

### Level 3: Local Browser Harness (`tests/harness/`)
* **Viewport 375px (Mobile)**:
  * Tap targets of all interactive items (board cells, buttons, pieces) must be **$\ge 32\text{px}$**.
  * Board horizontal overflow must be **$0\text{px}$** (no sideways scroll).
  * Board horizontal center offset must be **$0\text{px}$**.
* **Console / Runtime**:
  * **$0$ JavaScript console errors**.
  * Confirm button stays enabled after rejected action until the active player alters their choice.
  * Sounds fire **only** on confirmed events, never on hover/staging, and are muted in replay mode or with pref 101 off.

### Level 4: Live BGA Studio Verification
* **Sync**: SFTP deploy to Studio sandbox (`<slug>test` or `<slug>`).
* **Multi-player**:
  * 2-player match with dummy sandbox accounts.
  * Verify state progression (turn changes, notifications update opponent boards without refresh).
  * Zombie test: abandon player mid-turn to verify `zombie()` transition.
  * Game end: verify scoring breakdown popup and table end stats.

---

## 📋 Active Games Under QA

1. **People** (`people`): Passed Level 1, 2, 3. Ready for Studio live session.
2. **Microchip** (`microchip`): Passed Level 1, 2, 3. Ready for Studio live session.
3. **Gardens of Enceladus** (`gardensofenceladus`): Passed Level 1, 2, 3. Ready for Studio live session.
4. **Red** (`red`): Passed Level 1, 2, 3. Ready for Studio live session.
5. **Hippos & Crocodiles** (`hipposcrocodiles`): Original assets in hand (`hc.zip`). Ready for implementation.
