"""Black-and-white helpers for the printable files (design/print_sheets.py)."""
import re

_HEX = re.compile(r'(?<![&\w])#([0-9a-fA-F]{6})\b|(?<![&\w])#([0-9a-fA-F]{3})\b')


def _gray(m):
    h = m.group(1) or ''.join(c * 2 for c in m.group(2))
    r, g, b = (int(h[i:i + 2], 16) for i in (0, 2, 4))
    lum = 0.299 * r + 0.587 * g + 0.114 * b
    v = lum if lum < 90 else 255 - (255 - lum) * 0.7  # keep ink dark, wash everything else out to save toner
    v = int(round(v))
    return f'#{v:02x}{v:02x}{v:02x}'


def to_gray(svg):
    """Replace every #rrggbb / #rgb colour in an SVG string by a light grey (dark colours stay dark)."""
    return _HEX.sub(_gray, svg)
