"""Player boards (A4 landscape, 297x210 mm, 5 units per mm): BASIC side and ADVANCED side, one pair per faction colour.

Basic = production, Mechs, combat, Missions, 1 Credit per step. Advanced adds Docks, Ports, the market and the
triangular movement cost. Numbers in ADV below are the rules; change them and rerun build_design.py. Faction colour and the art slot
(id="art-slot") are the only per-faction parts. All pictograms come from icons.py.
"""
from html import escape

from icons import icon_group

INK = '#2b2233'
PAPER = '#f4ead7'
PANEL = '#fffaf0'
W, H = 1485, 1050

# Rules numbers
ADV = {'income': 10, 'extract': 1, 'factory': 1, 'step': 1, 'mission': 5, 'win_vp': 5, 'bot_power': 1, 'mech_power': 5}


def t(x, y, s, size=28, weight='normal', anchor='start', fill=INK):
    return f'<text x="{x}" y="{y}" font-size="{size}" font-weight="{weight}" text-anchor="{anchor}" fill="{fill}">{escape(str(s))}</text>'


def panel(x, y, w, h, title=None, col=None):
    s = f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="10" fill="{PANEL}" stroke="{INK}" stroke-width="3"/>'
    if title:
        s += t(x + 20, y + 40, title, 30, 'bold', fill=col or INK)
    return s


def cost(x, y, n, size=44):
    """credit icon with a number, e.g. a price."""
    return icon_group('credit', x, y, size) + t(x + size * 0.7, y + size * 0.3, n, size * 0.8, 'bold')


def frame(col, side, title):
    return [
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="297mm" height="210mm" font-family="DejaVu Sans, Arial, sans-serif">',
        f'<title>{escape(title)}</title>',
        f'<rect width="{W}" height="{H}" fill="{PAPER}"/>',
        f'<rect x="12" y="12" width="{W - 24}" height="{H - 24}" rx="18" fill="none" stroke="{col}" stroke-width="12"/>',
        f'<rect x="30" y="30" width="{W - 60}" height="130" rx="10" fill="{col}"/>',
        t(60, 110, 'COMMANDER BOARD', 54, 'bold', fill=PAPER),
        t(60, 145, side, 28, 'normal', fill=PAPER),
        f'<g id="art-slot"><rect x="{W - 330}" y="40" width="280" height="110" rx="8" fill="none" stroke="{PAPER}" stroke-width="3" stroke-dasharray="12 8"/>'
        f'<text class="placeholder" x="{W - 190}" y="102" text-anchor="middle" font-size="22" fill="{PAPER}">FACTION ART SLOT</text></g>',
    ]


def circles(x, y, cols, rows, gap, r, icon=None, fill='#ebe1cd'):
    s = ''
    for j in range(rows):
        for i in range(cols):
            cx, cy = x + i * gap, y + j * gap
            s += f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{fill}" stroke="{INK}" stroke-width="2.5" stroke-dasharray="6 5"/>'
            if icon:
                s += icon_group(icon, cx, cy, r * 1.5).replace('<g ', '<g opacity="0.28" ', 1)
    return s


