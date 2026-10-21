"""Draws the People gameplay icons as one sprite sheet: bga/img/icons.png.  Run:  python3 games/people/design/make_icons.py

The shapes copy the physical pieces (see people_small.jpg): factories with a sawtooth roof and a chimney in the four
player colours, the orange bulldozer, the black riot shield, the grey human, the orange bitcoin disc and a striped
road barrier. Flat fills, dark outline, no shadows.

Sheet layout: 9 columns x 2 rows of 128 px cells.
  columns: 0 factory red, 1 blue, 2 yellow, 3 green, 4 bulldozer, 5 police shield, 6 human, 7 bitcoin, 8 roadblock
  row 0: factories carry their colour-blind shape mark (red triangle, blue square, yellow diamond, green circle)
  row 1: the same without the mark (preference 100 off); the other columns are identical in both rows
people.css addresses cells with --i (column) and --r (row).
"""
import math
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw, ImageFilter, ImageFont

GAME = Path(__file__).resolve().parent.parent
OUT = GAME / 'bga' / 'img' / 'icons.png'

CELL = 128
SS = 4  # supersampling
W = CELL * SS
INK = (22, 22, 22, 255)
COLOURS = {  # same palette as people.css
    'red': (214, 43, 31),
    'blue': (31, 95, 214),
    'yellow': (224, 184, 26),
    'green': (46, 139, 58),
}
ORANGE = (236, 132, 24)
FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'


def u(v):
    return v * SS


def pts(points):
    return [(u(x), u(y)) for x, y in points]


def new_mask():
    m = Image.new('L', (W, W), 0)
    return m, ImageDraw.Draw(m)


def piece(canvas, mask, fill, outline=INK, width=4.5):
    """Paints a union of shapes with one dark outline around the whole silhouette."""
    size = int(u(width)) * 2 + 1
    grown = mask.filter(ImageFilter.MaxFilter(size))
    canvas.paste(Image.new('RGBA', (W, W), outline), (0, 0), grown)
    canvas.paste(Image.new('RGBA', (W, W), fill + (255,) if len(fill) == 3 else fill), (0, 0), mask)


def blank():
    im = Image.new('RGBA', (W, W), (0, 0, 0, 0))
    return im, ImageDraw.Draw(im)


def mark(d, shape, cx, cy, k):
    white = (255, 255, 255, 255)
    if shape == 'red':
        d.polygon(pts([(cx, cy - k), (cx + k, cy + k * 0.8), (cx - k, cy + k * 0.8)]), fill=white)
    elif shape == 'blue':
        d.rectangle([u(cx - k * 0.85), u(cy - k * 0.85), u(cx + k * 0.85), u(cy + k * 0.85)], fill=white)
    elif shape == 'yellow':
        d.polygon(pts([(cx, cy - k), (cx + k, cy), (cx, cy + k), (cx - k, cy)]), fill=white)
    else:
        d.ellipse([u(cx - k * 0.9), u(cy - k * 0.9), u(cx + k * 0.9), u(cy + k * 0.9)], fill=white)


def factory(colour, with_mark):
    im, d = blank()
    m, md = new_mask()
    md.rectangle([u(12), u(68), u(116), u(116)], fill=255)  # hall
    for x0 in (36, 62, 88):  # sawtooth roof
        md.polygon(pts([(x0, 68), (x0, 44), (x0 + 28, 68)]), fill=255)
    md.polygon(pts([(12, 68), (12, 16), (26, 12), (26, 68)]), fill=255)  # chimney
    piece(im, m, COLOURS[colour])
    d = ImageDraw.Draw(im)
    d.rectangle([u(40), u(100), u(54), u(116)], fill=INK)  # door
    d.rectangle([u(90), u(100), u(104), u(116)], fill=INK)
    if with_mark:
        mark(d, colour, 64, 90, 13)
    return im


def bulldozer():
    im, d = blank()
    m, md = new_mask()
    md.rectangle([u(26), u(56), u(92), u(84)], fill=255)  # engine
    md.polygon(pts([(32, 56), (38, 28), (72, 28), (80, 56)]), fill=255)  # cab
    md.rectangle([u(40), u(16), u(47), u(30)], fill=255)  # exhaust
    piece(im, m, ORANGE)
    d = ImageDraw.Draw(im)
    d.polygon(pts([(43, 54), (46, 34), (68, 34), (73, 54)]), fill=(200, 222, 238, 255), outline=INK, width=round(u(2)))
    tracks, td = new_mask()
    td.rounded_rectangle([u(16), u(82), u(100), u(110)], radius=u(14), fill=255)
    piece(im, tracks, (52, 52, 56))
    d = ImageDraw.Draw(im)
    for cx in (32, 58, 84):
        d.ellipse([u(cx - 6), u(90), u(cx + 6), u(102)], fill=(150, 150, 154, 255), outline=INK, width=round(u(1.5)))
    arm, ad = new_mask()
    ad.rectangle([u(88), u(62), u(106), u(70)], fill=255)
    ad.polygon(pts([(104, 40), (120, 40), (116, 104), (104, 104)]), fill=255)  # blade
    piece(im, arm, (96, 96, 100))
    return im


