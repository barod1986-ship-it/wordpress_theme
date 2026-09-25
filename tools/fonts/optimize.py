#!/usr/bin/env python3
"""Optimise the theme's self-hosted fonts (retrovault/assets/fonts) from the Google Fonts woff2 subsets.

    python3 -m venv .venv && .venv/bin/pip install fonttools brotli
    .venv/bin/python tools/fonts/optimize.py <dir with the original Google Fonts files> retrovault/assets/fonts

What changes (the outlines and metrics that are drawn stay the same):

* Handjet: every glyph is made of references to one "pixel" glyph. In the Google Fonts build that glyph is four
  identical, overlapping squares of 48 points each (192 points, left over from the element-shape axis the web
  build dropped). A letter of 25 pixels therefore has 4,800 points for the browser to load, measure and
  rasterise, which made laying out the home page's multicart menu cost more than the rest of the page together.
  It becomes a single 4-point square with the same corners at every weight (the weight axis grows the square).
  This also makes Chrome apply the weight axis: with the original glyph it drew 600 and 800 like 400.
* All files: glyph names are dropped from the post table (the browser never uses them).
* Arabic subsets: only the Arabic block (U+0600-06FF), the presentation forms and the joiners/punctuation the
  site uses are kept. Letters of Arabic Supplement / Extended (African languages, Quranic annotation) and the
  Arabic mathematical symbols fall back to the system font; fonts.css lists the matching unicode-range.
* Hinting, kerning, every OpenType feature and the name table (licence) are kept.
"""
import os
import sys

from fontTools import subset
from fontTools.pens.areaPen import AreaPen
from fontTools.pens.boundsPen import BoundsPen
from fontTools.ttLib import TTFont
from fontTools.ttLib.tables import ttProgram
from fontTools.ttLib.tables._g_l_y_f import Glyph, GlyphCoordinates
from fontTools.ttLib.tables.TupleVariation import TupleVariation

ARABIC = 'U+0600-06FF,U+200C-200E,U+2010-2011,U+204F,U+2E41,U+FB50-FDFF,U+FE70-FE74,U+FE76-FEFC'
# (file, subset): the Arabic subsets are narrowed to ARABIC, the others keep every character they have.
FILES = [('handjet-' + s, s) for s in ('arabic', 'latin', 'latin-ext')] + [
    ('ibm-plex-sans-arabic-%s-%d' % (s, w), s) for s in ('arabic', 'latin', 'latin-ext') for w in (400, 500, 700)
]


def square(bounds, clockwise):
    x0, y0, x1, y1 = bounds
    pts = [(x0, y0), (x0, y1), (x1, y1), (x1, y0)]  # clockwise with y up
    return pts if clockwise else pts[::-1]


def pixel_bounds(font, loc):
    gs = font.getGlyphSet(location=loc)
    b, a = BoundsPen(gs), AreaPen(gs)
    gs['pixel'].draw(b)
    gs['pixel'].draw(a)
    x0, y0, x1, y1 = b.bounds
    # four identical squares: the filled shape is the bounding box
    assert abs(abs(a.value) - 4 * (x1 - x0) * (y1 - y0)) < 1, (loc, a.value, b.bounds)
    return b.bounds


def simplify_pixel(font):
    axis = font['fvar'].axes
    assert [a.axisTag for a in axis] == ['wght'], 'expected only the wght axis'
    area = AreaPen(font.getGlyphSet())
    font.getGlyphSet()['pixel'].draw(area)
    clockwise = area.value < 0
    lo = pixel_bounds(font, {'wght': axis[0].minValue})
    mid = pixel_bounds(font, {})
    hi = pixel_bounds(font, {'wght': axis[0].maxValue})
    base = square(mid, clockwise)
    glyph = Glyph()
    glyph.numberOfContours = 1
    glyph.coordinates = GlyphCoordinates(base)
    glyph.endPtsOfContours = [3]
    glyph.flags = bytearray([1, 1, 1, 1])
    glyph.program = ttProgram.Program()
    glyph.program.fromBytecode(b'')
    font['glyf']['pixel'] = glyph
    variations = []
    for var in font['gvar'].variations['pixel']:
        region = var.axes['wght']
        assert region in ((-1.0, -1.0, 0.0), (0.0, 1.0, 1.0)), region
        target = square(hi if region[1] > 0 else lo, clockwise)
        deltas = [(t[0] - b[0], t[1] - b[1]) for t, b in zip(target, base)]
        phantom = [c if c is not None else (0, 0) for c in var.coordinates[-4:]]
        variations.append(TupleVariation(dict(var.axes), deltas + phantom))
    font['gvar'].variations['pixel'] = variations


def shapes(font, locations):
    out = {}
    for loc in locations:
        gs = font.getGlyphSet(location=loc) if loc else font.getGlyphSet()
        for name in font.getGlyphOrder():
            b = BoundsPen(gs)
            gs[name].draw(b)
            out[(name, tuple(sorted(loc.items())))] = (b.bounds, gs[name].width)
    return out


def optimise(src, dst, arabic):
    font = TTFont(src, recalcTimestamp=False)  # same input, same bytes
    variable = 'fvar' in font
    locations = [{'wght': w} for w in (100, 250, 400, 500, 600, 700, 800, 900)] if variable else [{}]
    before = shapes(font, locations)
    cmap_before = font.getBestCmap()
    if 'pixel' in font['glyf'].keys():
        simplify_pixel(font)
    options = subset.Options()
    options.layout_features = ['*']
    options.name_IDs = ['*']
    options.name_languages = ['*']
    options.name_legacy = True
    options.notdef_outline = True
    options.hinting = True
    options.glyph_names = False
    options.flavor = 'woff2'
    sub = subset.Subsetter(options)
    if arabic:
        sub.populate(unicodes=subset.parse_unicodes(ARABIC))
    else:
        sub.populate(unicodes=list(cmap_before))
    sub.subset(font)
    subset.save_font(font, dst, options)
    # the glyphs that remain draw and advance exactly as before
    after = shapes(TTFont(dst), locations)
    changed = [k for k, v in after.items() if k in before and before[k] != v]
    assert not changed, changed[:5]
    return len(cmap_before), len(TTFont(dst).getBestCmap())


def main(src_dir, dst_dir):
    total = [0, 0]
    for name, script in FILES:
        src = os.path.join(src_dir, name + '.woff2')
        dst = os.path.join(dst_dir, name + '.woff2')
        tmp = dst + '.tmp'
        chars = optimise(src, tmp, script == 'arabic')
        a, b = os.path.getsize(src), os.path.getsize(tmp)
        os.replace(tmp, dst)
        total[0] += a
        total[1] += b
        print('%-42s %6d -> %6d bytes  (%d -> %d characters)' % (name, a, b, chars[0], chars[1]))
    print('%-42s %6d -> %6d bytes' % ('total', total[0], total[1]))


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
