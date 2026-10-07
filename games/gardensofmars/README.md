# Gardens of Mars 🪐🌺

*Designed by Néstor Romeral Andrés (nestorgames, 2011)*
*BGG Link: https://boardgamegeek.com/boardgame/89319/gardens-of-mars*

---

## 📖 Overview
*Gardens of Mars* is a strategic spatial game for **2 to 5 players** (ages 8+). Players take on the role of Martian gardeners cultivating exotic alien flowers across a Martian hexagonal landscape.

---

## 🧩 Components
* **Hexagonal Martian Board**: Hex grid representing the red soil of Mars.
* **60 Flower Tokens**: In 6 vibrant alien colors (Blue, Yellow, White, Gray, Red, Green).
* **Gardener Pawns**: 1 gardener pawn per player.
* **Standard 6-Sided Dice**: For gardener movement and drafting.

---

## 🎲 Gameplay & Turn Structure

1. **Dice Pool / Rolling**:
   * If no dice remain on the table at the start of your turn: Count the number of empty adjacent hexes surrounding your gardener pawn. Grab that exact number of dice from the supply and roll them to establish the new pool.
2. **Move Gardener**:
   * Select 1 die from the pool and move your gardener straight or along legal hex paths by that exact number of spaces.
   * Return the used die to the pool/supply.
3. **Planting & Penalties**:
   * **Empty Hex**: Plant a flower of your choice (or drafted color) on the destination space.
   * **Occupied Hex / Blocked Move**: If unable to make a legal move or forced onto an occupied space, suffer a -1 point penalty.
4. **Scoring Flower Connections**:
   * When a flower is planted, score points equal to the size of the connected group of flowers of the same color!
5. **End Game**:
   * When all flowers are planted or no legal placements remain. High score wins!

---

## 🛠️ BGA Architecture
* **Slot ID**: `gardensofmars` (Test slot: `gardensofmarstest`)
* **PHP Framework**: Modern BGA OOP State Pattern (PHP 8.2)
* **Client**: Modern TypeScript / ES6 with dynamic SVG board scaler and audio effects
