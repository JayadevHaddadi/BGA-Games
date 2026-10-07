#!/usr/bin/env python3
"""Generates the plain print/BGA design files for Little Commanders.

  python3 design/build_design.py

Outputs (all SVG, art slots are empty placeholders with id="art-slot"):
  design/missions/*.svg        40 mission cards + 2 card backs (690x940 incl. 3mm bleed, trim 630x880)
  design/missions_overview.html  every card + a table you can read
  design/tiles/*.svg           land tiles (37), home tiles (6), port tiles (12)
  design/tiles_overview.html   tile list with counts
  design/board_layout.svg      where tiles sit (4-6 players, 37 land + 12 ports)

Mission data is copied from Game.php MISSION_POOL / Game.js MISSION_TEXT. If those change, edit MISSIONS below.
"""
import math
import os
import sys
import random
from html import escape

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from icons import ICONS, icon_svg, icon_group, mission_icon  # noqa: E402
import tileset  # noqa: E402
from boards import basic_board, advanced_board  # noqa: E402

# ---------- shared palette (see ART_PLAN.md colour theme) ----------
INK = '#2b2233'
PAPER = '#f4ead7'
PURPLE = '#6d3a8c'
TEAL = '#2f7f79'
IRON_GREEN = '#a9d07c'
CRYSTAL_BLUE = '#9fd0e6'
EMPTY_GREY = '#c3c6cc'
GOLD = '#e6c455'
SEA = '#6f95b0'
FACTIONS = [('Red', '#c0392b'), ('Blue', '#2980b9'), ('Green', '#27ae60'),
            ('Yellow', '#e1b12c'), ('Purple', '#8e44ad'), ('Orange', '#d35400')]

# ---------- missions ----------
TEXT = {
    'extractors': ('Mine Boss', 'Control {n} Extractors.'),
    'factories': ('Toy Factory', 'Control {n} Factories.'),
    'towers': ('Fort Builder', 'Control {n} Guard Towers.'),
    'buildings': ('Builder', 'Control {n} buildings (Docks do not count).'),
    'bots': ('Bot Pals', 'Have {n} bots on the map.'),
    'mechs': ('Mech Fan', 'Have {n} mechs on the map.'),
    'pieces': ('Big Army', 'Have {n} pieces (bots and mechs) on the map.'),
    'iron_tokens': ('Iron Pile', 'Hold {n} iron on hexes you control.'),
    'crystal_tokens': ('Crystal Cave', 'Hold {n} crystal on hexes you control.'),
    'tokens': ('Treasure Pile', 'Hold {n} resource tokens on hexes you control.'),
    'hexes': ('Land Grab', 'Control {n} hexes.'),
    'double_tiles': ('Rich Land', 'Control {n} hex(es) with two resources.'),
    'ports': ('Harbor Boss', 'Control {n} different Port tiles.'),
    'center': ('King of the Hill', 'Control the central hex.'),
    'credits': ('Piggy Bank', 'Have {n} Credits.'),
}
MISSIONS = {
    1: [('extractors', 2), ('extractors', 3), ('factories', 1), ('towers', 1), ('bots', 5),
        ('bots', 7), ('mechs', 1), ('pieces', 6), ('iron_tokens', 3), ('crystal_tokens', 3),
        ('tokens', 5), ('hexes', 3), ('hexes', 4), ('ports', 1), ('ports', 2),
        ('double_tiles', 1), ('credits', 15), ('buildings', 3), ('hexes', 5), ('buildings', 4)],
    2: [('extractors', 4), ('extractors', 5), ('factories', 2), ('factories', 3), ('towers', 2),
        ('towers', 3), ('bots', 10), ('bots', 13), ('mechs', 2), ('mechs', 3),
        ('pieces', 12), ('iron_tokens', 6), ('crystal_tokens', 5), ('tokens', 9), ('hexes', 7),
        ('hexes', 9), ('ports', 3), ('center', 1), ('double_tiles', 2), ('credits', 30)],
}
VP = {1: 1, 2: 2}

W, H, BLEED = 690, 940, 30  # card canvas incl. bleed


