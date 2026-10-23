"""Builds the Pent-Up art: sprites, title, and metadata images via tools/bga_assets.py.

Run: python3 tools/bga.py assets pentup
"""
from pathlib import Path
import shutil
import sys
from PIL import Image, ImageDraw, ImageFont

GAME = Path(__file__).resolve().parent.parent
REPO = GAME.parent.parent
sys.path.insert(0, str(REPO / 'tools'))
from bga_assets import build_metadata  # noqa: E402

SPRITES = GAME / 'design' / 'sprites'

COLORS = {
    'white': (240, 233, 214),
    'black': (32, 34, 39),
    'red': (178, 58, 52),
}

SHAPES = {
    'P': [[0, 0], [1, 0], [0, 1], [1, 1], [0, 2]],
    'X': [[1, 0], [0, 1], [1, 1], [2, 1], [1, 2]],
    'T': [[0, 0], [1, 0], [2, 0], [1, 1], [1, 2]],
    'F': [[1, 0], [2, 0], [0, 1], [1, 1], [1, 2]],
    'U': [[0, 0], [2, 0], [0, 1], [1, 1], [2, 1]],
}


def draw_pentomino(shape_cells, color_name, size=256):
    im = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    xs = [c[0] for c in shape_cells]
    ys = [c[1] for c in shape_cells]
    w_cells = max(xs) - min(xs) + 1
    h_cells = max(ys) - min(ys) + 1
    pad = 16
    cell_size = (size - 2 * pad) // max(w_cells, h_cells)
    off_x = (size - cell_size * w_cells) // 2
    off_y = (size - cell_size * h_cells) // 2

    fill = COLORS[color_name]
    outline = (255, 255, 255, 120) if color_name == 'black' else (30, 30, 30, 120)
    for cx, cy in shape_cells:
        x0 = off_x + (cx - min(xs)) * cell_size
        y0 = off_y + (cy - min(ys)) * cell_size
        x1 = x0 + cell_size
        y1 = y0 + cell_size
        d.rounded_rectangle(
            (x0 + 2, y0 + 2, x1 - 2, y1 - 2),
            radius=cell_size * 0.15,
            fill=(*fill, 255),
            outline=outline,
            width=2,
        )
    return im


def write_sprites():
    SPRITES.mkdir(parents=True, exist_ok=True)
    paths = []
    color_cycle = ['white', 'black', 'red', 'white', 'black']
    for i, (name, cells) in enumerate(SHAPES.items()):
        c_name = color_cycle[i % len(color_cycle)]
        path = SPRITES / f'piece_{name}_{c_name}.png'
        draw_pentomino(cells, c_name).save(path, optimize=True)
        paths.append(str(path.relative_to(GAME)))
    return paths


def make_title(path, text='PENT-UP'):
    im = Image.new('RGBA', (1400, 400), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    try:
        font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 200)
    except Exception:
        font = ImageFont.load_default()
    bbox = d.textbbox((0, 0), text, font=font)
    w = bbox[2] - bbox[0]
    h = bbox[3] - bbox[1]
    x = (1400 - w) // 2
    y = (400 - h) // 2 - bbox[1]
    d.text((x, y), text, font=font, fill=(240, 233, 214, 255))
    im.save(path)


if __name__ == '__main__':
    title_path = GAME / 'design' / 'title.png'
    make_title(title_path)
    flowers = write_sprites()
    build_metadata(
        GAME,
        {
            'wordmark': 'design/title.png',
            'flowers': flowers,
            'peak': None,
            'background': [28, 30, 36],
            'publisher_logo': '../Nestor Games Logo.jpg',
        },
    )

    # Use the real physical photographs from Nestor Images
    meta_dir = GAME / 'bga' / 'metadata_assets'
    nestor_dir = REPO / 'Nestor Images'
    if (nestor_dir / 'pentup_big_1.jpg').exists():
        shutil.copy(nestor_dir / 'pentup_big_1.jpg', meta_dir / 'display_1.jpg')
        shutil.copy(nestor_dir / 'pentup_big_2.jpg', meta_dir / 'display_2.jpg')
        print('Copied real display images from Nestor Images')

    print('metadata images written')
