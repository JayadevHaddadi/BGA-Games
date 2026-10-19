"""Builds the Red art: board and sprite crops here, metadata images via tools/bga_assets.py.

Run: python3 tools/bga.py assets redd
"""
from pathlib import Path
import sys

from PIL import Image, ImageDraw, ImageFont

GAME = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(GAME.parent.parent / 'tools'))
from bga_assets import build_metadata  # noqa: E402

SPRITES = GAME / 'design' / 'sprites'

FILL = {
    'white': (245, 245, 245),
    'black': (20, 20, 20),
    'red': (220, 38, 38),
}


def tile_sprite(outer, inner, size=256):
    k = 4
    s = size * k
    im = Image.new('RGBA', (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    # Outer square
    d.rounded_rectangle((s * 0.02, s * 0.02, s * 0.98, s * 0.98), radius=s * 0.06, fill=FILL[outer] + (255,), outline=(100, 100, 100, 150), width=int(s * 0.015))
    # Inner circle
    r = s * 0.28
    cx, cy = s * 0.5, s * 0.5
    d.ellipse((cx - r, cy - r, cx + r, cy + r), fill=FILL[inner] + (255,), outline=(100, 100, 100, 120), width=int(s * 0.01))
    return im.resize((size, size), Image.LANCZOS)


def make_title(path, text="RED"):
    im = Image.new('RGBA', (1000, 400), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    # Use default or basic bold font
    try:
        font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 240)
    except Exception:
        font = ImageFont.load_default()
    bbox = d.textbbox((0, 0), text, font=font)
    w = bbox[2] - bbox[0]
    h = bbox[3] - bbox[1]
    x = (1000 - w) // 2
    y = (400 - h) // 2 - bbox[1]
    d.text((x, y), text, font=font, fill=(220, 38, 38, 255))
    im.save(path)


def write_sprites():
    SPRITES.mkdir(parents=True, exist_ok=True)
    pairs = [
        ('white', 'red'),
        ('black', 'red'),
        ('red', 'white'),
        ('red', 'black'),
        ('white', 'black'),
    ]
    paths = []
    for i, (out, inn) in enumerate(pairs, 1):
        path = SPRITES / f'tile_{i}_{out}_{inn}.png'
        tile_sprite(out, inn).save(path, optimize=True)
        paths.append(str(path.relative_to(GAME)))
    return paths


if __name__ == '__main__':
    title_path = GAME / 'design' / 'title.png'
    make_title(title_path)
    flowers = write_sprites()
    build_metadata(GAME, {
        'wordmark': 'design/title.png',
        'flowers': flowers,
        'peak': None,
        'background': [24, 24, 28],
        'publisher_logo': '../Nestor Games Logo.jpg',
    })
    print('metadata images written')
