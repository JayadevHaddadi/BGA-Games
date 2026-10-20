"""Packs the single pieces in design/src/ into CSS sprite sheets in bga/img/ (BGA warns above 20 image files).
Run (after make_assets.py, which writes the single pieces to design/src/):
    python3 games/gardensofenceladus/design/make_sprites.py
Every sheet is one row of equal square cells, so CSS can use background-size: N*100% 100% and
background-position: i/(N-1)*100%. Keep the order below in sync with the .enc_spr_* rules in gardensofenceladus.css.
"""
from pathlib import Path

from PIL import Image

GAME = Path(__file__).resolve().parent.parent
SRC = GAME / 'design' / 'src'
IMG = GAME / 'bga' / 'img'

SHEETS = {
    'flowers.png': (176, ['flower_blue', 'flower_green', 'flower_magenta', 'flower_red', 'flower_yellow', 'flower_wild']),
    'martians.png': (200, ['martian_ali', 'martian_bob', 'martian_bot', 'martian_marty', 'martian_robby']),
    'specials.png': (160, [f'special_{i}' for i in range(1, 10)] + ['counter', 'peak']),
}


def build():
    IMG.mkdir(parents=True, exist_ok=True)
    for out, (cell, names) in SHEETS.items():
        sheet = Image.new('RGBA', (cell * len(names), cell), (0, 0, 0, 0))
        for i, name in enumerate(names):
            im = Image.open(SRC / f'{name}.png').convert('RGBA')
            if im.width > cell or im.height > cell:
                im.thumbnail((cell, cell), Image.LANCZOS)
            sheet.alpha_composite(im, (i * cell + (cell - im.width) // 2, (cell - im.height) // 2))
        sheet.save(IMG / out, optimize=True)
        print(out, sheet.size)


if __name__ == '__main__':
    build()