def police():
    im, d = blank()
    m, md = new_mask()
    md.polygon(pts([(22, 14), (106, 14), (106, 62), (96, 88), (64, 118), (32, 88), (22, 62)]), fill=255)
    piece(im, m, (44, 46, 52))
    d = ImageDraw.Draw(im)
    d.polygon(pts([(30, 22), (98, 22), (98, 60), (90, 82), (64, 108), (38, 82), (30, 60)]), outline=(206, 210, 216, 255), width=round(u(3)))
    d.rounded_rectangle([u(40), u(32), u(88), u(52)], radius=u(5), fill=(150, 176, 198, 255), outline=INK, width=round(u(2)))
    # a star on the lower field
    cx, cy, ro, ri = 64, 76, 13, 5.5
    star = []
    for i in range(10):
        a = -math.pi / 2 + i * math.pi / 5
        r = ro if i % 2 == 0 else ri
        star.append((cx + r * math.cos(a), cy + r * math.sin(a)))
    d.polygon(pts(star), fill=(238, 238, 232, 255))
    return im


def human():
    im, d = blank()
    m, md = new_mask()
    md.ellipse([u(46), u(4), u(82), u(40)], fill=255)  # head
    md.rounded_rectangle([u(34), u(36), u(94), u(84)], radius=u(14), fill=255)  # torso
    for (x0, y0, x1, y1, w) in [(40, 48, 12, 76, 15), (88, 48, 116, 76, 15), (50, 76, 38, 118, 20), (78, 76, 90, 118, 20)]:
        md.line([u(x0), u(y0), u(x1), u(y1)], fill=255, width=u(w))
        for (x, y) in ((x0, y0), (x1, y1)):
            md.ellipse([u(x - w / 2), u(y - w / 2), u(x + w / 2), u(y + w / 2)], fill=255)
    piece(im, m, (214, 214, 208), width=4)
    return im


def bitcoin():
    im, d = blank()
    m, md = new_mask()
    md.ellipse([u(10), u(10), u(118), u(118)], fill=255)
    piece(im, m, ORANGE)
    d = ImageDraw.Draw(im)
    d.ellipse([u(22), u(22), u(106), u(106)], outline=(196, 96, 10, 255), width=round(u(4)))
    font = ImageFont.truetype(FONT, u(78))
    cream = (255, 246, 228, 255)
    d.text((u(66), u(66)), 'B', font=font, fill=cream, anchor='mm')  # the font has no bitcoin sign: a B with two ticks
    for x in (58, 72):
        d.rectangle([u(x - 1.5), u(26), u(x + 1.5), u(36)], fill=cream)
        d.rectangle([u(x - 1.5), u(92), u(x + 1.5), u(102)], fill=cream)
    return im


def roadblock():
    im, d = blank()
    m, md = new_mask()
    md.rectangle([u(20), u(70), u(32), u(112)], fill=255)  # legs
    md.rectangle([u(96), u(70), u(108), u(112)], fill=255)
    piece(im, m, (250, 248, 240))
    board, bd = new_mask()
    bd.rounded_rectangle([u(6), u(34), u(122), u(76)], radius=u(4), fill=255)
    piece(im, board, (250, 248, 240))
    stripes, sd = new_mask()
    for x in range(-60, 140, 28):
        sd.polygon(pts([(x, 76), (x + 14, 76), (x + 14 + 42, 34), (x + 42, 34)]), fill=255)
    stripes = ImageChops.multiply(stripes, board)
    im.paste(Image.new('RGBA', (W, W), COLOURS['red'] + (255,)), (0, 0), stripes)
    d = ImageDraw.Draw(im)
    d.rounded_rectangle([u(6), u(34), u(122), u(76)], radius=u(4), outline=INK, width=round(u(4.5)))
    return im


def build():
    cols = [('factory', c) for c in COLOURS] + [('bulldozer', None), ('police', None), ('human', None), ('bitcoin', None), ('roadblock', None)]
    sheet = Image.new('RGBA', (CELL * len(cols), CELL * 2), (0, 0, 0, 0))
    fns = {'bulldozer': bulldozer, 'police': police, 'human': human, 'bitcoin': bitcoin, 'roadblock': roadblock}
    for row in (0, 1):
        for i, (kind, colour) in enumerate(cols):
            big = factory(colour, row == 0) if kind == 'factory' else fns[kind]()
            sheet.paste(big.resize((CELL, CELL), Image.LANCZOS), (i * CELL, row * CELL))
    OUT.parent.mkdir(parents=True, exist_ok=True)
    sheet.save(OUT, optimize=True)
    return sheet


if __name__ == '__main__':
    s = build()
    print(OUT, s.size, OUT.stat().st_size, 'bytes')
