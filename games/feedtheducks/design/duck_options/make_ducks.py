import os
OUT='/home/jayadevhaddadi/GitHub/Mandala-helper/games/feedtheducks/design/duck_options'
C={ # fill, outline, shade(darker), light(highlight), mark colour, eye
 'white':dict(f='#f4f1ea',o='#33404d',s='#d9d3c6',m='#33404d',eye='#20242b'),
 'green':dict(f='#2e9d3a',o='#0f3d14',s='#237a2d',m='#ffffff',eye='#20242b'),
 'red':dict(f='#d63a34',o='#4d0f0d',s='#a82723',m='#ffffff',eye='#20242b'),
 'blue':dict(f='#2a62d6',o='#0c1f55',s='#1d49a8',m='#ffffff',eye='#f4f1ea'),
}
BEAK='#ff9f1c'; BEAKO='#8a4b00'
def svg(body,defs=''):
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100"><defs>{defs}</defs>{body}</svg>\n'
def mark(name,cx,cy,r,col):
    if name=='white': return f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{col}"/>'
    if name=='green': return f'<rect x="{cx-r*.9}" y="{cy-r*.9}" width="{r*1.8}" height="{r*1.8}" fill="{col}"/>'
    if name=='red': return f'<path d="M{cx} {cy-r*1.1} L{cx+r*1.05} {cy+r*.8} L{cx-r*1.05} {cy+r*.8}Z" fill="{col}"/>'
    return f'<path d="M{cx} {cy-r*1.15} L{cx+r*1.15} {cy} L{cx} {cy+r*1.15} L{cx-r*1.15} {cy}Z" fill="{col}"/>'
def eyeg(cx,cy,r,c,sw=0):
    gl='#fff' if c['eye']!='#f4f1ea' else '#20242b'
    return f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{c["eye"]}"/><circle cx="{cx-r*.35}" cy="{cy-r*.35}" r="{r*.35}" fill="{gl}"/>'

# ---- side duck shared (rubber-duck proportions), facing left
def side(c,extra='',pat=None,sw=3):
    fill=c['f'] if not pat else c['f']
    b=f'''<g stroke="{c['o']}" stroke-width="{sw}" stroke-linejoin="round" stroke-linecap="round">
<path d="M80 40 C90 36 95 30 93 26 C88 30 84 31 78 33 Z" fill="{c['f']}"/>
<path d="M12 62 C12 44 28 40 42 46 C58 38 80 40 90 34 C92 48 90 82 56 84 C28 85 12 78 12 62Z" fill="{c['f']}"/>
<circle cx="33" cy="34" r="20" fill="{c['f']}"/>
<path d="M17 40 C6 36 4 44 7 46 C9 50 16 49 20 47Z" fill="{BEAK}" stroke="{BEAKO}" stroke-width="2.2"/>
</g>
<path d="M44 62 C54 74 72 72 80 56 C70 60 56 58 44 62Z" fill="{c['s']}" stroke="{c['o']}" stroke-width="2.2" stroke-linejoin="round"/>
{eyeg(29,30,4.2,c)}{extra}'''
    return b
def shine(c):
    col='#fff' if c['f']!='#f4f1ea' else '#fff'
    op='.75' if c['f'] not in('#f4f1ea',) else '1'
    return f'<path d="M23 24 C26 19 31 17 35 18" stroke="{col}" stroke-width="3.4" fill="none" stroke-linecap="round" opacity=".8"/><path d="M20 66 C24 74 30 77 36 78" stroke="{col}" stroke-width="3" fill="none" stroke-linecap="round" opacity=".55"/>'

def A(n,c):
    return svg(side(c,shine(c)))
def feet_water(c):return ''

# B kawaii: front view blob
def B(n,c):
    blush='#ff8fb1'
    sh='#ffffff'
    b=f'''<g stroke="{c['o']}" stroke-width="3" stroke-linejoin="round" stroke-linecap="round">
<path d="M50 6 C49 3 55 2 56 6 C55 9 52 10 50 10Z" fill="{c['f']}" stroke-width="2.4" transform="translate(-2 4)"/>
<ellipse cx="14" cy="62" rx="9" ry="12" transform="rotate(25 14 62)" fill="{c['s']}"/>
<ellipse cx="86" cy="62" rx="9" ry="12" transform="rotate(-25 86 62)" fill="{c['s']}"/>
<path d="M50 10 C78 10 90 32 90 56 C90 80 74 92 50 92 C26 92 10 80 10 56 C10 32 22 10 50 10Z" fill="{c['f']}"/>
<ellipse cx="42" cy="95" rx="9" ry="4" fill="{BEAK}" stroke="{BEAKO}" stroke-width="2" opacity="0"/>
</g>
<ellipse cx="25" cy="64" rx="7" ry="4.2" fill="{blush}" opacity=".85"/><ellipse cx="75" cy="64" rx="7" ry="4.2" fill="{blush}" opacity=".85"/>
<g stroke="{c['o']}" stroke-width="2.2"><path d="M50 52 C41 52 41 66 50 67 C59 66 59 52 50 52Z" fill="{BEAK}" stroke="{BEAKO}"/></g>
<path d="M44 59 H56" stroke="{BEAKO}" stroke-width="1.6" stroke-linecap="round"/>
{eyeg(32,47,8,c)}{eyeg(68,47,8,c)}
<circle cx="35.5" cy="50.5" r="2" fill="#fff" opacity=".9"/><circle cx="71.5" cy="50.5" r="2" fill="#fff" opacity=".9"/>
<path d="M30 22 C36 14 44 13 50 14" stroke="#fff" stroke-width="3.4" fill="none" stroke-linecap="round" opacity=".7"/>
<path d="M44 14 C40 6 47 3 49 9 C52 3 58 8 53 14" fill="{c['f']}" stroke="{c['o']}" stroke-width="2.4" stroke-linejoin="round" opacity="0"/>
'''
    return svg(b)

