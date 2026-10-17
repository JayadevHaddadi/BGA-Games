"""Control Panel display images (1000 x 750) from the browser harness. Run: python3 games/microchip/design/display.py

Serves games/microchip/ on a free local port, opens tests/harness/index.html in headless Chrome (clean=1 hides the
report), and saves the two screenshots as bga/metadata_assets/display_1.jpg and display_2.jpg.
The harness uses a fixed fixture, so the pictures show the game's layout, not a real table.
"""
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import shutil
import subprocess
import tempfile
import threading

from PIL import Image

GAME = Path(__file__).resolve().parent.parent
OUT = GAME / 'bga' / 'metadata_assets'
SHOTS = [('turn', 'display_1.jpg'), ('build', 'display_2.jpg')]
CHROME = shutil.which('google-chrome') or shutil.which('chromium') or 'google-chrome'


def main():
    handler = partial(SimpleHTTPRequestHandler, directory=str(GAME))
    handler.log_message = lambda *args: None
    server = ThreadingHTTPServer(('127.0.0.1', 0), handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    port = server.server_address[1]
    OUT.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        for mode, name in SHOTS:
            png = Path(tmp) / f'{mode}.png'
            url = f'http://127.0.0.1:{port}/tests/harness/index.html?mode={mode}&clean=1&zoom=0.72'
            subprocess.run([CHROME, '--headless=new', '--no-sandbox', '--disable-gpu', '--virtual-time-budget=15000',
                            '--window-size=1000,750', f'--screenshot={png}', url],
                           check=True, capture_output=True, timeout=180)
            Image.open(png).convert('RGB').save(OUT / name, quality=88, optimize=True)
            print('wrote', OUT / name)
    server.shutdown()


if __name__ == '__main__':
    main()
