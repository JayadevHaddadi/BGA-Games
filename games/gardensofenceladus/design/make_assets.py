"""Builds the Gardens of Enceladus art. Run from anywhere:  python3 tools/bga.py assets gardensofenceladus

Sources (all in games/gardensofenceladus/):
  image.png                clean 10 x 10 icy board, no text: the board texture (grid at 47 + 341 i, 50 + 341 i)
  enceladus_actions.png    the strip of special action icons, left to right = slots 1 to 9
  enceladustitle.png       the "Gardens of Enceladus" wordmark (title, box, banners)
Sprites reused from the sibling Gardens of Mars project (same family of art, same martian silhouettes):
  gardensofmars/bga/img/flower_*.png and bot/ali/marty/bob/robby.png

Outputs:
  bga/img/                 gameplay art (BGA preloads img/, so keep it small)
  bga/metadata_assets/     Control Panel images, written by tools/bga_assets.py
"""
from pathlib import Path
import colorsys
import sys

import numpy as np
from PIL import Image, ImageDraw

GAME = Path(__file__).resolve().parent.parent
REPO = GAME.parent.parent
sys.path.insert(0, str(REPO / 'tools'))
from bga_assets import build_metadata, texture  # noqa: E402

MARS = GAME.parent / 'gardensofmars' / 'bga' / 'img'
IMG = GAME / 'bga' / 'img'
IMG.mkdir(parents=True, exist_ok=True)

BG = (20, 26, 38)  # deep space blue, the table colour behind the board
FLOWER_SRC = {'blue': 'flower_blue.png', 'red': 'flower_red.png', 'yellow': 'flower_yellow.png',
              'green': 'flower_green.png', 'magenta': 'flower_purple.png'}


def rgba(path):
    return Image.open(path).convert('RGBA')


def hue_to_magenta(im):
    """Purple petals to magenta, the rulebook's fifth colour. Only saturated pixels change; the centre symbol stays."""
    arr = np.asarray(im).astype(float) / 255.0
    out = arr.copy()
    h, w = arr.shape[:2]
    for y in range(h):
        for x in range(w):
            r, g, b, a = arr[y, x]
            if a < 0.05:
                continue
            hh, ss, vv = colorsys.rgb_to_hsv(r, g, b)
            if 0.66 < hh < 0.85 and ss > 0.35:
                nr, ng, nb = colorsys.hsv_to_rgb(0.86, min(1.0, ss * 1.05), vv)
                out[y, x] = (nr, ng, nb, a)
    return Image.fromarray((out * 255).astype('uint8'), 'RGBA')


def icy_board():
    """The 10 x 10 grid from image.png, cropped to the outer grid lines."""
    board = Image.open(GAME / 'image.png').convert('RGB').crop((47, 50, 3458, 3461))
    board.thumbnail((1100, 1100), Image.LANCZOS)
    board.save(IMG / 'board.jpg', quality=88)


