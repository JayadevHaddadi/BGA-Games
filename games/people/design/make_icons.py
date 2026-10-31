"""Draws the People gameplay icons as one sprite sheet: bga/img/icons.png.  Run:  python3 games/people/design/make_icons.py

The shapes copy the physical pieces (see pic9479451.webp and pic9479452.webp):
- 3D-shaded resin factories with sawtooth roofs and conical chimney in four player colours.
- Cute orange bulldozer truck miniature with cab, scoop blade, and tracks.
- Black curved riot police shield with viewing visor slit.
- Star-shaped grey person meeple.
- Wooden orange bitcoin token disc.
- Concrete roadblock barrier.

Sheet layout: 9 columns x 2 rows of 128 px cells.
  columns: 0 factory red, 1 blue, 2 yellow, 3 green, 4 bulldozer, 5 police shield, 6 human, 7 bitcoin, 8 roadblock
  row 0: factories carry their colour-blind shape mark (red triangle, blue square, yellow diamond, green circle)
  row 1: the same without the mark (preference 100 off); the other columns are identical in both rows
people.css addresses cells with --i (column) and --r (row).
"""
import math
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

GAME = Path(__file__).resolve().parent.parent
OUT = GAME / 'bga' / 'img' / 'icons.png'
SRC_PERSON = Path(__file__).resolve().parent / 'src' / 'person_grey.png'

CELL = 128
SS = 4  # supersampling
W = CELL * SS
INK = (20, 20, 22, 255)

COLOURS = {
    'red': {'base': (216, 44, 32), 'light': (242, 92, 80), 'dark': (152, 24, 16)},
    'blue': {'base': (32, 100, 216), 'light': (82, 146, 246), 'dark': (18, 66, 160)},
    'yellow': {'base': (228, 182, 22), 'light': (250, 216, 72), 'dark': (174, 134, 8)},
    'green': {'base': (44, 142, 58), 'light': (78, 182, 92), 'dark': (26, 96, 36)},
}

ORANGE = {'base': (240, 126, 18), 'light': (255, 162, 54), 'dark': (186, 84, 8), 'chassis': (48, 46, 44)}
FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'


def u(v):
    return v * SS


def pts(points):
    return [(u(x), u(y)) for x, y in points]


def blank():
    im = Image.new('RGBA', (W, W), (0, 0, 0, 0))
    return im, ImageDraw.Draw(im)


def mark(d, shape, cx, cy, k):
    white = (255, 255, 255, 255)
    border = (20, 20, 20, 220)
    bw = int(u(1.5))
    if shape == 'red':
        d.polygon(pts([(cx, cy - k), (cx + k, cy + k * 0.8), (cx - k, cy + k * 0.8)]), fill=white, outline=border, width=bw)
    elif shape == 'blue':
        d.rectangle([u(cx - k * 0.82), u(cy - k * 0.82), u(cx + k * 0.82), u(cy + k * 0.82)], fill=white, outline=border, width=bw)
    elif shape == 'yellow':
        d.polygon(pts([(cx, cy - k), (cx + k, cy), (cx, cy + k), (cx - k, cy)]), fill=white, outline=border, width=bw)
    else:
        d.ellipse([u(cx - k * 0.85), u(cy - k * 0.85), u(cx + k * 0.85), u(cy + k * 0.85)], fill=white, outline=border, width=bw)


