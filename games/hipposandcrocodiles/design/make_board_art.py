"""Crops the publisher board (board_hippos.png) into River (20x10) and Lake (14x14) boards plus piece tiles.

Grid measured from the white lines: 14 x 20 cells of pitch ~59.07 px, origin (150.5, 233.5); the River is the
central 10 columns, the Lake the 14 blue rows (rows 3..16) across the full width.
Outputs to bga/img/: board_river.png, board_lake.png, piece_hippo.png, piece_crocodile.png and prints the JS
constants (image size, grid origin in the cropped image, pitch) to paste into Game.js (BOARD_ART).

Run: python3 games/hipposandcrocodiles/design/make_board_art.py
"""
import json
from pathlib import Path
from PIL import Image, ImageDraw

GAME = Path(__file__).resolve().parent.parent
OUT = GAME / 'bga' / 'img'
OUT.mkdir(parents=True, exist_ok=True)

PITCH_X = (977.5 - 150.5) / 14
PITCH_Y = (1414.5 - 233.5) / 20
PITCH = (PITCH_X + PITCH_Y) / 2
GX, GY = 150.5, 233.5
PAD = 18          # frame thickness around the grid
FRAME = (51, 204, 51)

src = Image.open(GAME / 'board_hippos.png').convert('RGB')


def build(name, col0, row0, cols, rows):
    gw, gh = round(cols * PITCH), round(rows * PITCH)
    x0 = GX + col0 * PITCH_X
    y0 = GY + row0 * PITCH_Y
    grid = src.crop((round(x0), round(y0), round(x0) + gw + 1, round(y0) + gh + 1))
    # Green frame only: no publisher logo / title on the board.
    w = gw + 2 * PAD
    h = gh + 2 * PAD
    img = Image.new('RGBA', (w, h), (255, 255, 255, 0))
    d = ImageDraw.Draw(img)
    gx = PAD
    gy = PAD
    d.rounded_rectangle((gx - PAD, gy - PAD, gx + gw + PAD, gy + gh + PAD), radius=PAD + 8, fill=FRAME)
    img.paste(grid, (gx, gy))
    img.save(OUT / f'board_{name}.png', optimize=True)
    # sub-pixel origin: round(x0) crop offset is compensated
    ox = gx - (round(x0) - x0)
    oy = gy - (round(y0) - y0)
    return {'img': f'board_{name}.png', 'w': w, 'h': h, 'x': round(ox, 2), 'y': round(oy, 2),
            'gw': round(cols * PITCH, 2), 'gh': round(rows * PITCH, 2), 'pitch': round(PITCH, 3)}


art = {'river': build('river', 2, 0, 10, 20), 'lake': build('lake', 0, 3, 14, 14)}

# Tiles: crop from hcpieces.png (cyan tile outline + silhouette), pitch 118 px, scaled to 72 px per cell.
pieces = Image.open(GAME / 'hcpieces.png').convert('RGBA')
CELL = 72
SP = 118.0
def tile(name, box, cols, rows):
    im = pieces.crop(box).resize((cols * CELL, rows * CELL), Image.LANCZOS)
    im.save(OUT / f'piece_{name}.png', optimize=True)
tile('hippo', (437, 609, 909, 963), 4, 3)
tile('crocodile', (488, 1032, 843, 1741), 3, 6)

(GAME / 'design' / 'board_art.json').write_text(json.dumps(art, indent=1))
print(json.dumps(art))
