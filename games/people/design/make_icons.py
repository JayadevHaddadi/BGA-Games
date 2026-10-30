"""Draws the People gameplay icons as one sprite sheet: bga/img/icons.png.  Run:  python3 games/people/design/make_icons.py

The shapes copy the physical pieces (see people_small.jpg): factories with a sawtooth roof and a chimney in the four
player colours, the orange bulldozer, the black riot shield, the grey person (the designer's PNG in design/src), the orange bitcoin disc and a striped
road barrier. Flat fills only, no gradients; thin outline only on the light pieces.

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
SRC_PERSON = Path(__file__).resolve().parent / 'src' / 'person_grey.png'

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


GREY = (132, 136, 142)
BLACK = (28, 28, 30)
WHITE = (246, 246, 242)
LINE = (70, 72, 78, 255)  # thin outline, only where the piece is light or grey (person, roadblock)


def factory(colour, with_mark):
    """Flat side view of the physical factory: stepped sawtooth roof and a cone chimney, one colour."""
    im, d = blank()
    col = COLOURS[colour] + (255,)
    d.rectangle([u(10), u(76), u(118), u(112)], fill=col)
    for x0 in (36, 62, 88):  # sawtooth teeth: vertical edge on the left, slope to the right
        d.polygon(pts([(x0, 76), (x0, 44), (x0 + 26, 76)]), fill=col)
    d.polygon(pts([(10, 80), (18, 12), (26, 80)]), fill=col)  # chimney cone
    if with_mark:
        mark(d, colour, 72, 96, 11)
    return im


def bulldozer():
    """Flat orange block with the sloped cab and the blade, as the physical orange piece."""
    im, d = blank()
    o = ORANGE + (255,)
    d.polygon(pts([(28, 104), (28, 36), (86, 36), (86, 56), (106, 56), (106, 104)]), fill=o)  # body and cab
    d.polygon(pts([(8, 108), (8, 52), (26, 44), (26, 108)]), fill=o)  # blade
    d.rectangle([u(30), u(100), u(104), u(108)], fill=(190, 96, 12, 255))  # tracks
    return im


def police():
    """The black half-cylinder shield with its slot, seen from the front: flat black."""
    im, d = blank()
    k = BLACK + (255,)
    top = [(24 + i * 2, 18 + 14 * math.sin(math.pi * i / 40)) for i in range(41)]  # concave top edge
    d.polygon(pts([(24, 108), (24, 22)] + top + [(104, 22), (104, 108)]), fill=k)
    d.rounded_rectangle([u(44), u(48), u(84), u(58)], radius=u(4), fill=(222, 222, 218, 255))
    return im


def human():
    """The designer's grey person (design/src/person_grey.png) as one flat grey, thin outline."""
    im, _ = blank()
    src = Image.open(SRC_PERSON).convert('RGBA')
    h = u(106)
    w = round(src.width * h / src.height)
    src = src.resize((w, h), Image.LANCZOS)
    alpha = Image.new('L', (W, W), 0)
    alpha.paste(src.getchannel('A'), ((W - w) // 2, u(11)))
    grown = alpha.filter(ImageFilter.MaxFilter(int(u(2)) * 2 + 1))
    im.paste(Image.new('RGBA', (W, W), LINE), (0, 0), grown)
    im.paste(Image.new('RGBA', (W, W), GREY + (255,)), (0, 0), alpha)
    return im


def bitcoin():
    """Flat orange disc, as the wooden bitcoin tokens, with a plain cream B."""
    im, d = blank()
    d.ellipse([u(10), u(10), u(118), u(118)], fill=ORANGE + (255,))
    font = ImageFont.truetype(FONT, u(72))
    d.text((u(64), u(66)), 'B', font=font, fill=(255, 246, 228, 255), anchor='mm')
    return im


def roadblock():
    """The white wedge-shaped roadblock piece: a flat white trapezoid, thin grey outline."""
    im, d = blank()
    m, md = new_mask()
    md.polygon(pts([(8, 98), (30, 44), (98, 44), (120, 98)]), fill=255)
    piece(im, m, WHITE, outline=LINE, width=2.5)
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
