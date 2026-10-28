"""Builds the Feed the Ducks art.

  bga/img/board.webp  the pond, cropped from design/board.jpg and muted (transparent outside the hexagons)
  bga/img/scene.jpg   calm pond-side background of the game area (design/make_scene.py)
  design/sprites/     duck sprites for the metadata images (box, icon, banners)
  bga/metadata_assets the Control Panel images (tools/bga_assets.py); displays are the real product photos

The board geometry was fitted to the art (all 61 hexagon outlines match): centre (1176, 1671) and corner radius
156.49 px in the 2363 x 3349 original. The crop and the numbers the client uses (BOARD in Game.js) are printed below.

Run: python3 tools/bga.py assets feedtheducks
"""
from pathlib import Path
import math
import re
import shutil
import sys

from PIL import Image, ImageDraw, ImageFont

GAME = Path(__file__).resolve().parent.parent
DESIGN = GAME / 'design'
REPO = GAME.parent.parent
sys.path.insert(0, str(REPO / 'tools'))
sys.path.insert(0, str(DESIGN))
from bga_assets import build_metadata  # noqa: E402
from make_scene import write_scene  # noqa: E402

CX, CY, S = 1176.0, 1671.0, 156.49       # board centre and hexagon corner radius, original pixels
CROP = (22, 370, 2330, 2972)              # pond with a margin of grass and glow
OUT_W = 1000
LINE_W, LINE_A, LINE_RGB = 2.4, 0.5, (214, 230, 232)   # hexagon lines: width px (of 1000), opacity, colour

# One duck, facing left (Game.js DUCK_PATH is the same path)
DUCK_PATH = ('M1 12.6 L5.6 10.6 C5.4 6.4 8.2 3.2 12.2 3.2 C16.4 3.2 19 6.2 18.6 10 C18.3 13 16.8 14.6 17.4 17.2 '
             'C20.4 19.4 25 18.4 29.4 15.4 L39 10.4 C39.2 20.6 33.6 30.6 23.6 30.6 C13.2 30.6 8.8 25 9.8 17.6 '
             'C10.1 15.6 10.9 14.5 10.9 13.2 C8.6 14 4.6 14 1 12.6 Z')
COLOURS = {
    'white': ((244, 241, 234), (51, 64, 77)),
    'black': ((32, 36, 43), (190, 204, 218)),
    'red': ((214, 58, 52), (90, 20, 20)),
    'blue': ((42, 98, 214), (14, 34, 90)),
}


def hex_poly(cx, cy, r, k):
    return [(cx + r * math.cos(math.pi / 3 * i) * k, cy + r * math.sin(math.pi / 3 * i) * k) for i in range(6)]


def write_board():
    """The pond: the publisher's water texture, calmer (muted, darker), with thin soft semi-transparent lines.
    Everything outside the hexagons is transparent (the page background shows through)."""
    from PIL import ImageFilter
    im = Image.open(DESIGN / 'board.jpg').convert('RGB')
    box = im.crop(CROP)
    k = OUT_W / box.width
    src = box.resize((OUT_W, round(box.height * k)), Image.LANCZOS)
    W, H = src.size
    cx, cy, s = (CX - CROP[0]) * k, (CY - CROP[1]) * k, S * k
    SS = 3
    # 1. muted water: lower saturation, a little darker
    h, sat, v = src.convert('HSV').split()
    sat = sat.point(lambda x: int(x * 0.62))
    v = v.point(lambda x: int(22 + x * 0.6))
    water = Image.merge('HSV', (h, sat, v)).convert('RGB')
    # 2. per hexagon: mean colour under the old lines, the texture only well inside the hexagon
    base = Image.new('RGB', (W, H), (0, 0, 0))
    tex_mask = Image.new('L', (W * SS, H * SS), 0)
    hull_mask = Image.new('L', (W * SS, H * SS), 0)
    lines = Image.new('L', (W * SS, H * SS), 0)
    dm, dh, dl, bd = ImageDraw.Draw(tex_mask), ImageDraw.Draw(hull_mask), ImageDraw.Draw(lines), ImageDraw.Draw(base)
    cells = [(q, r) for q in range(-4, 5) for r in range(-4, 5) if abs(q + r) <= 4]
    for q, r in cells:
        x = cx + 1.5 * s * q
        y = cy + math.sqrt(3) * s * (r + q / 2)
        inner = water.crop((int(x - s * 0.5), int(y - s * 0.5), int(x + s * 0.5), int(y + s * 0.5))).resize((1, 1), Image.BOX).getpixel((0, 0))
        bd.polygon(hex_poly(x, y, s, 1.0), fill=inner)
        dm.polygon([(px * SS, py * SS) for px, py in hex_poly(x, y, s, 0.885)], fill=255)
        pts = [(px * SS, py * SS) for px, py in hex_poly(x, y, s, 1.0)]
        dh.polygon(pts, fill=255)
        dl.line(pts + [pts[0]], fill=255, width=round(LINE_W * SS), joint='curve')
    tex_mask = tex_mask.resize((W, H), Image.LANCZOS).filter(ImageFilter.GaussianBlur(1.6))
    pond = Image.composite(water, base, tex_mask)
    # 3. thin, soft, semi-transparent lines
    lines = lines.resize((W, H), Image.LANCZOS).filter(ImageFilter.GaussianBlur(0.5)).point(lambda a: int(a * LINE_A))
    pond = Image.composite(Image.new('RGB', (W, H), LINE_RGB), pond, lines)
    out = pond.convert('RGBA')
    out.putalpha(hull_mask.resize((W, H), Image.LANCZOS))
    # WebP keeps the transparency and texture at ~40 KB (the PNG was 835 KB and took ~50 s from Studio's image server)
    out.save(GAME / 'bga' / 'img' / 'board.webp', 'WEBP', quality=80, method=6)
    print(f'BOARD = {{ w: {W}, h: {H}, cx: {cx:.2f}, cy: {cy:.2f}, s: {s:.3f} }}')
    return out


