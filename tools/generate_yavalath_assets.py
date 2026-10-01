#!/usr/bin/env python3
"""
Generate release-ready metadata assets for Yavalath (Cameron Browne / nestorgames)
per Board Game Arena (BGA) specifications.

Requirements:
- Box: 280x280 PNG (transparent background)
- Icon: 50x50 PNG (and 500x500 high-res)
- Banner: 1386x400 JPG (strictly NO text or logos)
- Publisher: 280x280 PNG (transparent background)
- Display: 900x600 JPG (height 400-760, width <= 1.5*height)
- Title: 2000x2000 JPG (high-res hero artwork)
"""

import os
import math
from PIL import Image, ImageDraw, ImageFont, ImageFilter

OUTPUT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "games", "yavalath", "bga", "metadata_assets"))
os.makedirs(OUTPUT_DIR, exist_ok=True)

def draw_hexagon(draw, center, radius, fill, outline, width=2):
    cx, cy = center
    points = []
    for i in range(6):
        angle_deg = 60 * i - 30
        angle_rad = math.radians(angle_deg)
        x = cx + radius * math.cos(angle_rad)
        y = cy + radius * math.sin(angle_rad)
        points.append((x, y))
    draw.polygon(points, fill=fill, outline=outline, width=width)

def generate_box():
    # 280x280 RGBA transparent
    img = Image.new("RGBA", (280, 280), (0, 0, 0, 0))

    # Soft ambient drop shadow
    shadow = Image.new("RGBA", (280, 280), (0, 0, 0, 0))
    sdraw = ImageDraw.Draw(shadow)
    sdraw.ellipse((32, 42, 248, 258), fill=(0, 0, 0, 120))
    shadow = shadow.filter(ImageFilter.GaussianBlur(12))
    img.paste(shadow, (0, 0), shadow)

    draw = ImageDraw.Draw(img)
    # Slate/Stone disc gradient
    for r in range(106, 0, -1):
        ratio = r / 106.0
        val = int(28 + (1.0 - ratio) * 44)
        draw.ellipse((140 - r, 140 - r, 140 + r, 140 + r), fill=(val, val + 3, val + 6, 255))

    # Outer border: emerald / cyan
    draw.ellipse((34, 34, 246, 246), outline=(46, 175, 125, 240), width=4)
    draw.ellipse((41, 41, 239, 239), outline=(80, 210, 160, 180), width=2)

    # 4 connected dots (representing 4 in a row)
    for dy in [-45, -15, 15, 45]:
        draw.ellipse((140 - 13, 140 + dy - 13, 140 + 13, 140 + dy + 13), fill=(248, 248, 250), outline=(46, 175, 125), width=2)

    # Title "YAVALATH"
    try:
        font = ImageFont.truetype("DejaVuSans-Bold.ttf", 26)
        sub_font = ImageFont.truetype("DejaVuSans.ttf", 12)
    except:
        try:
            font = ImageFont.truetype("arial.ttf", 26)
            sub_font = ImageFont.truetype("arial.ttf", 12)
        except:
            font = ImageFont.load_default()
            sub_font = ImageFont.load_default()

    t_bbox = draw.textbbox((0, 0), "YAVALATH", font=font)
    tw = t_bbox[2] - t_bbox[0]
    draw.text((140 - tw/2, 58), "YAVALATH", font=font, fill=(255, 255, 255))

    credit = "BROWNE & LUDI"
    c_bbox = draw.textbbox((0, 0), credit, font=sub_font)
    cw = c_bbox[2] - c_bbox[0]
    draw.text((140 - cw/2, 204), credit, font=sub_font, fill=(180, 225, 205))

    for fname in ["box.png", "box_280x280.png"]:
        box_path = os.path.join(OUTPUT_DIR, fname)
        img.save(box_path, "PNG")
    print(f"Generated box.png (280x280)")

def generate_icon():
    for size, filenames in [(50, ["icon.png", "icon_50x50.png"]), (500, ["icon_500x500.png"])]:
        img = Image.new("RGBA", (size, size), (20, 26, 23, 255))
        draw = ImageDraw.Draw(img)

        pad = size * 0.08
        draw_hexagon(draw, (size / 2, size / 2), size / 2 - pad, fill=(30, 42, 36, 255), outline=(46, 175, 125, 255), width=max(1, int(size * 0.04)))

        # Letter Y
        font_size = int(size * 0.55)
        try:
            font = ImageFont.truetype("DejaVuSans-Bold.ttf", font_size)
        except:
            try:
                font = ImageFont.truetype("arial.ttf", font_size)
            except:
                font = ImageFont.load_default()
        text = "Y"
        bbox = draw.textbbox((0, 0), text, font=font)
        w = bbox[2] - bbox[0]
        h = bbox[3] - bbox[1]
        draw.text((size/2 - w/2, size/2 - h/2 - size*0.04), text, font=font, fill=(80, 220, 160, 255))

        for fname in filenames:
            out_path = os.path.join(OUTPUT_DIR, fname)
            img.save(out_path, "PNG")
    print("Generated icon.png (50x50) and icon_500x500.png")

