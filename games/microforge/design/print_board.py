"""Ready-made boards for the print-and-play set.

* 4 players: the whole board (33 land tiles, 4 homes, 8 ports = 45 hexagons) is laid out on ONE A4 landscape page, tiles
  edge to edge, coordinates outside. The layout is searched once (fixed seed): connected over open roads, one blocked edge per
  tile, resources balanced around the four homes. Home and port spots are exactly where the BGA server puts them.
* 2-3 players: the same sheet is used. A second page shows the same board with the outer ring set aside, the homes moved to
  the ring-2 spots the server uses, and the ports placed from a small sheet of spare ports.

All geometry here mirrors TileDealer.php (home and port spots, port side rule), so paper and BGA play the same board shape.
"""
import math
import random

import build_design as bd
import tileset
from mono import to_gray

DIRS = tileset.DIRS
RAD = 13.4                         # hexagon centre-to-corner in mm (flat-to-flat 23.2 mm); the largest that fits an A4 landscape
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


HATCH = '<defs><pattern id="hatch" width="2.2" height="2.2" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="2.2" stroke="#000" stroke-width="0.35"/></pattern></defs>'


def board_4p_svg(width=297, height=210):
    """The ready-made 4 player board on one A4 landscape page."""
    L = layout_4p()
    out = []
    ox, oy = width / 2 - 8, height / 2
    for p, t in L['land'].items():
        x, y = xy(p)
        out.append(tile_group(ox + x, oy + y, land_inner(t)))
    for p, player in L['homes'].items():
        x, y = xy(p)
        out.append(tile_group(ox + x, oy + y, home_inner(player)))
    for p, t in L['ports'].items():
        x, y = xy(p)
        out.append(tile_group(ox + x, oy + y, port_inner(t)))
    allp = list(L['land']) + list(L['homes']) + list(L['ports'])
    out.append(coordinate_labels(allp, ox, oy))
    # legend (right margin)
    lx = ox + 4.2 * RAD * math.sqrt(3) + 12
    out.append(f'<text x="{lx}" y="22" font-size="3.4" font-weight="bold">4 PLAYERS</text>')
    out.append(f'<text x="{lx}" y="27" font-size="2.6">board is ready: cut out</text>')
    out.append(f'<text x="{lx}" y="31" font-size="2.6">the hexagons, put them back</text>')
    out.append(f'<text x="{lx}" y="35" font-size="2.6">where they are printed.</text>')
    for k, pl in enumerate((1, 2, 3, 4)):
        out.append(mark_shape(pl, lx + 2, 46 + k * 8, 5))
        out.append(f'<text x="{lx + 7}" y="{47.2 + k * 8}" font-size="2.6">home of player {pl}</text>')
    out.append(f'<text x="{lx}" y="86" font-size="2.6">Ports show the 2 goods</text>')
    out.append(f'<text x="{lx}" y="90" font-size="2.6">they open (Advanced).</text>')
    out.append(f'<text x="{lx}" y="94" font-size="2.6">Basic: a port opens all.</text>')
    out.append(f'<text x="{lx}" y="104" font-size="2.6">A road runs to every edge</text>')
    out.append(f'<text x="{lx}" y="108" font-size="2.6">except the hazard-striped one.</text>')
    out.append(f'<text x="{lx}" y="118" font-size="2.6">2-3 players: next page.</text>')
    return ''.join(out), L


