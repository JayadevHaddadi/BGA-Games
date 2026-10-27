"""Builds the Feed the Ducks art.

  bga/img/board.jpg   the pond, cropped from design/board.jpg (the publisher's board art, no text on it)
  bga/img/grass.jpg   a seamless grass tile (the board's own grass, mirrored) for the table background
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
from bga_assets import build_metadata  # noqa: E402

CX, CY, S = 1176.0, 1671.0, 156.49       # board centre and hexagon corner radius, original pixels
CROP = (22, 370, 2330, 2972)              # pond with a margin of grass and glow
OUT_W = 1000

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


def write_board():
    im = Image.open(DESIGN / 'board.jpg').convert('RGB')
    box = im.crop(CROP)
    k = OUT_W / box.width
    out = box.resize((OUT_W, round(box.height * k)), Image.LANCZOS)
    out.save(GAME / 'bga' / 'img' / 'board.jpg', quality=80, optimize=True, progressive=True)
    print(f'BOARD = {{ w: {out.width}, h: {out.height}, cx: {(CX - CROP[0]) * k:.2f}, cy: {(CY - CROP[1]) * k:.2f}, s: {S * k:.3f} }}')
    return out


def write_grass():
    im = Image.open(DESIGN / 'board.jpg').convert('RGB')
    tile = im.crop((60, 60, 60 + 320, 60 + 320))
    big = Image.new('RGB', (640, 640))
    big.paste(tile, (0, 0))
    big.paste(tile.transpose(Image.FLIP_LEFT_RIGHT), (320, 0))
    big.paste(tile.transpose(Image.FLIP_TOP_BOTTOM), (0, 320))
    big.paste(tile.transpose(Image.ROTATE_180), (320, 320))
    big.save(GAME / 'bga' / 'img' / 'grass.jpg', quality=78, optimize=True)


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
    out = DESIGN / 'sprites'
    out.mkdir(exist_ok=True)
    names = []
    for n in ['white', 'black', 'red', 'blue']:
        p = out / f'duck_{n}.png'
        duck_sprite(n).save(p, optimize=True)
        names.append(str(p.relative_to(GAME)))
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
    write_grass()
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
