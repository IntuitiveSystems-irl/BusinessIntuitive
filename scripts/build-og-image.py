#!/usr/bin/env python3
"""
Generates branded 1200x630 Open Graph images with Pillow (no browser needed).

Usage:
  python3 scripts/build-og-image.py                       # default brand card
  python3 scripts/build-og-image.py --out public/assets/og-portfolio.png \
      --eyebrow "PORTFOLIO VALUE CREATION" \
      --title "Digital value creation for your portfolio." \
      --sub "Dashboards · automation · internal tools — without the FTE add"
"""
import argparse
import os
from PIL import Image, ImageDraw, ImageFont, ImageFilter

W, H = 1200, 630
BG = (11, 12, 14)
TEAL = (22, 162, 174)
TEAL_DEEP = (15, 111, 120)
LIME = (200, 255, 90)
WHITE = (240, 244, 245)
GREY = (150, 165, 170)

FONT_CANDIDATES_SERIF = [
    "/System/Library/Fonts/Supplemental/Georgia Bold.ttf",
    "/System/Library/Fonts/Supplemental/Georgia.ttf",
    "/Library/Fonts/Georgia.ttf",
]
FONT_CANDIDATES_SANS = [
    "/System/Library/Fonts/Supplemental/Arial.ttf",
    "/System/Library/Fonts/Helvetica.ttc",
    "/System/Library/Fonts/SFNS.ttf",
]
FONT_CANDIDATES_MONO = [
    "/System/Library/Fonts/Menlo.ttc",
    "/System/Library/Fonts/Supplemental/Courier New.ttf",
]


def load_font(candidates, size):
    for p in candidates:
        if os.path.exists(p):
            try:
                return ImageFont.truetype(p, size)
            except Exception:
                continue
    return ImageFont.load_default()


def radial_glow(color, center, radius, alpha):
    """Soft circular glow on a transparent layer."""
    layer = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    cx, cy = center
    d.ellipse([cx - radius, cy - radius, cx + radius, cy + radius],
              fill=color + (alpha,))
    return layer.filter(ImageFilter.GaussianBlur(radius // 2))


def wrap(draw, text, font, max_w):
    words, lines, cur = text.split(), [], ""
    for w in words:
        t = (cur + " " + w).strip()
        if draw.textlength(t, font=font) <= max_w:
            cur = t
        else:
            if cur:
                lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines


def build(out, eyebrow, title, sub):
    img = Image.new("RGB", (W, H), BG)
    img = Image.alpha_composite(img.convert("RGBA"),
                                radial_glow(TEAL, (1010, -40), 460, 90))
    img = Image.alpha_composite(img, radial_glow(TEAL_DEEP, (-60, 690), 380, 70))
    img = img.convert("RGB")
    d = ImageDraw.Draw(img)

    pad = 86
    f_eye = load_font(FONT_CANDIDATES_MONO, 26)
    f_title = load_font(FONT_CANDIDATES_SERIF, 70)
    f_sub = load_font(FONT_CANDIDATES_SANS, 31)
    f_brand = load_font(FONT_CANDIDATES_SANS, 30)

    # eyebrow (letter-spaced)
    y = 92
    ex = pad
    for ch in eyebrow.upper():
        d.text((ex, y), ch, font=f_eye, fill=TEAL)
        ex += d.textlength(ch, font=f_eye) + 4

    # title (serif, wrapped)
    y = 168
    for line in wrap(d, title, f_title, W - pad * 2):
        d.text((pad, y), line, font=f_title, fill=WHITE)
        y += 84

    # accent line
    y += 14
    d.rounded_rectangle([pad, y, pad + 96, y + 7], radius=4, fill=TEAL)
    d.ellipse([pad + 108, y - 1, pad + 117, y + 8], fill=LIME)

    # subline
    y += 36
    for line in wrap(d, sub, f_sub, W - pad * 2):
        d.text((pad, y), line, font=f_sub, fill=GREY)
        y += 42

    # brand lockup bottom
    by = H - 92
    d.rounded_rectangle([pad, by, pad + 8, by + 40], radius=3, fill=TEAL)
    d.ellipse([pad + 2, by - 12, pad + 6, by - 8], fill=LIME)
    d.text((pad + 26, by + 2), "Business Intuitive", font=f_brand, fill=WHITE)

    os.makedirs(os.path.dirname(out), exist_ok=True)
    img.save(out, "PNG", optimize=True)
    print(f"Wrote {out} ({os.path.getsize(out)//1024} KB, {W}x{H})")


if __name__ == "__main__":
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=os.path.join(root, "public/assets/og-image.png"))
    ap.add_argument("--eyebrow", default="businessintuitive.tech")
    ap.add_argument("--title", default="The system your business should already have.")
    ap.add_argument("--sub", default="Custom web systems, dashboards & automation for founder-led businesses.")
    a = ap.parse_args()
    build(a.out, a.eyebrow, a.title, a.sub)
