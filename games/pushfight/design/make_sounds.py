"""Push Fight sounds: the original Web Audio tones rendered to files (run from the repo root)."""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tools'))
from synth_sounds import render_all  # noqa: E402

SOUNDS = {
    'pf_slide': [(0, 'sine', 220, 140, 0.12, 0.09)],
    'pf_thud': [(0, 'triangle', 160, 45, 0.18, 0.18)],
    'pf_anchor': [(0, 'sine', 587.33, None, 0.35, 0.11)],
    'pf_win': [(i * 0.12, 'triangle', f, None, 0.35, 0.14) for i, f in enumerate([261.63, 329.63, 392.00, 523.25])],
    'pf_shock': [(0, 'sawtooth', 360, [(0.08, 440), (0.16, 340), (0.24, 460)], 0.35, 0.08)],
    'pf_falling': [(0, 'sine', 520, 70, 0.52, 0.12)],
}

if __name__ == '__main__':
    render_all(ROOT / 'games' / 'pushfight' / 'bga' / 'sounds', SOUNDS)
