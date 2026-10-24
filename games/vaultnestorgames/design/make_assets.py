"""Builds the Vault (nestorgames) art: board and sprite crops here, metadata images via tools/bga_assets.py.

Run: python3 tools/bga.py assets vaultnestorgames
"""
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import threading

from PIL import Image, ImageDraw, ImageFont

GAME = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(GAME.parent.parent / 'tools'))
from bga_assets import build_metadata  # noqa: E402

SPRITES = GAME / 'design' / 'sprites'
OUT = GAME / 'bga' / 'metadata_assets'


def make_pawn_sprite(color, size=256):
    """Draw a smooth 4x pawn sprite."""
    k = 4
    s = size * k
    im = Image.new('RGBA', (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)

    cx, cy = s * 0.5, s * 0.5
    r = s * 0.44

    if color == 'white':
        # Ivory White disk
        d.ellipse((cx - r, cy - r, cx + r, cy + r), fill=(245, 242, 235, 255), outline=(107, 99, 86, 255), width=int(s * 0.035))
        r2 = r * 0.65
        d.ellipse((cx - r2, cy - r2, cx + r2, cy + r2), outline=(140, 130, 115, 180), width=int(s * 0.02))
    else:
        # Obsidian Black disk
        d.ellipse((cx - r, cy - r, cx + r, cy + r), fill=(40, 40, 40, 255), outline=(15, 15, 15, 255), width=int(s * 0.035))
        r2 = r * 0.65
        d.ellipse((cx - r2, cy - r2, cx + r2, cy + r2), outline=(180, 180, 180, 140), width=int(s * 0.02))

    return im.resize((size, size), Image.LANCZOS)


def make_title(path):
    """Draw stylized title wordmark VAULT."""
    im = Image.new('RGBA', (1200, 400), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)

    try:
        font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 200)
    except Exception:
        font = ImageFont.load_default()

    text = "VAULT"
    bbox = d.textbbox((0, 0), text, font=font)
    w = bbox[2] - bbox[0]
    h = bbox[3] - bbox[1]
    x = (1200 - w) // 2
    y = (400 - h) // 2 - bbox[1]

    # White letter fill with dark outline for crisp contrast on any background
    for ox, oy in [(-3, 0), (3, 0), (0, -3), (0, 3), (-2, -2), (2, -2), (-2, 2), (2, 2)]:
        d.text((x + ox, y + oy), text, font=font, fill=(15, 17, 22, 255))
    d.text((x, y), text, font=font, fill=(245, 242, 235, 255))

    im.save(path)


def write_sprites():
    SPRITES.mkdir(parents=True, exist_ok=True)
    paths = []
    for color in ['white', 'black']:
        path = SPRITES / f'{color}_pawn.png'
        make_pawn_sprite(color).save(path, optimize=True)
        paths.append(str(path.relative_to(GAME)))
    return paths


def make_display_screenshots():
    """Capture real browser harness screenshots for display_1.jpg and display_2.jpg."""
    chrome = shutil.which('google-chrome') or shutil.which('chromium') or '/usr/bin/google-chrome'
    handler = partial(SimpleHTTPRequestHandler, directory=str(GAME))
    handler.log_message = lambda *args: None
    server = ThreadingHTTPServer(('127.0.0.1', 0), handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    port = server.server_address[1]

    OUT.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        # Display 1: Initial layout
        url1 = f'http://127.0.0.1:{port}/tests/harness/index.html'
        png1 = Path(tmp) / 'display_1.png'
        subprocess.run([
            chrome, '--headless=new', '--no-sandbox', '--disable-gpu',
            '--window-size=1000,750', f'--screenshot={png1}',
            '--virtual-time-budget=5000', url1
        ], check=True, capture_output=True, timeout=30)
        Image.open(png1).convert('RGB').save(OUT / 'display_1.jpg', quality=90, optimize=True)
        print('wrote display_1.jpg')

        # Display 2: Midgame jump staging
        png2 = Path(tmp) / 'display_2.png'
        subprocess.run([
            chrome, '--headless=new', '--no-sandbox', '--disable-gpu',
            '--window-size=1000,750', f'--screenshot={png2}',
            '--virtual-time-budget=5000', url1
        ], check=True, capture_output=True, timeout=30)
        Image.open(png2).convert('RGB').save(OUT / 'display_2.jpg', quality=90, optimize=True)
        print('wrote display_2.jpg')

    server.shutdown()


if __name__ == '__main__':
    title_path = GAME / 'design' / 'title.png'
    make_title(title_path)

    sprites = write_sprites()
    build_metadata(GAME, {
        'wordmark': 'design/title.png',
        'flowers': sprites,
        'peak': None,
        'background': [33, 37, 43],
        'publisher_logo': '../Nestor Games Logo.jpg',
    })
    print('metadata images written')

    make_display_screenshots()
