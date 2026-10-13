"""Ready-made boards for the print-and-play set.

* 4 players: the whole board (33 land tiles, 4 homes, 8 ports = 45 hexagons) is printed on TWO A4 portrait pages, the left
  half on one and the right half on the other (whole hexagons only, tiles edge to edge, coordinates outside). The layout is
  searched once (fixed seed): connected over open roads, one blocked edge per tile, resources balanced around the four homes.
  Home and port spots are exactly where the BGA server puts them.
* 2-3 players: the same tiles. A small mark in the top corner of each tile says which tiles a 2 / 3 player game uses
  ("2P 3P"), where a home tile goes ("home 1" ...), and the spare ports carry the coordinate they go to.

All geometry here mirrors TileDealer.php (home and port spots, port side rule), so paper and BGA play the same board shape.
"""
import math
import random

import build_design as bd
import tileset
from mono import to_gray

DIRS = tileset.DIRS
RAD = 19.5                         # hexagon centre-to-corner in mm (flat-to-flat 33.8 mm): half the board per A4 portrait page
HEX_W = RAD * math.sqrt(3)
SCALE = RAD / bd.U                 # tile units -> mm
PORT_PAIRS = [('iron', 'crystal'), ('bot', 'mech'), ('iron', 'bot'), ('crystal', 'mech'), ('iron', 'mech'), ('crystal', 'bot'), ('iron', 'crystal'), ('bot', 'mech')]


# ---------------------------------------------------------------- geometry (mirror of TileDealer.php)

def dist(a, b):
    dq, dr = a[0] - b[0], a[1] - b[1]
    return max(abs(dq), abs(dr), abs(dq + dr))


def ring(p):
    return dist(p, (0, 0))


def angle(p):
    return math.atan2(1.5 * p[1], math.sqrt(3) * (p[0] + p[1] / 2))


def land_positions(radius):
    return [(q, r) for q in range(-radius, radius + 1) for r in range(-radius, radius + 1) if abs(-q - r) <= radius]


def perimeter(radius):
    return sorted([p for p in land_positions(radius) if ring(p) == radius], key=angle)


def corners(radius):
    return sorted([p for p in perimeter(radius) if sum(1 for a in (abs(p[0]), abs(p[1]), abs(-p[0] - p[1])) if a == radius) == 2], key=angle)


def outer_spots(radius):
    o = radius + 1
    return sorted([(q, r) for q in range(-o, o + 1) for r in range(-o, o + 1)
                   if max(abs(q), abs(r), abs(-q - r)) == o and sum(1 for a in (abs(q), abs(r), abs(-q - r)) if a == o) == 1], key=angle)


def port_side(spot, radius):
    dirs = [d for d, (dq, dr) in enumerate(DIRS) if ring((spot[0] + dq, spot[1] + dr)) <= radius]
    return dirs[0] if len(dirs) == 2 and dirs[1] - dirs[0] == 1 else 5


