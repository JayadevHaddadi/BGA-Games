# KILN (nestorgames) — Rules & BGA Adaptation Architecture

> **Game Designer**: Néstor Romeral Andrés (2019)  
> **Rulebook Revisions**: Nathan Morse  
> **Publisher**: nestorgames  
> **Players**: 2–4 players (plus 2-player 2-color advanced variant)  
> **Target Framework**: Modern BGA Studio (PHP 8.2+ State Engine, JavaScript/TypeScript UI, HTML5/CSS3 Grid & SVG)

---

## 📖 Table of Contents
1. [Game Overview & Theme](#-game-overview--theme)
2. [Components](#-components)
3. [Official Game Rules](#-official-game-rules)
   - [Setup](#setup)
   - [Turn Structure (Step-by-Step)](#turn-structure-step-by-step)
   - [Warehouse Placement Rules](#warehouse-placement-rules)
   - [Selling & Scoring Table](#selling--scoring-table)
   - [Extra Turns & Winning](#extra-turns--winning)
   - [Variants & Optional Rules](#variants--optional-rules)
4. [Strategic Concepts & Edge Cases](#-strategic-concepts--edge-cases)
5. [BGA System Architecture & Data Model](#-bga-system-architecture--data-model)
   - [Database Schema (`dbmodel.sql`)](#database-schema-dbmodelsql)
   - [State Machine Design (`states.php`)](#state-machine-design-statesphp)
   - [Turn Progression Flowchart](#turn-progression-flowchart)
6. [Interactive UI / UX Design & Responsive Scaling](#-interactive-ui--ux-design--responsive-scaling)
7. [BGA Guidelines & Gotchas Checklist](#-bga-guidelines--gotchas-checklist)

---

## 🎨 Game Overview & Theme

Where do all the wooden and clay tiles in tile-placement games come from? They are fired in the **KILN**!  
In **KILN**, 2 to 4 players operate a shared high-temperature kiln (a $6 \times 6$ tile grid). Players manipulate the kiln by pushing tiles through the rows and columns. When connected groups of tiles in a player's color form in the kiln, the player extracts and replicates that shape onto their private $5 \times 5$ **Warehouse**.

Once rows or columns in the warehouse are completely filled, they can be sold to game publishers for victory points (following a triangular progression). The first player to reach the winning score threshold wins the game.

---

## 🧱 Components

1. **Main Kiln Board**:
   - Inner **Kiln Area**: $6 \times 6$ grid holding 36 tiles.
   - **Border Cells**: 24 outer arrow cells (6 on each side: North, East, South, West).
   - **Scoring Track**: Track numbered from 0 to 29, featuring striped bonus spaces on **5, 8, 14, 17, 19, 23, 26**.
2. **Tiles (37 total)**:
   - 36 Colored Tiles: 9 Red, 9 Blue, 9 Green, 9 Yellow.
   - 1 Neutral Black Tile.
3. **Player Warehouses**:
   - $5 \times 5$ square storage grid for each player.
   - Fixed orientation: must always remain strictly aligned with the central kiln (no rotation or reflection!).
   - Built-in pricing chart:
     - 1 Line = **1 pt**
     - 2 Lines = **3 pts**
     - 3 Lines = **6 pts**
     - 4 Lines = **10 pts**
     - 5 Lines = **15 pts**
4. **Markers & Tokens**:
   - 1 Goal marker disc (Black disc placed at target score, default 17).
   - Player scoring discs (1 per player color).
   - 7 Grey bonus discs (used for the "Heating up the Kiln" variant).

---

## 📜 Official Game Rules

### Setup
1. **Kiln Grid Initialization**:
   - Randomly arrange the 36 colored tiles (9 of each color: Red, Blue, Green, Yellow) inside the $6 \times 6$ kiln.
   - Place the **Black neutral tile** onto one randomly chosen border arrow cell (this becomes the initial *outer tile*).
2. **Player Allocation**:
   - Each player selects a color and receives the matching $5 \times 5$ warehouse and scoring disc.
   - Place player scoring discs at the starting space (0) of the score track.
   - Set the Black goal marker at the chosen target score (recommended **17** for first game; can be set up to 29).
3. **Determine First Player**:
   - Calculate the size of the **largest connected group** (orthogonal adjacency only) for each player's color in the initial kiln.
   - The player whose largest group is the **smallest** goes first (compensating for a disadvantageous initial board).
   - *Tie-breaker*: Compare the 2nd largest group, then 3rd largest, etc. If still tied, pick randomly.

---

### Turn Structure (Step-by-Step)

The player who holds the turn must execute the following **6 steps in strict sequential order**:

#### Step 1: Relocate the Outer Tile
- Pick up the current **outer tile** from its current border cell.
- Place it onto **any different border cell** among the remaining 23 border arrow cells.
- *(Note: A player cannot leave it in the same border cell it currently occupies).*

#### Step 2: Push the Tile into the Kiln
- Push the outer tile into the kiln along the row or column indicated by the arrow.
- All 6 tiles along that line slide forward by 1 position.
- The tile at the far opposite end is pushed out of the kiln into the opposite border cell, becoming the **new outer tile**.

#### Step 3: Identify the Largest Connected Group
- Inspect the active player's color on the kiln board.
- Identify the **largest orthogonally connected group** (adjacent by edge, corners do not count).
- If there is a tie for the largest size, the active player chooses which one of the tied groups to replicate.

#### Step 4: Copy the Shape into the Warehouse
- The player copies the exact polyomino shape into empty cells on their $5 \times 5$ warehouse.
- **Strict Orientation Rule**: The shape **CANNOT** be rotated and **CANNOT** be reflected. It must match the kiln's orientation exactly (translation only).
- **Legality Rule**: All cells of the shape must land on currently **empty** warehouse cells (cells not already painted/marked).
- **If Impossible**: If the largest shape does not fit anywhere in the warehouse, this step is **skipped** entirely.
  > *Rule clarification*: A player cannot choose a smaller group instead. Only the largest group may be copied!

#### Step 5: Optional Selling Phase (Scoring)
- The player may optionally sell either **complete rows** OR **complete columns** (where all 5 cells are filled).
- **Either / Or Constraint**: You can sell $k$ rows ($1 \le k \le 5$) OR $k$ columns ($1 \le k \le 5$). You **cannot** sell a mixture of rows and columns in the same turn!
- **Calculate Points**:
  $$\text{Points} = T_k = \frac{k(k + 1)}{2}$$
  | Completed Lines Sold | Points Earned |
  | :---: | :---: |
  | **1 Line** | **1 pt** |
  | **2 Lines** | **3 pts** |
  | **3 Lines** | **6 pts** |
  | **4 Lines** | **10 pts** |
  | **5 Lines** (Wipeout) | **15 pts** |
- **Advance Score**: Advance the scoring disc by the points earned.
- **Clear Sold Lines**: Erase the sold rows or columns from the warehouse. Any tiles in unsold perpendicular lines remain intact.

#### Step 6: Extra Turn Check
- If the tile pushed out in **Step 2** was the **Black neutral tile**, the active player immediately takes an **extra turn**!
- Otherwise, play passes clockwise to the next player.

---

### Extra Turns & Winning
- **Instant Win**: The instant a player's score disc reaches or exceeds the Black goal marker (e.g. 17 points), the game ends immediately and that player wins.
- **Black Tile Incentive**: Pushing the neutral Black tile out of the kiln awards an immediate extra turn. This rewards tactical line-push calculations.

---

### Variants & Optional Rules

1. **Heating up the Kiln (Grey Bonus Tokens)**:
   - Place grey tokens on target track numbers: **5, 8, 14, 17, 19, 23, 26**.
   - *(Mathematical significance: These are the only integers under 30 that are neither triangular numbers nor the sum of two triangular numbers).*
   - When a player's score marker lands *exactly* on a space with a grey token, that player receives an **extra turn**.
   - *Rule*: Extra turns are not cumulative (max 1 extra turn per turn sequence).
2. **Fixing the Mess**:
   - If after pushing, a player cannot fit their largest shape into the warehouse, they are allowed to **erase 1 painted cell** from their warehouse to unclog a bottleneck.
3. **Advanced 2-Player Variant (2 Colors per Player)**:
   - Each player controls 2 colors (with 2 private warehouses and 2 score discs).
   - On their turn, the player makes 1 push, copies 1 shape for Color A to Warehouse A, copies 1 shape for Color B to Warehouse B, and can sell from Warehouse A and then Warehouse B.

---

## 🧠 Strategic Concepts & Edge Cases

1. **Tetris Packing vs. Fragmentation**:
   - Small shapes ($1$–$3$ tiles) are easy to place and help fill gaps.
   - Large shapes ($5+$ tiles) take massive warehouse space. If you fail to sell, a giant 6-tile shape might become unplaceable, effectively skipping your placement turns until you clear space.
2. **Defensive Board Play**:
   - When an opponent's warehouse is nearly full, pushing tiles to merge their colors into an oversized $7$-tile monstrosity can paralyze them.
   - Conversely, if an opponent has an empty warehouse, splitting their color into singletons deprives them of high-tempo packing.
3. **Triangular Leap Calculations**:
   - Since $1 \to 1$, $2 \to 3$, $3 \to 6$, $4 \to 10$, $5 \to 15$, single-line sales are highly inefficient.
   - Going from 11 points to 17 requires either a 6-point sale (3 lines) or two 3-point sales (2 lines each). Planning the exact "scoring jump" determines who reaches the goal first.

---

## 💻 BGA System Architecture & Data Model

### Database Schema (`dbmodel.sql`)

```sql
-- Main kiln board grid (6x6)
CREATE TABLE IF NOT EXISTS `kiln_board` (
    `x` TINYINT UNSIGNED NOT NULL, -- 0 to 5
    `y` TINYINT UNSIGNED NOT NULL, -- 0 to 5
    `tile_color` VARCHAR(16) NOT NULL, -- 'red', 'blue', 'green', 'yellow', 'black'
    PRIMARY KEY (`x`, `y`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Outer tile state
CREATE TABLE IF NOT EXISTS `outer_tile` (
    `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `border_slot` TINYINT UNSIGNED NOT NULL, -- 0 to 23 (border arrow index)
    `tile_color` VARCHAR(16) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Player warehouses (5x5 per player)
CREATE TABLE IF NOT EXISTS `player_warehouse` (
    `player_id` INT UNSIGNED NOT NULL,
    `wx` TINYINT UNSIGNED NOT NULL, -- 0 to 4
    `wy` TINYINT UNSIGNED NOT NULL, -- 0 to 4
    `filled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`, `wx`, `wy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Game settings & global state tracking
-- Uses BGA's built-in `globals` / `gamestate` table for:
-- 'goal_score' (default 17)
-- 'last_pushed_black' (1/0)
-- 'variant_grey_tokens' (1/0)
-- 'variant_fixing_mess' (1/0)
```

---

### Border Slot Indexing Convention ($0$ to $23$)

We number the 24 border arrow slots clockwise around the perimeter:
- **North Border** ($0$ to $5$): pushing South into columns $x = 0 \dots 5$, row $y = 0$.
- **East Border** ($6$ to $11$): pushing West into rows $y = 0 \dots 5$, col $x = 5$.
- **South Border** ($12$ to $17$): pushing North into columns $x = 5 \dots 0$ (or $0 \dots 5$), row $y = 5$.
- **West Border** ($18$ to $23$): pushing East into rows $y = 5 \dots 0$ (or $0 \dots 5$), col $x = 0$.

When pushed:
$$\text{Entering Slot } S \implies \text{Line shifts } \implies \text{Ejected tile lands in opposite slot } S_{\text{opp}}$$

---

### State Machine Design (`states.php`)

```php
$machinestates = [
    1 => [
        "name" => "gameSetup",
        "description" => "",
        "type" => "manager",
        "action" => "stGameSetup",
        "transitions" => ["" => 10]
    ],

    // Active player selects border slot and pushes
    10 => [
        "name" => "playerTurnMoveOuterTile",
        "description" => clienttranslate('${actplayer} must select a border cell to push the outer tile'),
        "descriptionmyturn" => clienttranslate('${you} must select a border cell to push the outer tile'),
        "type" => "activeplayer",
        "possibleactions" => ["actPushTile"],
        "transitions" => [
            "resolvePush" => 20,
            "zombiePass" => 99
        ]
    ],

    // Engine determines largest group; if tied, player chooses which group
    20 => [
        "name" => "playerTurnSelectGroup",
        "description" => clienttranslate('${actplayer} must choose which tied group to copy'),
        "descriptionmyturn" => clienttranslate('${you} must choose which tied group to copy'),
        "type" => "activeplayer",
        "possibleactions" => ["actSelectGroup"],
        "transitions" => [
            "placeGroup" => 30,
            "skipPlacement" => 40,
            "zombiePass" => 99
        ]
    ],

    // Player places the polyomino onto their 5x5 warehouse
    30 => [
        "name" => "playerTurnPlaceShape",
        "description" => clienttranslate('${actplayer} must position the tile shape in their warehouse'),
        "descriptionmyturn" => clienttranslate('${you} must position the tile shape in your warehouse'),
        "type" => "activeplayer",
        "possibleactions" => ["actPlaceShape", "actCannotFit"],
        "transitions" => [
            "shapePlaced" => 40,
            "skipPlacement" => 40,
            "fixMess" => 35,
            "zombiePass" => 99
        ]
    ],

    // Optional: Fixing the mess (erase 1 cell if cannot fit)
    35 => [
        "name" => "playerTurnFixMess",
        "description" => clienttranslate('${actplayer} may erase 1 cell from their warehouse'),
        "descriptionmyturn" => clienttranslate('${you} may erase 1 cell from your warehouse'),
        "type" => "activeplayer",
        "possibleactions" => ["actEraseCell", "actSkipFix"],
        "transitions" => [
            "doneFix" => 40,
            "zombiePass" => 99
        ]
    ],

    // Selling phase: complete rows or columns
    40 => [
        "name" => "playerTurnSell",
        "description" => clienttranslate('${actplayer} may sell completed rows or columns'),
        "descriptionmyturn" => clienttranslate('${you} may sell completed rows or columns'),
        "type" => "activeplayer",
        "possibleactions" => ["actSellLines", "actPassSell"],
        "transitions" => [
            "nextTurn" => 50,
            "gameWon" => 99,
            "zombiePass" => 99
        ]
    ],

    // Turn resolution: checks win, black tile / grey bonus extra turns
    50 => [
        "name" => "resolveTurnEnd",
        "type" => "game",
        "action" => "stResolveTurnEnd",
        "transitions" => [
            "extraTurn" => 10,
            "nextPlayer" => 10,
            "endGame" => 99
        ]
    ],

    99 => [
        "name" => "gameEnd",
        "description" => clienttranslate("End of game"),
        "type" => "manager",
        "action" => "stGameEnd",
        "args" => "argGameEnd"
    ]
];
```

---

## 🎯 Interactive UI / UX Design & Responsive Scaling

1. **Board Layout**:
   - Left side / Top on mobile: **Score Track** ($0 \to 29$) with disc markers and grey bonus emblems.
   - Center: The **Kiln ($6 \times 6$)** with 24 animated arrow slots around its boundary.
   - Right side / Bottom: **Player Warehouses ($5 \times 5$)** with live completion indicators on rows and columns.
2. **Push Visual Animation**:
   - When a border arrow is clicked, the outer tile glides into the row/column.
   - CSS `transform: translate()` shifts the 6 interior tiles simultaneously ($300\text{ms}$).
   - The opposite tile slides out smoothly into the destination border arrow.
3. **Polyomino Placement Preview**:
   - Connected groups on the kiln highlight with pulsing colored glows.
   - Hovering over valid cells in the warehouse displays a ghost preview of the shape.
   - Invalid placements (overlapping filled cells or out of bounds) glow red.
4. **Selling Interface**:
   - Filled rows and columns display a sparkling gold border with point tags ($+1, +3, +6$).
   - Toggle buttons: `Sell Rows (3 pts)` / `Sell Columns (1 pt)` / `Pass (Save for later)`.
5. **Responsive Sizing (`AGENTS.md` standard)**:
   - Wrapper `.game-board-scaler` dynamically scales from $320\text{px}$ up to desktop width without double-scaling.
   - Touch targets for arrows and warehouse cells are strictly $\ge 40\text{px} \times 40\text{px}$ on touch devices.

---

## 🛡️ BGA Guidelines & Gotchas Checklist

- [x] **Mistake 1**: `setupNewGame` properly initializes `player` table, calls `$this->reloadPlayersBasicInfos()`, sets stats, calls `changeActivePlayer()`.
- [x] **Mistake 2**: Every active state implements `public function zombie(int $playerId): string`.
- [x] **Mistake 3**: PlayerStats parameter order: `inc(name, delta, playerId)` (delta 2nd, playerId 3rd).
- [x] **Mistake 4**: Client uses defensive player checking (`isCurrentPlayerActive`).
- [x] **Mistake 5**: No `TRUNCATE TABLE` (using `DELETE FROM`).
- [x] **Mistake 6**: Metadata images isolated in `metadata_assets/`, gameplay assets in `img/`.
- [x] **Mistake 7**: `ensureSchema()` implemented in `Game.php`.
- [x] **Mistake 8**: Action button colors adhere strictly to traffic light standards (`'primary'` for Blue, `'alert'` for Red, `'secondary'` for White).
