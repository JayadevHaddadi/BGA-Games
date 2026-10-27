"""Builds the Feed the Ducks art: board and sprite crops here, metadata images via tools/bga_assets.py.

Run: python3 tools/bga.py assets feedtheducks
"""
from pathlib import Path
import sys

GAME = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(GAME.parent.parent / 'tools'))
from bga_assets import build_metadata  # noqa: E402

if __name__ == '__main__':
    # TODO: crop or draw the gameplay art into bga/img/ (keep it small: BGA preloads img/)
    build_metadata(GAME, {
        'wordmark': 'TODO_title.png',            # title art in the game folder
        'flowers': [],                           # up to five sprites, e.g. 'bga/img/piece_1.png'
        'peak': None,
        'background': [29, 31, 36],
        'publisher_logo': None,                  # e.g. '../Nestor Games Logo.jpg'
        'publisher_tint': None,
    })
    print('metadata images written')
