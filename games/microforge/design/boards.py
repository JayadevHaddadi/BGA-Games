"""Player boards (A4 landscape, 297x210 mm, 5 units per mm): BASIC side and ADVANCED side, one pair per faction colour.

Basic = the market has fixed prices and Ports open every good. Advanced = prices move (1 per item) and a Port opens only the
two goods printed on it. Numbers in ADV / PRICES below are the rules; change them and rerun build_design.py. Faction colour,
commander art and (for print) the player mark are the only per-player parts. All pictograms come from icons.py.

mono=True makes the black-and-white print version (player 1 / player 2 marks, grey commander art, no filled colour).
"""
import base64
import os
from html import escape

from icons import icon_group
from mono import to_gray

HERE = os.path.dirname(os.path.abspath(__file__))
INK = '#2b2233'
PAPER = '#f4ead7'
PANEL = '#fffaf0'
W, H = 1485, 1050

# Rules numbers
ADV = {'income': 3, 'extract': 1, 'factory': 1, 'step': 1, 'mission': 5, 'win_vp': 5, 'bot_power': 1, 'mech_power': 5}
# (fixed price / start price, lowest, highest) per good: Basic uses the first number only
PRICES = {'iron': (3, 1, 6), 'crystal': (4, 2, 8), 'bot': (5, 2, 10), 'mech': (10, 3, 12)}
# Size of the paper tokens in mm: the supply areas below are drawn as a grid of exactly this size.
BOT_MM, MECH_MM = 14, 16


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


def art_uri(colour, mono):
    """Commander portrait cropped from the concept art, embedded so the SVG works as an <img> in BGA."""
    path = os.path.join(HERE, 'art', f'commander_{colour}{"_mono" if mono else ""}.jpg')
    with open(path, 'rb') as f:
        return 'data:image/jpeg;base64,' + base64.b64encode(f.read()).decode()


def player_mark(x, y, player, size=64, fill=PAPER):
    """Print only: player 1 = triangle, player 2 = circle (same marks as on the pieces)."""
    if player == 1:
        return f'<polygon points="{x},{y - size / 2} {x + size / 2},{y + size / 2} {x - size / 2},{y + size / 2}" fill="{fill}"/>'
    return f'<circle cx="{x}" cy="{y}" r="{size / 2}" fill="{fill}"/>'


def frame(col, side, title, colour, mono, player):
    ink_fill = '#222222' if mono else col
    s = [
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="297mm" height="210mm" font-family="DejaVu Sans, Arial, sans-serif">',
        f'<title>{escape(title)}</title>',
        f'<rect width="{W}" height="{H}" fill="{PAPER}"/>',
        f'<rect x="12" y="12" width="{W - 24}" height="{H - 24}" rx="18" fill="none" stroke="{ink_fill}" stroke-width="12"/>',
        f'<rect x="30" y="30" width="{W - 60}" height="130" rx="10" fill="{ink_fill}"/>',
    ]
    x = 60
    if mono:
        s.append(player_mark(88, 95, player, 70))
        x = 150
    s.append(t(x, 105, 'COMMANDER BOARD' + (f'  -  PLAYER {player}' if mono else ''), 54, 'bold', fill=PAPER))
    s.append(t(x, 142, side, 28, 'normal', fill=PAPER))
    s.append(f'<g id="art-slot"><rect x="{W - 30 - 240}" y="30" width="240" height="130" rx="10" fill="{PAPER}"/>'
             f'<image href="{art_uri(colour, mono)}" x="{W - 30 - 240}" y="30" width="240" height="130" preserveAspectRatio="xMidYMid slice"/>'
             f'<rect x="{W - 30 - 240}" y="30" width="240" height="130" rx="10" fill="none" stroke="{PAPER}" stroke-width="5"/></g>')
    return s


def token_grid(x, y, cols, rows, mm, icon):
    """Dashed squares of the exact token size: the supply areas double as a placement guide for the paper tokens."""
    k = mm * 5
    s = ''
    for j in range(rows):
        for i in range(cols):
            cx, cy = x + i * k, y + j * k
            s += f'<rect x="{cx}" y="{cy}" width="{k}" height="{k}" fill="#ebe1cd" stroke="{INK}" stroke-width="2" stroke-dasharray="6 5"/>'
            s += icon_group(icon, cx + k / 2, cy + k / 2, k * 0.62).replace('<g ', '<g opacity="0.2" ', 1)
    return s


