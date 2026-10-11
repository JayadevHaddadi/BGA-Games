#!/usr/bin/env python3
"""Crops each commander's face out of concept_art/Little Commanders.jpg for the player boards (colour + grey for print)."""
import os
from PIL import Image, ImageEnhance, ImageOps

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, '..', 'concept_art', 'Little Commanders.jpg')
CENTRES = {'red': 195, 'blue': 435, 'green': 690, 'yellow': 940, 'purple': 1180, 'orange': 1395}

if __name__ == '__main__':
    src = Image.open(SRC).convert('RGB')
    os.makedirs(os.path.join(HERE, 'art'), exist_ok=True)
    for name, x in CENTRES.items():
        im = src.crop((x - 135, 366, x + 135, 366 + 146))
        im.save(os.path.join(HERE, 'art', f'commander_{name}.jpg'), quality=88)
        g = ImageEnhance.Brightness(ImageOps.autocontrast(ImageOps.grayscale(im), cutoff=1)).enhance(1.12)
        g.save(os.path.join(HERE, 'art', f'commander_{name}_mono.jpg'), quality=88)