def slug(s):
    return ''.join(c.lower() if c.isalnum() else '-' for c in s).strip('-')


def hexpts(cx, cy, r, flat=True):
    pts = []
    for i in range(6):
        a = math.radians(60 * i + (0 if flat else 30))
        pts.append((cx + r * math.cos(a), cy + r * math.sin(a)))
    return ' '.join(f'{x:.1f},{y:.1f}' for x, y in pts)


def wrap(text, width):
    words, lines, cur = text.split(), [], ''
    for w in words:
        if len(cur) + len(w) + (1 if cur else 0) > width:
            lines.append(cur)
            cur = w
        else:
            cur = f'{cur} {w}'.strip()
    if cur:
        lines.append(cur)
    return lines


def star_at(cx, cy, r_out, r_in, n=5):
    pts = []
    for i in range(2 * n):
        r = r_out if i % 2 == 0 else r_in
        a = math.radians(-90 + 180 * i / n)
        pts.append(f'{cx + r * math.cos(a):.1f},{cy + r * math.sin(a):.1f}')
    return ' '.join(pts)


def card_svg(level, idx, mtype, n):
    base, text = TEXT[mtype]
    name = base if mtype == 'center' else f'{base} {n}'
    desc = text.format(n=n)
    if n == 1:
        for plural, single in [('Factories', 'Factory'), ('Extractors', 'Extractor'), ('Guard Towers', 'Guard Tower'),
                               ('buildings', 'building'), ('bots', 'bot'), ('mechs', 'mech'), ('pieces', 'piece'),
                               ('hexes', 'hex'), ('Port tiles', 'Port tile'), ('resource tokens', 'resource token'),
                               ('Credits', 'Credit')]:
            desc = desc.replace(plural, single)
    accent = TEAL if level == 1 else PURPLE
    vp = VP[level]
    x0, y0, x1, y1 = BLEED, BLEED, W - BLEED, H - BLEED  # trim box
    card_w = x1 - x0
    s = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W / 10}mm" height="{H / 10}mm" font-family="DejaVu Sans, Arial, sans-serif">',
         f'<title>Mission L{level}-{idx:02d} {escape(name)}</title>',
         f'<rect id="bleed" x="0" y="0" width="{W}" height="{H}" fill="{accent}"/>',
         f'<rect id="trim" x="{x0}" y="{y0}" width="{card_w}" height="{y1 - y0}" rx="26" fill="{PAPER}" stroke="{INK}" stroke-width="4"/>',
         # header
         f'<rect x="{x0 + 20}" y="{y0 + 20}" width="{card_w - 40}" height="84" rx="10" fill="{accent}"/>',
         f'<text x="{W / 2}" y="{y0 + 78}" text-anchor="middle" font-size="44" font-weight="bold" fill="{PAPER}">{escape(name)}</text>',
         # compact art slot banner
         f'<g id="art-slot"><rect x="{x0 + 20}" y="{y0 + 116}" width="{card_w - 40}" height="170" rx="10" fill="#ebe1cd" stroke="{INK}" stroke-width="3" stroke-dasharray="12 8"/>',
         f'<text class="placeholder" x="{W / 2}" y="{y0 + 210}" text-anchor="middle" font-size="22" fill="#9a8f7c">ART SLOT {card_w - 40} x 170</text></g>',
         # hero condition area (large iconography)
         f'<rect x="{x0 + 20}" y="{y0 + 298}" width="{card_w - 40}" height="375" rx="14" fill="#fffbf0" stroke="{INK}" stroke-width="3.5"/>']

    y_mid = y0 + 445
    if mtype == 'center':
        s.append(mission_icon(mtype, W / 2, y_mid - 25, 170))
        s.append(f'<text x="{W / 2}" y="{y_mid + 85}" text-anchor="middle" font-size="42" font-weight="bold" fill="{INK}">CENTRE HEX</text>')
    else:
        s.append(mission_icon(mtype, x0 + 160, y_mid, 165))
        s.append(f'<text x="{x0 + 310}" y="{y_mid + 20}" font-size="75" font-weight="bold" fill="#7a6e60">&#215;</text>')
        s.append(f'<text x="{x0 + 380}" y="{y_mid + 46}" font-size="130" font-weight="bold" fill="{INK}">{n}</text>')

    # clear description banner inside condition card
    desc_y = y0 + 605
    s.append(f'<rect x="{x0 + 35}" y="{desc_y}" width="{card_w - 70}" height="56" rx="8" fill="{PAPER}" stroke="{INK}" stroke-width="2"/>')
    s.append(f'<text x="{W / 2}" y="{desc_y + 39}" text-anchor="middle" font-size="32" font-weight="bold" fill="{INK}">{escape(desc)}</text>')

    # footer: Cost (bottom left), Star VP reward (bottom right)
    cy = y1 - 65
    s += [
        f'<g id="cost">',
        f'<circle cx="{x0 + 65}" cy="{cy}" r="38" fill="#e6c455" stroke="{INK}" stroke-width="4"/>',
        f'<circle cx="{x0 + 65}" cy="{cy}" r="30" fill="none" stroke="#fff176" stroke-width="2.5"/>',
        f'<text x="{x0 + 65}" y="{cy + 14}" text-anchor="middle" font-size="40" font-weight="bold" fill="{INK}">5</text></g>',
        f'<text x="{x0 + 118}" y="{y1 - 76}" font-size="24" font-weight="bold" fill="{INK}">Cost: 5 Credits</text>',
        f'<text x="{x0 + 118}" y="{y1 - 50}" font-size="18" fill="{INK}">+1 for each bought</text>',
        f'<text x="{x0 + 118}" y="{y1 - 25}" font-size="16" fill="#7d7388">Level {level} &#8226; {idx:02d}/20</text>',
        # VP Star Icon Badge
        f'<g id="reward">',
        f'<polygon points="{star_at(x1 - 80, cy - 4, 52, 23)}" fill="{GOLD}" stroke="{INK}" stroke-width="4"/>',
        f'<polygon points="{star_at(x1 - 80, cy - 4, 32, 14)}" fill="#fff59d" stroke="none"/>',
        f'<text x="{x1 - 80}" y="{cy + 12}" text-anchor="middle" font-size="44" font-weight="bold" fill="{INK}">{vp}</text>',
        f'<text x="{x1 - 80}" y="{cy + 52}" text-anchor="middle" font-size="18" font-weight="bold" fill="{INK}">VP</text></g>',
        '</svg>'
    ]
    return name, desc, '\n'.join(s)