# C geometric top-down w/ symbol
def C_(n,c):
    mk='#ffd23f' if n in('green',) else c['m']
    mk=c['m']
    b=f'''<g stroke="{c['o']}" stroke-width="3.5" stroke-linejoin="round">
<path d="M70 38 L97 50 L70 62Z" fill="{c['s']}"/>
<ellipse cx="58" cy="50" rx="36" ry="30" fill="{c['f']}"/>
<circle cx="25" cy="50" r="17" fill="{c['f']}"/>
<path d="M10 40 L-1 50 L10 60Z" fill="{BEAK}" stroke="{BEAKO}" transform="translate(1 0)"/>
</g>
<path d="M44 26 L68 50 L44 74" fill="none" stroke="{c['s']}" stroke-width="0"/>
{mark(n,56,50,12,mk)}
<circle cx="22" cy="42" r="3.2" fill="{c['eye']}"/><circle cx="22" cy="58" r="3.2" fill="{c['eye']}"/>
'''
    return svg(b)

# D top-down swimming w/ ripples and wake
def D(n,c):
    b=f"""<g fill="none" stroke="#fff" stroke-linecap="round">
<ellipse cx="50" cy="50" rx="46" ry="40" stroke-width="2.4" opacity=".9"/>
<ellipse cx="50" cy="50" rx="39" ry="33" stroke-width="1.6" opacity=".6"/>
<path d="M80 36 C88 32 94 26 98 18 M80 64 C88 68 94 74 98 82" stroke-width="3.2" opacity=".95"/>
</g>
<g stroke="{c['o']}" stroke-width="3" stroke-linejoin="round" stroke-linecap="round">
<path d="M76 50 L86 44 L84 50 L86 56Z" fill="{c['s']}" stroke-width="2.4"/>
<path d="M30 50 C30 36 44 30 58 33 C70 36 78 44 80 50 C78 56 70 64 58 67 C44 70 30 64 30 50Z" fill="{c['f']}"/>
<circle cx="29" cy="50" r="12" fill="{c['f']}"/>
<path d="M19 44 L9 50 L19 56Z" fill="{BEAK}" stroke="{BEAKO}" stroke-width="2.2"/>
<path d="M44 38 C56 38 68 44 74 50 C68 56 56 62 44 62 C50 56 50 44 44 38Z" fill="{c['s']}" stroke-width="2.4"/>
</g>
<path d="M54 45 C60 46 65 48 69 50 M54 55 C60 54 65 52 69 50" stroke="{c['o']}" stroke-width="1.6" fill="none" stroke-linecap="round" opacity=".6"/>
<circle cx="26" cy="45" r="2.8" fill="{c['eye']}"/><circle cx="26" cy="55" r="2.8" fill="{c['eye']}"/>
"""
    return svg(b)

