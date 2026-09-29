# Gardens of Uranus (nestorgames) — BGA Implementation Reference

> **Game Design**: Néstor Romeral Andrés  
> **Publisher**: nestorgames  
> **Status**: Recommended by Néstor Romeral Andrés  
> **BGA Project**: `gardensofuranustest` / `gardensofuranus`

---

## 🌸 About the "Gardens of..." Series
*Gardens of Uranus* belongs to Néstor Romeral Andrés’s celebrated "Gardens" series (alongside *Gardens of Mars*, *Gardens of Io*, and *Gardens of Enceladus*).

### Core Mechanics
* **Theme**: Alien botanists cultivate cosmic flower gardens on celestial landscapes.
* **Spatial Connection & Area Control**: Players move across a grid, planting colored flower tiles or tokens.
* **Scoring**: Connected groups of matching flower species score points, while stepping on planted flowers or becoming blocked incurs penalties.
* **Player Count**: Typically 2 to 4 (or 5) players.

---

## 📐 Architecture & BGA Setup Plan

### Database Schema (`dbmodel.sql`)
* `garden_grid`: Stores grid coordinates, tile types, flower colors, and owner player IDs.
* `player_gardeners`: Stores position, inventory/flower reserve, and current turn state.
* `global_variables`: Turn counters, round tracking, active gardener.

### Front-End (`Game.js` & CSS)
* Colorful, organic SVG or Canvas rendering of the garden board.
* Clear visual distinction for flower species (color + symbolic pattern for accessibility/colorblind support).
* Tactile planting sounds via Web Audio API.
* Responsive auto-scaling down to 320px mobile viewports.
