"""Renders the Gardens of Enceladus sound effects (bga/sounds/*.ogg and *.mp3) with tools/synth_sounds.py.
Run:  python3 games/gardensofenceladus/design/make_sounds.py

Each one is a soft, short, clean tone: no clicks (5 ms fade-in), pure sine/triangle, peaks at 0.3 (below BGA's
default volume). The client queues them 220 ms apart so they never pile up.
"""
import sys
from pathlib import Path

GAME = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(GAME.parent.parent / 'tools'))
from synth_sounds import render_all  # noqa: E402

SOUNDS = {
    # a Martian lands: a soft low thump
    'enc_move': [(0, 'sine', 240, 130, 0.22, 0.9), (0, 'triangle', 480, 260, 0.12, 0.25)],
    # flowers picked: two short rising blips
    'enc_pick': [(0, 'sine', 740, 740, 0.14, 0.7), (0.11, 'sine', 988, 988, 0.2, 0.7)],
    # special action paid and used: a rising two-note chime
    'enc_special': [(0, 'sine', 392, 392, 0.3, 0.6), (0.12, 'sine', 587, 587, 0.34, 0.7), (0.12, 'sine', 1175, 1175, 0.2, 0.12)],
    # end of game: a slow major arpeggio
    'enc_score': [(0, 'sine', 523, 523, 0.5, 0.6), (0.18, 'sine', 659, 659, 0.5, 0.6), (0.36, 'sine', 784, 784, 0.7, 0.7)],
}

if __name__ == '__main__':
    render_all(GAME / 'bga' / 'sounds', SOUNDS)