def _board(col, name, adv, mono=False, player=1):
    A = ADV
    colour = name.lower() if not mono else ('red' if player == 1 else 'blue')
    side = ('ADVANCED SIDE  -  moving prices, Ports open two goods' if adv else 'BASIC SIDE  -  fixed prices, Ports open every good')
    s = frame(col, (name + '  -  ' if not mono else '') + side, f'Player board {name} ' + ('advanced' if adv else 'basic'), colour, mono, player)
    # supplies
    s.append(panel(30, 190, 450, 830, 'Unit Supply', col))
    s.append(icon_group('bot', 80, 274, 44) + t(120, 282, 'Bots (20 in reserve)', 26, 'bold', fill=col))
    s.append(token_grid(65, 302, 5, 4, BOT_MM, 'bot'))
    s.append(icon_group('mech', 80, 655, 44) + t(120, 663, 'Mechs (5 in reserve)', 26, 'bold', fill=col))
    s.append(token_grid(55, 695, 5, 1, MECH_MM, 'mech'))
    s.append(t(255, 830, 'Buildings in reserve', 26, 'bold', 'middle', col))
    s.append(icon_group('extractor', 90, 890, 56) + t(130, 900, 'x5', 30, 'bold') + icon_group('factory', 290, 890, 56) + t(330, 900, 'x5', 30, 'bold'))
    s.append(t(255, 960, 'Your home tile has a Dock:', 21, fill='#5b5166', anchor='middle'))
    s.append(t(255, 990, 'you can trade iron and bots there.', 21, fill='#5b5166', anchor='middle'))
    # turn flow
    s.append(panel(510, 190, 945, 150, 'Start of your turn', col))
    s.append(icon_group('income', 600, 285, 64))
    s.append(t(660, 282, f'Gain {A["income"]} Credits + 1 per land tile you hold.', 30, 'bold'))
    s.append(t(660, 316, 'Take the coins off your buildings (they can work again).', 24))
    # actions
    s.append(panel(510, 360, 945, 430, 'Then, in any order', col))
    rows = [
        ('extractor', 'Extractor: take 1 token of the tile\'s resource', None, f'{A["extract"]}', 'used'),
        ('factory', 'Factory: 1 iron = 2 Bots,  or  iron + crystal = 1 Mech', None, f'{A["factory"]}', 'used'),
        ('move', 'Move any pieces together: 1 Credit per step', 'Iron and crystal only travel with a Bot (one at the start and one at the end).', '1', None),
        ('trade', 'Trade at your Dock or a Port next to a tile you hold', 'prices', None, None),
        ('mission', 'Buy a Mission (+1 for each you have bought)', None, f'{A["mission"]}', None),
    ]
    for i, (ic, label, sub, price, mark) in enumerate(rows):
        y = 430 + i * 66
        s.append(icon_group(ic, 570, y, 50))
        s.append(t(620, y + 4, label, 24))
        if sub == 'prices':
            s.append(t(620, y + 30, 'Price now:' if adv else 'Fixed price:', 18, fill='#5b5166'))
            for j, g in enumerate(('iron', 'crystal', 'bot', 'mech')):
                p, lo, hi = PRICES[g]
                gx = 760 + j * 165
                s.append(icon_group(g, gx, y + 25, 26))
                s.append(t(gx + 20, y + 33, f'{p} ({lo}-{hi})' if adv else str(p), 22, 'bold'))
        elif sub:
            s.append(t(620, y + 30, sub, 18, fill='#5b5166'))
        if price:
            s.append(cost(1330, y - 4, price, 38))
        if mark:
            s.append(icon_group('used', 1420, y, 40))
    if adv:
        s.append(t(540, 738, 'Each item bought moves its price up 1, each item sold moves it down 1 (stay inside the range).', 17, fill='#7d7388'))
        s.append(t(540, 758, 'You cannot buy and sell the same good in one turn. A Port opens only the two goods on it.', 17, fill='#7d7388'))
        s.append(t(540, 778, 'A coin on a building = used this turn.', 17, fill='#7d7388'))
    else:
        s.append(t(540, 738, 'Buy and sell any amount, as often as you like, at the fixed price. A Port opens every good.', 17, fill='#7d7388'))
        s.append(t(540, 758, 'A coin on a building = used this turn.', 17, fill='#7d7388'))
    # build + combat + win
    s.append(panel(510, 800, 470, 220, 'Build (iron on the tile)', col))
    for i, (ic, n) in enumerate([('extractor', 1), ('factory', 2)]):
        x = 600 + i * 190
        s.append(icon_group(ic, x, 885, 56))
        s.append(icon_group('iron', x - 14, 950, 36) + t(x + 8, 960, f'x{n}', 28, 'bold'))
    s.append(panel(1000, 800, 455, 220, 'Fight', col))
    s.append(icon_group('bot', 1050, 878, 40) + t(1080, 888, f'power {A["bot_power"]}', 26, 'bold'))
    s.append(icon_group('mech', 1230, 878, 40) + t(1260, 888, f'power {A["mech_power"]}', 26, 'bold'))
    s.append(icon_group('push', 1040, 920, 44) + t(1075, 928, 'push: 2 x defenders', 22))
    s.append(icon_group('kill', 1040, 960, 44) + t(1075, 968, 'kill: 3 x defenders', 22))
    s.append(icon_group('vp', 1045, 999, 34) + t(1070, 1007, f'Win: {A["win_vp"]} VP, only from Missions', 22, 'bold'))
    s.append('</svg>')
    out = '\n'.join(s)
    return to_gray(out) if mono else out


def basic_board(col, name, mono=False, player=1):
    return _board(col, name, False, mono, player)


def advanced_board(col, name, mono=False, player=1):
    return _board(col, name, True, mono, player)