def action_icons():
    """Nine icons from the strip (slots 1 to 9). Slot 10, the wildflower, uses the grey wildflower sprite."""
    strip = rgba(GAME / 'enceladus_actions.png')
    alpha = np.asarray(strip.getchannel('A'))
    gray = np.asarray(strip.convert('L'))
    dark = (gray < 200) & (alpha > 60)
    cols = dark.sum(axis=0)
    segs, inside, start = [], False, 0
    for x, v in enumerate(cols):
        if v > 0 and not inside:
            start, inside = x, True
        if v == 0 and inside:
            segs.append((start, x - 1))
            inside = False
    if inside:
        segs.append((start, len(cols) - 1))
    merged = []
    for s, e in segs:
        if merged and s - merged[-1][1] < 12:
            merged[-1] = (merged[-1][0], e)
        else:
            merged.append((s, e))
    assert len(merged) == 9, f'expected 9 icon segments, found {len(merged)}'
    for slot, (s, e) in enumerate(merged, start=1):
        rows = np.where(dark[:, s:e + 1].any(axis=1))[0]
        y0, y1 = rows.min(), rows.max()
        crop = strip.crop((s - 4, y0 - 4, e + 5, y1 + 5))
        crop = fit_square(crop, 150)
        canvas = Image.new('RGBA', (160, 160), (0, 0, 0, 0))
        canvas.alpha_composite(crop, ((160 - crop.width) // 2, (160 - crop.height) // 2))
        canvas.save(IMG / f'special_{slot}.png', optimize=True)


def fit_square(im, side):
    if im.width >= im.height:
        return im.resize((side, round(im.height * side / im.width)), Image.LANCZOS)
    return im.resize((round(im.width * side / im.height), side), Image.LANCZOS)


def flowers_and_martians():
    for colour, name in FLOWER_SRC.items():
        im = rgba(MARS / name)
        if colour == 'magenta':
            im = hue_to_magenta(im)
        im.save(IMG / f'flower_{colour}.png', optimize=True)
    rgba(MARS / 'flower_gray.png').save(IMG / 'flower_wild.png', optimize=True)
    for name in ['bot', 'ali', 'marty', 'bob', 'robby']:
        rgba(MARS / f'{name}.png').save(IMG / f'martian_{name}.png', optimize=True)


def peak_and_counter():
    """Peak: a dark ice cone seen from above, flat fills and a thin outline. Counter: grey disc over a used action."""
    s = 240
    peak = Image.new('RGBA', (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(peak)
    d.ellipse((12, 12, s - 12, s - 12), fill=(72, 78, 90, 255), outline=(22, 25, 32, 255), width=7)
    d.ellipse((58, 58, s - 58, s - 58), fill=(96, 103, 116, 255), outline=(40, 45, 55, 255), width=4)
    d.ellipse((104, 104, s - 104, s - 104), fill=(120, 128, 142, 255))
    peak.save(IMG / 'peak.png', optimize=True)

    counter = Image.new('RGBA', (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(counter)
    d.ellipse((10, 10, s - 10, s - 10), fill=(150, 152, 158, 255), outline=(70, 72, 78, 255), width=8)
    counter.save(IMG / 'counter.png', optimize=True)


TABLE = (178, 200, 217)  # pale Enceladus ice: light enough that black Martians and the start squares read (designer: "too dark")


def table_background():
    """Icy table: soft blue-grey noise with a few faint hairline cracks. Low contrast, never a flat colour."""
    im = texture((1600, 1000), seed=7, base=TABLE, spread=7)
    rnd = np.random.default_rng(21)
    d = ImageDraw.Draw(im, 'RGBA')
    for _ in range(26):
        x, y = float(rnd.integers(0, 1600)), float(rnd.integers(0, 1000))
        ang = rnd.uniform(0, 6.28)
        for _seg in range(int(rnd.integers(4, 9))):
            ang += rnd.normal(0, 0.45)
            ln = rnd.uniform(25, 70)
            nx, ny = x + ln * np.cos(ang), y + ln * np.sin(ang)
            d.line((x, y, nx, ny), fill=(255, 255, 255, 70), width=2)
            d.line((x + 1, y + 2, nx + 1, ny + 2), fill=(120, 150, 175, 55), width=1)
            x, y = nx, ny
    im.save(IMG / 'table.jpg', quality=84)


def metadata_images():
    build_metadata(GAME, {
        'wordmark': 'enceladustitle.png',
        'flowers': [f'bga/img/flower_{c}.png' for c in ['blue', 'red', 'yellow', 'green', 'magenta']],
        'peak': 'bga/img/peak.png',
        'background': list(BG),
        'publisher_logo': '../Nestor Games Logo.jpg',
        'publisher_tint': [35, 49, 74],
        'seed': 11,
    })


if __name__ == '__main__':
    icy_board()
    action_icons()
    flowers_and_martians()
    peak_and_counter()
    table_background()
    metadata_images()
    print('art written to', IMG, 'and', GAME / 'bga' / 'metadata_assets')
