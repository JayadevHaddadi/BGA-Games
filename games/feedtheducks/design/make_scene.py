"""Calm pond-side background for the game area: bga/img/scene.jpg (1600 x 1000, scales with background-size: cover).

Muted greens and browns, low contrast, no busy pattern behind the text: soft water in the middle, a blurred grassy
bank along the top and sides, a few reeds and lily pads near the edges. Procedural (PIL + numpy), seeded.
Run: python3 design/make_scene.py
"""
from pathlib import Path
import math
import random

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

GAME = Path(__file__).resolve().parent.parent
W, H = 1600, 1000


def noise(scale, seed):
    rng = np.random.default_rng(seed)
    small = rng.random((H // scale + 2, W // scale + 2)).astype('float32')
    im = Image.fromarray((small * 255).astype('uint8')).resize((W, H), Image.BICUBIC)
    return np.asarray(im, dtype='float32') / 255.0 - 0.5


def write_scene():
    random.seed(7)
    yy, xx = np.mgrid[0:H, 0:W].astype('float32')
    nx, ny = xx / W, yy / H
    # water: deep muted teal-green, a slow gradient and very soft large blotches
    water = np.zeros((H, W, 3), 'float32')
    base = np.array([62, 98, 92], 'float32')
    water[:] = base
    water += (ny[..., None] - 0.5) * np.array([-6, -4, -2], 'float32')
    n = noise(160, 1) * 14 + noise(60, 2) * 6
    water += n[..., None] * np.array([0.7, 1.0, 0.9], 'float32')
    # bank: distance from the edges (top heavier, sides lighter, bottom corners)
    d = np.minimum.reduce([ny * 1.25, nx * 1.9, (1 - nx) * 1.9, (1 - ny) * 3.2])
    wob = noise(90, 3) * 0.22 + noise(30, 4) * 0.06
    bank = np.clip((0.2 + wob - d) / 0.12, 0, 1)
    bank = np.asarray(Image.fromarray((bank * 255).astype('uint8')).filter(ImageFilter.GaussianBlur(10)), 'float32') / 255
    grass = np.zeros((H, W, 3), 'float32')
    grass[:] = [82, 104, 66]
    gn = noise(40, 5) * 16 + noise(12, 6) * 8
    grass += gn[..., None] * np.array([0.9, 1.0, 0.6], 'float32')
    mud = np.clip((0.2 + wob - d - 0.06) / 0.06, 0, 1)[..., None] * 0   # (kept simple: no mud strip)
    img = water * (1 - bank[..., None]) + grass * bank[..., None] + mud
    im = Image.fromarray(np.clip(img, 0, 255).astype('uint8'))

    # reeds: thin soft strokes standing on the bank edge, left and right
    layer = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    dr = ImageDraw.Draw(layer)
    for side in (0, 1):
        for _ in range(46):
            x0 = random.uniform(0.0, 0.17) * W
            x0 = x0 if side == 0 else W - x0
            y0 = random.uniform(0.25, 0.97) * H
            hgt = random.uniform(70, 170)
            lean = random.uniform(-14, 14) + (10 if side == 0 else -10)
            col = random.choice([(96, 118, 74), (108, 112, 72), (88, 108, 70)]) + (95,)
            dr.line([(x0, y0), (x0 + lean * 0.5, y0 - hgt * 0.5), (x0 + lean, y0 - hgt)], fill=col, width=random.choice([3, 4, 5]))
            if random.random() < 0.3:
                dr.ellipse((x0 + lean - 4, y0 - hgt - 14, x0 + lean + 4, y0 - hgt + 6), fill=(112, 88, 62, 100))
    layer = layer.filter(ImageFilter.GaussianBlur(3.2))
    im.paste(layer, (0, 0), layer)

    # lily pads near the lower corners and along the bottom
    pads = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    dp = ImageDraw.Draw(pads)
    spots = [(0.2, 0.9), (0.25, 0.8), (0.13, 0.7), (0.8, 0.88), (0.87, 0.78), (0.75, 0.95), (0.9, 0.62), (0.1, 0.55), (0.36, 0.95), (0.62, 0.96)]
    for (px, py) in spots:
        for _ in range(random.choice([1, 2, 3])):
            cx = px * W + random.uniform(-50, 50)
            cy = py * H + random.uniform(-30, 30)
            rx = random.uniform(24, 40)
            ry = rx * 0.62
            tint = random.choice([(86, 124, 84), (78, 116, 80), (96, 128, 82)])
            dp.ellipse((cx - rx, cy - ry, cx + rx, cy + ry), fill=tint + (150,))
            a = random.uniform(0, 2 * math.pi)       # the notch of the pad
            dp.polygon([(cx, cy), (cx + math.cos(a - 0.18) * rx * 1.3, cy + math.sin(a - 0.18) * ry * 1.3),
                        (cx + math.cos(a + 0.18) * rx * 1.3, cy + math.sin(a + 0.18) * ry * 1.3)], fill=(62, 98, 92, 255))
        if random.random() < 0.5:
            fx, fy = px * W + random.uniform(-20, 20), py * H + random.uniform(-14, 14)
            dp.ellipse((fx - 6, fy - 6, fx + 6, fy + 6), fill=(214, 200, 196, 110))
    pads = pads.filter(ImageFilter.GaussianBlur(1.6))
    im.paste(pads, (0, 0), pads)

    # everything slightly soft, a gentle vignette, no hard detail anywhere
    im = im.filter(ImageFilter.GaussianBlur(1.2))
    arr = np.asarray(im, dtype='float32')
    vig = 1 - 0.16 * (((nx - 0.5) ** 2 + (ny - 0.5) ** 2) * 2.2)
    arr *= vig[..., None]
    out = Image.fromarray(np.clip(arr, 0, 255).astype('uint8'))
    out.save(GAME / 'bga' / 'img' / 'scene.jpg', quality=82, optimize=True, progressive=True)
    print('scene.jpg written')
    return out


if __name__ == '__main__':
    write_scene()
