"""Builds the People art. Run from anywhere:  python3 tools/bga.py assets people

Sources (all in games/people/):
  PEOPLE.jpg     the scan of the board. Only the clean 6 x 6 grid is used (the corners carry the logo and credits,
                 which stay out of img/ and out of banners). Gutter centres measured at x = 27 + 610.8 i, y = 195 + 610.8 i.
  city.jpg       aerial city, greyscale. Darkened into the table texture.
  PEOPLE.jpg     bottom-left "PEOPLE" wordmark, used only for the box and the title (never for banners).
  ../Nestor Games Logo.jpg   publisher logo (shared file in games/).

Outputs:
  bga/img/       gameplay art (BGA preloads img/, so keep it small): board.jpg, table.jpg, icons.png (design/make_icons.py)
  bga/metadata_assets/   Control Panel images, written by tools/bga_assets.py
"""
from pathlib import Path
import sys

from PIL import Image, ImageDraw, ImageFilter

GAME = Path(__file__).resolve().parent.parent
REPO = GAME.parent.parent
sys.path.insert(0, str(REPO / 'tools'))
from bga_assets import build_metadata  # noqa: E402

IMG = GAME / 'bga' / 'img'
DESIGN = GAME / 'design'
SPRITES = DESIGN / 'sprites'
SCREENS = DESIGN / 'screens'
IMG.mkdir(parents=True, exist_ok=True)
SPRITES.mkdir(parents=True, exist_ok=True)

# Gutter-centre crop of the scan: left, top, right, bottom (square, 6 pitches of 610.8 px)
BOARD_BOX = (27, 195, 3692, 3860)
WORDMARK_BOX = (30, 3850, 700, 4040)
TABLE = (33, 31, 35)  # charcoal, with a faint warm cast from the city photo

# The four player colours and their shapes (also used in the client): red triangle, blue square, yellow diamond, green circle
FACTORY = {
    'red': ((214, 43, 31), 'triangle'),
    'blue': ((31, 95, 214), 'square'),
    'yellow': ((224, 184, 26), 'diamond'),
    'green': ((46, 139, 58), 'circle'),
}


def board():
    """The 6 x 6 grid from the scan, cut at the outer gutter centres so the image is exactly 6 pitches wide."""
    im = Image.open(GAME / 'PEOPLE.jpg').convert('RGB').crop(BOARD_BOX)
    im.thumbnail((1100, 1100), Image.LANCZOS)
    im.save(IMG / 'board.jpg', quality=85, optimize=True)


def table_background():
    """Aerial city, darkened: a playmat behind the board, low contrast so the pieces stay the focus."""
    src = Image.open(GAME / 'city.jpg').convert('L')
    src = src.resize((1400, round(src.height * 1400 / src.width)), Image.LANCZOS)
    top = (src.height - 1000) // 2
    src = src.crop((0, max(0, top), 1400, max(0, top) + 1000))
    dark = Image.eval(src, lambda v: int(v * 0.30))
    tinted = Image.merge('RGB', [dark.point(lambda v, k=k: min(255, v * TABLE[k] // 30)) for k in range(3)])
    tinted.filter(ImageFilter.GaussianBlur(2.2)).save(IMG / 'table.jpg', quality=78, optimize=True)


def wordmark():
    """The scan's bottom-left PEOPLE logo: the white letters only (threshold), cut out for the title and box art."""
    crop = Image.open(GAME / 'PEOPLE.jpg').convert('L').crop(WORDMARK_BOX)
    mask = crop.point(lambda v: 255 if v > 205 else 0)
    mask = mask.crop(mask.getbbox())
    out = Image.new('RGBA', mask.size, (255, 255, 255, 0))
    out.putalpha(mask)
    out.save(DESIGN / 'wordmark_src.png', optimize=True)


def factory_sprites():
    """Metadata sprites only: a tower with three teeth, flat fill, dark outline, a shape mark per colour."""
    s = 240
    for name, (rgb, shape) in FACTORY.items():
        im = Image.new('RGBA', (s, s), (0, 0, 0, 0))
        d = ImageDraw.Draw(im)
        ink = (24, 24, 24, 255)
        d.rectangle([(44, 96), (196, 212)], fill=rgb + (255,), outline=ink, width=7)
        for x0 in (44, 102, 160):
            d.rectangle([(x0, 52), (x0 + 36, 96)], fill=rgb + (255,), outline=ink, width=7)
        cx, cy, k = 120, 154, 26
        mark = (255, 255, 255, 255)
        if shape == 'triangle':
            d.polygon([(cx, cy - k), (cx + k, cy + k), (cx - k, cy + k)], fill=mark)
        elif shape == 'square':
            d.rectangle([(cx - k, cy - k), (cx + k, cy + k)], fill=mark)
        elif shape == 'diamond':
            d.polygon([(cx, cy - k), (cx + k, cy), (cx, cy + k), (cx - k, cy)], fill=mark)
        else:
            d.ellipse([(cx - k, cy - k), (cx + k, cy + k)], fill=mark)
        im.save(SPRITES / f'factory_{name}.png', optimize=True)


def metadata_images():
    wordmark()
    factory_sprites()
    build_metadata(GAME, {
        'wordmark': 'design/wordmark_src.png',
        'flowers': [f'design/sprites/factory_{c}.png' for c in FACTORY],
        'background': list(TABLE),
        'publisher_logo': '../Nestor Games Logo.jpg',
        'publisher_tint': [214, 210, 200],
        'seed': 21,
    })


def displays():
    """Display images (Control Panel): the harness screenshots at 1280 px, cropped to the play area (no debug text, no status bar).
    design/screens/ holds them; regenerate with the commands in design/screens/README.md."""
    box = (200, 15, 1080, 790)
    for src, name in [('harness_factory_2p.png', 'display_1.jpg'), ('harness_bribe_4p.png', 'display_2.jpg')]:
        im = Image.open(SCREENS / src).convert('RGB').crop(box)
        im = im.resize((round(im.width * 750 / im.height), 750), Image.LANCZOS)
        im.save(GAME / 'bga' / 'metadata_assets' / name, quality=88, optimize=True)


if __name__ == '__main__':
    import make_icons
    make_icons.build()  # bga/img/icons.png (sprite sheet of all gameplay icons)
    board()
    table_background()
    metadata_images()
    displays()
