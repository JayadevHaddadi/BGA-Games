"""Generate candidate Kiln page backgrounds bg_A..bg_D.webp (1920x1080, calm, low contrast, < 120 KB each).
Run: python3 games/KILN/design/make_backgrounds.py"""
import math, os, random
import numpy as np
from PIL import Image, ImageDraw, ImageFilter
from scipy.ndimage import gaussian_filter

W, H = 1920, 1080
OUT = os.path.dirname(os.path.abspath(__file__))
rng = np.random.default_rng(7)
random.seed(7)

def noise(sigma, shape=(H, W)):
    n = gaussian_filter(rng.standard_normal(shape), sigma)
    return n / (np.abs(n).max() + 1e-9)

def to_img(a):
    return Image.fromarray(np.clip(a, 0, 255).astype(np.uint8), 'RGB')

def save(img, name, limit=118_000):
    path = os.path.join(OUT, name)
    for q in (82, 74, 66, 58, 50, 42, 34):
        img.save(path, 'WEBP', quality=q, method=6)
        if os.path.getsize(path) < limit:
            break
    print(name, os.path.getsize(path), 'bytes q=%d' % q)

def tint(base, amp_layers):
    a = np.ones((H, W, 3)) * np.array(base, float)
    for n, amp in amp_layers:
        a += n[..., None] * np.array(amp, float)
    return a

