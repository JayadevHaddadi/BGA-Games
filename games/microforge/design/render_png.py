#!/usr/bin/env python3
"""Render SVG files to PNG with headless Chrome (exact browser rendering; ImageMagick drops clips/details).

  python3 design/render_png.py out_dir width file1.svg file2.svg ...
Used for rulebook figures. Keeps the SVG aspect ratio.
"""
import os
import re
import subprocess
import sys
import tempfile


def render(svg_path, png_path, width):
    svg = open(svg_path, encoding='utf-8').read()
    m = re.search(r'viewBox="([-\d.\s]+)"', svg)
    vb = [float(v) for v in m.group(1).split()]
    height = round(width * vb[3] / vb[2])
    with tempfile.NamedTemporaryFile('w', suffix='.html', delete=False) as f:
        f.write(f'<body style="margin:0;background:transparent"><img src="file://{os.path.abspath(svg_path)}" width="{width}" height="{height}" style="display:block"></body>')
        html = f.name
    subprocess.run(['google-chrome', '--headless', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--default-background-color=00000000',
                    f'--window-size={width},{height}', f'--screenshot={png_path}', f'file://{html}'],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    os.unlink(html)


if __name__ == '__main__':
    out, width, files = sys.argv[1], int(sys.argv[2]), sys.argv[3:]
    os.makedirs(out, exist_ok=True)
    for fpath in files:
        render(fpath, os.path.join(out, os.path.splitext(os.path.basename(fpath))[0] + '.png'), width)
