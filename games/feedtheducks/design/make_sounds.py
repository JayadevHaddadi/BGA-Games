"""Renders the three short sounds (splash, duck, win) into bga/sounds/ (ogg + mp3). Run: python3 games/feedtheducks/design/make_sounds.py"""
from pathlib import Path
import sys

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / 'tools'))
from synth_sounds import render_all  # noqa: E402

render_all(REPO / 'games' / 'feedtheducks' / 'bga' / 'sounds', {
    'ftd_splash': [(0, 'sine', 900, 280, 0.16, 0.12), (0.05, 'sine', 1400, 500, 0.12, 0.06), (0.14, 'sine', 700, 260, 0.14, 0.05)],
    'ftd_duck': [(0, 'triangle', 520, 330, 0.09, 0.10), (0.1, 'triangle', 480, 300, 0.11, 0.09)],
    'ftd_win': [(0, 'sine', 523, 523, 0.22, 0.09), (0.12, 'sine', 659, 659, 0.22, 0.09), (0.24, 'sine', 784, 784, 0.4, 0.09)],
})