def generate_banner():
    # 1386x400 JPG strictly WITHOUT any text or logos (BGA overlays title & player rankings)
    w, h = 1386, 400
    img = Image.new("RGB", (w, h), (18, 24, 22))
    draw = ImageDraw.Draw(img)

    # Ambient background radial gradient
    for y in range(h):
        for x in range(0, w, 4):
            dx = (x - w / 2) / (w / 2)
            dy = (y - h / 2) / (h / 2)
            dist = math.sqrt(dx*dx + dy*dy)
            factor = max(0.0, 1.0 - dist * 0.75)
            r = int(14 + factor * 22)
            g = int(20 + factor * 30)
            b = int(18 + factor * 26)
            draw.rectangle([x, y, x + 3, y], fill=(r, g, b))

    # Hexagonal grid across canvas
    hex_size = 36
    for q in range(-12, 28):
        for r_idx in range(-6, 10):
            cx = 300 + hex_size * (math.sqrt(3) * q + (math.sqrt(3)/2) * r_idx)
            cy = 200 + hex_size * (1.5 * r_idx)
            if -60 <= cx <= w + 60 and -60 <= cy <= h + 60:
                dist = math.hypot(cx - w/2, cy - h/2)
                alpha_factor = max(0.12, 1.0 - dist / 750.0)
                outline_color = (int(38 * alpha_factor), int(68 * alpha_factor), int(55 * alpha_factor))
                fill_color = (int(22 * alpha_factor), int(34 * alpha_factor), int(28 * alpha_factor))
                draw_hexagon(draw, (cx, cy), hex_size - 2, fill=fill_color, outline=outline_color, width=1)

    # Left visual: 4 connected white stones with subtle green winning aura (NO TEXT)
    start_x, start_y = 160, 200
    for i in range(4):
        x = start_x + i * 50
        y = start_y
        # Soft shadow
        draw.ellipse((x - 20 + 2, y - 20 + 3, x + 20 + 2, y + 20 + 3), fill=(5, 10, 8))
        # Winning aura
        draw.ellipse((x - 23, y - 23, x + 23, y + 23), outline=(46, 175, 125), width=2)
        # Stone
        draw.ellipse((x - 18, y - 18, x + 18, y + 18), fill=(245, 245, 248), outline=(180, 180, 185), width=1)
        # Specular shine
        draw.ellipse((x - 11, y - 12, x - 3, y - 6), fill=(255, 255, 255))

    # Right visual: 3 connected dark stones with subtle red warning aura (NO TEXT)
    start_rx, start_ry = 1040, 200
    for i in range(3):
        x = start_rx + i * 50
        y = start_ry
        # Soft shadow
        draw.ellipse((x - 20 + 2, y - 20 + 3, x + 20 + 2, y + 20 + 3), fill=(5, 10, 8))
        # Warning aura
        draw.ellipse((x - 23, y - 23, x + 23, y + 23), outline=(215, 45, 40), width=2)
        # Stone
        draw.ellipse((x - 18, y - 18, x + 18, y + 18), fill=(30, 32, 36), outline=(60, 62, 68), width=1)
        # Specular shine
        draw.ellipse((x - 11, y - 12, x - 3, y - 6), fill=(100, 105, 115))

    for fname in ["banner.jpg", "banner_1386x400.jpg"]:
        banner_path = os.path.join(OUTPUT_DIR, fname)
        img.save(banner_path, "JPEG", quality=94)
    print(f"Generated banner.jpg (1386x400, strictly NO text)")

