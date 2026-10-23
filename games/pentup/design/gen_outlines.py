"""Parses Nestor's laser-cut tile outlines (pentup_tiles.svg, mm, 15 mm cell pitch) into per-shape outline
paths in cell units, expressed in the orientation of PU_SHAPES (Game.js), and prints the JS constant PU_OUTLINES.

Run: python3 games/pentup/design/gen_outlines.py   (paste/refresh the printed block in bga/modules/js/Game.js)
"""
import re
from pathlib import Path

PITCH = 15.0
SHAPES = {
    'F': [(1, 0), (2, 0), (0, 1), (1, 1), (1, 2)], 'I': [(0, 0), (0, 1), (0, 2), (0, 3), (0, 4)],
    'L': [(0, 0), (0, 1), (0, 2), (0, 3), (1, 3)], 'N': [(1, 0), (1, 1), (0, 2), (1, 2), (0, 3)],
    'P': [(0, 0), (1, 0), (0, 1), (1, 1), (0, 2)], 'T': [(0, 0), (1, 0), (2, 0), (1, 1), (1, 2)],
    'U': [(0, 0), (2, 0), (0, 1), (1, 1), (2, 1)], 'V': [(0, 0), (0, 1), (0, 2), (1, 2), (2, 2)],
    'W': [(0, 0), (0, 1), (1, 1), (1, 2), (2, 2)], 'X': [(1, 0), (0, 1), (1, 1), (2, 1), (1, 2)],
    'Y': [(1, 0), (0, 1), (1, 1), (1, 2), (1, 3)], 'Z': [(0, 0), (1, 0), (1, 1), (1, 2), (2, 2)],
}
ARGS = {'m': 2, 'l': 2, 'c': 6}


def parse(d):
    """Absolute segments: ('M',p) ('L',p) ('C',p1,p2,p3) ('Z',)."""
    toks = re.findall(r'[MmLlCcZz]|-?\d*\.?\d+', d)
    segs, i, cur, start, cmd = [], 0, (0.0, 0.0), (0.0, 0.0), None
    while i < len(toks):
        if re.match(r'[A-Za-z]', toks[i]):
            cmd = toks[i]; i += 1
            if cmd in 'zZ':
                segs.append(('Z',)); cur = start
                continue
        n = ARGS[cmd.lower()]
        v = [float(t) for t in toks[i:i + n]]; i += n
        pts = [(v[k], v[k + 1]) for k in range(0, n, 2)]
        if cmd.islower():
            pts = [(cur[0] + p[0], cur[1] + p[1]) for p in pts]
        if cmd.lower() == 'm':
            segs.append(('M', pts[0])); start = pts[0]; cmd = 'L' if cmd == 'M' else 'l'
        elif cmd.lower() == 'l':
            segs.append(('L', pts[0]))
        else:
            segs.append(('C', *pts))
        cur = pts[-1]
    return segs


def flatten(segs):
    out, cur = [], None
    for s in segs:
        if s[0] in 'ML':
            cur = s[1]; out.append(cur)
        elif s[0] == 'C':
            p0, (p1, p2, p3) = cur, s[1:]
            for k in range(1, 9):
                t = k / 8
                out.append(tuple((1 - t) ** 3 * p0[j] + 3 * (1 - t) ** 2 * t * p1[j] + 3 * (1 - t) * t * t * p2[j] + t ** 3 * p3[j] for j in (0, 1)))
            cur = p3
    return out


def inside(poly, x, y):
    c = False
    for (x1, y1), (x2, y2) in zip(poly, poly[1:] + poly[:1]):
        if (y1 > y) != (y2 > y) and x < (x2 - x1) * (y - y1) / (y2 - y1) + x1:
            c = not c
    return c


def tf(p, o):  # orientation transform of CONTRACT.md (mirror if o >= 4, then rotate o % 4 times)
    x, y = p
    if o >= 4:
        x = -x
    for _ in range(o % 4):
        x, y = -y, x
    return x, y


def fmt(v):
    return f'{v:.4f}'.rstrip('0').rstrip('.')


def main():
    svg = (Path(__file__).parent / 'pentup_tiles.svg').read_text()
    found = {}
    for d in re.findall(r'<path[^>]* d="([^"]+)"', svg):
        segs = parse(d)
        poly = flatten(segs)
        x0 = min(p[0] for p in poly); y0 = min(p[1] for p in poly)
        x1 = max(p[0] for p in poly); y1 = max(p[1] for p in poly)
        cells = [(i, j) for i in range(round((x1 - x0) / PITCH)) for j in range(round((y1 - y0) / PITCH))
                 if inside(poly, x0 + (i + .5) * PITCH, y0 + (j + .5) * PITCH)]
        assert len(cells) == 5, cells
        for name, ref in SHAPES.items():
            for o in range(8):
                t = [tf(c, o) for c in cells]
                mx = min(a for a, _ in t); my = min(b for _, b in t)
                if sorted((a - mx, b - my) for a, b in t) != sorted(ref) or name in found:
                    continue
                # continuous shift: lowest corner of the transformed unit squares
                corners = [tf((c[0] + dx, c[1] + dy), o) for c in cells for dx in (0, 1) for dy in (0, 1)]
                sx = min(a for a, _ in corners); sy = min(b for _, b in corners)
                parts = []
                for s in segs:
                    if s[0] == 'Z':
                        parts.append('Z'); continue
                    q = [tf(((p[0] - x0) / PITCH, (p[1] - y0) / PITCH), o) for p in s[1:]]
                    parts.append(s[0] + ' ' + ' '.join(f'{fmt(a - sx)} {fmt(b - sy)}' for a, b in q))
                found[name] = ' '.join(parts)
    assert len(found) == 12, sorted(found)
    print('const PU_OUTLINES = {')
    for k in sorted(found):
        print(f"    {k}: '{found[k]}',")
    print('};')


if __name__ == '__main__':
    main()
