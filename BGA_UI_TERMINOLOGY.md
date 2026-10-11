# Board Game Arena (BGA) UI Terminology Cheat-Sheet

A quick reference guide for standard BGA layout components, terms, and code references when discussing UI features and modifications.

---

## 🧭 Key Layout Areas

```
+-----------------------------------------------------------------------------------+
|  [BGA Top Header / Navigation]                                                    |
+-----------------------------------------------------------------------------------+
|  STATUS BAR / ACTION BAR                                                          |
|  "Click any highlighted hexagon..."     [Confirm (Blue)] [Undo (Red)] [Action]    |
+----------------------------------------------------+------------------------------+
|  TOP BANNER / CONTROL BAR (Game specific)          |  PLAYER PANELS (Sidebar)     |
|  [Dice Pool: (3) (5) (6)]                          |  +------------------------+  |
|                                                    |  | [Avatar] Player Name   |  |
+----------------------------------------------------+  | Score Counter: 18 VP   |  |
|  GAME PLAY AREA (#game_play_area)                  |  | Panel Badges / Reserve |  |
|                                                    |  +------------------------+  |
|  +----------------------------------------------+  |  | [Avatar] Player 2      |  |
|  | BOARD SCALER (.game-board-scaler)            |  |  | Score Counter: 12 VP   |  |
|  |   +---------------------------------------+  |  |  +------------------------+  |
|  |   | GAME BOARD (#garden_board)            |  |  |                              |
|  |   |                                       |  |  | GAME LOG (#logs)             |
|  |   | [Score Track (1-50)]                  |  |  | "Player 1 rolled 3, 5, 6"    |
|  |   | [Cells / Hexagons / Garden Spots]     |  |  | "Player 1 placed flower..."  |
|  |   | [Color Picker / Action Popup]         |  |  |                              |
|  |   +---------------------------------------+  |  |                              |
|  +----------------------------------------------+  |                              |
+----------------------------------------------------+------------------------------+
```

---

## 📋 Terminology Glossary

| Term | What It Is | Purpose / Behavior | Code Reference |
| :--- | :--- | :--- | :--- |
| **Status Bar** (or **Action Bar**) | The horizontal white/cream bar at the top of the screen. | Displays the active instruction prompt (*"Click any highlighted hexagon..."*) and action buttons. | `this.bga.statusBar`, `#pagemaintitletext`, `#generalactions` |
| **Action Buttons** | Buttons hosted inside the Status Bar. | Follows traffic-light conventions: **Blue** (`'primary'`) = advance/confirm; **Red** (`'alert'`) = undo/pass/cancel; **White** (`'secondary'`) = optional side actions. | `this.bga.statusBar.addActionButton()` |
| **Player Panel** (or **Player Board**) | The player cards in the right sidebar (collapsible on mobile). | Shows player avatar, username, think clock, scores, and custom badges (e.g., remaining flower counts). | `this.bga.playerPanels`, `#player_boards`, `player_board_${pId}` |
| **Score Counter** | The VP number display inside each Player Panel. | Official animated counter that ticks smoothly when scores change. | `this.bga.playerPanels.getScoreCounter(pId)` |
| **Game Play Area** | The main container filling the table width. | Wraps the entire game surface between the Status Bar and the bottom of the table. | `#game_play_area` |
| **Board Scaler** | Dynamic viewport wrapper. | Scales the game board crisply to fit 100% of the screen width on phones or desktop without double-zoom distortion. | `.game-board-scaler` |
| **Game Board** | The interactive board container. | Holds SVG/DOM tiles, grid coordinates, pieces, tokens, and scoring tracks. | `#garden_board` |
| **Score Track** (or **VP Track**) | Numbered track on the physical board (e.g., 1–50 track in Gardens of Mars). | Spaces traversed by scoring pawns/martians. | `.gom_track_space`, `#gom_track_layer` |
| **Top Banner** / **Control Bar** | Custom sub-header placed right above the board. | Houses persistent turn resources, such as the active **Dice Pool** or card draft row. | `#gom_dice_pool_area`, `#gou_decks_row` |
| **Popup / Picker / Modal** | Floating dialog attached to a clicked spot on the board. | Lets a player select an action on the spot, like picking which flower color to plant. | `#gom_color_picker`, `#gou_color_picker` |
| **Game Log** | Scrollable text history at the bottom of the sidebar. | Prints formatted game notifications (*"Player placed a Red flower at (1, -2)"*). | `#logs`, `this.notify.all()` |
| **Tooltip** | Hover popup explaining game items or rules. | Native BGA tooltip for cards, tokens, or rule hints. | `this.bga.gameui.addTooltip()` |

---

## 💬 How to Communicate UI Requests Quickly

* *"In the **Status Bar**, make the font / flower icon size..."*
* *"In the **Player Panel**, enlarge the flower badges by 35%..."*
* *"In the **Color Picker popup**, increase button size..."*
* *"In the **Top Banner / Dice Pool**, rearrange..."*
* *"On the **Score Track**, update the marker behavior..."*
