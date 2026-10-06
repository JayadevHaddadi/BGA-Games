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
import random
from html import escape

HERE = os.path.dirname(os.path.abspath(__file__))

# ---------- shared palette (see ART_PLAN.md colour theme) ----------
INK = '#2b2233'
PAPER = '#f4ead7'
PURPLE = '#6d3a8c'
TEAL = '#2f7f79'
IRON_GREEN = '#a9d07c'
CRYSTAL_BLUE = '#9fd0e6'
EMPTY_GREY = '#c3c6cc'
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


def icon(kind, cx, cy, s=60):
    """Simple flat pictograms, drawn inside a box of size s centred on cx, cy."""
    k = s / 60
    o = f'<g transform="translate({cx},{cy}) scale({k})" stroke="{INK}" stroke-width="3" stroke-linejoin="round" fill="none">'
    c = '</g>'
    if kind == 'extractors':
        return o + f'<path d="M-22 26 L0 -26 L22 26 Z" fill="#b9a27a"/><rect x="-6" y="-34" width="12" height="14" fill="#8a7a62"/>' + c
    if kind == 'factories':
        return o + f'<rect x="-28" y="-6" width="56" height="32" fill="#c9b79a"/><rect x="8" y="-30" width="12" height="26" fill="#8a7a62"/><rect x="-18" y="6" width="12" height="12" fill="{PAPER}"/>' + c
    if kind == 'towers':
        return o + f'<path d="M-16 28 L-16 -12 L-22 -12 L-22 -28 L-12 -28 L-12 -20 L-4 -20 L-4 -28 L4 -28 L4 -20 L12 -20 L12 -28 L22 -28 L22 -12 L16 -12 L16 28 Z" fill="#a89c8a"/>' + c
    if kind == 'buildings':
        return o + f'<path d="M-26 24 L-26 -4 L0 -28 L26 -4 L26 24 Z" fill="#c9b79a"/><rect x="-7" y="6" width="14" height="18" fill="{PAPER}"/>' + c
    if kind == 'bots':
        return o + f'<circle cx="0" cy="6" r="22" fill="#d9d2e3"/><line x1="0" y1="-16" x2="0" y2="-28"/><circle cx="0" cy="-30" r="4" fill="{INK}"/><circle cx="-8" cy="4" r="4" fill="{INK}"/><circle cx="8" cy="4" r="4" fill="{INK}"/>' + c
    if kind == 'mechs':
        return o + f'<rect x="-20" y="-12" width="40" height="38" fill="#d9d2e3"/><rect x="-14" y="-32" width="28" height="20" fill="#d9d2e3"/><rect x="-8" y="-26" width="16" height="6" fill="{INK}"/><rect x="-32" y="-8" width="12" height="26" fill="#d9d2e3"/><rect x="20" y="-8" width="12" height="26" fill="#d9d2e3"/>' + c
    if kind == 'pieces':
        return icon('bots', cx - s * 0.28, cy + s * 0.12, s * 0.6) + icon('mechs', cx + s * 0.26, cy, s * 0.7)
    if kind == 'iron_tokens':
        return o + f'<rect x="-22" y="-14" width="44" height="30" fill="#8d8d96"/><path d="M-22 -14 L-12 -26 L32 -26 L22 -14 Z" fill="#b4b4be"/><path d="M22 -14 L32 -26 L32 4 L22 16 Z" fill="#6f6f78"/>' + c
    if kind == 'crystal_tokens':
        return o + f'<path d="M0 -30 L18 -6 L0 30 L-18 -6 Z" fill="{CRYSTAL_BLUE}"/><path d="M-18 -6 L18 -6"/>' + c
    if kind == 'tokens':
        return icon('iron_tokens', cx - s * 0.22, cy + s * 0.06, s * 0.62) + icon('crystal_tokens', cx + s * 0.26, cy - s * 0.04, s * 0.62)
    if kind == 'hexes':
        return o + f'<polygon points="{hexpts(0, 0, 28)}" fill="{IRON_GREEN}"/>' + c
    if kind == 'double_tiles':
        return o + f'<polygon points="{hexpts(0, 0, 28)}" fill="{IRON_GREEN}"/><path d="M0 -24 L24 0 L0 24 L-24 0 Z" fill="{CRYSTAL_BLUE}"/>' + c
    if kind == 'ports':
        return o + f'<path d="M-30 12 Q-15 0 0 12 T30 12 L30 28 L-30 28 Z" fill="{SEA}"/><rect x="-4" y="-28" width="8" height="40" fill="#8a6a42"/><path d="M4 -28 L26 -14 L4 -6 Z" fill="#c9b79a"/>' + c
    if kind == 'center':
        return o + f'<polygon points="{hexpts(0, 0, 28)}" fill="#e7d9b5"/><path d="M0 -16 L5 -4 L18 -4 L8 4 L12 16 L0 8 L-12 16 L-8 4 L-18 -4 L-5 -4 Z" fill="{PURPLE}"/>' + c
    if kind == 'credits':
        return o + f'<circle cx="0" cy="0" r="26" fill="#e6c455"/><circle cx="0" cy="0" r="17"/><path d="M-6 -8 L6 -8 M-6 0 L6 0 M0 -14 L0 14" stroke-width="4"/>' + c
    return ''


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
    s = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W / 10}mm" height="{H / 10}mm" font-family="DejaVu Sans, Arial, sans-serif">',
         f'<title>Mission L{level}-{idx:02d} {escape(name)}</title>',
         f'<rect id="bleed" x="0" y="0" width="{W}" height="{H}" fill="{accent}"/>',
         f'<rect id="trim" x="{x0}" y="{y0}" width="{x1 - x0}" height="{y1 - y0}" rx="26" fill="{PAPER}" stroke="{INK}" stroke-width="4"/>',
         # header
         f'<rect x="{x0 + 24}" y="{y0 + 24}" width="{x1 - x0 - 48}" height="96" rx="8" fill="{accent}"/>',
         f'<text x="{W / 2}" y="{y0 + 90}" text-anchor="middle" font-size="46" font-weight="bold" fill="{PAPER}">{escape(name)}</text>',
         # art slot
         f'<g id="art-slot"><rect x="{x0 + 24}" y="{y0 + 140}" width="{x1 - x0 - 48}" height="380" rx="8" fill="#ebe1cd" stroke="{INK}" stroke-width="3" stroke-dasharray="14 8"/>',
         f'<text class="placeholder" x="{W / 2}" y="{y0 + 340}" text-anchor="middle" font-size="26" fill="#9a8f7c">ART SLOT 582 x 380</text></g>',
         # condition band
         f'<rect x="{x0 + 24}" y="{y0 + 540}" width="{x1 - x0 - 48}" height="150" rx="8" fill="#fffaf0" stroke="{INK}" stroke-width="3"/>',
         icon(mtype, x0 + 110, y0 + 615, 92)]
    if mtype != 'center':
        s.append(f'<text x="{x0 + 210}" y="{y0 + 648}" font-size="92" font-weight="bold" fill="{INK}">{n}</text>')
    else:
        s.append(f'<text x="{x0 + 210}" y="{y0 + 632}" font-size="40" font-weight="bold" fill="{INK}">Centre hex</text>')
    for i, line in enumerate(wrap(desc, 30)):
        s.append(f'<text x="{x0 + 24 + 20}" y="{y0 + 730 + i * 34}" font-size="28" fill="{INK}">{escape(line)}</text>')
    # reward + id
    s += [f'<circle cx="{x1 - 78}" cy="{y1 - 82}" r="48" fill="{accent}" stroke="{INK}" stroke-width="4"/>',
          f'<text x="{x1 - 78}" y="{y1 - 66}" text-anchor="middle" font-size="52" font-weight="bold" fill="{PAPER}">{vp}</text>',
          f'<text x="{x1 - 78}" y="{y1 - 28}" text-anchor="middle" font-size="18" fill="{INK}">VP</text>',
          f'<text x="{x0 + 24}" y="{y1 - 30}" font-size="20" fill="#7d7388">Level {level}  -  {idx:02d}/20</text>',
          '</svg>']
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


