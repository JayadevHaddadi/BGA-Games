"""
Builds the board art used by the BGA game from the original poster (GOIO_BIG.jpg):

  bga/img/board.jpg     the 11 x 11 board. NOTE: now the supplied games/gardensofio/board_clean.png (resized to 1100, flattened on black); this script no longer generates it
  bga/img/specials.jpg  the strip of five special action counters, flat, without the X marks

The poster header (publisher logo, title, designer name, flying saucer art) is NOT used.
Run from the repository root:  python3 games/gardensofio/design/make_board_assets.py
"""
from pathlib import Path

import numpy as np
from PIL import Image, ImageFilter
from scipy import ndimage as ndi

HERE = Path(__file__).resolve().parent.parent
SRC = HERE / 'GOIO_BIG.jpg'
OUT = HERE / 'bga' / 'img'

im = Image.open(SRC).convert('RGB')
W, H = im.size


def make_board() -> None:
    S = W / 11
    board = im.crop((0, H - W, W, H))

    def box(rr, rc):
        return (round(rc * S), round(rr * S), round((rc + 1) * S), round((rr + 1) * S))

    # The clean right/bottom part has two flat tones that alternate by square parity: use it as the reference.
    pad = 6

    def padded(rr, rc):
        x0, y0, x1, y1 = box(rr, rc)
        return board.crop((x0 - pad, y0 - pad, x1 + pad, y1 + pad))

    ref = {0: padded(9, 9), 1: padded(9, 8)}
    res = board.copy()
    for g_r in range(9):
        for g_c in range(9):
            rr, rc = g_r + 1, g_c + 1
            x0, y0, x1, y1 = box(rr, rc)
            tile = ref[(rr + rc) % 2]
            size = (x1 - x0 + 2 * pad, y1 - y0 + 2 * pad)
            res.paste(tile if tile.size == size else tile.resize(size), (x0 - pad, y0 - pad))

    # Track squares of the top row and left column show the photo: keep only the bright glyphs on the flat tone.
    arr = np.asarray(res).astype(np.float32)
    lum = arr.mean(axis=2)
    lum_img = Image.fromarray(lum.astype(np.uint8))
    bg = np.asarray(lum_img.filter(ImageFilter.MinFilter(45)).filter(ImageFilter.GaussianBlur(10))).astype(np.float32)
    floor = np.maximum(bg, 172.0)
    alpha = np.clip((lum - floor) / (255.0 - floor + 1e-3), 0, 1)
    for rr, rc in [(0, c) for c in range(11)] + [(r, 0) for r in range(1, 11)]:
        x0, y0, x1, y1 = box(rr, rc)
        tone = 77.0 if (rr + rc) % 2 else 38.0
        a = alpha[y0:y1, x0:x1, None]
        arr[y0:y1, x0:x1] = tone * (1 - a) + 255.0 * a
    Image.fromarray(np.clip(arr, 0, 255).astype(np.uint8)).resize((1100, 1100), Image.LANCZOS).save(
        OUT / 'board.jpg', quality=88, optimize=True)


def make_specials() -> None:
    strip = im.crop((1540, 600, 3240, 1300))
    a = np.asarray(strip).astype(np.float32)
    lum = a.mean(axis=2)
    white = lum > 200
    labels, _ = ndi.label(~white)
    edge = np.unique(np.concatenate([labels[0, :], labels[-1, :], labels[:, 0], labels[:, -1]]))
    background = np.isin(labels, edge[edge > 0])
    a[background] = 0
    near = ndi.binary_dilation(background, iterations=2) & (lum < 200) & (lum <= 110)
    a[near] = 0
    lum = a.mean(axis=2)
    # shaded balls become flat grey
    a[(lum > 70) & (lum < 225)] = 176.0
    # erase the X marks: the counter circles are at x = 192 + 319 k, y = 185 in strip pixels
    yy, xx = np.mgrid[0:a.shape[0], 0:a.shape[1]]
    for k in range(5):
        a[(xx - (192 + 319 * k)) ** 2 + (yy - 185) ** 2 < 108 ** 2] = 0
    Image.fromarray(a.astype(np.uint8)).resize((850, 350), Image.LANCZOS).save(
        OUT / 'specials.jpg', quality=92, optimize=True)


if __name__ == '__main__':
    make_board()
    make_specials()
    print('wrote', OUT / 'board.jpg', OUT / 'specials.jpg')
