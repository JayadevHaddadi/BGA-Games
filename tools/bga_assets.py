"""Shared image helpers for BGA games: the metadata images (box, icon, banners, title, publisher, displays).

A game calls build_metadata(game_dir, spec) from its design/make_assets.py. The spec says which art to use, so
the same code makes every game's Control Panel images:

    build_metadata(GAME_DIR, {
        "wordmark": "enceladustitle.png",          # title art, relative to the game folder
        "flowers": ["bga/img/flower_blue.png", ...],  # up to five sprites for the row and the badge
        "peak": "bga/img/peak.png",                # optional extra sprite for badge and banners
        "background": [20, 26, 38],                # RGB of the table colour behind the art
        "publisher_logo": "../Nestor Games Logo.jpg",  # optional: any light or dark logo on a plain background
        "publisher_tint": [35, 49, 74],            # optional: logo is redrawn in this colour
    })

Outputs go to bga/metadata_assets/ with the sizes in BGA_METADATA_ASSETS_SPEC.md and AGENTS.md section 5.D.
No text is ever drawn into banners: BGA overlays the title and the player rankings itself.
"""
from pathlib import Path
import random

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

ROOT = Path(__file__).resolve().parent.parent
OUTLINE = (214, 226, 236, 255)


def rgba(path):
    return Image.open(path).convert('RGBA')


def fit(im, w=None, h=None):
    if w is not None:
        h = round(im.height * w / im.width)
    else:
        w = round(im.width * h / im.height)
    return im.resize((w, h), Image.LANCZOS)


