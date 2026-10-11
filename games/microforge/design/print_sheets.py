#!/usr/bin/env python3
"""Printable black-and-white prototype of Little Commanders for 2 players, as A4 sheets.

  python3 design/print_sheets.py          ->  print/little_commanders_print_2p.pdf  (+ print/preview/*.png)

Everything comes from the same sources as the BGA game (tileset.py, icons.py, boards.py, build_design.py), so a rule or art
change only needs build_design.py + this script to be rerun. Print at 100% / "actual size", plain paper, black and white.

Cutting: hexagons sit edge to edge (one cut serves two tiles); every other component is a square or rectangle in a tight
grid, so a paper cutter along the printed lines does the job. Level marks on the paper tiles: 2 dots = ring 1, 3 dots =
ring 2 (the centre tile has none). Players are told apart by a mark: player 1 = triangle, player 2 = circle.
"""
import math
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import build_design as bd  # noqa: E402
import tileset  # noqa: E402
from boards import BOT_MM, MECH_MM, PRICES, advanced_board, basic_board, t as _t  # noqa: E402
from icons import icon_group  # noqa: E402
from mono import to_gray  # noqa: E402

OUT = os.path.join(HERE, '..', 'print')
FONT = 'DejaVu Sans, Arial, sans-serif'
LINE = '#444444'
HEX_W = 38.0  # mm, flat-to-flat of a pointy-top hexagon
HEX_H = HEX_W / 0.8660254
U = bd.U
SCALE = HEX_W / (2 * U * 0.866)  # tile units -> mm
# Mission cards that cannot be played at the moment (Guard Towers were removed, the centre tile cannot be stopped on)
SKIP_MISSIONS = {'towers', 'center'}


def txt(x, y, s, size=3.2, weight='normal', anchor='start', fill='#222'):
    return f'<text x="{x:.2f}" y="{y:.2f}" font-size="{size}" font-weight="{weight}" text-anchor="{anchor}" fill="{fill}">{s}</text>'


def para(x, y, text, size=2.9, width=186, gap=1.5, weight='normal'):
    """Wrapped paragraph; returns (svg, y below it). Width in mm, ~0.53 em per character."""
    import textwrap
    lines = textwrap.wrap(text, max(20, int(width / (size * 0.53)))) or ['']
    out = ''.join(txt(x, y + k * size * 1.32, ln, size, weight) for k, ln in enumerate(lines))
    return out, y + len(lines) * size * 1.32 + gap


def page(inner, landscape=False, title=''):
    w, h = (297, 210) if landscape else (210, 297)
    head = f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}mm" height="{h}mm" font-family="{FONT}">'
    return {'svg': head + (txt(w / 2, 6.5, title, 3.6, 'bold', 'middle', '#666') if title else '') + inner + '</svg>', 'land': landscape}


# ---------------------------------------------------------------- tiles

def mark(x, y, player, size, on_dark=False):
    c = '#ffffff' if on_dark else '#000000'
    if player == 1:
        return f'<polygon points="{x},{y - size / 2} {x + size / 2},{y + size / 2} {x - size / 2},{y + size / 2}" fill="{c}"/>'
    return f'<circle cx="{x}" cy="{y}" r="{size / 2}" fill="{c}"/>'


def hex_tile(cx, cy, tile, home_player=None):
    """One paper tile centred on (cx, cy) mm, in black and white."""
    if tile['kind'] == 'port':
        inner = bd.tile_inner('port', 0, [c == '1' for c in tile['mask']], extra=bd.port_extra(tile['goods']), mono=True)
    elif tile['kind'] == 'home':
        extra = mark(20, -22, home_player, 11) if home_player == 1 else (
            f'<circle cx="20" cy="-22" r="5.5" fill="#ffffff" stroke="#000" stroke-width="1.4"/>')
        banner = '#000000' if home_player == 1 else '#ffffff'
        inner = bd.tile_inner('empty', 0, [True] * 6, extra=extra, banner=banner, mono=True)
    else:
        kind = 'centre' if tile['level'] == 1 else (tile['res'] or 'empty')
        pips = {1: 0, 2: 2, 3: 3, 4: 4}[tile['level']]
        inner = bd.tile_inner(kind, tile['slots'], [c == '1' for c in tile['mask']], mono=True, pips=pips)
    return f'<g transform="translate({cx:.2f},{cy:.2f}) scale({SCALE:.5f})">{to_gray(inner)}</g>'