def generate_publisher():
    # 280x280 transparent PNG for nestorgames
    img = Image.new("RGBA", (280, 280), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    # Stylized nestorgames red/white circular logo
    draw.ellipse((30, 30, 250, 250), fill=(215, 45, 40, 255), outline=(160, 25, 20, 255), width=3)
    draw.ellipse((42, 42, 238, 238), outline=(255, 255, 255, 200), width=2)

    try:
        n_font = ImageFont.truetype("DejaVuSans-Bold.ttf", 110)
        sub_font = ImageFont.truetype("DejaVuSans-Bold.ttf", 20)
    except:
        try:
            n_font = ImageFont.truetype("arial.ttf", 110)
            sub_font = ImageFont.truetype("arial.ttf", 20)
        except:
            n_font = ImageFont.load_default()
            sub_font = ImageFont.load_default()

    n_bbox = draw.textbbox((0, 0), "n", font=n_font)
    nw = n_bbox[2] - n_bbox[0]
    draw.text((140 - nw/2 - 2, 58), "n", font=n_font, fill=(255, 255, 255, 255))

    nestor_text = "nestorgames"
    sub_bbox = draw.textbbox((0, 0), nestor_text, font=sub_font)
    sw = sub_bbox[2] - sub_bbox[0]
    draw.text((140 - sw/2, 184), nestor_text, font=sub_font, fill=(255, 255, 255, 240))

    for fname in ["publisher.png", "publisher_280x280.png"]:
        pub_path = os.path.join(OUTPUT_DIR, fname)
        img.save(pub_path, "PNG")
    print(f"Generated publisher.png (280x280)")

def generate_display():
    # 900x600 JPG screenshot preview for BGA gallery
    w, h = 900, 600
    img = Image.new("RGB", (w, h), (242, 235, 224))
    draw = ImageDraw.Draw(img)

    # Outer mat / wooden rim
    draw.rectangle([20, 20, w - 20, h - 20], fill=(237, 230, 214), outline=(194, 181, 159), width=2)

    cx, cy = w / 2, h / 2
    hex_size = 28
    radius = 4

    # Hex cells
    for q in range(-radius, radius + 1):
        for r in range(-radius, radius + 1):
            if -radius <= q + r <= radius:
                x = cx + hex_size * (math.sqrt(3) * q + (math.sqrt(3) / 2) * r)
                y = cy + hex_size * (1.5 * r)
                draw_hexagon(draw, (x, y), hex_size - 1, fill=(250, 246, 237), outline=(210, 196, 174), width=1)
                draw.ellipse((x - 2, y - 2, x + 2, y + 2), fill=(180, 168, 150))

    # Sample stones on board to show exciting game state
    stones = [
        (0, 0, 'white'), (1, 0, 'white'), (2, 0, 'white'), (3, 0, 'white'), # 4 in a row win!
        (0, 1, 'black'), (-1, 1, 'black'), (0, -1, 'black'), (-1, 0, 'black'),
        (1, -1, 'black'), (-2, 2, 'white'), (0, 2, 'white'), (-1, 2, 'white')
    ]

    for q, r, color in stones:
        x = cx + hex_size * (math.sqrt(3) * q + (math.sqrt(3) / 2) * r)
        y = cy + hex_size * (1.5 * r)
        sr = int(hex_size * 0.72)
        # Drop shadow
        draw.ellipse((x - sr + 2, y - sr + 4, x + sr + 2, y + sr + 4), fill=(0, 0, 0, 70))
        if color == 'white':
            draw.ellipse((x - sr, y - sr, x + sr, y + sr), fill=(248, 246, 240), outline=(170, 160, 146), width=1)
            # Specular shine
            draw.ellipse((x - sr*0.5, y - sr*0.5, x - sr*0.1, y - sr*0.2), fill=(255, 255, 255))
        else:
            draw.ellipse((x - sr, y - sr, x + sr, y + sr), fill=(24, 24, 24), outline=(10, 10, 10), width=1)
            # Specular shine
            draw.ellipse((x - sr*0.5, y - sr*0.5, x - sr*0.1, y - sr*0.2), fill=(100, 100, 100))

    # Highlight winning line
    for q in range(4):
        x = cx + hex_size * (math.sqrt(3) * q + (math.sqrt(3) / 2) * 0)
        y = cy
        draw.ellipse((x - hex_size * 0.82, y - hex_size * 0.82, x + hex_size * 0.82, y + hex_size * 0.82), outline=(46, 125, 50), width=3)

    try:
        font = ImageFont.truetype("DejaVuSans-Bold.ttf", 20)
    except:
        try:
            font = ImageFont.truetype("arial.ttf", 20)
        except:
            font = ImageFont.load_default()

    draw.text((40, 35), "YAVALATH — Official nestorgames Edition", font=font, fill=(60, 60, 60))
    display_path = os.path.join(OUTPUT_DIR, "display.jpg")
    img.save(display_path, "JPEG", quality=92)
    print(f"Generated display.jpg (900x600)")

def generate_title():
    # 2000x2000 Ultra high-resolution presentation artwork
    size = 2000
    img = Image.new("RGB", (size, size), (16, 22, 19))
    draw = ImageDraw.Draw(img)

    # Deep radial gradient
    for r in range(1200, 0, -8):
        factor = 1.0 - (r / 1200.0)
        rv = int(14 + factor * 28)
        gv = int(20 + factor * 42)
        bv = int(18 + factor * 32)
        draw.ellipse((1000 - r, 1000 - r, 1000 + r, 1000 + r), fill=(rv, gv, bv))

    # Large board rendered in center
    cx, cy = 1000, 1100
    hex_size = 90
    radius = 4

    for q in range(-radius, radius + 1):
        for r in range(-radius, radius + 1):
            if -radius <= q + r <= radius:
                x = cx + hex_size * (math.sqrt(3) * q + (math.sqrt(3) / 2) * r)
                y = cy + hex_size * (1.5 * r)
                draw_hexagon(draw, (x, y), hex_size - 4, fill=(244, 238, 226), outline=(195, 180, 155), width=3)
                draw.ellipse((x - 6, y - 6, x + 6, y + 6), fill=(175, 160, 140))

    # Show stones in play
    sample_stones = [
        (0, 0, 'white'), (1, 0, 'white'), (2, 0, 'white'), (3, 0, 'white'),
        (0, 1, 'black'), (-1, 1, 'black'), (0, -1, 'black'), (-1, 0, 'black'),
        (1, -1, 'black'), (-2, 2, 'white'), (0, 2, 'white'), (-1, 2, 'white'),
        (-2, 0, 'red'), (-1, -1, 'red'), (-2, 1, 'red')
    ]

    for q, r, color in sample_stones:
        x = cx + hex_size * (math.sqrt(3) * q + (math.sqrt(3) / 2) * r)
        y = cy + hex_size * (1.5 * r)
        sr = int(hex_size * 0.73)
        # Deep shadow
        draw.ellipse((x - sr + 8, y - sr + 14, x + sr + 8, y + sr + 14), fill=(5, 8, 7))
        if color == 'white':
            draw.ellipse((x - sr, y - sr, x + sr, y + sr), fill=(248, 248, 252), outline=(160, 155, 145), width=3)
            draw.ellipse((x - sr*0.5, y - sr*0.5, x - sr*0.1, y - sr*0.2), fill=(255, 255, 255))
        elif color == 'red':
            draw.ellipse((x - sr, y - sr, x + sr, y + sr), fill=(215, 45, 40), outline=(130, 20, 15), width=3)
            draw.ellipse((x - sr*0.5, y - sr*0.5, x - sr*0.1, y - sr*0.2), fill=(255, 140, 140))
        else:
            draw.ellipse((x - sr, y - sr, x + sr, y + sr), fill=(24, 25, 28), outline=(10, 10, 12), width=3)
            draw.ellipse((x - sr*0.5, y - sr*0.5, x - sr*0.1, y - sr*0.2), fill=(90, 95, 105))

    # Glow around winning 4 stones
    for q in range(4):
        x = cx + hex_size * (math.sqrt(3) * q + (math.sqrt(3) / 2) * 0)
        y = cy
        draw.ellipse((x - hex_size * 0.85, y - hex_size * 0.85, x + hex_size * 0.85, y + hex_size * 0.85), outline=(46, 175, 125), width=8)

    # Typography at top
    try:
        title_font = ImageFont.truetype("DejaVuSans-Bold.ttf", 150)
        sub_font = ImageFont.truetype("DejaVuSans-Bold.ttf", 40)
        tag_font = ImageFont.truetype("DejaVuSans.ttf", 36)
    except:
        try:
            title_font = ImageFont.truetype("arial.ttf", 150)
            sub_font = ImageFont.truetype("arial.ttf", 40)
            tag_font = ImageFont.truetype("arial.ttf", 36)
        except:
            title_font = ImageFont.load_default()
            sub_font = ImageFont.load_default()
            tag_font = ImageFont.load_default()

    t_text = "YAVALATH"
    tb = draw.textbbox((0, 0), t_text, font=title_font)
    tw = tb[2] - tb[0]
    draw.text((size/2 - tw/2 + 5, 175 + 5), t_text, font=title_font, fill=(0, 0, 0))
    draw.text((size/2 - tw/2, 175), t_text, font=title_font, fill=(80, 220, 160))

    s_text = "BY CAMERON BROWNE & LUDI • PUBLISHED BY NESTORGAMES"
    sb = draw.textbbox((0, 0), s_text, font=sub_font)
    sw = sb[2] - sb[0]
    draw.text((size/2 - sw/2, 335), s_text, font=sub_font, fill=(200, 215, 210))

    tag_text = "Connect 4 to WIN — Connect 3 to LOSE"
    tag_b = draw.textbbox((0, 0), tag_text, font=tag_font)
    tag_w = tag_b[2] - tag_b[0]
    draw.text((size/2 - tag_w/2, 395), tag_text, font=tag_font, fill=(240, 240, 240))

    title_path = os.path.join(OUTPUT_DIR, "title.jpg")
    img.save(title_path, "JPEG", quality=92)
    print(f"Generated title.jpg (2000x2000)")

if __name__ == "__main__":
    generate_box()
    generate_icon()
    generate_banner()
    generate_publisher()
    generate_display()
    generate_title()
    print("All Yavalath metadata assets generated successfully!")