def board_small_svg(width=297, height=210):
    """Same board with the outer ring set aside and the 2-3 player homes / ports marked."""
    L = layout_4p()
    pool, plans = spare_ports()
    homes2, homes3 = homes_for(2), homes_for(3)
    out = [HATCH]
    ox, oy = width / 2 - 8, height / 2
    for p, t in L['land'].items():
        x, y = xy(p)
        out.append(tile_group(ox + x, oy + y, land_inner(t)))
        if ring(p) == 3:
            out.append(f'<polygon points="{hex_points(ox + x, oy + y, RAD)}" fill="url(#hatch)" stroke="#000" stroke-width="0.3"/>')
        elif p in homes2 or p in homes3:
            out.append(f'<polygon points="{hex_points(ox + x, oy + y, RAD * 0.97)}" fill="none" stroke="#000" stroke-width="0.7" stroke-dasharray="1.2 1"/>')
    for p, player in L['homes'].items():
        x, y = xy(p)
        out.append(tile_group(ox + x, oy + y, home_inner(player)))
        out.append(f'<polygon points="{hex_points(ox + x, oy + y, RAD)}" fill="url(#hatch)" stroke="#000" stroke-width="0.3"/>')
    for p, t in L['ports'].items():
        x, y = xy(p)
        out.append(tile_group(ox + x, oy + y, port_inner(t)))
        out.append(f'<polygon points="{hex_points(ox + x, oy + y, RAD)}" fill="url(#hatch)" stroke="#000" stroke-width="0.3"/>')
    # where the homes go (ring 2) and the ports (ring 3 spots, now empty)
    for n, homes in ((2, homes2), (3, homes3)):
        for i, h in enumerate(homes):
            x, y = xy(h)
            out.append(f'<text x="{ox + x:.2f}" y="{oy + y - 1:.2f}" font-size="3" font-weight="bold" text-anchor="middle" fill="#000" stroke="#fff" stroke-width="0.8" paint-order="stroke">{n}P</text>')
    spots = {}
    for n, items in plans.items():
        for sp, label in items:
            spots.setdefault((sp, label), []).append(n)
    for (sp, label), ns in spots.items():
        x, y = xy(sp)
        out.append(f'<polygon points="{hex_points(ox + x, oy + y, RAD * 0.9)}" fill="#fff" stroke="#000" stroke-width="0.6" stroke-dasharray="1.5 1"/>')
        out.append(f'<text x="{ox + x:.2f}" y="{oy + y - 1.2:.2f}" font-size="2.6" text-anchor="middle">{" / ".join(str(n) + "P" for n in sorted(ns))}</text>')
        out.append(f'<text x="{ox + x:.2f}" y="{oy + y + 3.6:.2f}" font-size="5" font-weight="bold" text-anchor="middle">{label}</text>')
    allp = list(L['land']) + list(L['homes']) + list(L['ports'])
    out.append(coordinate_labels(allp, ox, oy))
    lx = ox + 4.2 * RAD * math.sqrt(3) + 12
    notes = ['2 OR 3 PLAYERS', '', 'Set aside the hatched hexagons:', 'outer ring, the 4 homes, the 8 ports.', '',
             'Play on the 19 hexagons inside.', '', '2P / 3P (dashed, with a bold', 'outline): put a home tile on that', 'spot, take its land tile away.',
             'Homes: triangle = player 1,', 'circle = 2, square = 3.', '', 'A letter on an empty spot = the spare', 'port with that letter goes there',
             '(spare ports: next sheet).', 'Some spots take the same port in', 'the 2 and the 3 player game.']
    for k, n in enumerate(notes):
        out.append(f'<text x="{lx}" y="{22 + k * 4.4}" font-size="{3.4 if k == 0 else 2.5}" font-weight="{"bold" if k == 0 else "normal"}">{n}</text>')
    return ''.join(out)


def spare_ports_svg():
    pool, _plans = spare_ports()
    out = []
    cols = 5
    for i, (label, t) in enumerate(pool):
        r, c = divmod(i, cols)
        x = 30 + c * (HEX_W * 1.3) + 10
        y = 45 + r * RAD * 2.5
        out.append(tile_group(x, y, port_inner(t)))
        out.append(f'<text x="{x:.2f}" y="{y + RAD + 4.5:.2f}" font-size="4" font-weight="bold" text-anchor="middle">{label}</text>')
    return ''.join(out), len(pool)


if __name__ == '__main__':
    L = layout_4p()
    print('spread', L['spread'], 'land', len(L['land']), 'homes', L['homes'])
    pool, plans = spare_ports()
    print('spare ports', [(l, t['goods'], t['side']) for l, t in pool])
    print(plans)