def honeycomb(tiles, rows, x0=None, y0=22):
    """Lay out tiles edge to edge in rows of the given lengths (alternate rows shifted by half a tile)."""
    out, i = [], 0
    for r, n in enumerate(rows):
        shift = HEX_W / 2 if r % 2 else 0
        start = (210 - max(rows) * HEX_W) / 2 + HEX_W / 2 if x0 is None else x0
        for c in range(n):
            if i >= len(tiles):
                break
            out.append(hex_tile(start + shift + c * HEX_W, y0 + HEX_H / 2 + r * HEX_H * 0.75, *tiles[i]))
            i += 1
    return ''.join(out)


def tiles_page():
    land = tileset.land_tiles()
    seq = [(t, None) for t in land if t['level'] <= 3]  # 1 centre + 6 + 12
    homes = tileset.home_tiles()
    seq += [(homes[0], 1), (homes[1], 2)]
    inner = honeycomb(seq, [5, 4, 5, 4, 3])
    y = 22 + HEX_H * 0.75 * 4 + HEX_H + 8
    inner += txt(12, y, 'LAND TILES  (21 hexagons: 1 centre, 6 ring-1 tiles with 2 dots, 12 ring-2 tiles with 3 dots, 2 home tiles).', 3.4, 'bold')
    notes = [
        'Cut along the outlines; neighbouring hexagons share a cut. Keep the three groups apart (centre / 2 dots / 3 dots / home).',
        'Dotted ground = iron mine, hatched ground = crystal, plain = empty. Round sockets = places to build (put the Extractor / Factory token on them).',
        'A road runs to the edge on every side except ONE side per tile (hazard stripes = blocked, nothing can cross). The centre and the homes have no blocked side.',
        'Home tiles: player 1 = black flag and triangle, player 2 = white flag and circle. Put the Dock token on your own home tile.',
    ]
    yy = y + 6
    for n in notes:
        o, yy = para(12, yy, n, 2.9)
        inner += o
    return page(inner, title='LITTLE COMMANDERS - print and play, 2 players - tiles 1/2')


def port_page():
    ports = tileset.port_tiles()
    front = [p for p in ports if p['goods'][0] == 'all' and p['side'] == 0]
    backs = [p for p in ports if p['goods'][0] != 'all' and p['side'] == 0]
    seq = [(front[0], None), (front[0], None)] + [(b, None) for b in backs]
    inner = honeycomb(seq, [4, 4], y0=18)
    y = 18 + HEX_H * 1.75 + 10
    inner += txt(12, y, 'PORT TILES  (8 hexagons)', 3.4, 'bold')
    yy = y + 6
    for n in [
        'First two tiles = the FRONT side: a Port that opens all four goods (Basic game, use both).',
        'The other six = the BACK side, each opens only two goods (Advanced game: shuffle them face down and draw 2).',
        'Place a Port just outside the land, touching exactly two land tiles (the two edges with a road). Turn it to fit.',
        'A Port is NOT a place to walk to. You trade there while one of your pieces stands on a land tile next to it.',
    ]:
        o, yy = para(12, yy, n, 2.9)
        inner += o
    inner += map_2p(yy + 8)
    return page(inner, title='LITTLE COMMANDERS - print and play, 2 players - tiles 2/2 and setup map')


