"""Calm African riverbank background for the game area: bga/img/scene.webp (1600 x 1200, used with background-size: cover).

Dry savanna grass and a few far acacia silhouettes along the top, a soft muted river, reed beds on both sides and a
muddy bank along the bottom. Muted, low contrast, no detail behind the board. Procedural (PIL + numpy), seeded.
Run: python3 design/make_scene.py
"""
from pathlib import Path
import random

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

GAME = Path(__file__).resolve().parent.parent
W, H = 1600, 1200


def noise(scale, seed):
    rng = np.random.default_rng(seed)
    small = rng.random((H // scale + 2, W // scale + 2)).astype('float32')
    im = Image.fromarray((small * 255).astype('uint8')).resize((W, H), Image.BICUBIC)
    return np.asarray(im, dtype='float32') / 255.0 - 0.5


def smooth(x, a, b):
    return np.clip((x - a) / (b - a), 0, 1)


def main():
    random.seed(11)
    yy, xx = np.mgrid[0:H, 0:W].astype('float32')
    nx, ny = xx / W, yy / H
    wob = noise(120, 1) * 0.05 + noise(40, 2) * 0.012
    # three zones: savanna (top), river (middle), mud bank (bottom); soft wobbly borders
    top = smooth(ny + wob, 0.16, 0.26)          # 0 = savanna, 1 = water
    bot = smooth(ny + wob * 1.3, 0.84, 0.92)    # 0 = water, 1 = mud
    sav = np.array([150, 138, 96], 'float32') + (noise(50, 3) * 16 + noise(14, 4) * 8)[..., None] * np.array([1, 0.95, 0.7], 'float32')
    water = np.array([72, 104, 98], 'float32') + (noise(200, 5) * 14 + noise(70, 6) * 6)[..., None] * np.array([0.7, 1.0, 0.9], 'float32')
    water += ((0.5 - ny) * 10)[..., None]
    mud = np.array([116, 94, 70], 'float32') + (noise(60, 7) * 14 + noise(18, 8) * 7)[..., None] * np.array([1, 0.9, 0.7], 'float32')
    img = sav * (1 - top[..., None]) + water * top[..., None]
    img = img * (1 - bot[..., None]) + mud * bot[..., None]
    im = Image.fromarray(np.clip(img, 0, 255).astype('uint8'))

    # far acacia silhouettes on the savanna strip
    far = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(far)
    for x, base, s, shade in ((120, 196, 1.2, 0), (330, 178, 0.7, 1), (640, 172, 0.55, 1), (900, 190, 1.0, 0),
                              (1170, 176, 0.65, 1), (1440, 198, 1.3, 0)):
        x += random.randint(-30, 30)
        col = (96, 100, 68, 175) if not shade else (112, 114, 80, 150)
        th = max(3, int(5 * s))
        top = base - 46 * s
        # short trunk forking into angled branches
        d.line([(x, base), (x + random.uniform(-3, 3), base - 20 * s)], fill=col, width=th + 1)
        fx, fy = x, base - 20 * s
        for k in range(random.choice([2, 3, 4])):
            ang = random.uniform(-1, 1) * 60 * s
            d.line([(fx, fy), (fx + ang, top + 6 * s)], fill=col, width=th)
        # wide, thin, irregular flat canopy: overlapping soft clumps
        for k in range(random.randint(7, 10)):
            cx = x + random.uniform(-1, 1) * 62 * s
            cy = top + random.uniform(-6, 5) * s - abs(cx - x) * 0.04
            rx = random.uniform(20, 34) * s
            ry = random.uniform(6, 11) * s
            d.ellipse((cx - rx, cy - ry, cx + rx, cy + ry), fill=col)
    far = far.filter(ImageFilter.GaussianBlur(2.4))
    im.paste(far, (0, 0), far)

    # soft ripples on the water
    rip = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(rip)
    for _ in range(70):
        x, y = random.uniform(0.05, 0.95) * W, random.uniform(0.3, 0.82) * H
        w = random.uniform(40, 110)
        d.arc((x - w, y - 5, x + w, y + 5), 200, 340, fill=(150, 178, 168, 55), width=2)
    rip = rip.filter(ImageFilter.GaussianBlur(1.4))
    im.paste(rip, (0, 0), rip)

    # reed beds, left and right edges, reaching down the sides
    reeds = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(reeds)
    for side in (0, 1):
        for _ in range(95):
            ex = random.uniform(0, 0.13) ** 1.2 * W * 1.6
            x0 = ex if side == 0 else W - ex
            y0 = random.uniform(0.28, 0.97) * H
            hgt = random.uniform(80, 190)
            lean = random.uniform(-12, 12) + (12 if side == 0 else -12)
            col = random.choice([(96, 114, 70), (110, 112, 72), (86, 106, 68)]) + (110,)
            d.line([(x0, y0), (x0 + lean * 0.5, y0 - hgt * 0.5), (x0 + lean, y0 - hgt)], fill=col, width=random.choice([3, 4, 5]))
            if random.random() < 0.3:
                d.ellipse((x0 + lean - 4, y0 - hgt - 14, x0 + lean + 4, y0 - hgt + 6), fill=(110, 84, 58, 120))
    reeds = reeds.filter(ImageFilter.GaussianBlur(2.6))
    im.paste(reeds, (0, 0), reeds)

    # mud-bank patches and a few pebbles at the bottom
    mudl = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(mudl)
    for _ in range(40):
        x, y = random.uniform(0, W), random.uniform(0.9, 1.0) * H
        r = random.uniform(14, 40)
        d.ellipse((x - r, y - r * 0.4, x + r, y + r * 0.4), fill=random.choice([(94, 76, 56, 90), (136, 112, 84, 80)]))
    mudl = mudl.filter(ImageFilter.GaussianBlur(3))
    im.paste(mudl, (0, 0), mudl)

    im = im.filter(ImageFilter.GaussianBlur(1.3))
    arr = np.asarray(im, dtype='float32')
    arr *= (1 - 0.14 * (((nx - 0.5) ** 2 + (ny - 0.5) ** 2) * 2.2))[..., None]
    out = Image.fromarray(np.clip(arr, 0, 255).astype('uint8'))
    out.save(GAME / 'bga' / 'img' / 'scene.webp', quality=72, method=6)
    print('scene.webp written', (GAME / 'bga' / 'img' / 'scene.webp').stat().st_size)


if __name__ == '__main__':
    main()
