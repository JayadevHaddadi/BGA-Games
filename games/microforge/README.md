# MicroForge 🌸🤖

*A 2–6 player tactical game of cheerful toy automation, dynamic markets, conveyor logistics, and deterministic friendly skirmishes in a blooming nature valley.*

---

## 🎨 Visual Concepts
* 🌸 **Bright Nature Tabletop Gameplay Mockup**: [`concept_art/happy_nature_bots_mockup.jpg`](concept_art/happy_nature_bots_mockup.jpg)
* ☀️ **Happy Toy Bots Box Art**: [`metadata_assets/happy_nature_box_art.jpg`](metadata_assets/happy_nature_box_art.jpg)

---

## 📖 The Setting: The Sunny Bloom Valley
Nestled in a hidden, sunlit meadow filled with blooming wild orchids, rainbow quartz crystals, and sparkling streamlets, a community of cheerful, smiling toy automata and colorful chibi bots awaken. Under warm blue skies, these friendly little tinkerers build pastel workshops, wooden watermills, and winding irrigation flumes to harvest solar nectar, river stones, and prism crystals—competing joyfully to construct the most prosperous automated garden valley!

---

## 🚶‍♂️ Movement Cost Formula (Triangular Scaling)
Transporting resources and moving units follows an escalating triangular cost formula:
* **1 hex** = **1 Credit**
* **2 hexes** = **3 Credits** (1 + 2)
* **3 hexes** = **6 Credits** (1 + 2 + 3)
* **4 hexes** = **10 Credits** (1 + 2 + 3 + 4)
* **Formula**: $\text{Cost} = \frac{N(N+1)}{2}$

*Strategic Design*: Short-distance local distribution is very cheap and efficient; long-distance continent-crossing transit is expensive, making forward hubs and local outposts deeply rewarding!

---

## 💰 Economic Calibration & Starting Capital
* **Starting Money**: Each player begins with a uniform **25 Credits** (or 30 Credits).
* **Trading Vault / Depot**: Generates **+5 to +10 Credits** per round.
* **Worker Bots**: Each Bot assigned to an Extractor or Factory boosts production by **+5 units/value**.
* **Operating Upkeep**:
  * Extractors and Factories cost **2 to 3 Credits** to run each round, requiring players to balance industrial expansion with active trade.
* **Decommission / Scrapping (50% Refund)**:
  * Players can demolish any building or decommission a Bot to immediately reclaim **50% of its initial cost in Credits**.

---

## 🧩 1. The Modular Hex Grid

### Board Scaling
* **2–3 Players**: **19 Hexes** (Center hex + 2 concentric rings) + 4 to 6 Perimeter Ports.
* **4–6 Players**: **37 Hexes** (Center hex + 3 concentric rings) + 8 to 12 Perimeter Ports.

### Richer Center Hexes
* **Outer Ring (Spawn / Lean)**: 1 resource slot or 1 building slot. Easy transit.
* **Middle Ring (Industrial Zone)**: 1 resource deposit + 1 building slot.
* **The Central Core (Hex #0 and adjacent)**: **The Prime Reactor & Mother Lode**. Dual extraction slots (Crystal + Bio-Fuel) and 2 building slots. Controlling the center gives massive economic fuel, making it a fiercely contested combat zone!

### Hex Anatomy
Each hex contains:
1. **Extraction Site (0, 1, or 2 slots)**: Iron Ore (⚙️), Energy Crystals (💎), or Bio-Fuel/Steam (🧪).
2. **Construction Slot (0, 1, or 2 slots)**: Ground to construct an Extractor, Factory, Defense Outpost, or Vault.
3. **6 Edge Borders**: Unified pathways for both logistics lines and bot movement. Most edges are open; selected edges feature broken terrain (cliffs/chasms) that block direct transit and create chokepoints.

---

## 🏭 2. Buildings & Structures

Players build structures on available slots in hexes they control:

1. **Extractor**: Placed on a resource deposit. Automatically produces the hex's resource at the start of the round.
2. **Assembly Plant (Factory)**: Consumes raw resources to manufacture finished goods (Worker Bots, Combat Mechs, Energy Cores).
3. **Defense Outpost (Turret)**: Adds +2 Combat Strength to defending units in this hex. Prevents stealth captures.
4. **Trading Vault (Depot)**: Increases local resource storage and generates +1 Credit per round.

---

## 🤖 3. Dual-Use Bots: Workers vs Mobile Scouts

Finished bots have versatile dual utility:
* **Worker Duty**: Station a Bot inside an Extractor or Factory to boost production by **+1 Resource/Product per round**.
* **Mobile Army Duty**: Deploy the Bot to the hex grid:
  * **Scout Bot** (1 Iron + 1 Fuel): Fast 2-hex movement, scouts fog-of-war, claims neutral hexes, raids opponent pipelines.
  * **Heavy Combat Mech** (2 Iron + 1 Crystal): 1-hex movement, high combat power, sieges opponent factories and ports.

---

## 💰 4. The 6-Resource Economy & Global Market

### The 6 Goods
* **3 Raw Resources**:
  1. ⚙️ **Iron Ore**
  2. 💎 **Energy Crystals**
  3. 🧪 **Bio-Fuel**
* **3 Finished Goods**:
  1. 🤖 **Worker/Scout Bots** (Iron + Fuel)
  2. 🛡️ **Combat Mechs** (2 Iron + Crystal)
  3. 🔋 **Energy Cores** (Crystal + Fuel)

### Global Market vs. Peripheral Ports ($2 \times N$)
* **Global Market**:
  * Always accessible on your turn.
  * Uses dynamic supply & demand tracks: buying drives price up; selling drops price for everyone!
* **Peripheral Ports ($2 \times N$ around the board edge)**:
  * Each port rolls a demand contract for **3 random goods out of the 6**.
  * If your pipeline or bot reaches a port, you gain access to **premium trade discounts and sell bonuses** (e.g. +3 Credits above global market value!).

---

## ⚔️ 5. Conflict & Warfare: Strictly NO DICE

Zero luck in combat resolution:
* **Battle Trigger**: When your mechs enter a hex containing enemy units or buildings.
* **Combat Resolution**:
  $$\text{Total Strength} = \text{Unit Base Power} + \text{Defense Outpost Bonus} + \text{Secret Tactics Card} + \text{Resources Burned}$$
* Both players choose a Tactics Card from their hand and may secretly burn up to 2 Fuel/Crystal tokens to boost power.
* Highest total wins. Loser retreats or scraps a damaged unit. If all defenders fall, the attacker captures the hex and its buildings!

---

## 🏆 6. Victory: The 5 VP Threshold & Mission Claim Fee

* The game ends immediately when a player reaches **5 Victory Points (VP)**!
* **Public Missions**: 4–5 public mission cards face-up (e.g., *Industrial Tycoon*, *Master of Ports*, *Prime Core Hegemony*, *Fleet Supremacy*).
* **The 5-Credit Claim Fee**:
  * Players can claim **1 mission per turn**.
  * To claim a mission, you must fulfill the condition AND pay **5 Credits**.
  * This guarantees that military/territory expansion must be backed by a healthy, functional economy!