def map_2p(y_top):
    """Setup picture for 2 players: where the tiles and ports go (same dealing as TileDealer)."""
    r = 2
    land = [(q, rr) for q in range(-r, r + 1) for rr in range(-r, r + 1) if abs(-q - rr) <= r]
    ring = lambda q, rr: max(abs(q), abs(rr), abs(-q - rr))
    ang = lambda q, rr: math.atan2(1.5 * rr, math.sqrt(3) * (q + rr / 2))
    corners = sorted([p for p in land if sum(1 for a in (abs(p[0]), abs(p[1]), abs(-p[0] - p[1])) if a == r) == 2], key=lambda p: ang(*p))
    homes = {corners[0]: 1, corners[3]: 2}
    outer = r + 1
    spots = sorted([(q, rr) for q in range(-outer, outer + 1) for rr in range(-outer, outer + 1)
                    if max(abs(q), abs(rr), abs(-q - rr)) == outer and sum(1 for a in (abs(q), abs(rr), abs(-q - rr)) if a == outer) == 1], key=lambda p: ang(*p))
    ports = []
    for c in (corners[1], corners[4]):
        for s in spots:
            if max(abs(s[0] - c[0]), abs(s[1] - c[1]), abs(-s[0] - s[1] + c[0] + c[1])) == 1 and s not in ports:
                ports.append(s)
                break
    size = 12.5
    cx0, cy0 = 105, y_top + 62
    out = [txt(12, y_top - 2, 'SETUP MAP (2 players). Letters = rows, numbers = diagonals, as in the BGA game.', 3.2, 'bold')]
    allc = land + ports
    minr = min(rr for q, rr in allc)
    minq = min(q for q, rr in allc)
    for q, rr in allc:
        x, y = cx0 + size * math.sqrt(3) * (q + rr / 2), cy0 + size * 1.5 * rr
        pts = ' '.join(f'{x + size * math.cos(math.radians(60 * i - 30)):.2f},{y + size * math.sin(math.radians(60 * i - 30)):.2f}' for i in range(6))
        if (q, rr) in ports:
            fill, label = '#dddddd', 'PORT'
        elif (q, rr) in homes:
            fill, label = '#bbbbbb', 'HOME'
        elif (q, rr) == (0, 0):
            fill, label = '#ffffff', 'CENTRE'
        else:
            fill, label = '#ffffff', ''
        out.append(f'<polygon points="{pts}" fill="{fill}" stroke="#444" stroke-width="0.3"/>')
        coord = chr(65 + rr - minr) + str(q - minq + 1)
        out.append(txt(x, y - 1.2, coord, 2.4, 'bold', 'middle'))
        dots = {0: 0, 1: 2, 2: 3}[ring(q, rr)] if (q, rr) not in ports and (q, rr) not in homes else 0
        if (q, rr) in homes:
            out.append(mark(x, y + 3.2, homes[(q, rr)], 3.4))
            out.append(txt(x, y + 7.4, f'P{homes[(q, rr)]}', 2.2, 'bold', 'middle'))
        elif label:
            out.append(txt(x, y + 3.6, label, 2.1, 'normal', 'middle'))
        else:
            out.append(''.join(f'<circle cx="{x + (i - (dots - 1) / 2) * 2.2:.2f}" cy="{y + 4:.2f}" r="0.7" fill="#222"/>' for i in range(dots)))
    return ''.join(out)


# ---------------------------------------------------------------- tokens

def token(x, y, s, icon, player=None, label=None, heavy=False, number=None):
    bg = '#e6e6e6' if player == 2 else '#ffffff'
    o = f'<rect x="{x:.2f}" y="{y:.2f}" width="{s}" height="{s}" fill="{bg}" stroke="{LINE}" stroke-width="{0.5 if heavy else 0.25}"/>'
    if icon:
        k = 0.66 if number is None else 0.5
        o += to_gray(icon_group(icon, x + s / 2 + (s * 0.1 if number is not None else 0), y + s / 2 + (s * 0.06 if player else 0), s * k))
    if number is not None:
        o += txt(x + s * 0.12, y + s * 0.42, number, s * 0.42, 'bold')
    if player:
        o += mark(x + s * 0.2, y + s * 0.2, player, s * 0.24)
    if label:
        o += txt(x + s / 2, y + s - s * 0.08, label, s * 0.17, 'bold', 'middle')
    return o