def back_svg(level):
    accent = TEAL if level == 1 else PURPLE
    x0, y0, x1, y1 = BLEED, BLEED, W - BLEED, H - BLEED
    return '\n'.join([
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W / 10}mm" height="{H / 10}mm" font-family="DejaVu Sans, Arial, sans-serif">',
        f'<title>Mission card back level {level}</title>',
        f'<rect width="{W}" height="{H}" fill="{accent}"/>',
        f'<rect x="{x0}" y="{y0}" width="{x1 - x0}" height="{y1 - y0}" rx="26" fill="{PAPER}" stroke="{INK}" stroke-width="4"/>',
        f'<g id="art-slot"><rect x="{x0 + 40}" y="{y0 + 40}" width="{x1 - x0 - 80}" height="{y1 - y0 - 80}" rx="12" fill="#ebe1cd" stroke="{INK}" stroke-width="3" stroke-dasharray="14 8"/>',
        f'<text class="placeholder" x="{W / 2}" y="{H / 2 - 20}" text-anchor="middle" font-size="26" fill="#9a8f7c">BACK ART SLOT</text></g>',
        f'<polygon points="{hexpts(W / 2, H / 2 + 120, 80)}" fill="{accent}" stroke="{INK}" stroke-width="4"/>',
        f'<text x="{W / 2}" y="{H / 2 + 142}" text-anchor="middle" font-size="70" font-weight="bold" fill="{PAPER}">{"I" * level}</text>',
        '</svg>'])


