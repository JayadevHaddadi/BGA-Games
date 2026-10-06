#!/usr/bin/env python3
"""Generates the short, quiet game sounds into bga/sounds (ogg + mp3). Needs: pip install numpy soundfile lameenc.
Placeholder sounds: replace the files (same names) any time; BGA plays them with this.bga.sounds.play('<name>')."""
import os

import lameenc
import numpy as np
import soundfile as sf

SR = 44100
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'bga', 'sounds')


def tone(freq, dur, vol=0.22, kind='sine', decay=6.0):
    t = np.linspace(0, dur, int(SR * dur), endpoint=False)
    if kind == 'square':
        w = np.sign(np.sin(2 * np.pi * freq * t)) * 0.5 + 0.5 * np.sin(2 * np.pi * freq * t)
    else:
        w = np.sin(2 * np.pi * freq * t) + 0.3 * np.sin(2 * np.pi * freq * 2 * t)
    env = np.exp(-decay * t / dur) * np.minimum(1, t / 0.004)
    return vol * w * env


def seq(notes, gap=0.0):
    parts = []
    for n in notes:
        parts.append(tone(*n))
        if gap:
            parts.append(np.zeros(int(SR * gap)))
    return np.concatenate(parts)


SOUNDS = {
    'mf_build': seq([(160, 0.12, 0.3, 'square', 8), (240, 0.16, 0.25, 'sine', 7)]),
    'mf_move': seq([(520, 0.07, 0.18, 'sine', 9)]),
    'mf_attack': seq([(110, 0.22, 0.35, 'square', 7)]),
    'mf_fail': seq([(330, 0.14, 0.22), (262, 0.14, 0.22), (196, 0.22, 0.22)]),
    'mf_trade': seq([(1320, 0.07, 0.18, 'sine', 9), (1760, 0.18, 0.18, 'sine', 8)]),
    'mf_mission': seq([(523, 0.1, 0.22), (659, 0.1, 0.22), (784, 0.22, 0.22)]),
    'mf_win': seq([(523, 0.12, 0.25), (659, 0.12, 0.25), (784, 0.12, 0.25), (1047, 0.4, 0.25, 'sine', 4)]),
}


def main():
    os.makedirs(OUT, exist_ok=True)
    for name, wave in SOUNDS.items():
        wave = np.clip(wave, -1, 1)
        sf.write(os.path.join(OUT, f'{name}.ogg'), wave, SR, format='OGG', subtype='VORBIS')
        enc = lameenc.Encoder()
        enc.set_bit_rate(96)
        enc.set_in_sample_rate(SR)
        enc.set_channels(1)
        enc.set_quality(2)
        pcm = (wave * 32767).astype(np.int16).tobytes()
        with open(os.path.join(OUT, f'{name}.mp3'), 'wb') as f:
            f.write(enc.encode(pcm) + enc.flush())
    print('sounds:', ', '.join(SOUNDS))


if __name__ == '__main__':
    main()
