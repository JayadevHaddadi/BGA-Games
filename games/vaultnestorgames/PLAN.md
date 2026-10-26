# Vault (nestorgames): rules, decisions and task list

Designer: Néstor Romeral Andrés (nestorgames, 2015).
Rulebook: `VAULT_EN.pdf` / `rules.txt` ("Rules and Rule book (c) 2015 Néstor Romeral Andrés").
BGG: https://boardgamegeek.com/boardgame/186404/vault
BGA Studio project: `vaultnestorgames` (local: `games/vaultnestorgames/bga/`).

---

## 1. Rules as implemented

### Material
- 10x10 square board checkered with 4 tones. Tones correspond to $(x \pmod 2, y \pmod 2) \in \{(0,0), (0,1), (1,0), (1,1)\}$, ensuring every jump lands on a space with the exact same tone as the origin square.
- 10 White pawns, 10 Black pawns.

### Setup
- White occupies Rank 0 ($y = 0$, $x \in [0, 9]$).
- Black occupies Rank 9 ($y = 9$, $x \in [0, 9]$).
- White plays first. Players alternate turns.

### Turn
On their turn, the active player must perform exactly one action: **Walk** or **Jump**.

1. **Walk**:
   - Select 1 friendly pawn at $(x, y)$.
   - Move it to any adjacent empty cell (8 directions, King movement in chess: $\Delta x \in \{-1, 0, 1\}, \Delta y \in \{-1, 0, 1\}, (\Delta x, \Delta y) \ne (0, 0)$).
   - Target space must be empty and on the board.
   - Captures are **never** made by walking.

2. **Jump**:
   - Select 2 friendly pawns: jumping pawn $A = (x_1, y_1)$ and pivot pawn $B = (x_2, y_2)$ ($A \ne B$).
   - The jump vector is $\vec{v} = B - A = (x_2 - x_1, y_2 - y_1)$.
   - The landing destination is $C = B + \vec{v} = 2B - A = (2x_2 - x_1, 2y_2 - y_1)$.
   - Jumping is not restricted to orthogonal or diagonal directions; any arbitrary vector formed by two friendly pieces is valid.
   - Intervening spaces between $A$ and $C$ do not need to be empty.
   - Destination $C$ must be within board bounds ($0 \le x_C \le 9$, $0 \le y_C \le 9$).
   - Destination $C$ cannot be occupied by a friendly piece.
   - If destination $C$ is empty, $A$ moves to $C$.
   - If destination $C$ contains an enemy piece, the enemy piece is captured and removed from the game, and $A$ lands on $C$.
   - Exactly one jump per turn (no multi-jumps).

### Game End & Victory Conditions
Checked at the **start of each player's turn**:
1. **Back Rank Goal**:
   - If at the *start of your turn* you have at least one pawn on your opponent's back rank (Rank 9 for White, Rank 0 for Black), you win!
   - (Note: Reaching the back rank on your turn does not win immediately; the opponent has one turn to react, e.g. capture that pawn).
2. **No Legal Moves**:
   - If at the start of your turn you have no legal move available (neither walk nor jump), you lose.
3. **Mutual Agreement Draw**:
   - Per the rulebook: "Players may agree on a draw at any moment during the game." Handled via standard BGA mutual draw proposal.

### Scoring
- Abstract combinatorial game with binary outcome:
  - Winner: 1 point (`playerScore = 1`).
  - Loser: 0 points (`playerScore = 0`).
  - Draw: 0 points each (`playerScore = 0`).
  - No invented tie-breakers (Rule 11).

---

## 2. Implementation decisions (where the rulebook is open)

| # | Topic | Decision | Confidence |
|---|---|---|---|
| D1 | Studio project naming | `vaultnestorgames` with game name `"Vault (nestorgames)"`. The slug `vault` is already taken on BGA production by an unrelated game (*Vault: A Solitaire Dice Game*). BGA official convention uses publisher disambiguation. `re.sub(r'[^a-z0-9]', '', 'Vault (nestorgames)'.lower()) == 'vaultnestorgames'` satisfies Rule 25 with 0 errors. | High: aligns with BGA policy and Rule 25 |
| D2 | Board dimensions | Configurable board size via game option 100: 5x5, 6x6, 7x7, 8x8, 9x9, 10x10, with 8x8 as the default. Parity and tone invariance hold identically for both even and odd grid dimensions. | High: requested by user, supported by rules |
| D3 | Board coloring | 4 distinct checkered tones for the 4 parity classes $(x \bmod 2, y \bmod 2)$. Makes all jump landing squares immediately recognizable and verified mathematically. | High: official rulebook note |
| D4 | Vector jump directions | All possible straight vectors $2B - A$ (orthogonal, diagonal, knight jumps, extended ratios). No path obstructions checked between start, pivot, and destination. | High: rulebook explicitly states jumping is like a mirror in any direction and intervening spaces need not be empty |
| D5 | Win check timing | Checked strictly at the start of the player's turn (`onEnteringState`). | High: explicitly written in rulebook: *"If at the start of your turn you have at least one piece on your opponent's back rank then you have won."* |
| D6 | Simultaneous back rank | If player 1 enters rank $N-1$, and player 2 on their turn also enters player 1's back rank (rank 0), when player 1's turn begins, player 1 starts their turn with a pawn on rank $N-1$ and wins first. | High: direct corollary of turn-start evaluation |
| D7 | Stalemate / Inactivity | Standard BGA draw proposal and turn limit counter to prevent infinite cycles. | High |
| D8 | Zombie mode | Level 1: plays a legal move if available (preferring forward/jump moves), or passes/resigns. | High |
| D9 | Last move indicators | Non-darkening border indicators: dashed amber inner border (`::after` inset) on origin cell, solid warm gold inner border on destination cell, dashed outline on pivot. Leaves cell background tones completely intact and clear. | High: requested by user |

---

## 3. Task list
- [x] Read rulebook, download PDF and rules text (`VAULT_EN.pdf`, `rules.txt`)
- [x] Scaffold project (`vaultnestorgames`) with `bga.py new`
- [x] Setup `metadata.json`, `gameinfos.jsonc`, `PLAN.md`
- [x] Register in `tools/sync.py`, `.github/workflows/bga-deploy.yml`, and `LICENSING_TRACKER.md`
- [x] Implement pure rules engine in `bga/modules/php/Rules.php`
- [x] Implement CLI tests in `tests/rules_test.php` (2731 checks pass)
- [x] Implement server logic (`Game.php`, `States/PlayerTurn.php`, `States/EndScore.php`, `dbmodel.sql`, `stats.jsonc`)
- [x] Implement client interface (`Game.js`, `vaultnestorgames.css`) with 4-toned board, tap-to-stage vector jumping, highlight legal moves
- [x] Art and metadata assets in `bga/metadata_assets/` and `design/make_assets.py`
- [x] MediaWiki rulebook in `bga/GAMEHELP.wiki`
- [x] Run `python3 tools/bga.py check vaultnestorgames` (0 FAIL)
- [x] Browser harness testing (`tests/harness/index.html` at 375px and 1280px, 0 errors)
- [ ] Deploy and verify on BGA Studio live table