def grid(x, y, cols, n, s, **kw):
    out = []
    for i in range(n):
        out.append(token(x + (i % cols) * s, y + (i // cols) * s, s, **kw))
    rows = math.ceil(n / cols)
    return ''.join(out), rows * s


def pieces_page():
    out = []
    y = 14
    for p, x in ((1, 12), (2, 92)):
        out.append(txt(x, y - 2, f'BOTS - player {p} (20)', 3.2, 'bold'))
        g, h = grid(x, y, 5, 20, BOT_MM, icon='bot', player=p)
        out.append(g)
    y2 = y + 4 * BOT_MM + 10
    for p, x in ((1, 12), (2, 102)):
        out.append(txt(x, y2 - 2, f'MECHS - player {p} (5)', 3.2, 'bold'))
        g, h = grid(x, y2, 5, 5, MECH_MM, icon='mech', player=p)
        out.append(g)
    y3 = y2 + MECH_MM + 10
    for p, x in ((1, 12), (2, 102)):
        out.append(txt(x, y3 - 2, f'EXTRACTORS - player {p} (5)', 3.2, 'bold'))
        out.append(grid(x, y3, 5, 5, 16, icon='extractor', player=p)[0])
    y4 = y3 + 16 + 10
    for p, x in ((1, 12), (2, 102)):
        out.append(txt(x, y4 - 2, f'FACTORIES - player {p} (5)', 3.2, 'bold'))
        out.append(grid(x, y4, 5, 5, 16, icon='factory', player=p)[0])
    y5 = y4 + 16 + 10
    out.append(txt(12, y5 - 2, 'DOCKS (2, one per home tile)', 3.2, 'bold'))
    out.append(token(12, y5, 16, 'dock', 1) + token(28, y5, 16, 'dock', 2))
    out.append(txt(70, y5 - 2, 'PRICE MARKERS (4, Advanced game)', 3.2, 'bold'))
    for i, g_ in enumerate(('iron', 'crystal', 'bot', 'mech')):
        out.append(token(70 + i * 14, y5, 14, g_))
    o, yy = para(12, y5 + 28, 'Bots and Mechs: stand them on the tiles. Extractors / Factories: put on a round socket of a tile you hold; Docks: on your home tile. Spare bots and mechs wait on your player board (the printed squares are the right size). Player 1 = triangle, player 2 = circle + grey.', 2.9)
    out.append(o)
    return page(''.join(out), title='LITTLE COMMANDERS - print and play, 2 players - pieces')


def economy_page():
    out = []
    s = 12
    y = 14
    out.append(txt(12, y - 2, 'CREDIT COINS  1  (40)', 3.2, 'bold'))
    g, h = grid(12, y, 15, 40, s, icon='credit', number=None)
    out.append(g)
    y += h + 10
    out.append(txt(12, y - 2, 'CREDIT COINS  5  (15)', 3.2, 'bold'))
    g, h = grid(12, y, 15, 15, s, icon='credit', number='5', heavy=True)
    out.append(g)
    y += h + 10
    out.append(txt(12, y - 2, 'IRON TOKENS (30)', 3.2, 'bold'))
    g, h = grid(12, y, 15, 30, s, icon='iron')
    out.append(g)
    y += h + 10
    out.append(txt(12, y - 2, 'CRYSTAL TOKENS (15)', 3.2, 'bold'))
    g, h = grid(12, y, 15, 15, s, icon='crystal')
    out.append(g)
    y += h + 10
    out.append(txt(12, y - 2, 'VICTORY POINTS (10)', 3.2, 'bold'))
    g, h = grid(12, y, 10, 10, 14, icon='vp', label='VP')
    out.append(g)
    y += h + 8
    for n in [
        'A Credit coin on an Extractor or Factory = used this turn: you paid 1 Credit; take the coins off at the start of your turn.',
        'Iron and crystal lie on the tile that holds them; they only move together with a Bot. A mission card scores 1 VP (level 1 cards) or 2 VP (level 2 cards).',
    ]:
        o, y = para(12, y, n, 2.9)
        out.append(o)
    return page(''.join(out), title='LITTLE COMMANDERS - print and play, 2 players - Credits, resources, VP')


# ---------------------------------------------------------------- boards

def market_page(advanced):
    out = ['<rect x="8" y="8" width="281" height="194" fill="none" stroke="#222" stroke-width="0.8"/>']
    title = 'THE MARKET  -  ADVANCED SIDE (prices move)' if advanced else 'THE MARKET  -  BASIC SIDE (fixed prices)'
    out.append(txt(148.5, 24, title, 8, 'bold', 'middle'))
    out.append(txt(148.5, 32, 'Buy and sell at your Dock (iron and bots) or at a Port next to a tile you hold: any amount, as often as you like, no fee.' if not advanced else
                   'Buy and sell at your Dock or a Port. Every item bought moves the price up 1, every item sold down 1. Buying and selling the same good in one turn is not allowed.', 3.2, 'normal', 'middle'))
    names = {'iron': 'IRON', 'crystal': 'CRYSTAL', 'bot': 'BOT', 'mech': 'MECH'}
    y = 44
    for g in ('iron', 'crystal', 'bot', 'mech'):
        p, lo, hi = PRICES[g]
        out.append(to_gray(icon_group(g, 28, y + 12, 18)))
        out.append(txt(42, y + 15, names[g], 6, 'bold'))
        if advanced:
            for i, v in enumerate(range(lo, hi + 1)):
                x = 72 + i * 20
                start = v == p
                out.append(f'<rect x="{x}" y="{y}" width="20" height="22" fill="{"#dddddd" if start else "#ffffff"}" stroke="#222" stroke-width="{0.8 if start else 0.3}"/>')
                out.append(txt(x + 10, y + 15, v, 8, 'bold', 'middle'))
                if start:
                    out.append(txt(x + 10, y + 19.5, 'START', 2.4, 'bold', 'middle'))
        else:
            out.append(f'<rect x="72" y="{y}" width="40" height="22" fill="#ffffff" stroke="#222" stroke-width="0.8"/>')
            out.append(txt(92, y + 16, p, 12, 'bold', 'middle'))
            out.append(txt(122, y + 15, 'Credits, to buy and to sell', 4, 'normal'))
        y += 28
    out.append(f'<rect x="12" y="{y + 4}" width="273" height="42" fill="none" stroke="#222" stroke-width="0.3"/>')
    lines = ['Place the 4 price markers on the START squares.', 'Every sale and every purchase is paid at the CURRENT price; the price moves after the whole trade (1 per item).',
             'A Port opens only the two goods printed on its back. Prices never go below the lowest or above the highest square.'] if advanced else [
        'Prices never change. Iron 3, Crystal 4, Bot 5, Mech 10.', 'A Dock opens iron and bots. A Port (front side) opens all four goods.', 'Same price to buy and to sell: you earn from Factories and Extractors, not from the market itself.']
    for k, n in enumerate(lines):
        out.append(txt(18, y + 15 + k * 8, n, 3.6))
    return page(''.join(out), landscape=True, title='')


def board_page(adv, player):
    col = '#222222'
    svg = (advanced_board if adv else basic_board)(col, 'PRINT', mono=True, player=player)
    svg = svg.replace('width="297mm" height="210mm"', 'x="0" y="0" width="297" height="210"', 1)
    return {'svg': f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 297 210" width="297mm" height="210mm">{svg}</svg>', 'land': True}


# ---------------------------------------------------------------- mission cards

def card_pages():
    cards = []
    for level in (1, 2):
        for i, (mt, n) in enumerate(bd.MISSIONS[level], start=1):
            if mt in SKIP_MISSIONS:
                continue
            _, _, svg = bd.card_svg(level, i, mt, n)
            svg = svg.replace(bd.TEAL, '#555555').replace(bd.PURPLE, '#111111')
            svg = re.sub(r'<text class="placeholder".*?</text>', '', svg)
            svg = svg.replace(f'<rect id="bleed" x="0" y="0" width="{bd.W}" height="{bd.H}" fill="#555555"/>', f'<rect id="bleed" width="{bd.W}" height="{bd.H}" fill="#ffffff"/>')
            svg = svg.replace(f'<rect id="bleed" x="0" y="0" width="{bd.W}" height="{bd.H}" fill="#111111"/>', f'<rect id="bleed" width="{bd.W}" height="{bd.H}" fill="#ffffff"/>')
            body = re.sub(r'^<svg[^>]*>', '', svg).rsplit('</svg>', 1)[0]
            cards.append(to_gray(body))
    cw, ch = 63.0, 88.0
    pages = []
    for p in range(0, len(cards), 9):
        out = []
        for k, body in enumerate(cards[p:p + 9]):
            x, y = 10.5 + (k % 3) * cw, 16.5 + (k // 3) * ch
            out.append(f'<svg x="{x}" y="{y}" width="{cw}" height="{ch}" viewBox="{bd.BLEED} {bd.BLEED} {bd.W - 2 * bd.BLEED} {bd.H - 2 * bd.BLEED}" font-family="{FONT}">{body}</svg>')
            out.append(f'<rect x="{x}" y="{y}" width="{cw}" height="{ch}" fill="none" stroke="#999" stroke-width="0.2"/>')
        pages.append(page(''.join(out), title=f'LITTLE COMMANDERS - print and play - mission cards {p // 9 + 1}/{math.ceil(len(cards) / 9)}  (cut along the grid)'))
    return pages


# ---------------------------------------------------------------- how-to page

def howto_page():
    L = [
        ('LITTLE COMMANDERS  -  print and play (2 players)', 6.5, 'bold'),
        ('', 3, 'normal'),
        ('PRINT: A4, 100% / "actual size", black and white. 15 pages. Do not scale to fit.', 3.6, 'bold'),
        ('CUT: hexagon tiles along the outlines (neighbours share a cut), everything else along the printed squares / the card grid.', 3.4, 'normal'),
        ('STICK (optional): glue tiles and tokens on thin cardboard. Board and market: print two-sided or glue sheets back to back.', 3.4, 'normal'),
        ('', 3, 'normal'),
        ('WHAT IS IN THE BOX', 4.4, 'bold'),
        ('Pages 2-3: 21 land tiles and 8 port tiles + a setup map.  Pages 4-5: bots, mechs, buildings, docks, markers, coins, resources, VP.', 3.4, 'normal'),
        ('Pages 6-7: market board (one side Basic, one side Advanced).  Pages 8-11: 2 player boards x 2 sides.  Pages 12-15: mission cards.', 3.4, 'normal'),
        ('', 3, 'normal'),
        ('TWO SETTINGS', 4.4, 'bold'),
        ('BASIC: market prices are fixed (iron 3, crystal 4, bot 5, mech 10). Ports use the front side and open all four goods.', 3.4, 'normal'),
        ('ADVANCED: prices start the same but move 1 per item bought or sold (iron 1-6, crystal 2-8, bot 2-10, mech 3-12). Ports use the back side and open two goods.', 3.4, 'normal'),
        ('', 3, 'normal'),
        ('SETUP', 4.4, 'bold'),
        ('1. Lay the 19 land tiles as on the setup map: centre, ring 1 (2 dots) around it, ring 2 (3 dots) around that. Shuffle each group first. The two home tiles take opposite corners of ring 2.', 3.4, 'normal'),
        ('2. Turn tiles so that every tile can be reached from the centre over roads (a blocked side cannot be crossed from either side).', 3.4, 'normal'),
        ('3. Place two Ports (Basic: front side; Advanced: draw 2 backs) on the two other corners, just outside the land.', 3.4, 'normal'),
        ('4. Each player: board in front of them, on the home tile: 3 Bots, 2 iron, the Dock. Market board: Basic side up, or Advanced side with the 4 markers on START.', 3.4, 'normal'),
        ('5. Shuffle the mission cards by ring (1 VP / 2 VP): draw 10 of each, put the 2 VP cards under the 1 VP cards, turn 5 face up. First player goes first.', 3.4, 'normal'),
        ('', 3, 'normal'),
        ('A TURN (in any order, as often as you can pay)', 4.4, 'bold'),
        ('Start: gain 3 Credits + 1 for each land tile you control; take the coins off your buildings.', 3.4, 'normal'),
        ('Move pieces from a tile you control to a tile you can reach over roads: 1 Credit per step for the whole group, any number of pieces. Iron/crystal only move with a Bot.', 3.4, 'normal'),
        ('Extractor: 1 Credit once per turn = 1 token of the tile\'s resource.  Factory: 1 Credit once per turn: 1 iron = 2 Bots, or 1 iron + 1 crystal = 1 Mech.', 3.4, 'normal'),
        ('Build: Extractor = 1 iron, Factory = 2 iron on a free round socket of a tile you control. Trade: at your Dock (iron, bots) or a Port next to a tile you hold.', 3.4, 'normal'),
        ('Mission: pay 5 Credits (+1 for each you bought earlier) for a face-up card: you score only if you meet it right now. Win with 5 VP.', 3.4, 'normal'),
        ('Fight: moving onto a tile held by someone else attacks it. Bot = power 1, Mech = power 5. 2 x defender power pushes it away, 3 x kills it. Resolved when you end your turn.', 3.4, 'normal'),
        ('You control a tile while one of your pieces stands on it. Full rules: rulebook/rulebook.pdf.', 3.4, 'normal'),
    ]
    out, y = [], 22
    for s_, size, w in L:
        if not s_:
            y += 3
            continue
        o, y = para(12, y, s_, size, 186, gap=1.6, weight=w)
        out.append(o)
    return page(''.join(out))


# ---------------------------------------------------------------- build

def build():
    pages = [howto_page(), tiles_page(), port_page(), pieces_page(), economy_page(),
             market_page(False), market_page(True)]
    for p in (1, 2):
        pages += [board_page(False, p), board_page(True, p)]
    pages += card_pages()
    css = ('@page{size:A4;margin:0}@page land{size:A4 landscape;margin:0}html,body{margin:0;background:#fff}'
           '.p{width:210mm;height:297mm;page-break-after:always;overflow:hidden}.l{page:land;width:297mm;height:210mm}svg{display:block}')
    body = ''.join(f'<div class="p{" l" if pg["land"] else ""}">{pg["svg"]}</div>' for pg in pages)
    os.makedirs(OUT, exist_ok=True)
    html = os.path.join(OUT, '_pages.html')
    with open(html, 'w', encoding='utf-8') as f:
        f.write(f'<!doctype html><meta charset="utf-8"><style>{css}</style>{body}')
    pdf = os.path.join(OUT, 'little_commanders_print_2p.pdf')
    subprocess.run(['google-chrome', '--headless', '--no-sandbox', '--disable-gpu', '--no-pdf-header-footer', f'--print-to-pdf={pdf}', f'file://{os.path.abspath(html)}'],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    os.unlink(html)
    print('pages:', len(pages), '->', pdf)


if __name__ == '__main__':
    build()
