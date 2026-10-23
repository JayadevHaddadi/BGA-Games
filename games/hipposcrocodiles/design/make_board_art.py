"""Generates every Hippos & Crocodiles image from the cell masks in Rules.php (single source of truth).

Boards (bga/img/board_<map>.png): one uniform water colour, thin white grid lines on the mask cells, and a green
rounded frame that follows the mask outline. No title, logo or publisher name. The grid starts at (PAD, PAD) and
every cell is PITCH px, so Game.js needs only PITCH / PAD (no per-map table).
Tiles: piece_hippo.png / piece_crocodile.png (cropped and recoloured from the print) and piece_trunk.png (drawn).
Also writes tests/harness/masks.json for the browser harness.

Run: python3 games/hipposcrocodiles/design/make_board_art.py
"""
import json
import re
from pathlib import Path
from PIL import Image, ImageDraw

GAME = Path(__file__).resolve().parent.parent
OUT = GAME / 'bga' / 'img'
OUT.mkdir(parents=True, exist_ok=True)

PITCH = 60        # px per cell (keep in sync with Game.js)
PAD = 18          # frame thickness around the grid (keep in sync with Game.js)
SS = 4            # supersampling factor
FRAME = (51, 204, 51)
WATER = (79, 168, 204)
LINE = (255, 255, 255)
LINE_W = 2

NAMES = {1: 'square', 2: 'rectangle', 3: 'lagoon', 4: 'pool'}


def read_masks():
    src = (GAME / 'bga' / 'modules' / 'php' / 'Rules.php').read_text(encoding='utf-8')
    block = src[src.index('public const MASKS'):]
    block = block[:block.index('];\n    ];') + 3] if '];\n    ];' in block else block
    masks = {}
    for m in re.finditer(r"(\d) => \[((?:\s*'[#.]+',)+)\s*\]", block):
        masks[int(m.group(1))] = re.findall(r"'([#.]+)'", m.group(2))
    assert sorted(masks) == [1, 2, 3, 4], masks.keys()
    return masks


def build(rows):
    nr, nc = len(rows), len(rows[0])
    w, h = nc * PITCH + 2 * PAD, nr * PITCH + 2 * PAD
    P, A = PITCH * SS, PAD * SS
    big = Image.new('RGBA', (w * SS, h * SS), (0, 0, 0, 0))
    d = ImageDraw.Draw(big)
    cells = [(r, c) for r in range(nr) for c in range(nc) if rows[r][c] == '#']
    # frame: every cell grown by PAD with rounded corners (union follows the outline)
    for r, c in cells:
        x0, y0 = A + c * P, A + r * P
        d.rounded_rectangle((x0 - A, y0 - A, x0 + P + A, y0 + P + A), radius=A, fill=FRAME)
    # water
    for r, c in cells:
        x0, y0 = A + c * P, A + r * P
        d.rectangle((x0, y0, x0 + P - 1, y0 + P - 1), fill=WATER)
    # grid lines centred on cell borders, only around mask cells
    lw = LINE_W * SS // 2
    for r, c in cells:
        x0, y0 = A + c * P, A + r * P
        for (ax, ay, bx, by) in ((x0, y0, x0 + P, y0), (x0, y0 + P, x0 + P, y0 + P),
                                 (x0, y0, x0, y0 + P), (x0 + P, y0, x0 + P, y0 + P)):
            d.rectangle((min(ax, bx) - lw, min(ay, by) - lw, max(ax, bx) + lw - 1, max(ay, by) + lw - 1), fill=LINE)
    return big.resize((w, h), Image.LANCZOS)


masks = read_masks()
art = {}
for mid, rows in masks.items():
    img = build(rows)
    img.save(OUT / f'board_{NAMES[mid]}.png', optimize=True)
    art[NAMES[mid]] = {'id': mid, 'rows': len(rows), 'cols': len(rows[0]), 'cells': sum(r.count('#') for r in rows),
                       'w': img.width, 'h': img.height}
(GAME / 'tests' / 'harness' / 'masks.json').write_text(json.dumps({str(k): v for k, v in masks.items()}))

# Trunk tile: 3x1 cells, cyan tile outline like the animal tiles, wood brown with simple grain.
CELL = 72


def trunk_tile():
    W, H = 3 * CELL * SS, CELL * SS
    im = Image.new('RGBA', (W, H), (0, 204, 255, 255))
    d = ImageDraw.Draw(im)
    m = round(0.14 * CELL * SS)
    d.rounded_rectangle((m, m, W - m - 1, H - m - 1), radius=m // 2, fill=(150, 100, 60, 255))
    # grain: a few darker and lighter horizontal wavy lines
    import math
    for i, (yy, col) in enumerate([(0.32, (120, 78, 44)), (0.5, (176, 124, 78)), (0.68, (120, 78, 44)), (0.42, (132, 88, 52)), (0.6, (166, 114, 70))]):
        pts = []
        for x in range(m + 6 * SS, W - m - 6 * SS, 4 * SS):
            pts.append((x, H * yy + math.sin(x / (CELL * SS * 0.45) + i * 1.7) * 0.05 * H))
        d.line(pts, fill=col + (255,), width=SS * 2)
    # end rings
    for x in (m + 0.5 * (H - 2 * m), W - m - 0.5 * (H - 2 * m)):
        cy = H / 2
        for rr, col in ((0.30, (112, 72, 40)), (0.17, (176, 124, 78))):
            r0 = rr * H
            d.ellipse((x - r0 * 0.55, cy - r0, x + r0 * 0.55, cy + r0), outline=col + (255,), width=SS)
    return im.resize((3 * CELL, CELL), Image.LANCZOS)


trunk_tile().save(OUT / 'piece_trunk.png', optimize=True)

# Tiles: crop from hcpieces.png (cyan tile outline + silhouette), pitch 118 px, scaled to 72 px per cell.
pieces = Image.open(GAME / 'hcpieces.png').convert('RGBA')
CELL = 72
SP = 118.0
# Softer, natural animal colours instead of the print's magenta / neon green.
# Each pixel is a blend of the cyan tile and the animal colour; keep the blend, swap the colour.
CYAN = (0, 204, 255)
ANIMAL = {'hippo': (124, 92, 72), 'crocodile': (74, 112, 52)}
def recolor(im, name):
    px = im.load()
    new = ANIMAL[name]
    for y in range(im.height):
        for x in range(im.width):
            r, g, b, a = px[x, y]
            if a == 0:
                continue
            t = r / 255 if name == 'hippo' else 1 - b / 255   # share of animal colour
            t = max(0.0, min(1.0, t))
            px[x, y] = tuple(round(t * n + (1 - t) * c) for n, c in zip(new, CYAN)) + (a,)
    return im

def tile(name, box, cols, rows):
    im = pieces.crop(box).resize((cols * CELL, rows * CELL), Image.LANCZOS)
    im = recolor(im, name)
    im.save(OUT / f'piece_{name}.png', optimize=True)
tile('hippo', (437, 609, 909, 963), 4, 3)
tile('crocodile', (488, 1032, 843, 1741), 3, 6)

print(json.dumps(art))