# ---------- tiles ----------
R = 100  # centre to vertex
TW, TH = 2 * R + 20, int(2 * 0.866 * R) + 20
EDGE_ANG = [30, -30, -90, -150, 150, 90]  # same order as Game.php DIRS (flat-top hex, y down)


def tile_svg(title, fill, resource, slots, paths, extra='', banner=None, ring_label=''):
    cx, cy = TW / 2, TH / 2
    s = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {TW} {TH}" width="{TW}" height="{TH}" font-family="DejaVu Sans, Arial, sans-serif">',
         f'<title>{escape(title)}</title>',
         f'<polygon id="tile" points="{hexpts(cx, cy, R)}" fill="{fill}" stroke="{INK}" stroke-width="4"/>']
    # art slot (inner hex)
    s.append(f'<g id="art-slot"><polygon points="{hexpts(cx, cy, R * 0.72)}" fill="none" stroke="{INK}" stroke-opacity="0.35" stroke-width="2" stroke-dasharray="6 5"/></g>')
    # open edges: road stub from the edge midpoint inwards; closed edges: short fence tick
    for d in range(6):
        a = math.radians(EDGE_ANG[d])
        ex, ey = cx + 0.866 * R * math.cos(a), cy + 0.866 * R * math.sin(a)
        ix, iy = cx + 0.5 * R * math.cos(a), cy + 0.5 * R * math.sin(a)
        if paths[d]:
            s.append(f'<line x1="{ex:.1f}" y1="{ey:.1f}" x2="{ix:.1f}" y2="{iy:.1f}" stroke="#6b5a3e" stroke-width="16" stroke-linecap="butt"/>')
            s.append(f'<line x1="{ex:.1f}" y1="{ey:.1f}" x2="{ix:.1f}" y2="{iy:.1f}" stroke="#e7d9b5" stroke-width="9" stroke-linecap="butt"/>')
    # resource symbol
    if resource == 'iron':
        s.append(f'<g stroke="{INK}" stroke-width="2.5" stroke-linejoin="round"><polygon points="{cx - 22},{cy - 4} {cx - 8},{cy - 24} {cx + 10},{cy - 14} {cx + 8},{cy + 8} {cx - 12},{cy + 12}" fill="#8d8d96"/><polygon points="{cx + 6},{cy - 2} {cx + 22},{cy - 10} {cx + 30},{cy + 6} {cx + 16},{cy + 16}" fill="#a3a3ad"/></g>')
    elif resource == 'crystal':
        s.append(f'<g stroke="{INK}" stroke-width="2.5" stroke-linejoin="round"><polygon points="{cx},{cy - 30} {cx + 12},{cy - 4} {cx},{cy + 16} {cx - 12},{cy - 4}" fill="#4f9fc9"/><polygon points="{cx + 16},{cy - 14} {cx + 26},{cy + 4} {cx + 16},{cy + 18} {cx + 8},{cy + 4}" fill="#78b9dc"/></g>')
    # building sockets (bottom of the hex)
    sx = {0: [], 1: [cx], 2: [cx - 20, cx + 20]}[slots]
    for x in sx:
        s.append(f'<rect x="{x - 13}" y="{cy + 28}" width="26" height="26" fill="#ebe1cd" stroke="{INK}" stroke-width="2.5"/>')
    s.append(extra)
    if banner:
        s.append(f'<rect x="{cx - 20}" y="{cy - 58}" width="40" height="12" fill="{banner}" stroke="{INK}" stroke-width="2"/>')
    if ring_label:
        s.append(f'<text x="{cx}" y="{TH - 4}" text-anchor="middle" font-size="11" fill="{INK}">{escape(ring_label)}</text>')
    s.append('</svg>')
    return '\n'.join(s)