# ---------- tiles: ONE drawing shared by print and BGA ----------
# Pointy-top hex, unit size 40 (centre to vertex) = Game.js renderBoard. Directions follow Game.php DIRS.
U = 40
EDGE_ANG = [0, -60, -120, 180, 120, 60]
VB = f'{-U * 0.866:.2f} {-U} {2 * U * 0.866:.2f} {2 * U}'
GROUND_FILL = {'iron': IRON_GREEN, 'crystal': CRYSTAL_BLUE, 'empty': EMPTY_GREY, 'port': SEA, 'double': IRON_GREEN}


def ground_group(kind, outline=True):
    """Ground + texture + corner emblem. Decorations stay 5 units inside the edge; centre stays free for slots and units."""
    o = [f'<polygon points="{hexpts(0, 0, U, flat=False)}" fill="{GROUND_FILL[kind]}"' + (f' stroke="{INK}" stroke-width="1.6"' if outline else '') + '/>',
         f'<clipPath id="c_{kind}"><polygon points="{hexpts(0, 0, U - 5, flat=False)}"/></clipPath>', f'<g clip-path="url(#c_{kind})">']
    if kind == 'iron':
        for x, y in [(-18, 22), (-6, 28), (10, 30), (24, 20), (20, -4), (-28, 6)]:
            o.append(f'<path d="M{x} {y} l-2 -7 M{x} {y} l1 -8 M{x} {y} l3 -6" stroke="#6f9b45" stroke-width="1.6" fill="none" stroke-linecap="round"/>')
        o.append(f'<g stroke="{INK}" stroke-width="1.3" stroke-linejoin="round"><polygon points="-27,-14 -21,-27 -12,-24 -11,-14 -18,-10" fill="#8d8d96"/><polygon points="-14,-16 -8,-24 -1,-18 -4,-10" fill="#a9a9b3"/></g>')
    elif kind == 'crystal':
        for x, y in [(-16, 26), (14, 28), (24, 10)]:
            o.append(f'<path d="M{x} {y - 4} l3 4 l-3 4 l-3 -4 z" fill="#d9eef8" stroke="{INK}" stroke-width="0.8"/>')
        o.append(f'<g stroke="{INK}" stroke-width="1.3" stroke-linejoin="round"><polygon points="-22,-10 -17,-28 -12,-10 -17,-6" fill="#4f9fc9"/><polygon points="-14,-12 -9,-24 -4,-12 -9,-8" fill="#78b9dc"/><polygon points="-30,-8 -27,-20 -23,-8" fill="#78b9dc"/></g>')
    elif kind == 'double':
        for x, y in [(-18, 24), (-6, 28), (24, 20), (-28, 6)]:
            o.append(f'<path d="M{x} {y} l-2 -7 M{x} {y} l1 -8 M{x} {y} l3 -6" stroke="#6f9b45" stroke-width="1.6" fill="none" stroke-linecap="round"/>')
        o.append(f'<g stroke="{INK}" stroke-width="1.3" stroke-linejoin="round"><polygon points="-27,-14 -21,-27 -12,-24 -11,-14 -18,-10" fill="#8d8d96"/><polygon points="-14,-16 -8,-24 -1,-18 -4,-10" fill="#a9a9b3"/>'
                 f'<polygon points="10,26 15,10 20,26 15,30" fill="#4f9fc9"/><polygon points="19,24 23,14 27,24 23,28" fill="#78b9dc"/><polygon points="3,26 6,16 9,26 6,28" fill="#78b9dc"/></g>')
    elif kind == 'empty':
        for x, y in [(-18, 24), (-6, 30), (8, 28), (18, 24), (-24, 10), (24, 8), (-2, 18)]:
            o.append(f'<ellipse cx="{x}" cy="{y}" rx="5" ry="3.4" fill="none" stroke="#8f929a" stroke-width="1.3"/>')
        o.append(f'<g stroke="{INK}" stroke-width="1.2"><rect x="-24" y="-24" width="12" height="12" fill="#b3b6bd"/><path d="M-24 -18 h12 M-18 -24 v6 M-18 -12 v-6" fill="none"/></g>')
    elif kind == 'port':
        for y in (-26, -18, 20, 28):
            o.append(f'<path d="M-24 {y} q6 -5 12 0 t12 0 t12 0 t12 0" stroke="#e8f1f7" stroke-opacity="0.75" stroke-width="2" fill="none" stroke-linecap="round"/>')
    o.append('</g>')
    return ''.join(o)