# A: cream studio wall with faint hand-drawn pots
def bg_a():
    a = tint((247, 238, 222), [(noise(60), (4, 4, 5)), (noise(3), (1.5, 1.5, 1.5))])
    img = to_img(a)
    S = 2
    layer = Image.new('RGBA', (W * S, H * S), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    cols = [(214, 170, 140, 120), (176, 196, 180, 120), (205, 185, 215, 100), (232, 190, 150, 120)]
    def wob(pts):
        return [(x + random.uniform(-3, 3), y + random.uniform(-3, 3)) for x, y in pts]
    def pot(cx, cy, s, kind, col):
        pts = []
        for i in range(0, 41):  # right profile, top to bottom
            t = i / 40
            if kind == 0:   # round pot
                r = 0.55 * math.sin(math.pi * (0.1 + 0.85 * t)) + 0.15
            elif kind == 1: # tall vase
                r = 0.28 + 0.22 * math.sin(math.pi * t * 1.6) * (1 - t * 0.3)
            else:           # jug / narrow neck
                r = 0.18 + 0.4 * math.sin(math.pi * min(1, t * 1.1)) ** 2 if t > 0.25 else 0.17
            pts.append((r * s, (t - 0.5) * 1.5 * s))
        left = [(-x, y) for x, y in reversed(pts)]
        poly = [(cx + x, cy + y) for x, y in pts + left]
        poly = [(x * S, y * S) for x, y in wob(poly)]
        d.line(poly + [poly[0]], fill=col, width=5 * S, joint='curve')
        top = cy - 0.75 * s
        w = pts[0][0]
        d.ellipse([(cx - w) * S, (top - 6) * S, (cx + w) * S, (top + 6) * S], outline=col, width=4 * S)
        # a little decoration band
        yb = cy + 0.1 * s
        d.line([((cx - 0.4 * s) * S, yb * S), ((cx + 0.4 * s) * S, (yb + 4) * S)], fill=col, width=3 * S)
        for k in range(5):
            xx = cx - 0.3 * s + k * 0.15 * s
            d.ellipse([xx * S - 4 * S, (yb + 0.22 * s) * S - 4 * S, xx * S + 4 * S, (yb + 0.22 * s) * S + 4 * S], outline=col, width=3 * S)
    gx, gy = 300, 270
    for j in range(5):
        for i in range(7):
            cx = 120 + i * gx + (gx / 2 if j % 2 else 0) + random.uniform(-25, 25)
            cy = 100 + j * gy + random.uniform(-20, 20)
            pot(cx, cy, random.uniform(80, 110), random.randint(0, 2), random.choice(cols))
    layer = layer.resize((W, H), Image.LANCZOS).filter(ImageFilter.GaussianBlur(0.8))
    img = img.convert('RGBA')
    img.alpha_composite(layer)
    save(img.convert('RGB'), 'bg_A.webp')

# B: pastel gingham-like checks, big soft squares
def bg_b():
    sq = 135
    yy, xx = np.mgrid[0:H, 0:W]
    cx = (xx // sq) % 2
    cy = (yy // sq) % 2
    cream = np.array((252, 246, 238), float)
    peach = np.array((250, 218, 200), float)
    mint = np.array((208, 236, 222), float)
    a = np.where(((cx + cy) % 2 == 0)[..., None], cream, peach)
    a = a.copy()
    both = ((cx == 1) & (cy == 1))
    a[both] = mint
    # soft fabric feel and gentle vignette
    a += (noise(1.2) * 3)[..., None] + (noise(80) * 4)[..., None]
    img = to_img(a).filter(ImageFilter.GaussianBlur(1.6))
    save(img, 'bg_B.webp')

# C: watercolour sky + clay blobs
def bg_c():
    n1, n2, n3 = noise(140), noise(50), noise(10)
    a = tint((232, 241, 247), [(n1, (14, 10, 4)), (n2, (6, 5, 3)), (n3, (2, 2, 1.5))])
    yy, xx = np.mgrid[0:H, 0:W]
    a += (yy / H)[..., None] * np.array((12, 6, -2))  # cream towards the bottom
    img = to_img(a).convert('RGBA')
    S = 1
    layer = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    clay = [(226, 176, 146, 70), (236, 196, 160, 70), (214, 160, 134, 55), (240, 214, 184, 80)]
    for _ in range(16):
        cx, cy = random.uniform(0, W), random.uniform(0, H)
        r = random.uniform(70, 190)
        pts = []
        k = random.uniform(0, 6)
        for i in range(60):
            t = i / 60 * 2 * math.pi
            rr = r * (1 + 0.18 * math.sin(3 * t + k) + 0.1 * math.sin(5 * t + 2 * k))
            pts.append((cx + rr * math.cos(t), cy + rr * 0.8 * math.sin(t)))
        d.polygon(pts, fill=random.choice(clay))
    layer = layer.filter(ImageFilter.GaussianBlur(22))
    img.alpha_composite(layer)
    # watercolour paper grain
    arr = np.asarray(img.convert('RGB'), float) + (noise(1.0) * 3)[..., None]
    save(to_img(arr), 'bg_C.webp')

# D: honey cartoon wood, rounded planks
def bg_d():
    base = np.array((243, 214, 160), float)
    a = np.ones((H, W, 3)) * base
    img = to_img(a).convert('RGBA')
    d = ImageDraw.Draw(img, 'RGBA')
    ph = 135
    rows = H // ph + 1
    shades = [(244, 216, 164), (240, 208, 152), (246, 222, 172), (238, 204, 148)]
    for r in range(rows):
        y0 = r * ph
        x = -random.randint(0, 300)
        while x < W:
            pw = random.randint(380, 640)
            col = random.choice(shades)
            d.rounded_rectangle([x + 5, y0 + 5, x + pw - 5, y0 + ph - 5], radius=34, fill=col + (255,), outline=(222, 184, 126, 255), width=4)
            # a few soft, rounded grain squiggles
            for _ in range(2):
                gx = random.randint(x + 60, x + pw - 160)
                gy = random.randint(y0 + 30, y0 + ph - 30)
                gl = random.randint(60, 130)
                d.arc([gx, gy - 8, gx + gl, gy + 8], 180, 360, fill=(226, 190, 134, 200), width=4)
            # plank knots as round dots
            if random.random() < 0.35:
                kx, ky = random.randint(x + 70, x + pw - 70), random.randint(y0 + 40, y0 + ph - 40)
                d.ellipse([kx - 11, ky - 8, kx + 11, ky + 8], outline=(222, 184, 126, 255), width=4)
            x += pw
    arr = np.asarray(img.convert('RGB'), float) + (noise(1.0) * 2)[..., None]
    save(to_img(arr).filter(ImageFilter.GaussianBlur(0.7)), 'bg_D.webp')

if __name__ == '__main__':
    bg_a(); bg_b(); bg_c(); bg_d()
