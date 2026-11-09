"""Writes the Beni Bul logo with the wordmark converted to paths, so it needs no font.

Needs fontTools (pip install fonttools). Run from the repo root:
    python brand/scripts/outline-logo.py

Output: brand/logo/*-outlined.svg and backend/public/img/beni-bul-logo*.svg (for the visitor page).
"""
from pathlib import Path

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

ROOT = Path(__file__).resolve().parents[2]
FONT = ROOT / 'mobile/node_modules/@expo-google-fonts/manrope/700Bold/Manrope_700Bold.ttf'
NAVY, SIGNAL, WHITE = '#14213D', '#FF6B2C', '#FFFFFF'
SIZE = 52  # locked wordmark size (brand/README.md)

font = TTFont(FONT)
glyphs = font.getGlyphSet()
cmap = font.getBestCmap()
scale = SIZE / font['head'].unitsPerEm


def advance(text):
    return sum(font['hmtx'][cmap[ord(c)]][0] for c in text) * scale


def text_path(text, x, baseline, anchor='start'):
    if anchor == 'end':
        x -= advance(text)
    pen = SVGPathPen(glyphs)
    for c in text:
        name = cmap[ord(c)]
        # Font units are y-up; SVG is y-down.
        glyphs[name].draw(TransformPen(pen, (scale, 0, 0, -scale, x, baseline)))
        x += font['hmtx'][name][0] * scale
    return pen.getCommands()


WORDS = text_path('beni', 12, 100) + ' ' + text_path('bul', 268, 120, 'end')


def svg(ink, accent, words=True):
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="-4 -18 288 216" width="288" height="216" role="img" aria-label="beni bul">
  <g fill="none" stroke="{ink}" stroke-width="16" stroke-linecap="round">
    <line x1="14" y1="120" x2="110" y2="120"/>
    <circle cx="44" cy="150" r="30"/>
    <line x1="170" y1="60" x2="266" y2="60"/>
    <circle cx="236" cy="30" r="30"/>
  </g>
  <line x1="127" y1="108" x2="153" y2="72" stroke="{accent}" stroke-width="5" stroke-linecap="round"/>
  <g fill="{accent}"><circle cx="127" cy="108" r="7"/><circle cx="153" cy="72" r="7"/></g>
''' + (f'  <path fill="{ink}" d="{WORDS}"/>\n' if words else '') + '</svg>\n'


outputs = {
    ROOT / 'brand/logo/beni-bul-logo-outlined.svg': svg(NAVY, SIGNAL),
    ROOT / 'brand/logo/beni-bul-logo-white-outlined.svg': svg(WHITE, SIGNAL),
    ROOT / 'brand/logo/beni-bul-logo-mono-outlined.svg': svg(NAVY, NAVY),
    ROOT / 'backend/public/img/beni-bul-logo.svg': svg(NAVY, SIGNAL),
    ROOT / 'backend/public/img/beni-bul-logo-white.svg': svg(WHITE, SIGNAL),
}
for path, content in outputs.items():
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding='utf-8', newline='\n')
    print(f'wrote {path.relative_to(ROOT)} ({len(content)} bytes)')