def road_group(d, open_=True):
    """Open edge: road from edge to middle. Closed edge: bold black/yellow hazard blockade."""
    a = math.radians(EDGE_ANG[d])
    ca, sa = math.cos(a), math.sin(a)
    ex, ey = 0.866 * U * ca, 0.866 * U * sa
    if open_:
        ix, iy = 0.45 * U * ca, 0.45 * U * sa
        return (f'<line x1="{ex:.1f}" y1="{ey:.1f}" x2="{ix:.1f}" y2="{iy:.1f}" stroke="#6b5a3e" stroke-width="6.4"/>'
                f'<line x1="{ex:.1f}" y1="{ey:.1f}" x2="{ix:.1f}" y2="{iy:.1f}" stroke="#e7d9b5" stroke-width="3.6"/>')
    bx, by = (0.866 * U - 4) * ca, (0.866 * U - 4) * sa
    rot = EDGE_ANG[d] + 90
    cid = f'blk_{d}'
    return (
        f'<g transform="translate({bx:.1f},{by:.1f}) rotate({rot:.1f})">'
        f'<defs><clipPath id="{cid}"><rect x="-11" y="-3" width="22" height="6" rx="1"/></clipPath></defs>'
        f'<rect x="-11" y="-3" width="22" height="6" rx="1" fill="#f1c40f" stroke="#1a1a1a" stroke-width="1.2"/>'
        f'<g clip-path="url(#{cid})">'
        f'<line x1="-14" y1="-4" x2="-8" y2="4" stroke="#1a1a1a" stroke-width="3"/>'
        f'<line x1="-7" y1="-4" x2="-1" y2="4" stroke="#1a1a1a" stroke-width="3"/>'
        f'<line x1="0" y1="-4" x2="6" y2="4" stroke="#1a1a1a" stroke-width="3"/>'
        f'<line x1="7" y1="-4" x2="13" y2="4" stroke="#1a1a1a" stroke-width="3"/>'
        f'</g>'
        f'<rect x="-11" y="-3" width="22" height="6" rx="1" fill="none" stroke="#1a1a1a" stroke-width="1.2"/>'
        f'<rect x="-9.5" y="2.5" width="2.5" height="2.5" fill="#2c2c2c"/>'
        f'<rect x="7" y="2.5" width="2.5" height="2.5" fill="#2c2c2c"/>'
        f'</g>'
    )


def sockets_group(slots):
    return ''.join(f'<rect x="{-slots * 12 + i * 24 + 1}" y="-16" width="22" height="16" fill="#ebe1cd" stroke="{INK}" stroke-width="1.2" stroke-dasharray="3"/>' for i in range(slots))


def wrap_svg(inner, scale=1.0, title='', pad=0):
    w, h = 2 * U * 0.866 + 2 * pad, 2 * U + 2 * pad
    vb = f'{-U * 0.866 - pad:.2f} {-U - pad:.2f} {w:.2f} {h:.2f}'
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb}" width="{w * scale:.0f}" height="{h * scale:.0f}" font-family="DejaVu Sans, Arial, sans-serif">'
            + (f'<title>{escape(title)}</title>' if title else '') + inner + '</svg>')


def tile_svg(title, kind, slots, paths, extra='', banner=None, code=''):
    """Print tile: ground + roads on open edges + building sockets (same positions as the BGA board) + extras."""
    s = [ground_group(kind)]
    s += [road_group(d, paths[d]) for d in range(6) if paths[d] or kind != 'port']
    s.append(sockets_group(slots))
    if banner:
        s.append(f'<rect x="-9" y="-34" width="18" height="6" fill="{banner}" stroke="{INK}" stroke-width="0.9"/>')
    s.append(extra)
    return wrap_svg(''.join(s), scale=2.5, title=title, pad=1.5)


