# Board Game Arena (BGA) Game Metadata & Branding Media Specifications

This document defines the exact dimensions, formats, transparency rules, and placement requirements for all game branding media uploaded via the **BGA Game Metadata Manager** ([Studio Control Panel](https://studio.boardgamearena.com/controlpanelgames) / [Production Control Panel](https://boardgamearena.com/controlpanelgames)).

---

## 🎨 Asset Summary Table

| Asset Name | Target Filename | Dimensions | Aspect Ratio | Format | Transparency | Purpose & Placement |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Game Box** | `box.png` | **280 × 280 px** | 1:1 (Square) | PNG | **Yes (Required)** | Main game catalog cards, search results, and lobby table cards. Should show 3D box or stylized game packshot. |
| **Game Icon** | `icon.png` | **50 × 50 px** | 1:1 (Square) | PNG | Optional (No border) | Favicons, tiny table status icons, notifications, and mobile quick menus. Must be sharp and recognizable at microscopic size. |
| **Banner Header** | `banner.jpg` | **1920 × 556 px** | ~3.45:1 | JPG/PNG | No | Panoramic backdrop across the game description page, top banner behind player tables, and social sharing embeds. |
| **Publisher Logo** | `publisher.png` | **280 × 280 px** | 1:1 (Square) | PNG | **Yes (Required)** | Displayed beside publisher credits on the game details page. |
| **Display / Screenshot** | `display.jpg` | **900 × 600 px** | 3:2 | JPG/PNG | No | Carousel screenshot in game information tab showing gameplay. |

---

## 📐 Detailed Design Rules

### 1. Game Box (`box.png` — 280 × 280 PNG)
* **Background MUST be transparent**: BGA renders this box directly over different light/dark theme backgrounds. Any solid white bounding box will look unprofessional.
* **Abstract Games / Cotton Case Games (e.g., nestorgames)**:
  * For nestorgames titles without traditional chipboard boxes, BGA standards permit a stylized 3D circular emblem, pouch render, or circular badge with the game's distinctive logo/wordmark with a subtle drop shadow.
* **Drop Shadow**: A subtle, soft drop shadow around the box art is recommended to float over BGA's background.

### 2. Game Icon (`icon.png` — 50 × 50 PNG)
* **No Borders**: Never include a 1px artificial black or white frame.
* **High Contrast**: Must be readable down to 16×16 px in some responsive browser views.
* **Focus**: Crop directly onto the most iconic piece (e.g. the Omega Ω symbol, a Yavalath hex cluster, or a clan crest).

### 3. Game Banner (`banner.jpg` — 1920 × 556 JPG)
* **Safe Zone**: Center 1200 × 500 px. Keep key logos and artwork away from the extreme left/right edges as mobile responsive views crop horizontally.
* **Gradient / Readability**: BGA overlays white title text and buttons on top of the banner. Keep the center or bottom third slightly darkened or clean so text remains legible.

### 4. Publisher Logo (`publisher.png` — 280 × 280 PNG)
* Transparent background.
* Clean vector-rasterized logo of the publisher (e.g., nestorgames, Z-Man Games, Brettco).

---

## ⚠️ Important Rules for the `img/` Directory vs `metadata_assets/`
* Keep official metadata images in `metadata_assets/` in your repository.
* Do **NOT** put 1920×556 banners or promotional graphics into your BGA code's `img/` folder:
  * Every image inside `img/` is loaded into memory during the table preloader phase. Large non-gameplay images cause table creation lag or timeout errors.
  * Metadata assets are uploaded separately in the BGA Studio Web Control Panel.

---

> **Update (Gardens of Enceladus, Oct 2026):** the sizes above differ from AGENTS.md section 5.D. `tools/bga_assets.py` now produces both banner sizes (1386x400 and 1920x556), so either upload works. Check `python3 tools/bga.py check <slug>` for the sizes the tool expects.
