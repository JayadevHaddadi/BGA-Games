# Hippos & Crocodiles — Rules & Implementation Plan

## 1. Game Overview
- **Designer**: Néstor Romeral Andrés
- **Publisher**: nestorgames (BGG ID: 9976)
- **BGG ID**: 51197
- **Players**: 2
- **Duration**: ~10–15 min

The object of the game is to place animal pieces into the river or lake. Each animal takes up 10 cells on a square grid. If on your turn you cannot place a piece on the board, you lose immediately; the last player to place a piece wins.

---

## 2. Components & Geometry

### Pieces (10 cells each)
1. **Hippo** (`hippo`):
   ```
   ###.
   ####
   ###.
   ```
   A 3×3 square body with 1 centered protruding snout cell (width 4, height 3 at rotation 0).
   8 pieces in reserve.
   Color: Magenta / Purple (`#d81b60` / `#9c27b0`).

2. **Crocodile** (`crocodile`):
   ```
   .#.
   .#.
   ###
   .#.
   ###
   .#.
   ```
   Central spine (height 6) with front legs at row 2 and rear legs at row 4 (width 3, height 6 at rotation 0).
   8 pieces in reserve.
   Color: Vivid Green (`#2e7d32` / `#43a047`).

### Boards
- **Rectangle** (Option 100 = 2): 20 rows × 10 columns (200 squares).
- **Lake** (Option 100 = 2): 14 rows × 14 columns (196 squares).

Grid coordinates: row $r \in [0, \text{rows}-1]$, col $c \in [0, \text{cols}-1]$.

---

## 3. Decisions Table

| Topic | Rulebook text | Decision | Confidence |
| :--- | :--- | :--- | :--- |
| **Rotations** | "Example after 2 moves" shows rotated animals | 4 cardinal rotations ($0^\circ, 90^\circ, 180^\circ, 270^\circ$) allowed for all pieces. | High |
| **Reflections** | Visual pieces have symmetry | Hippo snout is centered (horizontal flip = $180^\circ$ rotation). Crocodile is bilateral symmetric. 4 rotations cover all valid shapes. | High |
| **Species assignment** | "Starting player chooses whether he wants to play with the hippos or the crocodiles" | Player 1 defaults to Hippos, Player 2 to Crocodiles (or preference). | High |
| **Win condition** | "The game ends when a player can't place one of his animals, and therefore, he loses." | The active player with 0 legal placements loses. The opponent wins (Score = 1, Loser = 0). | High |
| **All pieces placed** | "The last player playing a piece wins." | If all 16 pieces are placed, the player who placed the last piece wins. | High |
| **Tie-breakers** | None in rulebook | Equal scores never happen (normal play ends by loss of moves). | High |

---

## 4. State Machine

1. `PlayerTurn` (Active player, state 10):
   - Arguments: list of remaining pieces in player's reserve, board state, valid placement positions for the player's species.
   - Action: `actPlace(int $rotation, int $r, int $c)`.
   - Zombie: auto-picks the first legal placement. If no legal placement exists, game transitions to `EndScore`.
2. `NextPlayer` (Game state, state 20):
   - Checks if the next player has any legal moves.
   - If next player has at least 1 legal move, activates them and transitions to `PlayerTurn`.
   - If next player has NO legal moves (blocked or out of pieces), transitions to `EndScore`.
3. `EndScore` (Game state, state 90):
   - Computes winner (opponent of player who had no moves, or last player who placed).
   - Sets `playerScore` (1 for winner, 0 for loser), `playerScoreAux` (number of pieces placed).
   - Sends `finalScoring` notification and transitions to built-in `ST_END_GAME` (99).

---

## 5. Client & UX Plan
- SVG/CSS rendering of Hippo and Crocodile pieces with distinct animal silhouettes and color-blind patterns.
- Responsive board scaling that fits phone width (375px) and desktop (1280px) cleanly with zero horizontal scroll.
- Staging flow: click/tap a cell $\to$ piece is previewed with Rotate and Confirm buttons $\to$ clicking Confirm calls `actPlace`.
- Sound on placement and game end.