def factory(colour_name, with_mark):
    """3D-shaded resin factory miniature: conical chimney on left, 3 sawtooth ridges with lit facets."""
    c = COLOURS[colour_name]
    base, light, dark = c['base'] + (255,), c['light'] + (255,), c['dark'] + (255,)
    im, d = blank()

    # 1. Base building body
    d.rectangle([u(10), u(74), u(118), u(114)], fill=base)
    # Bottom shadow on base
    d.rectangle([u(10), u(108), u(118), u(114)], fill=dark)

    # 2. Conical chimney on the left
    d.polygon(pts([(10, 76), (16, 14), (23, 14), (23, 76)]), fill=light)
    d.polygon(pts([(23, 14), (28, 14), (32, 76), (23, 76)]), fill=dark)
    d.ellipse([u(16), u(11), u(28), u(17)], fill=light, outline=dark, width=int(u(1)))

    # 3. Sawtooth roofs (3 teeth)
    teeth = [(36, 62), (62, 88), (88, 114)]
    for x0, x1 in teeth:
        # Sloped roof (light reflection)
        d.polygon(pts([(x0, 74), (x0, 42), (x1, 74)]), fill=light)
        # Vertical tooth edge/face on the left
        d.polygon(pts([(x0, 74), (x0, 42), (x0 + 4, 46), (x0 + 4, 74)]), fill=dark)
        # Ridge highlight line along slope
        d.line(pts([(x0, 42), (x1, 74)]), fill=(255, 255, 255, 120), width=int(u(1.5)))

    # Thin outline around silhouette
    d.line(pts([(10, 114), (118, 114), (118, 74)]), fill=INK, width=int(u(2.5)))
    d.line(pts([(10, 114), (10, 76)]), fill=INK, width=int(u(2.5)))

    if with_mark:
        mark(d, colour_name, 76, 94, 11)
    return im


def bulldozer():
    """Cute orange bulldozer truck miniature with cab, front scoop, and tracks."""
    im, d = blank()
    o_base = ORANGE['base'] + (255,)
    o_light = ORANGE['light'] + (255,)
    o_dark = ORANGE['dark'] + (255,)
    chassis = ORANGE['chassis'] + (255,)

    # 1. Lower chassis / tracks
    d.rounded_rectangle([u(24), u(98), u(108), u(114)], radius=u(4), fill=chassis, outline=INK, width=int(u(2)))
    # Track wheels (3 wheels)
    for cx in (38, 66, 94):
        d.ellipse([u(cx - 5), u(101), u(cx + 5), u(111)], fill=(90, 88, 86, 255))
        d.ellipse([u(cx - 2), u(104), u(cx + 2), u(108)], fill=(160, 156, 150, 255))

    # 2. Main engine body (rear)
    d.rectangle([u(60), u(68), u(106), u(98)], fill=o_base)
    d.rectangle([u(60), u(92), u(106), u(98)], fill=o_dark)
    d.line(pts([(60, 68), (106, 68)]), fill=o_light, width=int(u(2)))

    # Exhaust pipe stack on rear hood
    d.rectangle([u(96), u(50), u(100), u(68)], fill=(70, 70, 72, 255))
    d.ellipse([u(95), u(48), u(101), u(52)], fill=(120, 120, 124, 255))

    # 3. Cab (operator cabin)
    d.polygon(pts([(30, 98), (30, 48), (62, 42), (66, 98)]), fill=o_base)
    d.polygon(pts([(28, 46), (30, 48), (64, 42), (62, 40)]), fill=o_light)
    d.polygon(pts([(34, 52), (58, 47), (58, 70), (34, 70)]), fill=(42, 68, 92, 255), outline=INK, width=int(u(1.5)))
    d.polygon(pts([(36, 54), (46, 52), (40, 68), (36, 68)]), fill=(108, 160, 206, 200))

    # 4. Front scoop / blade
    d.line(pts([(40, 92), (18, 92)]), fill=(60, 60, 60, 255), width=int(u(4)))
    d.polygon(pts([(14, 114), (8, 64), (16, 60), (22, 114)]), fill=o_light)
    d.polygon(pts([(18, 114), (12, 64), (22, 60), (26, 114)]), fill=o_dark)
    d.line(pts([(8, 64), (14, 114)]), fill=INK, width=int(u(2)))
    d.line(pts([(14, 114), (26, 114)]), fill=(255, 255, 255, 180), width=int(u(2)))

    return im


