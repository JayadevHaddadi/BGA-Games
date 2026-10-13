#!/usr/bin/env python3
"""Printable black-and-white prototype of Little Commanders for 4 players (2-3 players: same sheets), as A4 sheets.

  python3 design/print_sheets.py          ->  print/little_commanders_print.pdf

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
from boards import BOT_MM, MECH_MM, PRICES, advanced_board  # noqa: E402
import print_board as pb  # noqa: E402
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


# ---------------------------------------------------------------- tokens

def mark(x, y, player, size, on_dark=False):
    return pb.mark_shape(player, x, y, size, '#ffffff' if on_dark else '#000000')


def token(x, y, s, icon, player=None, label=None, heavy=False, number=None):
    bg = '#e6e6e6' if player in (2, 4) else '#ffffff'
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
    return ''.join(out), math.ceil(n / cols) * s


PLAYERS = (1, 2, 3, 4)


def pieces_page():
    """Bots and Mechs of all four players (they belong to a commander; the marks tell them apart)."""
    out = []
    y = 16
    for k, p in enumerate(PLAYERS):
        x = 12 + (k % 2) * 98
        yy = y + (k // 2) * 58
        out.append(txt(x, yy - 2, f'BOTS - player {p} (20)', 3.2, 'bold'))
        out.append(mark(x + 52, yy - 3.5, p, 3.4))
        out.append(grid(x, yy, 5, 20, BOT_MM, icon='bot', player=p)[0])
    y2 = y + 2 * 58 + 6
    for k, p in enumerate(PLAYERS):
        x = 12 + (k % 2) * 98
        yy = y2 + (k // 2) * (MECH_MM + 12)
        out.append(txt(x, yy - 2, f'MECHS - player {p} (5)', 3.2, 'bold'))
        out.append(mark(x + 52, yy - 3.5, p, 3.4))
        out.append(grid(x, yy, 5, 5, MECH_MM, icon='mech', player=p)[0])
    y3 = y2 + 2 * (MECH_MM + 12) + 6
    out.append(txt(12, y3 - 2, 'DOCKS (4, one per home tile)', 3.2, 'bold'))
    for k, p in enumerate(PLAYERS):
        out.append(token(12 + k * 14, y3, 14, 'dock', p))
    out.append(txt(86, y3 - 2, 'PRICE MARKERS (4, Advanced game)', 3.2, 'bold'))
    for i, g_ in enumerate(('iron', 'crystal', 'bot', 'mech')):
        out.append(token(86 + i * 14, y3, 14, g_))
    o, _ = para(12, y3 + 24, 'Bots and Mechs stand on the tiles. The Dock goes on your home tile. Your spare Bots and Mechs wait on your commander board (the printed squares are the right size). Marks: player 1 = triangle, 2 = circle (grey), 3 = square, 4 = diamond (grey).', 2.9)
    out.append(o)
    return page(''.join(out), title='LITTLE COMMANDERS - print and play, 4 players - pieces')


def economy_page():
    """Shared things: buildings (not personal), Credit coins, resources, Victory Points."""
    out = []
    s = 12
    y = 14
    out.append(txt(12, y - 2, 'EXTRACTORS (12, shared by everyone)', 3.2, 'bold'))
    g, h = grid(12, y, 12, 12, s, icon='extractor')
    out.append(g)
    y += h + 9
    out.append(txt(12, y - 2, 'FACTORIES (12, shared by everyone)', 3.2, 'bold'))
    g, h = grid(12, y, 12, 12, s, icon='factory')
    out.append(g)
    y += h + 9
    out.append(txt(12, y - 2, 'CREDIT COINS  1  (60)', 3.2, 'bold'))
    g, h = grid(12, y, 15, 60, s, icon='credit')
    out.append(g)
    y += h + 9
    out.append(txt(12, y - 2, 'CREDIT COINS  5  (20)', 3.2, 'bold'))
    g, h = grid(12, y, 15, 20, s, icon='credit', number='5', heavy=True)
    out.append(g)
    y += h + 9
    out.append(txt(12, y - 2, 'IRON TOKENS (40)', 3.2, 'bold'))
    g, h = grid(12, y, 15, 40, s, icon='iron')
    out.append(g)
    y += h + 9
    out.append(txt(12, y - 2, 'CRYSTAL TOKENS (24)', 3.2, 'bold'))
    g, h = grid(12, y, 15, 24, s, icon='crystal')
    out.append(g)
    y += h + 9
    out.append(txt(12, y - 2, 'VICTORY POINTS (20)', 3.2, 'bold'))
    g, h = grid(12, y, 10, 20, 14, icon='vp', label='VP')
    out.append(g)
    y += h + 5
    o, y = para(12, y, 'Extractors and Factories are not personal: they work for whoever holds the tile they stand on. Put one on a free round socket. A Credit coin on an Extractor or Factory = used this turn; take the coins off at the start of your turn.', 2.9)
    out.append(o)
    return page(''.join(out), title='LITTLE COMMANDERS - print and play, 4 players - shared buildings, Credits, resources, VP')


# ---------------------------------------------------------------- boards

def a5_stack(a, b):
    """Two A5 landscape sheets (210 x 148.5 mm) on one A4 portrait page. Both are drawn in a 297 x 210 box and scaled by 0.707."""
    def a5(content, y):
        return f'<svg x="0" y="{y}" width="210" height="148.5" viewBox="0 0 297 210">{content}</svg>'
    cut = '<line x1="0" y1="148.5" x2="210" y2="148.5" stroke="#999" stroke-width="0.25" stroke-dasharray="2 2"/>'
    return {'svg': f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 210 297" width="210mm" height="297mm" font-family="{FONT}">{a5(a, 0)}{a5(b, 148.5)}{cut}</svg>', 'land': False}


def market_inner(advanced):
    out = ['<rect x="8" y="8" width="281" height="194" fill="none" stroke="#222" stroke-width="0.8"/>']
    title = 'THE MARKET  -  ADVANCED SIDE (prices move)' if advanced else 'THE MARKET  -  BASIC SIDE (fixed prices)'
    out.append(txt(148.5, 24, title, 9, 'bold', 'middle'))
    out.append(txt(148.5, 33, 'Buy and sell at your Dock (iron and bots) or at a Port next to a tile you hold: any amount, as often as you like, no fee.' if not advanced else
                   'Buy and sell at your Dock or a Port. Every item bought moves the price up 1, every item sold down 1.', 4.4, 'normal', 'middle'))
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
            out.append(txt(122, y + 15, 'Credits, to buy and to sell', 4.5, 'normal'))
        y += 28
    out.append(f'<rect x="12" y="{y + 4}" width="273" height="40" fill="none" stroke="#222" stroke-width="0.3"/>')
    lines = ['Put the 4 price markers on the START squares.', 'Everything is paid at the CURRENT price; the price moves after the whole trade (1 per item).',
             'You cannot buy and sell the same good in one turn. A Port opens only the 2 goods printed on it.'] if advanced else [
        'Prices never change: Iron 3, Crystal 4, Bot 5, Mech 10.', 'A Dock opens iron and bots. A Port opens all four goods (ignore the two printed on it).',
        'Same price to buy and to sell: you earn from Factories and Extractors, not from the market.']
    for k, n in enumerate(lines):
        out.append(txt(18, y + 14 + k * 8, n, 4.2))
    return ''.join(out)


def market_page():
    return a5_stack(market_inner(False), market_inner(True))


def board_pages():
    """Four commander boards (Advanced side only), two per A4 portrait page, A5 each."""
    def inner(player):
        svg = advanced_board('#222222', 'PRINT', mono=True, player=player)
        return svg.replace('width="297mm" height="210mm"', 'x="0" y="0" width="297" height="210"', 1)
    return [a5_stack(inner(1), inner(2)), a5_stack(inner(3), inner(4))]


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
        ('LITTLE COMMANDERS  -  print and play (4 players; 2-3 players use the same sheets)', 6, 'bold'),
        ('', 3, 'normal'),
        ('PRINT: A4, 100% / "actual size", black and white. Do not scale to fit.', 3.6, 'bold'),
        ('CUT: the hexagons on the two board pages (they touch, one cut serves two), the squares and cards along the printed grid lines, and each A4 board / market page along its dashed middle line (two A5 sheets per page).', 3.4, 'normal'),
        ('', 3, 'normal'),
        ('WHAT IS WHERE', 4.4, 'bold'),
        ('Pages 2-3: the READY-MADE 4 player board, left half on page 2 and right half on page 3 (33 land tiles, 4 homes, 8 ports, balanced and connected). Cut out the hexagons and put them back where they are printed (letters and numbers outside the board name every tile). Tiles marked 2P 3P are the ones a 2 or 3 player game uses. Page 4: six spare ports (only for 2-3 players).', 3.4, 'normal'),
        ('Page 5: Bots and Mechs of 4 players, docks, price markers. Page 6: shared buildings, Credit coins, iron, crystal, VP. Page 7: market (Basic side and Advanced side, A5 each). Pages 8-9: 4 commander boards (A5, Advanced side). Pages 10-13: 36 mission cards.', 3.4, 'normal'),
        ('', 3, 'normal'),
        ('TWO SETTINGS', 4.4, 'bold'),
        ('BASIC: market prices are fixed (iron 3, crystal 4, bot 5, mech 10). A Port opens all four goods (ignore the two goods printed on its tile). The 4 price markers are not used.', 3.4, 'normal'),
        ('ADVANCED: prices start the same but move 1 per item bought or sold (iron 1-6, crystal 2-8, bot 2-10, mech 3-12). A Port opens only the two goods printed on it. You cannot buy and sell the same good in one turn.', 3.4, 'normal'),
        ('', 3, 'normal'),
        ('SETUP', 4.4, 'bold'),
        ('1. Cut out the board (pages 2-3) and lay the two halves side by side. Each player takes a commander board, the Bots (20) and Mechs (5) with their mark, and a Dock; their home tile is the one with their mark.', 3.4, 'normal'),
        ('2. On your home tile: 3 Bots, 2 iron, your Dock. Keep your other Bots and Mechs on your commander board. Extractors and Factories are shared: put them in the middle of the table.', 3.4, 'normal'),
        ('3. Market: Basic side up, or Advanced side with the 4 markers on START. Shuffle the mission cards by level (1 VP / 2 VP): draw 10 of each, level 2 under level 1, turn 5 face up. Youngest player starts.', 3.4, 'normal'),
        ('4. 2-3 players: use only the tiles marked 2P 3P. Where a tile says "home 1" (2, 3), put that player\'s home tile there instead. Ports: place the spare ports of page 4 at the coordinates printed under them.', 3.4, 'normal'),
        ('', 3, 'normal'),
        ('A TURN (in any order, as often as you can pay)', 4.4, 'bold'),
        ('Start: gain 3 Credits + 1 for each land tile you control; take the coins off the buildings.', 3.4, 'normal'),
        ('Move pieces from a tile you control to a tile you can reach over roads: 1 Credit per step for the whole group, any number of pieces. Iron/crystal only move with a Bot.', 3.4, 'normal'),
        ('Extractor: 1 Credit once per turn = 1 token of the tile\'s resource. Factory: 1 Credit once per turn: 1 iron = 2 Bots, or 1 iron + 1 crystal = 1 Mech. These buildings belong to whoever holds the tile.', 3.4, 'normal'),
        ('Build: Extractor = 1 iron, Factory = 2 iron on a free round socket of a tile you control. Trade: at your Dock (iron, bots) or a Port next to a tile you hold, any amount, as often as you like.', 3.4, 'normal'),
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

def extras_page():
    """Spare ports for the 2-3 player setups, plus a reminder of which home goes where."""
    svg, n = pb.spare_ports_svg()
    inner = txt(12, 20, 'SPARE PORTS (only for 2 or 3 players)', 3.4, 'bold') + svg
    inner += txt(12, 165, 'Under each port: the game (2P / 3P) and the board coordinate where it goes. In a 2 or 3 player game the 8 ports', 2.9)
    inner += txt(12, 170, 'of the big board are not used; the outer ring of land tiles is not used either (no 2P 3P mark).', 2.9)
    inner += txt(12, 175, 'In a 4 player game the spare ports are not used.', 2.9)
    return page(inner, title='LITTLE COMMANDERS - print and play - spare ports')


def build():
    def half(side):
        return {'svg': f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 210 297" width="210mm" height="297mm" font-family="{FONT}">{pb.board_half_svg(side)}</svg>', 'land': False}
    pages = [howto_page(), half('left'), half('right'), extras_page(), pieces_page(), economy_page(), market_page()]
    pages += board_pages()
    pages += card_pages()
    css = ('@page{size:A4;margin:0}@page land{size:A4 landscape;margin:0}html,body{margin:0;background:#fff}'
           '.p{width:210mm;height:297mm;page-break-after:always;overflow:hidden}.l{page:land;width:297mm;height:210mm}svg{display:block}')
    body = ''.join(f'<div class="p{" l" if pg["land"] else ""}">{pg["svg"]}</div>' for pg in pages)
    os.makedirs(OUT, exist_ok=True)
    html = os.path.join(OUT, '_pages.html')
    with open(html, 'w', encoding='utf-8') as f:
        f.write(f'<!doctype html><meta charset="utf-8"><style>{css}</style>{body}')
    pdf = os.path.join(OUT, 'little_commanders_print.pdf')
    subprocess.run(['google-chrome', '--headless', '--no-sandbox', '--disable-gpu', '--no-pdf-header-footer', f'--print-to-pdf={pdf}', f'file://{os.path.abspath(html)}'],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    os.unlink(html)
    print('pages:', len(pages), '->', pdf)


if __name__ == '__main__':
    build()
