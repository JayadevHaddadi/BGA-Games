# Gardens of Io: plan

## Options
| Id | Option | Decision |
| :--- | :--- | :--- |
| 100 | Lap flower bonus | Optional rule, default off. |
| 101 | Game mode | Competitive (default) or cooperative. |
| 102 | Tree placement | 1 Random (default): the server places the 3 trees at setup (replay and refresh safe), the game starts directly at the first turn. 2 Players place them: rulebook, state PlaceTree, unchanged. |

## Random tree placement
* Same number of trees as the rulebook (3). No circle is reserved at setup (the garden starts empty, Martians start off the garden).
* Rule: the non-tree circles must form one region (4-neighbour adjacency, the adjacency flowers connect by; this also implies 8-neighbour connectivity). Trees may sit anywhere else.
* `Rules::randomTrees()` re-draws up to 200 times, then falls back to `Rules::FALLBACK_TREES`.
* The option only applies to the initial layout. Players can still move trees with the special action (rulebook).
* Client reads trees from `getAllDatas`; nothing assumes fixed positions.