def police():
    """Curved riot shield with top concave highlight and visor viewing slit."""
    im, d = blank()
    top_rim = [(24 + i * 2, 22 + 12 * math.sin(math.pi * i / 40)) for i in range(41)]
    # Left highlight side
    d.polygon(pts([(24, 108), (24, 22)] + top_rim[:20] + [(64, 34), (64, 108)]), fill=(44, 46, 50, 255))
    # Right shadow side
    d.polygon(pts([(64, 108), (64, 34)] + top_rim[20:] + [(104, 22), (104, 108)]), fill=(22, 23, 26, 255))

    # Metallic top rim highlight
    d.line(pts(top_rim), fill=(160, 164, 172, 255), width=int(u(3)))

    # Visor slit
    d.rounded_rectangle([u(40), u(46), u(88), u(58)], radius=u(3), fill=(180, 200, 218, 255), outline=INK, width=int(u(2)))
    d.line(pts([(44, 52), (72, 52)]), fill=(255, 255, 255, 180), width=int(u(2)))

    # Outer border
    d.line(pts([(24, 22), (24, 108), (104, 108), (104, 22)]), fill=INK, width=int(u(2.5)))
    return im


def human():
    """Nestor's 5-pointed star-meeple with clean outline and bevel."""
    im, _ = blank()
    src = Image.open(SRC_PERSON).convert('RGBA')
    h = u(104)
    w = round(src.width * h / src.height)
    src = src.resize((w, h), Image.LANCZOS)
    alpha = Image.new('L', (W, W), 0)
    alpha.paste(src.getchannel('A'), ((W - w) // 2, u(12)))

    im.paste(Image.new('RGBA', (W, W), (142, 146, 154, 255)), (0, 0), alpha)
    grown = alpha.filter(ImageFilter.MaxFilter(int(u(2)) * 2 + 1))
    outline_img = Image.new('RGBA', (W, W), INK)
    canvas = Image.new('RGBA', (W, W), (0, 0, 0, 0))
    canvas.paste(outline_img, (0, 0), grown)
    canvas.paste(im, (0, 0), alpha)
    return canvas


def bitcoin():
    """Warm orange wooden token disc with satin bevel and cream B."""
    im, d = blank()
    o = ORANGE['base'] + (255,)
    o_dark = ORANGE['dark'] + (255,)
    o_light = ORANGE['light'] + (255,)

    d.ellipse([u(10), u(10), u(118), u(118)], fill=o_dark)
    d.ellipse([u(12), u(10), u(116), u(114)], fill=o_light)
    d.ellipse([u(14), u(13), u(114), u(113)], fill=o)

    font = ImageFont.truetype(FONT, u(68))
    d.text((u(64), u(65)), 'B', font=font, fill=(255, 246, 230, 255), anchor='mm')
    return im


def roadblock():
    """Concrete triangular prism roadblock with 3D bevels and reflector stripes."""
    im, d = blank()
    d.polygon(pts([(12, 104), (28, 40), (100, 40), (116, 104)]), fill=(244, 244, 240, 255))
    d.polygon(pts([(26, 40), (32, 34), (96, 34), (100, 40)]), fill=(255, 255, 255, 255))
    d.polygon(pts([(12, 104), (28, 40), (26, 40), (8, 104)]), fill=(200, 200, 196, 255))
    d.polygon(pts([(100, 40), (116, 104), (120, 104), (96, 34)]), fill=(180, 180, 176, 255))
    d.rectangle([u(8), u(104), u(120), u(108)], fill=(120, 120, 118, 255))

    stripes = [(38, 52), (60, 74), (82, 96)]
    for sx0, sx1 in stripes:
        d.polygon(pts([(sx0, 96), (sx0 + 6, 48), (sx1 - 2, 48), (sx1 - 8, 96)]), fill=(216, 44, 32, 220))

    d.line(pts([(8, 104), (26, 40), (100, 40), (120, 104), (8, 104)]), fill=INK, width=int(u(2)))
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
