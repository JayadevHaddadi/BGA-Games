"""nestorgames GP sounds: the original Web Audio tones rendered to files (run from the repo root)."""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tools'))
from synth_sounds import render_all  # noqa: E402

SOUNDS = {
    'gp_roll': [(0, 'sine', 523.25, None, 0.045, 0.022), (0.038, 'sine', 783.99, None, 0.055, 0.020)],
    'gp_engine': [(0, 'sawtooth', 110, 260, 0.22, 0.035)],
    'gp_step': [(0, 'sawtooth', 150, None, 0.045, 0.02)],
    'gp_chirp': [(0, 'sawtooth', 680, 920, 0.09, 0.025)],
    'gp_brake': [(0, 'triangle', 240, 90, 0.14, 0.03)],
    'gp_bump': [(0, 'sine', 300, 460, 0.13, 0.04)],
    'gp_crash': [(0, 'square', 180, 45, 0.28, 0.05)],
    'gp_fanfare': [(i * 0.08, 'triangle', f, None, 0.12, 0.04) for i, f in enumerate([523.25, 659.25, 783.99, 1046.50])],
    'gp_chime': [(0, 'sine', 659.25, None, 0.14, 0.04), (0.08, 'sine', 880.0, None, 0.14, 0.04)],
    'gp_bust': [(0, 'sawtooth', 330, None, 0.14, 0.035), (0.16, 'sawtooth', 311, None, 0.14, 0.035),
                (0.32, 'sawtooth', 293, None, 0.14, 0.035), (0.48, 'sawtooth', 260, None, 0.35, 0.035)],
}

if __name__ == '__main__':
    render_all(ROOT / 'games' / 'nestorgamesgp' / 'bga' / 'sounds', SOUNDS)
