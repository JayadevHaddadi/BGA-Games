# nestorgames GP — BGA Implementation Reference

> **Game Design**: Néstor Romeral Andrés (2021)  
> **Publisher**: nestorgames  
> **BGG ID**: 351146  
> **Status**: Recommended by Néstor Romeral Andrés  
> **BGA Project**: `nestorgamesgptest` / `nestorgamesgp`

---

## 🏎️ Game Overview

*nestorgames GP* is a fast and furious 2 to 8 player racing game designed as an homage to classic 1980s top-down arcade racers like *SuperSprint* and *Nitro*.

### Key Mechanics
* **Track Grid**: Vehicles navigate a segmented circuit with straightaways, tight corners, and alternate shortcuts.
* **Special Items & Obstacles**:
  * **Turbines**: Speed boosts.
  * **Rockets / Missiles**: Attack opponents ahead.
  * **Bombs & Oil Spills**: Trap opponents behind.
  * **Wrenches**: Vehicle repairs.
  * **Gates / Teleporters**: Warp between circuit segments (on advanced track variants).
* **Speed Management**: Balancing throttle to take tight corners without spinning out or colliding.
* **Player Count**: 2 to 8 players (ideal for party tables and tournaments).

---

## 📐 Architecture & BGA Setup Plan

### Database Schema (`dbmodel.sql`)
* `racer`: Position (`pos_x`, `pos_y`, `lane`), speed/gear, facing direction, damage, lap count, checkpoint status.
* `track_hazard`: Oil slicks, bombs, dropped obstacles.
* `player_inventory`: Items held (rocket, oil, boost, wrench).
* `global_variables`: Current lap leader, round phase.

### Front-End (`Game.js` & CSS)
* Top-down 80s arcade aesthetic with modern vector/CSS rendering.
* Animated car movements, skid marks, smoke/boost particle effects.
* 8 distinct, high-contrast vehicle colors.
* Web Audio API 8-bit / chiptune engine roars, screeching tires, and turbo chimes.
* Responsive auto-scaling down to 320px mobile viewports.
