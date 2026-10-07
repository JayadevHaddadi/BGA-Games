"""Canonical icon set for Little Commanders.

Every icon is drawn in a -32..32 box, flat fills, 3px dark outline. This is the ONE place icons are defined: the mission
cards, BGA (bga/img/icons), the rulebook and the player boards all use these. To change the art, edit a drawing here
(or replace the generated SVG file) and rerun build_design.py.
"""
import math

INK = '#2b2233'
GOLD = '#e6c455'
IRON = '#8d8d96'
IRON_LIGHT = '#b4b4be'
CRYSTAL = '#78b9dc'
BODY = '#d9d2e3'
TAN = '#c9b79a'
SEA = '#6f95b0'
GREEN = '#a9d07c'
PAPER = '#f4ead7'
PURPLE = '#6d3a8c'
RED = '#c0392b'


def hexpts(r, rot=-30):
    return ' '.join(f'{r * math.cos(math.radians(60 * i + rot)):.1f},{r * math.sin(math.radians(60 * i + rot)):.1f}' for i in range(6))


def star(r_out, r_in, n=5):
    pts = []
    for i in range(2 * n):
        r = r_out if i % 2 == 0 else r_in
        a = math.radians(-90 + 180 * i / n)
        pts.append(f'{r * math.cos(a):.1f},{r * math.sin(a):.1f}')
    return ' '.join(pts)


# name -> (label, inner svg drawn in the -32..32 box)
ICONS = {
    'credit': ('Credit', f'<circle r="27" fill="{GOLD}" stroke="{INK}" stroke-width="3"/><circle r="21" fill="none" stroke="#fff176" stroke-width="2"/><circle r="15" fill="none" stroke="{INK}" stroke-width="1.5"/><path d="M-5 -6 L5 -6 M-5 6 L5 6 M0 -10 V10" stroke="{INK}" stroke-width="3.5"/>'),
    'iron': ('Iron', f'<rect x="-22" y="-12" width="44" height="32" fill="{IRON}"/><path d="M-22 -12 L-12 -26 L32 -26 L22 -12 Z" fill="{IRON_LIGHT}"/><path d="M22 -12 L32 -26 L32 6 L22 20 Z" fill="#6f6f78"/>'),
    'crystal': ('Crystal', f'<path d="M0 -30 L18 -6 L0 30 L-18 -6 Z" fill="{CRYSTAL}"/><path d="M-18 -6 H18 M0 -30 L-7 -6 L0 30 M0 -30 L7 -6 L0 30" fill="none" stroke-width="2"/>'),
    'bot': ('Bot', f'<circle cy="6" r="22" fill="{BODY}"/><path d="M0 -16 V-24"/><circle cy="-27" r="4" fill="{INK}"/><circle cx="-8" cy="4" r="4" fill="{INK}"/><circle cx="8" cy="4" r="4" fill="{INK}"/><path d="M-7 15 H7" fill="none"/>'),
    'mech': ('Mech', f'<rect x="-20" y="-10" width="40" height="38" fill="{BODY}"/><rect x="-14" y="-30" width="28" height="20" fill="{BODY}"/><rect x="-8" y="-24" width="16" height="6" fill="{INK}"/><rect x="-32" y="-6" width="12" height="26" fill="{BODY}"/><rect x="20" y="-6" width="12" height="26" fill="{BODY}"/><path d="M-10 28 V32 M10 28 V32"/>'),
    'extractor': ('Extractor', f'<path d="M-22 26 L0 -26 L22 26 Z" fill="{TAN}"/><rect x="-6" y="-34" width="12" height="14" fill="#8a7a62"/><path d="M-10 8 H10" fill="none"/>'),
    'factory': ('Factory', f'<rect x="-28" y="-6" width="56" height="32" fill="{TAN}"/><rect x="8" y="-30" width="12" height="26" fill="#8a7a62"/><rect x="-18" y="6" width="12" height="12" fill="{PAPER}"/><rect x="2" y="6" width="12" height="12" fill="{PAPER}"/>'),
    'tower': ('Guard Tower', f'<path d="M-16 28 L-16 -12 L-22 -12 L-22 -28 L-12 -28 L-12 -20 L-4 -20 L-4 -28 L4 -28 L4 -20 L12 -20 L12 -28 L22 -28 L22 -12 L16 -12 L16 28 Z" fill="#a89c8a"/><path d="M-4 28 V14 Q0 8 4 14 V28" fill="{INK}"/>'),
    'building': ('Building', f'<path d="M-26 24 L-26 -4 L0 -28 L26 -4 L26 24 Z" fill="{TAN}"/><rect x="-7" y="6" width="14" height="18" fill="{PAPER}"/>'),
    'dock': ('Dock', f'<path d="M-30 12 Q-15 0 0 12 T30 12 V28 H-30 Z" fill="{SEA}"/><rect x="-26" y="-6" width="52" height="8" fill="#8a6a42"/><path d="M-20 2 V20 M0 2 V20 M20 2 V20" stroke-width="4"/>'),
    'port': ('Port', f'<path d="M-30 12 Q-15 0 0 12 T30 12 V28 H-30 Z" fill="{SEA}"/><rect x="-4" y="-28" width="8" height="40" fill="#8a6a42"/><path d="M4 -28 L26 -14 L4 -6 Z" fill="{PAPER}"/>'),
    'hex': ('Tile', f'<polygon points="{hexpts(28)}" fill="{GREEN}"/>'),
    'hex2': ('Tile with two resources', f'<polygon points="{hexpts(28)}" fill="{GREEN}"/><path d="M0 -24 L24 0 L0 24 L-24 0 Z" fill="{CRYSTAL}"/>'),
    'centre': ('Centre tile', f'<polygon points="{hexpts(28)}" fill="#e7d9b5"/><polygon points="{star(18, 8)}" fill="{PURPLE}"/>'),
    'control': ('Control', f'<polygon points="{hexpts(28)}" fill="{GREEN}"/><path d="M-4 18 V-20 L16 -12 L-4 -4" fill="{RED}"/>'),
    'mission': ('Mission', f'<rect x="-18" y="-26" width="36" height="52" rx="4" fill="{PAPER}" stroke="{INK}" stroke-width="3"/><path d="M-10 -14 H10 M-10 -4 H10" stroke="{INK}" stroke-width="2.5" fill="none"/><polygon points="{star(9, 4)}" fill="{GOLD}" stroke="{INK}" stroke-width="1.5" transform="translate(0 12)"/>'),
    'vp': ('Victory point', f'<polygon points="{star(28, 12)}" fill="{GOLD}" stroke="{INK}" stroke-width="3"/><polygon points="{star(17, 7.5)}" fill="#fff59d" stroke="none"/>'),
    'move': ('Move (1 Credit per step)', f'<path d="M-28 0 H12" fill="none" stroke-width="5"/><path d="M8 -18 L28 0 L8 18 Z" fill="{GREEN}"/>'),
    'power': ('Attack power', f'<polygon points="{star(30, 15, 8)}" fill="{GOLD}"/>'),
    'push': ('Push', f'<rect x="4" y="-16" width="26" height="32" fill="{BODY}"/><path d="M-30 0 H-4" fill="none" stroke-width="5"/><path d="M-8 -14 L8 0 L-8 14 Z" fill="{RED}"/>'),
    'kill': ('Kill', f'<path d="M-26 10 Q-32 -8 -14 -14 Q-10 -30 6 -22 Q26 -26 24 -6 Q34 8 18 14 Q12 28 -6 20 Q-22 28 -26 10 Z" fill="{BODY}"/><path d="M-8 -6 L8 10 M8 -6 L-8 10" stroke-width="4" fill="none"/>'),
    'used': ('Used this turn', f'<circle r="28" fill="none" stroke-dasharray="5 4"/><circle r="17" fill="{GOLD}"/><circle r="10" fill="none" stroke-width="2"/>'),
    'income': ('Income', f'<circle cx="-6" cy="6" r="22" fill="{GOLD}"/><circle cx="-6" cy="6" r="14" fill="none"/><path d="M18 -26 V-6 M8 -16 H28" stroke-width="5" fill="none"/>'),
    'trade': ('Trade', f'<path d="M-28 -10 H18" fill="none" stroke-width="5"/><path d="M12 -26 L30 -10 L12 6 Z" fill="{GREEN}"/><path d="M28 12 H-18" fill="none" stroke-width="5"/><path d="M-12 -4 L-30 12 L-12 28 Z" fill="{CRYSTAL}"/>'),
    'turn': ('Your turn', f'<path d="M22 -10 A24 24 0 1 0 22 10" fill="none" stroke-width="6"/><path d="M12 -26 L30 -10 L8 -4 Z" fill="{PURPLE}"/>'),
    'up': ('Price up', f'<path d="M-18 8 L0 -14 L18 8 Z" fill="{RED}"/><rect x="-6" y="8" width="12" height="16" fill="{RED}"/>'),
    'down': ('Price down', f'<path d="M-18 -8 L0 14 L18 -8 Z" fill="{GREEN}"/><rect x="-6" y="-24" width="12" height="16" fill="{GREEN}"/>'),
    'road': ('Road', f'<path d="M-30 0 H30" stroke="#6b5a3e" stroke-width="16" fill="none"/><path d="M-30 0 H30" stroke="#e7d9b5" stroke-width="9" fill="none"/>'),
    'stump': ('No road (broken stump)', f'<path d="M-30 0 H-14" stroke="#6b5a3e" stroke-width="16" fill="none"/><path d="M-30 0 H-14" stroke="#e7d9b5" stroke-width="9" fill="none"/><circle cx="-6" cy="8" r="2" fill="#6b5a3e"/>'),
}