def build_land_tiles():
    """37 land tiles by level (1 centre, 6 + 12 + 18). Resources 25% none / 50% iron / 25% crystal, per Game.php rollTile."""
    rng = random.Random(7)
    tiles = []

    def roll(level):
        x = rng.randint(1, 4)
        res = None if x == 1 else ('iron' if x <= 3 else 'crystal')
        if level == 1:
            slots = 2
        elif level == 2:
            slots = rng.randint(1, 2)
        else:
            y = rng.randint(1, 4)
            slots = 0 if y == 1 else (1 if y <= 3 else 2)
            if res is None:
                slots = max(1, slots)
        lo, hi = {1: (6, 6), 2: (5, 6)}.get(level, (4, 5))
        k = rng.randint(lo, hi)
        open_dirs = set(rng.sample(range(6), k))
        return res, slots, [d in open_dirs for d in range(6)]

    for level, count in [(1, 1), (2, 6), (3, 12), (4, 18)]:
        for i in range(count):
            res, slots, paths = roll(level)
            tiles.append({'level': level, 'n': i + 1, 'res': res, 'slots': slots, 'paths': paths})
    return tiles


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
    note = ('<p><b>Known issue:</b> "Rich Land" cards (L1-16, L2-19) need hexes with two resources, but the current board '
            'generator never creates one (<code>resource_type_2</code> is always empty), so they cannot be completed yet. '
            'Either add double-resource tiles or replace these two cards.</p>')
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

    # ----- tiles
    land = build_land_tiles()
    groups = {'Centre (level 1)': [], 'Level 2': [], 'Level 3': [], 'Level 4': [], 'Home tiles': [], 'Port tiles': []}
    for t in land:
        fill = {None: EMPTY_GREY, 'iron': IRON_GREEN, 'crystal': CRYSTAL_BLUE}[t['res']]
        name = f"land_L{t['level']}_{t['n']:02d}"
        label = f"L{t['level']}-{t['n']:02d}  {t['res'] or 'empty'}  {t['slots']} slot(s)  {sum(t['paths'])} paths"
        write(os.path.join(HERE, f'tiles/{name}.svg'), tile_svg(name, fill, t['res'], t['slots'], t['paths'], ring_label=''))
        key = 'Centre (level 1)' if t['level'] == 1 else f"Level {t['level']}"
        groups[key].append((f'tiles/{name}.svg', label))
    for i, (fname, col) in enumerate(FACTIONS, start=1):
        name = f'home_{i}_{fname.lower()}'
        paths = [True, True, True, False, True, False]
        write(os.path.join(HERE, f'tiles/{name}.svg'), tile_svg(name, IRON_GREEN, 'iron', 2, paths, banner=col,
                                                                  extra=f'<rect x="{TW / 2 - 8}" y="{TH / 2 + 56}" width="16" height="6" fill="#8a6a42"/>'))
        groups['Home tiles'].append((f'tiles/{name}.svg', f'{fname} home: iron, Dock + Extractor start here, 2 slots'))
    port_combos = [(g, k) for g in ('iron', 'crystal', 'bot', 'mech') for k in ('cheaper', 'pays_more')]
    for i in range(12):
        g, k = port_combos[i % 8]
        name = f'port_{i + 1:02d}_{g}_{k}'
        paths = [False, False, False, True, True, False]  # the 2 edges facing the land
        txt = f'{g} -2' if k == 'cheaper' else f'{g} +3'
        extra = f'<text x="{TW / 2}" y="{TH / 2 + 8}" text-anchor="middle" font-size="22" font-weight="bold" fill="{INK}">{txt}</text>'
        write(os.path.join(HERE, f'tiles/{name}.svg'), tile_svg(name, SEA, None, 0, paths, extra=extra))
        groups['Port tiles'].append((f'tiles/{name}.svg', f'Port: {g} {"costs 2 less to buy" if k == "cheaper" else "sells for 3 more"}'))

    body = ''
    for g, items in groups.items():
        figs = ''.join(f'<figure><img src="{fn}"><figcaption>{escape(lb)}</figcaption></figure>' for fn, lb in items)
        body += f'<h2>{g} ({len(items)})</h2><div class="grid">{figs}</div>'
    write(os.path.join(HERE, 'tiles_overview.html'), f'''<!doctype html><meta charset="utf-8"><title>Little Commanders - tiles</title>
<style>body{{font-family:sans-serif;background:#f4ead7;color:#2b2233;margin:20px}}.grid{{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}}
figure{{margin:0}}img{{width:100%;display:block}}figcaption{{font-size:11px;text-align:center}}table{{border-collapse:collapse}}td,th{{border:1px solid #2b2233;padding:4px 8px}}</style>
<h1>Tiles</h1>
<p><b>Maximum on the table (4-6 players): 37 land tiles + 12 port tiles = 49 hexes.</b> 2-3 players: 19 land + 6 ports = 25 hexes (level 1-3 only). Home tiles replace 1 outer-ring tile per player.</p>
<table><tr><th>Group</th><th>2-3 players</th><th>4-6 players</th></tr>
<tr><td>Centre (level 1)</td><td>1</td><td>1</td></tr><tr><td>Level 2 (ring 1)</td><td>6</td><td>6</td></tr>
<tr><td>Level 3 (ring 2)</td><td>12</td><td>12</td></tr><tr><td>Level 4 (ring 3)</td><td>-</td><td>18</td></tr>
<tr><td><b>Land total</b></td><td><b>19</b></td><td><b>37</b></td></tr>
<tr><td>Port tiles (2 per player)</td><td>4-6</td><td>8-12</td></tr></table>
<p>Print set: 37 land + 6 home + 12 port = 55 tiles (home tiles are swapped in for outer-ring land tiles). Resource art slot = the dashed inner hex. Paths: a road stub on an edge means that edge is open; a path only works when BOTH touching tiles have it.</p>
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
    print('done:', len(cards), 'cards,', sum(len(v) for v in groups.values()), 'tiles')


if __name__ == '__main__':
    main()
