"""Builds the Microchip art. Run: python3 tools/bga.py assets microchip

Sources (all in games/microchip/design/):
  title.png        the "microchip" wordmark from Microchip.zip (title, box and banners)
The tiles are drawn here, with the same geometry as the in-game SVG (Game.js mcTileHTML):
  a square tile with white paths from the middle to each connection, and a white pad on the end tile.

Outputs:
  design/sprites/          the five tile sprites used as the badge row (not in bga/img/)
  bga/metadata_assets/     Control Panel images, written by tools/bga_assets.py
The gameplay art is CSS and SVG, so bga/img/ only holds the table texture (see display.py for screenshots).
"""
from pathlib import Path
import sys

from PIL import Image, ImageDraw

GAME = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(GAME.parent.parent / 'tools'))
from bga_assets import build_metadata  # noqa: E402

SPRITES = GAME / 'design' / 'sprites'

FILL = {'blue': (29, 78, 216), 'red': (214, 40, 40), 'green': (31, 157, 85), 'yellow': (240, 192, 0), 'pink': (168, 85, 199)}
SIDES = {'plus': [0, 1, 2, 3], 'tee': [0, 1, 2], 'corner': [1, 2], 'straight': [0, 2], 'end': [0]}
# Ends in the same order as Game.js: north, east, south, west (as fractions of the tile)
ENDS = [(0.5, 0.0), (1.0, 0.5), (0.5, 1.0), (0.0, 0.5)]


def tile_sprite(colour, shape, rot=0, size=256):
    """One tile, drawn at 4x and reduced, so the edges are smooth."""
    k = 4
    s = size * k
    im = Image.new('RGBA', (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.rounded_rectangle((s * 0.01, s * 0.01, s * 0.99, s * 0.99), radius=s * 0.03, fill=FILL[colour] + (255,))
    width = int(s * 0.12)
    centre = (s * 0.5, s * 0.5)
    for side in SIDES[shape]:
        end = ENDS[(side + rot) % 4]
        d.line([centre, (end[0] * s, end[1] * s)], fill=(255, 255, 255, 255), width=width)
    if shape == 'end':
        pad = s * 0.16
        d.rectangle((centre[0] - pad, centre[1] - pad, centre[0] + pad, centre[1] + pad), fill=(255, 255, 255, 255))
    return im.resize((size, size), Image.LANCZOS)


def write_sprites():
    SPRITES.mkdir(parents=True, exist_ok=True)
    picks = [('blue', 'plus'), ('red', 'tee'), ('green', 'corner'), ('yellow', 'straight'), ('pink', 'end')]
    paths = []
    for i, (colour, shape) in enumerate(picks, 1):
        path = SPRITES / f'tile_{i}_{colour}_{shape}.png'
        tile_sprite(colour, shape, 0).save(path, optimize=True)
        paths.append(str(path.relative_to(GAME)))
    return paths


def make_display_photos():
    """Build display_1.jpg and display_2.jpg from the real physical photo microchip2.jpg."""
    out = GAME / 'bga' / 'metadata_assets'
    out.mkdir(parents=True, exist_ok=True)
    src_path = GAME / 'microchip2.jpg'
    if not src_path.exists():
        src_path = GAME / 'microchip.jpg'
    if not src_path.exists():
        print('Warning: microchip2.jpg not found, keeping existing display images')
        return

    src = Image.open(src_path)
    # Display 1: full table photo of physical components (1000x750)
    d1 = src.resize((1000, 750), Image.LANCZOS)
    d1.save(out / 'display_1.jpg', quality=92, optimize=True)
    print('wrote display_1.jpg (real physical photo)')

    # Display 2: close focus crop on pool and tiles (1000x750)
    w, h = 600, 450
    c_left = 580 - 300
    c_top = 360 - 225
    d2 = src.crop((c_left, c_top, c_left + w, c_top + h)).resize((1000, 750), Image.LANCZOS)
    d2.save(out / 'display_2.jpg', quality=92, optimize=True)
    print('wrote display_2.jpg (real physical photo focus crop)')


if __name__ == '__main__':
    flowers = write_sprites()
    build_metadata(GAME, {
        'wordmark': 'design/title.png',
        'flowers': flowers,
        'peak': None,
        'background': [236, 232, 222],  # the wordmark is dark ink, so the box is light paper
        'publisher_logo': '../Nestor Games Logo.jpg',
    })
    print('metadata images written')
    make_display_photos()
