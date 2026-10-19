"""Render short synth sounds to .ogg and .mp3 files for a game's sounds/ folder (BGA plays files, not Web Audio).

Each sound is a list of tones: (start_s, wave, freq_start, freq_end, duration_s, gain). Waves: sine, triangle,
sawtooth, square. The pitch glides exponentially from freq_start to freq_end; the volume rises over 5 ms and decays
exponentially. `freq_end` may be a list of (time_s, freq) steps instead of a glide.

    from synth_sounds import render_all
    render_all('games/<slug>/bga/sounds', {'pf_slide': [(0, 'sine', 220, 140, 0.12, 0.09)]})

Needs: pip install soundfile numpy (libsndfile 1.1+ writes OGG and MP3). Peaks are normalised to 0.3, below BGA's
default volume (guideline D.4).
"""
from pathlib import Path

import numpy as np
import soundfile as sf

RATE = 44100
PEAK = 0.3


def tone(wave, f0, f1, dur, gain):
    n = int(RATE * dur)
    t = np.arange(n) / RATE
    if isinstance(f1, list):
        freq = np.full(n, float(f0))
        for at, f in f1:
            freq[t >= at] = f
    elif f1 and f1 != f0:
        freq = f0 * (f1 / f0) ** (t / dur)
    else:
        freq = np.full(n, float(f0))
    phase = 2 * np.pi * np.cumsum(freq) / RATE
    cycle = (phase / (2 * np.pi)) % 1.0
    signal = {
        'sine': np.sin(phase),
        'triangle': 2 * np.abs(2 * cycle - 1) - 1,
        'sawtooth': 2 * cycle - 1,
        'square': np.where(cycle < 0.5, 1.0, -1.0),
    }[wave]
    attack = min(int(RATE * 0.005), n)
    env = np.exp(np.log(1e-4) * t / dur)
    env[:attack] *= np.linspace(0, 1, attack)
    return signal * env * gain


def render(tones):
    length = max(start + dur for start, _, _, _, dur, _ in tones) + 0.02
    out = np.zeros(int(RATE * length))
    for start, wave, f0, f1, dur, gain in tones:
        part = tone(wave, f0, f1, dur, gain)
        i = int(RATE * start)
        out[i:i + len(part)] += part
    peak = np.max(np.abs(out)) or 1
    return out * (PEAK / peak)


def render_all(folder, sounds):
    folder = Path(folder)
    folder.mkdir(parents=True, exist_ok=True)
    for name, tones in sounds.items():
        data = render(tones).astype(np.float32)
        sf.write(folder / f'{name}.ogg', data, RATE, format='OGG', subtype='VORBIS')
        sf.write(folder / f'{name}.mp3', data, RATE, format='MP3', subtype='MPEG_LAYER_III')
        print('wrote', folder / name)