def texture(size, seed, base=(20, 26, 38), spread=6, stars=0):
    """Low-contrast noise, blurred: a playmat, never a flat colour. Optional faint stars."""
    rnd = np.random.default_rng(seed)
    w, h = size
    noise = rnd.normal(0, 1, (h // 4 + 1, w // 4 + 1))
    noise = (noise - noise.min()) / (np.ptp(noise) + 1e-9) * 255
    noise = np.asarray(Image.fromarray(noise.astype('uint8')).resize((w, h), Image.BICUBIC)).astype(float) / 255.0
    field = np.array(base, float)[None, None, :] + (noise[:, :, None] - 0.5) * spread * 2
    im = Image.fromarray(np.clip(field, 0, 255).astype('uint8'), 'RGB').filter(ImageFilter.GaussianBlur(1.2))
    if stars:
        d = ImageDraw.Draw(im)
        for _ in range(stars):
            x, y = rnd.integers(0, w), rnd.integers(0, h)
            s = rnd.uniform(0.6, 2.0)
            v = int(rnd.uniform(120, 200))
            d.ellipse((x, y, x + s, y + s), fill=(v, v, v + 10))
    return im


def wordmark(path, width):
    """The title art as a transparent image. A scan without alpha is keyed out by brightness."""
    im = rgba(path)
    if im.getchannel('A').getextrema()[0] == 255:
        arr = np.asarray(im.convert('L'))
        im.putalpha(Image.fromarray(((arr > 90) * 255).astype('uint8')))
    im = im.crop(im.getbbox())
    return fit(im, w=width)


def flower_row(names, width, gap):
    total = len(names) * width + (len(names) - 1) * gap
    row = Image.new('RGBA', (total, int(width * 1.1)), (0, 0, 0, 0))
    for i, f in enumerate(names):
        im = fit(rgba(f), w=width)
        row.alpha_composite(im, (i * (width + gap), 0))
    return row


def badge(size, flowers, peak, background, outline=OUTLINE):
    im = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.ellipse((1, 1, size - 2, size - 2), fill=(*background, 255), outline=outline, width=max(2, size // 40))
    slots = [(0.30, 0.22), (0.54, 0.26), (0.20, 0.50), (0.46, 0.52), (0.70, 0.52)]
    for i, (fx, fy) in enumerate(slots[:len(flowers)]):
        f = fit(rgba(flowers[i]), w=int(size * 0.24))
        im.alpha_composite(f, (int(size * fx - f.width / 2), int(size * fy - f.height / 2)))
    if peak:
        pk = fit(rgba(peak), w=int(size * 0.20))
        im.alpha_composite(pk, (int(size * 0.5 - pk.width / 2), int(size * 0.74)))
    return im


def square_cover(size, seed, word, flowers, background):
    im = texture((size, size), seed=seed, base=background, spread=5, stars=max(40, size // 6)).convert('RGBA')
    im.alpha_composite(word, ((size - word.width) // 2, int(size * 0.34)))
    if flowers:
        gap = int(size * 0.03)
        width = int(size * 0.15)
        row = flower_row(flowers, width, gap)
        im.alpha_composite(row, ((size - row.width) // 2, int(size * 0.70)))
    return im


def banner(width, height, seed, flowers, peak, background):
    """Flowers on both sides, a peak low on the right, the middle left calm for BGA's title overlay."""
    im = texture((width, height), seed=seed, base=background, spread=6, stars=width // 8).convert('RGBA')
    fh = int(height * 0.42)
    for side in (0, 1):
        for i in range(3):
            if not flowers:
                break
            f = fit(rgba(flowers[(i + 3 * side) % len(flowers)]), h=fh)
            if side == 0:
                x = int(width * 0.03) + i * int(f.width * 0.9)
            else:
                x = width - int(width * 0.03) - f.width - i * int(f.width * 0.9)
            y = int(height * (0.08 if i % 2 == 0 else 0.46))
            im.alpha_composite(f, (x, y))
        if peak:
            pk = fit(rgba(peak), h=int(height * 0.30))
            px = int(width * 0.46) if side == 0 else int(width * 0.54) - pk.width
            im.alpha_composite(pk, (px, int(height * 0.62)))
    return im.convert('RGB')


def publisher_logo(path, tint=None):
    """Brightness of the source becomes alpha. Tint redraws it in one colour so it reads on a light BGA page."""
    src = Image.open(path).convert('L')
    colour = tint or (0, 0, 0)
    logo = Image.new('RGBA', src.size, (*colour, 255))
    logo.putalpha(src)
    logo = logo.crop(logo.getbbox())
    logo.thumbnail((240, 240), Image.LANCZOS)
    canvas = Image.new('RGBA', (280, 280), (0, 0, 0, 0))
    canvas.alpha_composite(logo, ((280 - logo.width) // 2, (280 - logo.height) // 2))
    return canvas


def build_metadata(game_dir, spec, out_dir=None):
    """Writes every metadata image for a game. Paths in spec are relative to game_dir."""
    game_dir = Path(game_dir)
    out = Path(out_dir) if out_dir else game_dir / 'bga' / 'metadata_assets'
    out.mkdir(parents=True, exist_ok=True)
    flowers = [str(game_dir / f) for f in spec.get('flowers', [])]
    peak = str(game_dir / spec['peak']) if spec.get('peak') else None
    background = tuple(spec.get('background', [20, 26, 38]))
    word = wordmark(game_dir / spec['wordmark'], 1700)
    seed = spec.get('seed', 1)

    # Box: transparent corners, a dark disc with the title and the flower row
    box = Image.new('RGBA', (280, 280), (0, 0, 0, 0))
    ImageDraw.Draw(box).ellipse((6, 6, 274, 274), fill=(*background, 255), outline=OUTLINE, width=4)
    small = wordmark(game_dir / spec['wordmark'], 230)
    box.alpha_composite(small, ((280 - small.width) // 2, 62))
    if flowers:
        row = flower_row(flowers, 34, 12)
        box.alpha_composite(row, ((280 - row.width) // 2, 186))
    box.save(out / 'box_280x280.png', optimize=True)

    # Icon: 500 px master and the 50 px version
    icon = badge(500, flowers, peak, background)
    icon.save(out / 'icon_500x500.png', optimize=True)
    icon.resize((50, 50), Image.LANCZOS).save(out / 'icon_50x50.png', optimize=True)

    # Title 2000 x 2000 and two banner sizes (no text)
    square_cover(2000, seed, word, flowers, background).convert('RGB').save(out / 'title_2000x2000.png', optimize=True)
    banner(1386, 400, seed + 22, flowers, peak, background).save(out / 'banner_1386x400.jpg', quality=90)
    banner(1920, 556, seed + 23, flowers, peak, background).save(out / 'banner_1920x556.jpg', quality=90)

    if spec.get('publisher_logo'):
        tint = tuple(spec['publisher_tint']) if spec.get('publisher_tint') else None
        publisher_logo(game_dir / spec['publisher_logo'], tint).save(out / 'publisher_280x280.png', optimize=True)

    # Displays: 1000 x 750 placeholders until real screenshots are rendered (see tests/harness)
    for name in ['display_1.jpg', 'display_2.jpg']:
        if not (out / name).exists():
            Image.new('RGB', (1000, 750), background).save(out / name, quality=88)
    return out
