"""Builds the Hippos & Crocodiles art: sprites, title, and metadata images via tools/bga_assets.py.

Run: python3 tools/bga.py assets hipposcrocodiles
"""
from pathlib import Path
import sys

GAME = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(GAME.parent.parent / 'tools'))
from bga_assets import build_metadata  # noqa: E402

if __name__ == '__main__':
    flowers = [
        'design/sprites/piece_hippo.png',
        'design/sprites/piece_crocodile.png',
        'design/sprites/piece_buffalo.png',
        'design/sprites/piece_stork.png',
    ]
    build_metadata(GAME, {
        'wordmark': 'design/title.png',
        'flowers': flowers,
        'peak': None,
        'background': [14, 34, 48],
        'publisher_logo': '../Nestor Games Logo.jpg',
    })
    print('metadata images written')
