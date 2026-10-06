#!/usr/bin/env python3
"""Placeholder BGA metadata images from the SVG art (bga/metadata_assets/). Replace with final art later, same names.
  icon 50/500, box 280, banner 1386x400 (no text, BGA overlays the title), publisher 280, display 1000x750."""
import math
import os
import random
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from build_design import ground_group, road_group, U  # noqa: E402
from icons import icon_group, hexpts  # noqa: E402

OUT = os.path.join(HERE, '..', 'bga', 'metadata_assets')
PURPLE, PAPER, INK = '#6d3a8c', '#f4ead7', '#2b2233'


def shot(svg, w, h, name, fmt='png'):
    os.makedirs(OUT, exist_ok=True)
    with tempfile.NamedTemporaryFile('w', suffix='.html', delete=False) as f:
        f.write(f'<body style="margin:0;background:{"#fff" if fmt == "jpg" else "transparent"}">{svg}</body>')
        html = f.name
    png = os.path.join(OUT, f'{name}.png')
    subprocess.run(['google-chrome', '--headless', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--default-background-color=00000000' if fmt == 'png' else '--default-background-color=ffffffff',
                    f'--window-size={w},{h}', f'--screenshot={png}', f'file://{html}'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    os.unlink(html)
    if fmt == 'jpg':
        subprocess.run(['convert', png, '-quality', '92', os.path.join(OUT, f'{name}.jpg')], check=True)
        os.unlink(png)


def tile(x, y, scale, kind, roads):
    g = ground_group(kind, outline=True) + ''.join(road_group(d, True) for d in range(6) if roads[d])
    return f'<g transform="translate({x:.1f},{y:.1f}) scale({scale})">{g}</g>'


def mosaic(w, h, scale, seed, extras=()):
    rng = random.Random(seed)
    kinds = ['iron', 'iron', 'crystal', 'empty']
    dx, dy = 2 * U * 0.866 * scale, 1.5 * U * scale
    out = []
    rows = int(h / dy) + 3
    cols = int(w / dx) + 3
    for r in range(-1, rows):
        for c in range(-1, cols):
            x = c * dx + (dx / 2 if r % 2 else 0)
            y = r * dy
            out.append(tile(x, y, scale, rng.choice(kinds), [rng.random() < 0.75 for _ in range(6)]))
    out.extend(extras)
    return f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}">' + ''.join(out) + '</svg>'


def main():
    # banner: tile floor with a few pieces, NO text
    pieces = [icon_group('mech', 300, 210, 150), icon_group('bot', 520, 260, 90), icon_group('bot', 1000, 150, 90), icon_group('mech', 1100, 250, 160), icon_group('tower', 760, 120, 110)]
    shot(mosaic(1386, 400, 2.2, 3, pieces), 1386, 400, 'banner_1386x400', 'jpg')
    # display image: floor + pieces, 1000x750 (replace with a real screenshot before beta)
    pieces = [icon_group('mech', 250, 300, 190), icon_group('bot', 470, 420, 110), icon_group('bot', 580, 330, 110), icon_group('factory', 720, 250, 150), icon_group('mech', 780, 520, 190), icon_group('credit', 150, 560, 100)]
    shot(mosaic(1000, 750, 2.6, 5, pieces), 1000, 750, 'display_1000x750', 'jpg')
    # icon + box + publisher
    def hexicon(size):
        return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{size}" height="{size}" viewBox="-50 -50 100 100">'
                f'<rect x="-50" y="-50" width="100" height="100" fill="{PURPLE}"/><polygon points="{hexpts(44)}" fill="{PAPER}" stroke="{INK}" stroke-width="3"/>'
                f'{icon_group("mech", 0, 2, 52)}</svg>')
    shot(hexicon(500), 500, 500, 'icon_500x500')
    shot(hexicon(50), 50, 50, 'icon_50x50')
    box = (f'<svg xmlns="http://www.w3.org/2000/svg" width="280" height="280" viewBox="0 0 280 280" font-family="DejaVu Sans, Arial, sans-serif"><rect width="280" height="280" fill="{PURPLE}"/>'
           f'<polygon points="{hexpts(100)}" transform="translate(140 130)" fill="{PAPER}" stroke="{INK}" stroke-width="4"/>'
           f'{icon_group("mech", 140, 134, 110)}'
           f'<text x="140" y="262" text-anchor="middle" font-size="21" font-weight="bold" fill="{PAPER}">LITTLE COMMANDERS</text></svg>')
    shot(box, 280, 280, 'box_280x280')
    pub = (f'<svg xmlns="http://www.w3.org/2000/svg" width="280" height="280" viewBox="0 0 280 280" font-family="DejaVu Sans, Arial, sans-serif"><rect width="280" height="280" fill="{PAPER}"/>'
           f'<polygon points="{hexpts(90)}" transform="translate(140 110)" fill="{PURPLE}" stroke="{INK}" stroke-width="4"/>'
           f'<text x="140" y="125" text-anchor="middle" font-size="40" font-weight="bold" fill="{PAPER}">JG</text>'
           f'<text x="140" y="250" text-anchor="middle" font-size="28" font-weight="bold" fill="{INK}">Jayadev Games</text></svg>')
    shot(pub, 280, 280, 'publisher_280x280')
    print('metadata written to', os.path.abspath(OUT))


if __name__ == '__main__':
    main()
