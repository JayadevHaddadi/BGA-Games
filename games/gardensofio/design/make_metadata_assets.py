"""Builds the BGA metadata images into bga/metadata_assets/ from GOIO_BIG.jpg and the in-game art."""
from pathlib import Path
import random
import numpy as np
from PIL import Image, ImageDraw, ImageFilter

HERE = Path(__file__).resolve().parent.parent
OUT = HERE / 'bga' / 'metadata_assets'
IMG = HERE / 'bga' / 'img'
OUT.mkdir(exist_ok=True)

big = Image.open(HERE / 'GOIO_BIG.jpg').convert('RGB')

# Wordmark "GARDENS OF IO": white pixels only, the designer line is blanked out.
box = (1270, 60, 3420, 440)
crop = np.array(big.crop(box)).astype(int)
mask = (crop.min(axis=2) > 190)
mask[300 - 60:, :1500] = False  # "Nestor Romeral Andres" line (x < 2770, y > 300)
mask = Image.fromarray((mask * 255).astype('uint8'))
word = Image.new('RGBA', mask.size, (244, 234, 210, 255))
word.putalpha(mask)
word = word.crop(word.getbbox())
# glyph "IO" only (right part)
io = np.array(mask)
cols = np.where(io.max(axis=0) > 0)[0]
gap_start = None
for x in range(1500, io.shape[1] - 1):
    pass
io_img = Image.new('RGBA', mask.size, (244, 234, 210, 255))
io_mask = np.array(mask).copy()
io_mask[:, :1590] = 0  # keep only IO
io_img.putalpha(Image.fromarray(io_mask))
io_img = io_img.crop(io_img.getbbox())

BG = (26, 28, 40)
flowers = {c: Image.open(IMG / f'flower_{c}.png').convert('RGBA') for c in ['blue', 'yellow', 'orange', 'purple', 'red', 'green']}
tree = Image.open(IMG / 'tree.png').convert('RGBA')


def fit(im, w=None, h=None):
    if w is not None:
        h = round(im.height * w / im.width)
    else:
        w = round(im.width * h / im.height)
    return im.resize((w, h), Image.LANCZOS)


def circles(size, pitch, seed=1):
    rnd = random.Random(seed)
    im = Image.new('RGB', size, BG)
    d = ImageDraw.Draw(im)
    for r in range(size[1] // pitch + 2):
        for c in range(size[0] // pitch + 2):
            tone = 40 if (r + c) % 2 else 52
            cx, cy = c * pitch, r * pitch
            d.ellipse((cx - pitch * 0.45, cy - pitch * 0.45, cx + pitch * 0.45, cy + pitch * 0.45),
                      fill=(tone, tone + 2, tone + 12), outline=(70, 74, 92), width=2)
    return im


# Banner 1386 x 400: no text. Flowers and trees on the sides, the middle stays calm for BGA's title overlay.
banner = circles((1386, 400), 100)
shade = Image.new('RGBA', banner.size, (0, 0, 0, 0))
sd = ImageDraw.Draw(shade)
for x in range(380, 1006):
    t = 1 - abs(x - 693) / 313
    sd.line([(x, 0), (x, 400)], fill=(*BG, int(150 * t)))
banner = Image.alpha_composite(banner.convert('RGBA'), shade)
seq = ['blue', 'red', 'yellow', 'green', 'purple', 'orange']
for side in (0, 1):
    for i in range(3):
        col = seq[(i + 3 * side) % 6]
        f = fit(flowers[col], w=150)
        x = 30 + i * 175 if side == 0 else 1386 - 30 - 150 - i * 175
        y = 40 if i % 2 == 0 else 190
        banner.alpha_composite(f, (x, y))
    t = fit(tree, h=120)
    tx = 545 if side == 0 else 1386 - 545 - t.width
    banner.alpha_composite(t, (tx, 270))
banner.convert('RGB').save(OUT / 'banner_1386x400.jpg', quality=90)

# Title 2000 x 2000: wordmark on dark circles, flowers below.
title = circles((2000, 2000), 250, seed=3).convert('RGBA')
w = fit(word, w=1700)
title.alpha_composite(w, ((2000 - w.width) // 2, 560))
x = 160
for i, col in enumerate(names):
    f = fit(flowers[col], w=240)
    title.alpha_composite(f, (x + i * 290, 1100))
t = fit(tree, h=300)
title.alpha_composite(t, (910, 1450))
title.convert('RGB').save(OUT / 'title_2000x2000.png', optimize=True)


def badge(size):
    s = size
    im = Image.new('RGBA', (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.ellipse((1, 1, s - 2, s - 2), fill=(26, 28, 40, 255), outline=(244, 234, 210, 255), width=max(2, s // 40))
    g = fit(io_img, w=int(s * 0.5))
    im.alpha_composite(g, ((s - g.width) // 2, int(s * 0.14)))
    f = fit(flowers['red'], w=int(s * 0.22))
    for i, col in enumerate(['red', 'yellow', 'blue']):
        ff = fit(flowers[col], w=int(s * 0.2))
        im.alpha_composite(ff, (int(s * 0.5 - s * 0.32 + i * s * 0.22), int(s * 0.62)))
    return im


badge(280).save(OUT / 'box_280x280.png', optimize=True)
big_icon = badge(500)
big_icon.save(OUT / 'icon_500x500.png', optimize=True)
badge(500).resize((50, 50), Image.LANCZOS).save(OUT / 'icon_50x50.png', optimize=True)
print('ok')