def _board(col, name, adv):
    A = ADV
    s = frame(col, f'{name}  -  ' + ('ADVANCED SIDE  (adds Docks, Ports and the market)' if adv else 'BASIC SIDE  (flip for Docks, Ports and the market)'), f'Player board {name} ' + ('advanced' if adv else 'basic'))
    # supplies
    s.append(panel(30, 190, 450, 830, 'Unit Supply', col))
    # Bots holding area (all 20 bots)
    s.append(icon_group('bot', 80, 252, 44) + t(120, 260, 'Bots (20 in reserve)', 26, 'bold', fill=col))
    s.append(f'<rect x="55" y="280" width="400" height="330" rx="14" fill="#ebe1cd" stroke="{INK}" stroke-width="3" stroke-dasharray="10 7"/>')
    s.append(icon_group('bot', 255, 435, 140).replace('<g ', '<g opacity="0.16" ', 1))
    s.append(t(255, 575, 'Place your 20 Bots here', 20, 'bold', 'middle', '#8a7f70'))
    # Mechs holding area (all 5 mechs)
    s.append(icon_group('mech', 80, 655, 44) + t(120, 663, 'Mechs (5 in reserve)', 26, 'bold', fill=col))
    s.append(f'<rect x="55" y="685" width="400" height="315" rx="14" fill="#ebe1cd" stroke="{INK}" stroke-width="3" stroke-dasharray="10 7"/>')
    s.append(icon_group('mech', 255, 830, 130).replace('<g ', '<g opacity="0.16" ', 1))
    s.append(t(255, 965, 'Place your 5 Mechs here', 20, 'bold', 'middle', '#8a7f70'))
    # turn flow
    s.append(panel(510, 190, 945, 150, 'Start of your turn', col))
    s.append(icon_group('income', 600, 285, 64))
    s.append(t(660, 282, f'Gain {A["income"]} Credits.', 30, 'bold'))
    s.append(t(660, 316, 'Take the coins off your buildings (they can work again).', 24))
    # actions
    s.append(panel(510, 360, 945, 410, 'Then, in any order', col))
    move_label = 'Move: 1, 3, 6, 10 Credits for 1-4 steps (per piece)' if adv else 'Move a piece: 1 Credit per step'
    rows = [
        ('extractor', 'Extract: 1 token of the tile\'s resource', f'{A["extract"]}', 'used'),
        ('factory', 'Factory: 1 iron = 2 Bots,  or iron + crystal = 1 Mech', f'{A["factory"]}', 'used'),
        ('move', move_label, '1+' if adv else '1', None),
    ]
    if adv:
        rows.append(('trade', 'Trade at your Dock or a Port (current price)', '1', 'used'))
    rows.append(('mission', 'Buy a Mission (+1 for each you have bought)', f'{A["mission"]}', None))
    gap = 66 if adv else 82
    for i, (ic, label, price, mark) in enumerate(rows):
        y = 435 + i * gap
        s.append(icon_group(ic, 570, y, 50))
        s.append(t(620, y + 9, label, 24))
        s.append(cost(1330, y - 4, price, 38))
        if mark:
            s.append(icon_group('used', 1420, y, 40))
    s.append(t(540, 755, 'A coin on a building' + (' or post' if adv else '') + ' = used this turn. Take coins off at the start of your turn.', 20, fill='#7d7388'))
    # build + combat + win
    s.append(panel(510, 790, 470, 230, 'Build (iron on the tile)', col))
    for i, (ic, n) in enumerate([('extractor', 1), ('factory', 2), ('tower', 2)]):
        x = 560 + i * 150
        s.append(icon_group(ic, x, 880, 56))
        s.append(icon_group('iron', x - 14, 950, 36) + t(x + 8, 960, f'x{n}', 28, 'bold'))
    s.append(panel(1000, 790, 455, 230, 'Fight', col))
    s.append(icon_group('bot', 1050, 850, 40) + t(1080, 860, f'power {A["bot_power"]}', 26, 'bold'))
    s.append(icon_group('mech', 1230, 850, 40) + t(1260, 860, f'power {A["mech_power"]}', 26, 'bold'))
    s.append(icon_group('push', 1040, 905, 44) + t(1075, 913, 'push: 2 x defenders', 22))
    s.append(icon_group('kill', 1040, 950, 44) + t(1075, 958, 'kill: 3 x defenders', 22))
    s.append(icon_group('vp', 1045, 995, 34) + t(1070, 1003, f'Win: {A["win_vp"]} VP, only from Missions', 22, 'bold'))
    s.append('</svg>')
    return '\n'.join(s)


def basic_board(col, name):
    return _board(col, name, False)


def advanced_board(col, name):
    return _board(col, name, True)