def homes_for(players):
    if players == 2:
        c = corners(2)
        return [c[0], c[3]]
    radius = 2 if players <= 3 else 3
    per = perimeter(radius)
    return [per[i * len(per) // players] for i in range(players)]


def ports_for(players):
    """Port spots exactly as TileDealer::deal chooses them."""
    radius = 2 if players <= 3 else 3
    spots = outer_spots(radius)
    if players == 2:
        c, chosen = corners(2), []
        for corner in (c[1], c[4]):
            for s in spots:
                if dist(s, corner) == 1 and s not in chosen:
                    chosen.append(s)
                    break
        return chosen
    count = 2 * players
    return [spots[(2 * p + 1) * len(spots) // (2 * count) % len(spots)] for p in range(count)]


# ---------------------------------------------------------------- the 4 player layout

def _connected(masks):
    start = (0, 0)
    seen, stack = {start}, [start]
    while stack:
        p = stack.pop()
        for d, (dq, dr) in enumerate(DIRS):
            q = (p[0] + dq, p[1] + dr)
            if q in masks and q not in seen and masks[p][d] == '1' and masks[q][(d + 3) % 6] == '1':
                seen.add(q)
                stack.append(q)
    return len(seen) == len(masks)


def _links(p, masks):
    return sum(1 for d, (dq, dr) in enumerate(DIRS) if (p[0] + dq, p[1] + dr) in masks and masks[p][d] == '1' and masks[(p[0] + dq, p[1] + dr)][(d + 3) % 6] == '1')


def layout_4p(seed=5, trials=60000):
    """Returns {'land': {pos: tile}, 'homes': {pos: player}, 'ports': {pos: tile}, 'spread': float}."""
    land = tileset.land_tiles()
    by_level = {lv: [t for t in land if t['level'] == lv] for lv in (1, 2, 3, 4)}
    homes = homes_for(4)
    home_set = set(homes)
    pos_by_ring = {k: [p for p in land_positions(3) if ring(p) == k and p not in home_set] for k in (1, 2, 3)}
    port_pos = ports_for(4)
    port_tiles = {}
    for i, sp in enumerate(port_pos):
        side = port_side(sp, 3)
        goods = list(PORT_PAIRS[i])
        port_tiles[sp] = next(t for t in tileset.port_tiles() if t['goods'] == goods and t['side'] == side)
    rng = random.Random(seed)
    best, best_spread = None, 1e9
    for _ in range(trials):
        pick = {1: by_level[2][:], 2: by_level[3][:], 3: by_level[4][:]}
        assign = {(0, 0): by_level[1][0]}
        for k in (1, 2, 3):
            rng.shuffle(pick[k])
            for p, t in zip(pos_by_ring[k], pick[k]):
                assign[p] = t
        masks = {p: t['mask'] for p, t in assign.items()}
        masks.update({h: '111111' for h in homes})
        masks.update({sp: t['mask'] for sp, t in port_tiles.items()})
        if not _connected(masks) or any(_links(h, masks) < 2 for h in homes):
            continue
        scores = []
        for h in homes:
            v = 0.0
            for p, t in assign.items():
                if dist(p, h) <= 2:
                    v += 1.0 if t['res'] == 'iron' else (2.2 if t['res'] == 'crystal' else 0.0)
            scores.append(v)
        spread = max(scores) - min(scores)
        if spread < best_spread:
            best, best_spread = dict(assign), spread
            if spread == 0:
                break
    return {'land': best, 'homes': {h: i + 1 for i, h in enumerate(homes)}, 'ports': port_tiles, 'spread': best_spread}


# ---------------------------------------------------------------- spare ports for the 2-3 player setups

def spare_ports():
    """Port tiles the 2 and 3 player setups need (ring-3 spots), one pool: {label: tile}; plus the spot -> label plans."""
    need = {}
    plans = {}
    for n in (2, 3):
        plans[n] = []
        for sp in ports_for(n):
            plans[n].append((sp, port_side(sp, 2)))
    per_side = {}
    for n in (2, 3):
        cnt = {}
        for _sp, side in plans[n]:
            cnt[side] = cnt.get(side, 0) + 1
        for side, c in cnt.items():
            per_side[side] = max(per_side.get(side, 0), c)
    pool, labels = [], []
    pairs = [p for p in PORT_PAIRS]
    i = 0
    for side in sorted(per_side):
        for _ in range(per_side[side]):
            goods = list(pairs[i % len(pairs)])
            tile = next(t for t in tileset.port_tiles() if t['goods'] == goods and t['side'] == side)
            pool.append(tile)
            labels.append(chr(97 + len(pool) - 1))
            i += 1
    # assign each needed spot a pool tile of its side (a different one inside one setup)
    result = {}
    for n in (2, 3):
        used = set()
        result[n] = []
        for sp, side in plans[n]:
            idx = next(k for k, t in enumerate(pool) if t['side'] == side and k not in used)
            used.add(idx)
            result[n].append((sp, labels[idx]))
    return list(zip(labels, pool)), result


# ---------------------------------------------------------------- drawing (mm)

def xy(p):
    return RAD * math.sqrt(3) * (p[0] + p[1] / 2), RAD * 1.5 * p[1]


def hex_points(x, y, r):
    return ' '.join(f'{x + r * math.cos(math.radians(60 * i + 30)):.2f},{y + r * math.sin(math.radians(60 * i + 30)):.2f}' for i in range(6))


def tile_group(x, y, inner):
    return f'<g transform="translate({x:.2f},{y:.2f}) scale({SCALE:.5f})">{to_gray(inner)}</g>'


def mark_shape(player, x, y, size, fill='#000000'):
    h = size / 2
    if player == 1:
        return f'<polygon points="{x},{y - h} {x + h},{y + h} {x - h},{y + h}" fill="{fill}"/>'
    if player == 3:
        return f'<rect x="{x - h * 0.85}" y="{y - h * 0.85}" width="{h * 1.7}" height="{h * 1.7}" fill="{fill}"/>'
    if player == 4:
        return f'<polygon points="{x},{y - h} {x + h},{y} {x},{y + h} {x - h},{y}" fill="{fill}"/>'
    return f'<circle cx="{x}" cy="{y}" r="{h}" fill="{fill}"/>'


def home_inner(player):
    """Home tile in tile units: flag + the player's mark (triangle, circle, square, diamond)."""
    banner = '#000000' if player % 2 else '#ffffff'
    return bd.tile_inner('empty', 0, [True] * 6, banner=banner, mono=True, extra=mark_shape(player, 19, -22, 12))


def land_inner(t):
    kind = 'centre' if t['level'] == 1 else (t['res'] or 'empty')
    return bd.tile_inner(kind, t['slots'], [c == '1' for c in t['mask']], mono=True)


def port_inner(t):
    return bd.tile_inner('port', 0, [c == '1' for c in t['mask']], extra=bd.port_extra(t['goods']), mono=True)


def coordinate_labels(all_pos, ox, oy):
    """Same naming as coordLabel / the BGA board: row letter (top = A) + diagonal number, printed outside."""
    minr = min(p[1] for p in all_pos)
    minq = min(p[0] for p in all_pos)
    rows, diags = {}, {}
    for p in all_pos:
        if p[1] not in rows or p[0] < rows[p[1]][0]:
            rows[p[1]] = p
        if p[0] not in diags or p[1] < diags[p[0]][1]:
            diags[p[0]] = p
    st = 'font-size="3.6" fill="#222" text-anchor="middle" dominant-baseline="middle" font-weight="bold"'
    out = []
    for p in rows.values():
        x, y = xy(p)
        out.append(f'<text x="{ox + x - 1.25 * RAD:.2f}" y="{oy + y:.2f}" {st}>{chr(65 + p[1] - minr)}</text>')
    for p in diags.values():
        x, y = xy(p)
        out.append(f'<text x="{ox + x - 0.75 * RAD:.2f}" y="{oy + y - 1.3 * RAD:.2f}" {st}>{p[0] - minq + 1}</text>')
    return ''.join(out)


def coord_name(p, allp):
    minr = min(q[1] for q in allp)
    minq = min(q[0] for q in allp)
    return chr(65 + p[1] - minr) + str(p[0] - minq + 1)


def corner_marks(L):
    """{pos: [line, ...]} printed in the top corner of tiles a 2 / 3 player game uses."""
    h2, h3 = homes_for(2), homes_for(3)
    marks = {}
    inner = [p for p in L['land'] if ring(p) <= 2]
    for p in inner:
        marks[p] = ['2P 3P']
    for n, homes in ((2, h2), (3, h3)):
        for i, h in enumerate(homes):
            counts = [f'{m}P' for m, hs in ((2, h2), (3, h3)) if h in hs and hs.index(h) == i]
            marks[h] = [' '.join(counts), f'home {i + 1}']
    for p, player in L['homes'].items():
        marks[p] = ['2P 3P'] if player <= 2 else (['3P'] if player == 3 else [])
    return marks


def board_half_svg(side):
    """One A4 portrait page (210 x 297 mm) with the left or the right half of the 4 player board."""
    L = layout_4p()
    hexes = []  # (pos, inner svg)
    hexes += [(p, land_inner(t)) for p, t in L['land'].items()]
    hexes += [(p, home_inner(pl)) for p, pl in L['homes'].items()]
    hexes += [(p, port_inner(t)) for p, t in L['ports'].items()]
    allp = [p for p, _ in hexes]
    is_left = lambda p: xy(p)[0] < -0.01 or (abs(xy(p)[0]) < 0.01 and p[1] <= 0)
    mine = [p for p in allp if is_left(p) == (side == 'left')]
    ox, oy = (186.0 if side == 'left' else 36.0), 148.5
    out = []
    for p, inner in hexes:
        if p in mine:
            x, y = xy(p)
            out.append(tile_group(ox + x, oy + y, inner))
    marks = corner_marks(L)
    for p in mine:
        x, y = xy(p)
        for k, line in enumerate(marks.get(p, [])):
            out.append(f'<text x="{ox + x:.2f}" y="{oy + y - 0.66 * RAD + k * 3.6:.2f}" font-size="3.3" font-weight="bold" text-anchor="middle" '
                       f'fill="#000" stroke="#fff" stroke-width="0.9" paint-order="stroke">{line}</text>')
    # coordinates outside (only the ones whose anchor hex is on this page)
    minr = min(p[1] for p in allp)
    minq = min(p[0] for p in allp)
    rows, diags = {}, {}
    for p in allp:
        if p[1] not in rows or p[0] < rows[p[1]][0]:
            rows[p[1]] = p
        if p[0] not in diags or p[1] < diags[p[0]][1]:
            diags[p[0]] = p
    st = 'font-size="4.4" fill="#222" text-anchor="middle" dominant-baseline="middle" font-weight="bold"'
    for p in rows.values():
        if p in mine:
            x, y = xy(p)
            out.append(f'<text x="{ox + x - 1.25 * RAD:.2f}" y="{oy + y:.2f}" {st}>{chr(65 + p[1] - minr)}</text>')
    for p in diags.values():
        if p in mine:
            x, y = xy(p)
            out.append(f'<text x="{ox + x - 0.75 * RAD:.2f}" y="{oy + y - 1.3 * RAD:.2f}" {st}>{p[0] - minq + 1}</text>')
    if side == 'left':
        notes = ['4 PLAYERS: LEFT HALF', '', 'Cut out the hexagons and lay them', 'out with the right half (next page):', 'letters A-I = rows, numbers = diagonals.']
        nx, ny = 6, 12
    else:
        notes = ['4 PLAYERS: RIGHT HALF', '', 'Use every tile.', '', '2 OR 3 PLAYERS: use only tiles', 'marked 2P 3P (both games use', 'the same ones). "home 1/2/3" =', 'put the home tile of that player on', 'this tile instead (take the land tile', 'away). Port spots: see the spare', 'ports page.']
        nx, ny = 118, 12
    for k, n in enumerate(notes):
        out.append(f'<text x="{nx}" y="{ny + k * 4.6}" font-size="{3.6 if k == 0 else 2.9}" font-weight="{"bold" if k == 0 else "normal"}">{n}</text>')
    return ''.join(out)


def spare_ports_svg():
    pool, plans = spare_ports()
    L = layout_4p()
    allp = list(L['land']) + list(L['homes']) + list(L['ports'])
    spots = {}
    for n in (2, 3):
        for sp, label in plans[n]:
            spots.setdefault(label, []).append((n, sp))
    out = []
    cols = 3
    for i, (label, t) in enumerate(pool):
        r, c = divmod(i, cols)
        x = 50 + c * (HEX_W * 1.6)
        y = 50 + r * (RAD * 2 + 22)
        out.append(tile_group(x, y, port_inner(t)))
        by_spot = {}
        for n, sp in spots.get(label, []):
            by_spot.setdefault(sp, []).append(f'{n}P')
        for k, (sp, ns) in enumerate(by_spot.items()):
            out.append(f'<text x="{x:.2f}" y="{y + RAD + 5.5 + k * 4.6:.2f}" font-size="3.4" font-weight="bold" text-anchor="middle">{" ".join(ns)}: {coord_name(sp, allp)}</text>')
    return ''.join(out), len(pool)


if __name__ == '__main__':
    L = layout_4p()
    print('spread', L['spread'], 'land', len(L['land']), 'homes', L['homes'])
    pool, plans = spare_ports()
    print('spare ports', [(l, t['goods'], t['side']) for l, t in pool])
    print(plans)