# Which icons make up each mission condition (see build_design.py)
MISSION_ICON = {
    'extractors': ['extractor'], 'factories': ['factory'], 'towers': ['tower'], 'buildings': ['building'],
    'bots': ['bot'], 'mechs': ['mech'], 'pieces': ['bot', 'mech'], 'iron_tokens': ['iron'],
    'crystal_tokens': ['crystal'], 'tokens': ['iron', 'crystal'], 'hexes': ['hex'], 'double_tiles': ['hex2'],
    'ports': ['port'], 'center': ['centre'], 'credits': ['credit'],
}


def icon_group(name, cx=0, cy=0, size=64):
    """Icon as an SVG group centred on (cx, cy), `size` px square."""
    k = size / 64
    return (f'<g transform="translate({cx:.1f},{cy:.1f}) scale({k:.3f})" stroke="{INK}" stroke-width="3" stroke-linejoin="round" '
            f'stroke-linecap="round" fill="none">{ICONS[name][1]}</g>')


def icon_svg(name, size=64):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="-34 -34 68 68" width="{size}" height="{size}">'
            f'<title>{ICONS[name][0]}</title>{icon_group(name, 0, 0, 68)}</svg>')


def mission_icon(mtype, cx, cy, size):
    names = MISSION_ICON[mtype]
    if len(names) == 1:
        return icon_group(names[0], cx, cy, size)
    return icon_group(names[0], cx - size * 0.26, cy + size * 0.1, size * 0.62) + icon_group(names[1], cx + size * 0.26, cy - size * 0.04, size * 0.62)