def bezier_points(path):
    """Polygon for the duck path (M, L, C, Z only)."""
    tokens = re.findall(r'[MLCZ]|-?\d+\.?\d*', path)
    pts, i, cur = [], 0, (0.0, 0.0)
    while i < len(tokens):
        t = tokens[i]
        i += 1
        if t in 'ML':
            cur = (float(tokens[i]), float(tokens[i + 1]))
            i += 2
            pts.append(cur)
        elif t == 'C':
            p1 = (float(tokens[i]), float(tokens[i + 1]))
            p2 = (float(tokens[i + 2]), float(tokens[i + 3]))
            p3 = (float(tokens[i + 4]), float(tokens[i + 5]))
            i += 6
            for s in range(1, 25):
                u = s / 24
                x = (1 - u) ** 3 * cur[0] + 3 * (1 - u) ** 2 * u * p1[0] + 3 * (1 - u) * u * u * p2[0] + u ** 3 * p3[0]
                y = (1 - u) ** 3 * cur[1] + 3 * (1 - u) ** 2 * u * p1[1] + 3 * (1 - u) * u * u * p2[1] + u ** 3 * p3[1]
                pts.append((x, y))
            cur = p3
    return pts


def duck_sprite(name, size=400):
    fill, line = COLOURS[name]
    k = 4
    w = size * k
    scale = w / 42
    im = Image.new('RGBA', (w, round(w * 0.78)), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    pts = [((x - 0.5) * scale, (y - 2.5) * scale) for x, y in bezier_points(DUCK_PATH)]
    d.polygon(pts, fill=(*fill, 255), outline=(*line, 255), width=round(0.9 * scale))
    d.line(pts + [pts[0]], fill=(*line, 255), width=round(0.9 * scale), joint='curve')
    ex, ey, er = (12.4 - 0.5) * scale, (8 - 2.5) * scale, 1.3 * scale
    d.ellipse((ex - er, ey - er, ex + er, ey + er), fill=(20, 24, 30, 255) if name != 'black' else (240, 240, 240, 255))
    wing = []
    for s in range(0, 21):
        u = s / 20
        p0, p1, p2, p3 = (17, 21), (21, 25), (28, 25), (33, 19)
        x = (1 - u) ** 3 * p0[0] + 3 * (1 - u) ** 2 * u * p1[0] + 3 * (1 - u) * u * u * p2[0] + u ** 3 * p3[0]
        y = (1 - u) ** 3 * p0[1] + 3 * (1 - u) ** 2 * u * p1[1] + 3 * (1 - u) * u * u * p2[1] + u ** 3 * p3[1]
        wing.append(((x - 0.5) * scale, (y - 2.5) * scale))
    d.line(wing, fill=(*line, 255), width=round(0.9 * scale), joint='curve')
    return im.crop(im.getbbox()).resize((size, round(size * im.crop(im.getbbox()).height / im.crop(im.getbbox()).width)), Image.LANCZOS)


def write_sprites():
    """Duck design E (accessories) rendered from design/duck_options/duck_E_accessory_<colour>.svg with headless Chrome."""
    from playwright.sync_api import sync_playwright
    out = DESIGN / 'sprites'
    out.mkdir(exist_ok=True)
    for old in out.glob('duck_*.png'):
        old.unlink()
    names = []
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', headless=True)
        page = browser.new_page(viewport={'width': 400, 'height': 400})
        for n in ['white', 'green', 'red', 'blue']:
            svg = (DESIGN / 'duck_options' / f'duck_E_accessory_{n}.svg').read_text(encoding='utf-8')
            page.set_content('<html><body style="margin:0;background:transparent">'
                             '<div style="width:400px;height:400px">' + svg.replace('<svg ', '<svg width="400" height="400" ', 1)
                             + '</div></body></html>')
            path = out / f'duck_{n}.png'
            page.screenshot(path=str(path), omit_background=True, clip={'x': 0, 'y': 0, 'width': 400, 'height': 400})
            im = Image.open(path).convert('RGBA')
            im.crop(im.getbbox()).save(path, optimize=True)
            names.append(str(path.relative_to(GAME)))
        browser.close()
    return names


def make_title(path, text='FEED THE DUCKS'):
    im = Image.new('RGBA', (1800, 360), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 190)
    box = d.textbbox((0, 0), text, font=font)
    d.text(((1800 - (box[2] - box[0])) // 2 - box[0], (360 - (box[3] - box[1])) // 2 - box[1]), text, font=font, fill=(244, 241, 234, 255))
    im.save(path)


if __name__ == '__main__':
    (GAME / 'bga' / 'img').mkdir(parents=True, exist_ok=True)
    write_board()
    write_scene()
    sprites = write_sprites()
    make_title(DESIGN / 'title.png')
    build_metadata(GAME, {
        'wordmark': 'design/title.png',
        'flowers': sprites,
        'peak': None,
        'background': [28, 74, 48],
        'publisher_logo': '../Nestor Games Logo.jpg',
    })
    meta = GAME / 'bga' / 'metadata_assets'
    # Real product photos for the game page (reports/display_images.md)
    shutil.copy(DESIGN / 'feedtheducks.jpg', meta / 'display_1.jpg')
    shutil.copy(DESIGN / 'feedtheducks_detail.jpg', meta / 'display_2.jpg')
    print('images written')