def bga_tile_assets():
    """Gameplay icons for BGA. Tile art is NOT split into layers: the complete tile SVGs live in bga/img/tiles."""
    return {f'icons/{name}.svg': icon_svg(name) for name in ICONS}


def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(text)


def main():
    # ----- missions
    cards = []
    for level in (1, 2):
        for i, (t, n) in enumerate(MISSIONS[level], start=1):
            name, desc, svg = card_svg(level, i, t, n)
            fn = f'missions/L{level}_{i:02d}_{slug(name)}.svg'
            write(os.path.join(HERE, fn), svg)
            # BGA copy: id used by Game.php is "<level>_<0-based index>"
            write(os.path.join(HERE, '..', 'bga', 'img', 'missions', f'm_{level}_{i - 1}.svg'), svg)
            cards.append((level, i, name, desc, fn, t, n))
        write(os.path.join(HERE, f'missions/back_L{level}.svg'), back_svg(level))

    rows = ''.join(f'<tr><td>L{l}-{i:02d}</td><td>{escape(nm)}</td><td>{escape(d)}</td><td>{VP[l]}</td><td>{t}</td></tr>' for l, i, nm, d, fn, t, n in cards)
    grid = ''.join(f'<figure><img src="{fn}" alt="{escape(nm)}"><figcaption>L{l}-{i:02d} {escape(nm)}</figcaption></figure>' for l, i, nm, d, fn, t, n in cards)
    note = '<p>Rich Land cards (L1-16, L2-19) use the four land tiles that hold two resources.</p>'
    write(os.path.join(HERE, 'missions_overview.html'), f'''<!doctype html><meta charset="utf-8"><title>Little Commanders - 40 mission cards</title>
<style>body{{font-family:sans-serif;background:#f4ead7;color:#2b2233;margin:20px}}
.grid{{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px}}figure{{margin:0}}img{{width:100%;display:block}}
figcaption{{font-size:12px;text-align:center;padding:2px}}table{{border-collapse:collapse;margin:20px 0}}td,th{{border:1px solid #2b2233;padding:4px 8px;font-size:13px}}
code{{background:#e7d9b5;padding:0 3px}}</style>
<h1>Mission cards (40)</h1><p>Level 1 = 1 VP, Level 2 = 2 VP. A game uses 10 random cards per level; 5 face up. Buy cost 5 Credits (+1 per card you already bought); VP only if the condition is met at that moment.</p>
{note}
<div class="grid">{grid}</div>
<h2>List</h2><table><tr><th>ID</th><th>Name</th><th>Condition</th><th>VP</th><th>Type</th></tr>{rows}</table>
<h2>Backs</h2><div class="grid"><figure><img src="missions/back_L1.svg"></figure><figure><img src="missions/back_L2.svg"></figure></div>''')

    for rel, svg in bga_tile_assets().items():
        write(os.path.join(HERE, '..', 'bga', 'img', rel), svg)
    # player boards: basic side + advanced side per faction
    figs = ''
    for fname, col in FACTIONS:
        for side, fn in (('basic', basic_board), ('advanced', advanced_board)):
            rel = f'player_board_{fname.lower()}_{side}.svg'
            write(os.path.join(HERE, '..', 'bga', 'img', 'boards', rel), fn(col, fname.upper()))  # the one place mats live
            if fname == 'Purple':
                figs += f'<figure><img src="../bga/img/boards/{rel}"><figcaption>{fname} {side}</figcaption></figure>'
    write(os.path.join(HERE, 'boards_overview.html'), f'''<!doctype html><meta charset="utf-8"><title>Player boards</title>
<style>body{{font-family:sans-serif;background:#f4ead7;margin:20px}}figure{{margin:0 0 20px}}img{{width:100%;max-width:1100px;display:block;border:1px solid #2b2233}}</style>
<h1>Player boards (draft)</h1><p>One pair per faction (6 colours); only the colour and art slot differ. Showing Purple. Basic numbers are draft (see BASIC_GAME.md).</p>{figs}''')
    # canonical icon set (the same files BGA, the rulebook and the player boards use)
    for name in ICONS:
        write(os.path.join(HERE, 'icons', f'{name}.svg'), icon_svg(name))
        write(os.path.join(HERE, '..', 'bga', 'img', 'icons', f'{name}.svg'), icon_svg(name))
    cells = ''.join(f'<figure><img src="icons/{n}.svg"><figcaption>{n}<br>{escape(lbl)}</figcaption></figure>' for n, (lbl, _) in ICONS.items())
    write(os.path.join(HERE, 'icons_overview.html'), f'''<!doctype html><meta charset="utf-8"><title>Little Commanders - icons</title>
<style>body{{font-family:sans-serif;background:#f4ead7;color:#2b2233;margin:20px}}.grid{{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:14px}}
figure{{margin:0;text-align:center}}img{{width:72px;height:72px}}figcaption{{font-size:11px}}.s img{{width:32px;height:32px}}</style>
<h1>Icons ({len(ICONS)})</h1><p>One set for cards, BGA, rulebook and player boards. Defined in <code>design/icons.py</code>; the SVG files in <code>design/icons/</code> are generated.</p>
<div class="grid">{cells}</div><h2>At 32 px</h2><div class="grid s">{cells}</div>''')

    # ----- tiles: the complete tile SVGs go straight into BGA (bga/img/tiles = the one place), plus the manifest the server deals from
    tiles_dir = os.path.join(HERE, '..', 'bga', 'img', 'tiles')
    for old in os.listdir(tiles_dir) if os.path.isdir(tiles_dir) else []:
        os.remove(os.path.join(tiles_dir, old))
    groups = {'Centre (level 1)': [], 'Level 2': [], 'Level 3': [], 'Level 4': [], 'Home tiles': [], 'Port tiles (side 0 of 6 shown)': []}
    for t in tileset.land_tiles():
        paths = [c == '1' for c in t['mask']]
        write(os.path.join(tiles_dir, f"{t['art']}.svg"), tile_svg(t['code'], t['res'] or 'empty', t['slots'], paths, code=t['code']))
        label = f"{t['code']}  {t['res'] or 'empty'}  {t['slots']} slot(s)  {sum(paths)} roads"
        key = 'Centre (level 1)' if t['level'] == 1 else f"Level {t['level']}"
        groups[key].append((f"{t['art']}.svg", label))
    for t, (fname, col) in zip(tileset.home_tiles(), FACTIONS):
        write(os.path.join(tiles_dir, f"{t['art']}.svg"), tile_svg(t['code'], 'empty', 0, [True] * 6, banner=col, code=t['code']))
        groups['Home tiles'].append((f"{t['art']}.svg", f'{fname} home: no resource, 0 slots, all 6 roads'))
    for t in tileset.port_tiles():
        icons = icon_group(t['good'] if t['good'] in ICONS else 'iron', -9, 0, 17) + icon_group('down' if t['port_kind'] == 'cheaper' else 'income', 10, 0, 17)
        write(os.path.join(tiles_dir, f"{t['art']}.svg"), tile_svg(t['code'], 'port', 0, [c == '1' for c in t['mask']], extra=icons, code=t['code']))
        if t['side'] == 0:
            groups['Port tiles (side 0 of 6 shown)'].append((f"{t['art']}.svg", f"{t['code']}: {t['good']}, " + ('2 cheaper to buy' if t['port_kind'] == 'cheaper' else '3 more when sold')))
    tileset.write_manifest(os.path.join(HERE, '..', 'bga', 'modules', 'php', 'tileset.php'))

    body = ''
    for g, items in groups.items():
        figs = ''.join(f'<figure><img src="../bga/img/tiles/{fn}"><figcaption>{escape(lb)}</figcaption></figure>' for fn, lb in items)
        body += f'<h2>{g} ({len(items)})</h2><div class="grid">{figs}</div>'
    write(os.path.join(HERE, 'tiles_overview.html'), f'''<!doctype html><meta charset="utf-8"><title>Little Commanders - tiles</title>
<style>body{{font-family:sans-serif;background:#f4ead7;color:#2b2233;margin:20px}}.grid{{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}}
figure{{margin:0}}img{{width:100%;display:block}}figcaption{{font-size:11px;text-align:center}}table{{border-collapse:collapse}}td,th{{border:1px solid #2b2233;padding:4px 8px}}</style>
<h1>Tiles</h1>
<p><b>The tile art lives in ONE place: <code>bga/img/tiles/</code>.</b> These are the exact files BGA shows and print uses. Each tile carries its own resource, building sockets and roads; BGA adds nothing on top. The server deals from <code>bga/modules/php/tileset.php</code> (generated with them).</p>
<p><b>Maximum on the table (4-6 players): 37 land tiles + 12 port tiles = 49 hexes.</b> 2-3 players: 19 land + 4-6 ports. Home tiles replace 1 outer-ring tile per player.</p>
<table><tr><th>Group</th><th>2-3 players</th><th>4-6 players</th></tr>
<tr><td>Centre (level 1)</td><td>1</td><td>1</td></tr><tr><td>Level 2 (ring 1)</td><td>6</td><td>6</td></tr>
<tr><td>Level 3 (ring 2)</td><td>12</td><td>12</td></tr><tr><td>Level 4 (ring 3)</td><td>-</td><td>18</td></tr>
<tr><td><b>Land total</b></td><td><b>19</b></td><td><b>37</b></td></tr>
<tr><td>Port tiles (2 per player)</td><td>4-6</td><td>8-12</td></tr></table>
<p>Port tiles come in 8 good/discount types x 6 sides (the sea side decides which two edges have roads), 48 files; the table uses 2 per player. Physical print set: 37 land + 6 home + 12 ports, rotate a port to its side. Coin-plus icon = pays 3 more when you sell it, down arrow = 2 cheaper to buy. Four land tiles hold two resources (iron and crystal): the Rich Land missions need them.</p>
{body}''')

    # ----- board layout (4-6p)
    cells = []
    for q in range(-3, 4):
        for r in range(-3, 4):
            ring = max(abs(q), abs(r), abs(-q - r))
            if ring <= 3:
                cells.append((q, r, ring, 'land'))
    outer = 4
    for q in range(-outer, outer + 1):
        for r in range(-outer, outer + 1):
            ab = [abs(q), abs(r), abs(-q - r)]
            if max(ab) == outer and sum(1 for a in ab if a == outer) == 1:
                cells.append((q, r, outer, 'port'))
    ports = [c for c in cells if c[3] == 'port']
    ports.sort(key=lambda c: math.atan2(c[1] * 0.866, c[0] + c[1] / 2))
    keep = {ports[int((p + 0.5) * len(ports) / 12) % len(ports)][:2] for p in range(12)}
    size = 34
    parts = []
    for q, r, ring, kind in cells:
        if kind == 'port' and (q, r) not in keep:
            continue
        x, y = size * 1.5 * q, size * 1.732 * (r + q / 2)
        fill = SEA if kind == 'port' else {0: '#e7d9b5', 1: '#e0e6c8', 2: '#d3e1b8', 3: '#c3d8a7'}[ring]
        lab = 'P' if kind == 'port' else f'L{ring + 1}'
        parts.append(f'<polygon points="{hexpts(x, y, size - 1)}" fill="{fill}" stroke="{INK}" stroke-width="1.5"/><text x="{x:.0f}" y="{y + 5:.0f}" text-anchor="middle" font-size="13" fill="{INK}">{lab}</text>')
    ext = 4 * size * 1.75
    write(os.path.join(HERE, 'board_layout.svg'), f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{-ext} {-ext} {2 * ext} {2 * ext}" width="800" font-family="DejaVu Sans, Arial, sans-serif"><title>Board layout 4-6 players: 37 land + 12 ports (port positions illustrative)</title>{"".join(parts)}</svg>')
    print('done:', len(cards), 'cards,', len(os.listdir(tiles_dir)), 'tile files')


if __name__ == '__main__':
    main()