# E accessory
def E(n,c):
    if n=='white': # sailor hat navy band, white cap
        acc=f'''<g stroke="#1b2a4a" stroke-width="2.4" stroke-linejoin="round"><path d="M16 26 C16 8 50 8 50 26 Z" fill="#fff" transform="rotate(-8 33 18)"/>
<path d="M15 25 L51 25 L50 19 L17 19Z" fill="#1d3f8f" transform="rotate(-8 33 22)"/><circle cx="33" cy="9.5" r="3" fill="#d63a34" stroke-width="1.8" transform="rotate(-8 33 18)"/></g>'''
    elif n=='green': # sunglasses
        acc='''<g stroke="#000" stroke-width="2" stroke-linejoin="round"><path d="M18 30 H38" stroke-width="3"/><rect x="14" y="26" width="13" height="10" rx="4" fill="#0b0d10" stroke="#ffd23f" stroke-width="1.8"/><rect x="30" y="26" width="13" height="10" rx="4" fill="#0b0d10" stroke="#ffd23f" stroke-width="1.8"/></g><path d="M16 29 L22 28 M32 29 L38 28" stroke="#9fd8ff" stroke-width="1.8" stroke-linecap="round"/>'''
    elif n=='red': # bow on head
        acc='''<g stroke="#5a3b00" stroke-width="2.2" stroke-linejoin="round"><path d="M33 14 C20 2 14 12 22 18 C27 20 31 17 33 14Z M33 14 C44 2 52 10 45 18 C40 20 36 17 33 14Z" fill="#ffd23f"/><circle cx="33" cy="14" r="4" fill="#ffb300"/></g>'''
    else: # crown
        acc='''<g stroke="#6b4a00" stroke-width="2.4" stroke-linejoin="round"><path d="M19 17 L21 3 L28 11 L34 1 L40 11 L47 3 L48 17Z" fill="#ffd23f" transform="rotate(-6 33 10)"/><circle cx="21" cy="4" r="2.2" fill="#d63a34" stroke-width="1.2" transform="rotate(-6 33 10)"/><circle cx="34" cy="2" r="2.2" fill="#d63a34" stroke-width="1.2" transform="rotate(-6 33 10)"/><circle cx="46" cy="4" r="2.2" fill="#d63a34" stroke-width="1.2" transform="rotate(-6 33 10)"/></g>'''
    if n=='green':
        base=side(c,'<path/>')  # eye hidden by shades
        base=base.replace(eyeg(29,30,4.2,c),'')
    else: base=side(c)
    return svg(base+acc)

# F felt plush
def F(n,c):
    defs=f'''<pattern id="st" width="4" height="4" patternUnits="userSpaceOnUse"><path d="M0 0 L4 4 M4 0 L0 4" stroke="#000" stroke-width=".5" opacity=".18"/></pattern>
<pattern id="fz" width="7" height="7" patternUnits="userSpaceOnUse"><circle cx="1.5" cy="2" r=".7" fill="#fff" opacity=".22"/><circle cx="5" cy="5.2" r=".6" fill="#000" opacity=".16"/><circle cx="5.5" cy="1" r=".5" fill="#fff" opacity=".18"/></pattern>'''
    paths={
     'tail':'M80 40 C90 36 95 30 93 26 C88 30 84 31 78 33 Z',
     'body':'M12 62 C12 44 28 40 42 46 C58 38 80 40 90 34 C92 48 90 82 56 84 C28 85 12 78 12 62Z'}
    stitch=f'#fff' if n!='white' else '#c9392f'
    if n=='white': stitch='#d9534f'
    def piece(d,extra=''):
        return f'<path d="{d}" fill="{c["f"]}" stroke="{c["o"]}" stroke-width="3" stroke-linejoin="round"/><path d="{d}" fill="url(#fz)"/><path d="{d}" fill="url(#st)"/>'
    head=f'<circle cx="33" cy="34" r="20" fill="{c["f"]}" stroke="{c["o"]}" stroke-width="3"/><circle cx="33" cy="34" r="20" fill="url(#fz)"/><circle cx="33" cy="34" r="20" fill="url(#st)"/>'
    b=piece(paths['tail'])+piece(paths['body'])+head
    b+=f'<circle cx="33" cy="34" r="15.5" fill="none" stroke="{stitch}" stroke-width="1.3" stroke-dasharray="3 2.6" stroke-linecap="round"/>'
    b+=f'<path d="M17 62 C17 49 29 46 42 51 C58 43 78 45 86 40 C87 52 84 78 56 79 C32 80 17 74 17 62Z" fill="none" stroke="{stitch}" stroke-width="1.3" stroke-dasharray="3 2.6" stroke-linecap="round"/>'
    b+=f'<path d="M17 40 C6 36 4 44 7 46 C9 50 16 49 20 47Z" fill="{BEAK}" stroke="{BEAKO}" stroke-width="2.4" stroke-linejoin="round"/><path d="M9 43 L17 43" stroke="{BEAKO}" stroke-width="1.4" stroke-dasharray="2 2"/>'
    b+=f'<path d="M44 62 C54 74 72 72 80 56 C70 60 56 58 44 62Z" fill="{c["s"]}" stroke="{c["o"]}" stroke-width="2.4" stroke-linejoin="round"/><path d="M44 62 C54 74 72 72 80 56 C70 60 56 58 44 62Z" fill="url(#st)"/>'
    b+=f'<circle cx="29" cy="30" r="3.6" fill="{c["eye"]}"/><circle cx="27.8" cy="28.8" r="1.2" fill="#fff"/>'
    b+=f'<circle cx="44" cy="40" r="4" fill="#ff8fb1" opacity=".7"/>'
    b+=mark(n,61,70,0,c['m']) if False else ''
    return svg(b,defs)

STY={'A_rubber':A,'B_kawaii':B,'C_geometric':C_,'D_swimming':D,'E_accessory':E,'F_felt':F}
for k,fn in STY.items():
    for n,c in C.items():
        open(f'{OUT}/duck_{k}_{n}.svg','w').write(fn(n,c))
