#!/usr/bin/env python3
"""Generate the BoothPOS introduction deck as a real .pptx.

Mirrors docs/presentation/boothpos-presentation.html: same eight chapters, same
palette (taken from the product's own design tokens), same verified figures.
Slides are deliberately a little less dense than the HTML — a projected slide
carries fewer words than a page someone reads at their own pace.
"""
import pathlib
from pptx import Presentation
from pptx.util import Inches as In, Pt, Emu
from pptx.dml.color import RGBColor as C
from pptx.enum.text import PP_ALIGN, MSO_ANCHOR
from pptx.enum.shapes import MSO_SHAPE

BASE = pathlib.Path("/Users/chan/www/boothpos/docs/presentation")
JPG = BASE / "jpg"
OUT = BASE / "BoothPOS-Introduction.pptx"

# palette — resources/css/app.css @theme
INK      = C(0x14, 0x20, 0x1B)
INK2     = C(0x22, 0x33, 0x2B)
BRAND    = C(0x2F, 0x9E, 0x6E)
BRANDDP  = C(0x1E, 0x6B, 0x4B)
BRANDSF  = C(0xE4, 0xF3, 0xEC)
ACCENT   = C(0x8B, 0x2F, 0x9D)
ACCENTSF = C(0xF3, 0xE8, 0xF6)
CANVAS   = C(0xF7, 0xF9, 0xF8)
WHITE    = C(0xFF, 0xFF, 0xFF)
LINE     = C(0xDA, 0xE3, 0xDE)
MUTED    = C(0x5D, 0x6F, 0x66)
MUTED2   = C(0x7E, 0x8E, 0x86)
DARKMUT  = C(0x9D, 0xB0, 0xA7)
MINT     = C(0x8F, 0xC9, 0xAE)
WARN     = C(0x8A, 0x6A, 0x1E)
WARNBG   = C(0xFB, 0xF7, 0xEC)
DANGER   = C(0xA2, 0x53, 0x4B)
DANGERBG = C(0xFB, 0xED, 0xEC)

DISPLAY = "Helvetica Neue"
BODY    = "Helvetica Neue"
MONO    = "Menlo"

W, H = In(13.333), In(7.5)
MX = In(0.72)                      # side margin
CW = W - 2 * MX                    # content width

prs = Presentation()
prs.slide_width, prs.slide_height = W, H
BLANK = prs.slide_layouts[6]


# ---------------------------------------------------------------- primitives
def slide(dark=False):
    s = prs.slides.add_slide(BLANK)
    s.background.fill.solid()
    s.background.fill.fore_color.rgb = INK if dark else CANVAS
    return s


def box(s, l, t, w, h):
    tb = s.shapes.add_textbox(l, t, w, h)
    tf = tb.text_frame
    tf.word_wrap = True
    tf.margin_left = tf.margin_right = tf.margin_top = tf.margin_bottom = 0
    return tf


def para(tf, text, size, color, *, font=BODY, bold=False, first=False,
         space_before=0, space_after=0, line=None, caps=False, spacing=None):
    p = tf.paragraphs[0] if first else tf.add_paragraph()
    p.space_before = Pt(space_before)
    p.space_after = Pt(space_after)
    if line:
        p.line_spacing = line
    r = p.add_run()
    r.text = text.upper() if caps else text
    f = r.font
    f.name, f.size, f.bold, f.color.rgb = font, Pt(size), bold, color
    return p


def rect(s, l, t, w, h, fill=None, line_col=None, line_w=0.75, shape=MSO_SHAPE.ROUNDED_RECTANGLE, radius=0.04):
    sh = s.shapes.add_shape(shape, l, t, w, h)
    if shape == MSO_SHAPE.ROUNDED_RECTANGLE:
        try:
            sh.adjustments[0] = radius
        except Exception:
            pass
    if fill is None:
        sh.fill.background()
    else:
        sh.fill.solid(); sh.fill.fore_color.rgb = fill
    if line_col is None:
        sh.line.fill.background()
    else:
        sh.line.color.rgb = line_col; sh.line.width = Pt(line_w)
    sh.shadow.inherit = False
    return sh


def eyebrow(s, chapter_no, chapter, right=""):
    tf = box(s, MX, In(0.42), CW, In(0.26))
    p = tf.paragraphs[0]
    r = p.add_run(); r.text = f"{chapter_no}  "
    r.font.name, r.font.size, r.font.bold, r.font.color.rgb = MONO, Pt(9), True, ACCENT
    r2 = p.add_run(); r2.text = chapter.upper()
    r2.font.name, r2.font.size, r2.font.color.rgb = MONO, Pt(9), MUTED2
    if right:
        r3 = p.add_run(); r3.text = "   ·   " + right.upper()
        r3.font.name, r3.font.size, r3.font.color.rgb = MONO, Pt(9), MUTED2
    rect(s, MX, In(0.76), CW, Emu(9525), fill=LINE, line_col=None, shape=MSO_SHAPE.RECTANGLE)


def heading(s, text, top=In(0.98), size=30, color=INK, width=None):
    tf = box(s, MX, top, width or CW, In(1.0))
    para(tf, text, size, color, font=DISPLAY, bold=True, first=True, line=1.06)
    return tf


def note(s, l, t, w, text, kind="ok", h=In(0.62)):
    col, bg = {"ok": (BRANDDP, BRANDSF), "warn": (WARN, WARNBG), "stop": (DANGER, DANGERBG)}[kind]
    rect(s, l, t, w, h, fill=bg, line_col=None, shape=MSO_SHAPE.RECTANGLE)
    rect(s, l, t, Emu(34925), h, fill=col, line_col=None, shape=MSO_SHAPE.RECTANGLE)
    tf = box(s, l + In(0.16), t + In(0.09), w - In(0.3), h - In(0.16))
    para(tf, text, 9.5, col, first=True, line=1.28)


def picture(s, key, l, t, w):
    f = JPG / f"{key}.jpg"
    if not f.exists():
        raise SystemExit(f"missing image {f}")
    h = Emu(int(w * 900 / 1440))
    rect(s, l - Emu(19050), t - Emu(19050), w + Emu(38100), h + Emu(38100),
         fill=WHITE, line_col=LINE, shape=MSO_SHAPE.RECTANGLE)
    s.shapes.add_picture(str(f), l, t, width=w, height=h)
    return h


def bullets(s, l, t, w, items, size=10.5, gap=7):
    """Numbered how-to steps, mono index in the accent colour."""
    tf = box(s, l, t, w, In(2.6))
    for i, it in enumerate(items):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.space_after = Pt(gap)
        p.line_spacing = 1.3
        r = p.add_run(); r.text = f"{i+1:02d}   "
        r.font.name, r.font.size, r.font.bold, r.font.color.rgb = MONO, Pt(8.5), True, ACCENT
        r2 = p.add_run(); r2.text = it
        r2.font.name, r2.font.size, r2.font.color.rgb = BODY, Pt(size), MUTED
    return tf


def card(s, l, t, w, h, idx, title, bodytext):
    rect(s, l, t, w, h, fill=WHITE, line_col=LINE)
    tf = box(s, l + In(0.19), t + In(0.16), w - In(0.38), h - In(0.3))
    para(tf, idx, 8, ACCENT, font=MONO, bold=True, first=True, caps=True)
    para(tf, title, 12.5, INK, font=DISPLAY, bold=True, space_before=5, line=1.15)
    para(tf, bodytext, 9.5, MUTED, space_before=5, line=1.32)


def footer(s, text, dark=False):
    tf = box(s, MX, H - In(0.52), CW, In(0.24))
    para(tf, text, 8, DARKMUT if dark else MUTED2, font=MONO, first=True)


# ---------------------------------------------------------------- slide kinds
def cover_slide():
    s = slide(dark=True)
    rect(s, MX, In(0.62), In(0.3), In(0.3), fill=BRAND, line_col=None, radius=0.22)
    _m = box(s, MX, In(0.665), In(0.3), In(0.22))
    _p = para(_m, "B", 12, INK, font=DISPLAY, bold=True, first=True)
    _p.alignment = PP_ALIGN.CENTER
    tf = box(s, MX + In(0.42), In(0.66), In(4), In(0.26))
    para(tf, "B o o t h P O S", 10.5, MINT, font=MONO, bold=True, first=True)

    tf = box(s, MX, In(1.5), In(7.4), In(2.4))
    para(tf, "The till keeps running\nwhen the venue wifi dies.", 40, WHITE,
         font=DISPLAY, bold=True, first=True, line=1.04)

    tf = box(s, MX, In(3.62), In(6.9), In(1.3))
    para(tf, "A point-of-sale system for event merchandise booths shared by several "
             "independent artists. It runs entirely on one laptop, ties every sale to an "
             "event and to the seller who owns the item, and hands you each artist's share "
             "the moment the doors close.", 13, DARKMUT, first=True, line=1.42)

    chips = ["One-time licence", "No cloud, no subscription", "Works fully offline"]
    x = MX
    for ch in chips:
        w = In(0.078 * len(ch) + 0.3)
        rect(s, x, In(5.08), w, In(0.32), fill=None, line_col=BRAND, radius=0.5)
        tf = box(s, x, In(5.15), w, In(0.2))
        p = para(tf, ch, 9, MINT, first=True)
        p.alignment = PP_ALIGN.CENTER
        x += w + In(0.1)

    facts = [("Screens", "27", "plus 48 components"), ("Endpoints", "159", "REST API"),
             ("Automated tests", "715", "492 back + 223 front"), ("Built in", "8 days", "19 specified features")]
    fx, fy, fw, fh = In(8.42), In(3.55), In(2.0), In(1.02)
    for i, (k, v, sub) in enumerate(facts):
        l = fx + (i % 2) * (fw + In(0.1))
        t = fy + (i // 2) * (fh + In(0.1))
        rect(s, l, t, fw, fh, fill=INK2, line_col=None)
        tf = box(s, l + In(0.16), t + In(0.13), fw - In(0.3), fh - In(0.24))
        para(tf, k, 7.5, DARKMUT, font=MONO, first=True, caps=True)
        para(tf, v, 17, WHITE, font=DISPLAY, bold=True, space_before=3)
        para(tf, sub, 7.5, MINT, space_before=1)

    footer(s, "Every screenshot in this deck is the running application, captured 10 September 2026", dark=True)


def chapter_slide(num, title, lede, active):
    s = slide(dark=True)
    tf = box(s, MX, In(1.5), In(6), In(0.3))
    para(tf, f"CHAPTER {num}", 10, BRAND, font=MONO, bold=True, first=True)
    tf = box(s, MX, In(1.95), In(7.6), In(1.8))
    para(tf, title, 44, WHITE, font=DISPLAY, bold=True, first=True, line=1.02)
    tf = box(s, MX, In(4.0), In(7.2), In(1.2))
    para(tf, lede, 12.5, DARKMUT, first=True, line=1.45)

    names = ["01 Introduction", "02 Problem", "03 Goals", "04 Methodology",
             "05 Features", "06 End-to-end flow", "07 Result", "08 Gap"]
    x = MX
    for n in names:
        w = In(0.075 * len(n) + 0.3)
        on = n.startswith(active)
        rect(s, x, In(5.55), w, In(0.3), fill=BRAND if on else None,
             line_col=None if on else INK2, radius=0.5)
        tf = box(s, x, In(5.61), w, In(0.2))
        p = para(tf, n, 8, INK if on else MUTED2, font=MONO, bold=on, first=True, caps=True)
        p.alignment = PP_ALIGN.CENTER
        x += w + In(0.08)


def shot_slide(ch, chname, right, title, lede, steps, img, note_text=None, note_kind="ok", flip=False):
    s = slide()
    eyebrow(s, ch, chname, right)
    imgw = In(6.45)
    if flip:
        imgl, copyl = MX, MX + imgw + In(0.5)
    else:
        copyl, imgl = MX, MX + In(5.9) + In(0.5)
        imgw = In(6.2)
    copyw = CW - imgw - In(0.5)
    picture(s, img, imgl, In(1.34), imgw)

    tf = box(s, copyl, In(1.05), copyw, In(1.0))
    para(tf, title, 20, INK, font=DISPLAY, bold=True, first=True, line=1.14)
    y = In(1.05) + In(0.42) * (1 + title.count("\n")) + In(0.42)
    tf = box(s, copyl, y, copyw, In(0.9))
    para(tf, lede, 10.5, MUTED, first=True, line=1.4)
    bullets(s, copyl, y + In(0.86), copyw, steps)
    if note_text:
        note(s, copyl, In(5.9), copyw, note_text, note_kind, h=In(0.86))
    footer(s, f"boothpos · {right.lower()}")


def cards_slide(ch, chname, right, title, cards, cols=3, lede=None, tail=None, tail_kind="ok"):
    s = slide()
    eyebrow(s, ch, chname, right)
    heading(s, title)
    top = In(1.72)
    if lede:
        tf = box(s, MX, In(1.62), In(9.4), In(0.5))
        para(tf, lede, 11, MUTED, first=True, line=1.4)
        top = In(2.24)
    rows = (len(cards) + cols - 1) // cols
    gap = In(0.16)
    cw = (CW - gap * (cols - 1)) / cols
    avail = (H - In(1.0) if tail else H - In(0.62)) - top
    chh = min(In(1.92), (avail - gap * (rows - 1)) / rows)
    for i, (idx, t, b) in enumerate(cards):
        l = MX + (i % cols) * (cw + gap)
        tp = top + (i // cols) * (chh + gap)
        card(s, l, tp, cw, chh, idx, t, b)
    if tail:
        note(s, MX, top + rows * (chh + gap) + In(0.04), CW, tail, tail_kind, h=In(0.6))
    footer(s, f"boothpos · {right.lower()}")


def table_slide(ch, chname, right, title, headers, rows, widths, lede=None, tail=None, tail_kind="ok"):
    s = slide()
    eyebrow(s, ch, chname, right)
    heading(s, title)
    top = In(1.74)
    if lede:
        tf = box(s, MX, In(1.64), In(9.6), In(0.5))
        para(tf, lede, 10.5, MUTED, first=True, line=1.4)
        top = In(2.2)
    rect(s, MX, top, CW, Emu(9525), fill=LINE, shape=MSO_SHAPE.RECTANGLE)
    x = MX
    for hd, wd in zip(headers, widths):
        tf = box(s, x + In(0.1), top + In(0.1), In(wd) - In(0.2), In(0.24))
        para(tf, hd, 8, MUTED2, font=MONO, bold=True, first=True, caps=True)
        x += In(wd)
    y = top + In(0.42)
    for row in rows:
        rect(s, MX, y, CW, Emu(9525), fill=C(0xE9, 0xEF, 0xEC), shape=MSO_SHAPE.RECTANGLE)
        x = MX
        hmax = In(0.34)
        for cell, wd in zip(row, widths):
            strong = cell.startswith("**")
            txt = cell.replace("**", "")
            tf = box(s, x + In(0.1), y + In(0.11), In(wd) - In(0.2), In(0.5))
            para(tf, txt, 9.5 if not strong else 10, INK if strong else MUTED,
                 bold=strong, first=True, line=1.3)
            hmax = max(hmax, In(0.2 + 0.135 * (len(txt) / max(1, (wd * 8.4)) + 1) * 1.3))
            x += In(wd)
        y += hmax
    if tail:
        note(s, MX, min(y + In(0.14), H - In(1.22)), CW, tail, tail_kind, h=In(0.72))
    footer(s, f"boothpos · {right.lower()}")


def flow_slide(ch, right, title, steps, tail=None):
    s = slide()
    eyebrow(s, ch, "End-to-end flow", right)
    heading(s, title)
    top = In(1.76)
    avail = (H - In(1.15) if tail else H - In(0.68)) - top
    rh = min(In(0.42), avail / len(steps))
    for i, (who, act, eff, kind) in enumerate(steps):
        y = top + i * rh
        bg = {"": WHITE, "up": BRANDSF, "down": ACCENTSF, "block": DANGERBG}[kind]
        fg = {"": MUTED, "up": BRANDDP, "down": ACCENT, "block": DANGER}[kind]
        rect(s, MX, y, CW, rh - Emu(9525), fill=WHITE, line_col=LINE, shape=MSO_SHAPE.RECTANGLE)
        rect(s, MX + In(7.2), y, CW - In(7.2), rh - Emu(9525), fill=bg, line_col=LINE, shape=MSO_SHAPE.RECTANGLE)
        tf = box(s, MX + In(0.12), y + In(0.09), In(0.85), rh - In(0.14))
        para(tf, who, 7.5, MUTED2, font=MONO, bold=True, first=True, caps=True)
        tf = box(s, MX + In(1.05), y + In(0.08), In(6.05), rh - In(0.12))
        para(tf, act, 9.5, INK, first=True, line=1.22)
        tf = box(s, MX + In(7.32), y + In(0.08), CW - In(7.5), rh - In(0.12))
        para(tf, eff, 9, fg, bold=(kind != ""), first=True, line=1.22)
    if tail:
        note(s, MX, top + len(steps) * rh + In(0.08), CW, tail, "ok", h=In(0.6))
    footer(s, f"boothpos · {right.lower()}")


def gaps_slide(ch, right, title, items):
    s = slide()
    eyebrow(s, ch, "Gap", right)
    heading(s, title)
    top = In(1.76)
    gap = In(0.12)
    ih = min(In(0.92), (H - In(0.7) - top - gap * (len(items) - 1)) / len(items))
    for i, (tag, kind, t, b) in enumerate(items):
        y = top + i * (ih + gap)
        rect(s, MX, y, CW, ih, fill=WHITE, line_col=LINE)
        col, bg = {"cut": (MUTED2, C(0xEF, 0xF4, 0xF2)), "lim": (WARN, WARNBG),
                   "sec": (DANGER, DANGERBG), "plan": (BRANDDP, BRANDSF)}[kind]
        rect(s, MX + In(0.14), y + In(0.15), In(1.05), In(0.24), fill=bg, line_col=col, line_w=0.5, radius=0.2)
        tf = box(s, MX + In(0.14), y + In(0.19), In(1.05), In(0.18))
        p = para(tf, tag, 7.5, col, font=MONO, bold=True, first=True, caps=True)
        p.alignment = PP_ALIGN.CENTER
        tf = box(s, MX + In(1.36), y + In(0.12), CW - In(1.6), ih - In(0.2))
        para(tf, t, 11.5, INK, font=DISPLAY, bold=True, first=True)
        para(tf, b, 9, MUTED, space_before=3, line=1.3)
    footer(s, f"boothpos · {right.lower()}")


def metrics_slide():
    s = slide()
    eyebrow(s, "07", "Result", "What ships today")
    heading(s, "The product, in numbers.")
    rows = [
        [("Backend tests", "492", "all passing · 1,670 assertions"),
         ("Frontend tests", "223", "221 passing, 2 skipped · 45 files"),
         ("API endpoints", "159", "70 GET · 47 POST · 21 PUT · 17 DELETE"),
         ("Screens", "27", "plus 48 shared components")],
        [("Lines of code", "50,216", "30,753 PHP · 19,463 JS & Vue"),
         ("Migrations", "46", "38 models · 20 services"),
         ("Specified features", "19", "each with spec, plan & research"),
         ("Defects fixed", "27", "each proven by a failing test")],
    ]
    gap = In(0.14)
    cw = (CW - gap * 3) / 4
    for r, row in enumerate(rows):
        for i, (k, v, sub) in enumerate(row):
            l = MX + i * (cw + gap)
            t = In(1.78) + r * (In(1.32) + gap)
            rect(s, l, t, cw, In(1.32), fill=WHITE, line_col=LINE)
            tf = box(s, l + In(0.18), t + In(0.15), cw - In(0.36), In(1.0))
            para(tf, k, 8, MUTED2, font=MONO, first=True, caps=True)
            para(tf, v, 26, INK, font=DISPLAY, bold=True, space_before=3)
            para(tf, sub, 8.5, MUTED2, space_before=3, line=1.3)
    note(s, MX, In(4.68), (CW - In(0.16)) / 2,
         "Authorization: 45 request-level gates, 15 policy classes and inline role checks across three server-side layers.", "ok", h=In(0.72))
    note(s, MX + (CW - In(0.16)) / 2 + In(0.16), In(4.68), (CW - In(0.16)) / 2,
         "Delivery: 93 commits and 18 merged pull requests between 31 Aug and 8 Sep 2026, all against real MySQL 8.", "ok", h=In(0.72))
    footer(s, "boothpos · measured by running the commands, not quoting a document")


def gallery_slide(ch, right, title, items, tail=None):
    s = slide()
    eyebrow(s, ch, "Appendix", right)
    heading(s, title)
    cols, gap = 4, In(0.18)
    cw = (CW - gap * (cols - 1)) / cols
    for i, (key, cap) in enumerate(items):
        l = MX + (i % cols) * (cw + gap)
        t = In(1.8) + (i // cols) * In(2.44)
        picture(s, key, l, t, cw)
        tf = box(s, l, t + Emu(int(cw * 900 / 1440)) + In(0.09), cw, In(0.6))
        para(tf, cap, 8, MUTED2, first=True, line=1.3)
    if tail:
        footer(s, tail)
    else:
        footer(s, f"boothpos · {right.lower()}")


# ---------------------------------------------------------------- the deck
cover_slide()

# 01 INTRODUCTION -------------------------------------------------------------
chapter_slide("01", "Introduction",
              "What BoothPOS is, who runs it, and the one commercial decision that shapes everything else about it.", "01")

cards_slide("01", "Introduction", "What it is", "A till built for a booth with more than one owner.", [
    ("Pillar 01", "Every item knows its seller",
     "A single basket can mix three artists' goods. Each sold line permanently records which seller it belonged to, at the price and cost it had that day — so the payout maths is already done before you close."),
    ("Pillar 02", "Everything hangs off the event",
     "Sales, shifts, floats, profit and settlements are all scoped to a named event. \"Was that convention worth going to?\" is a report, not a guess."),
    ("Pillar 03", "The machine in front of you is the whole system",
     "Database, server and screens all run on the shop's own laptop over localhost. There is no cloud tier to fail, and no outbound call in the selling path."),
], lede="Most point-of-sale software assumes one shop, one owner, one cash drawer and a reliable internet connection. An event booth breaks all four assumptions at once.",
   tail="Commercially: BoothPOS is sold as a one-time licence installed locally, per store. No monthly fee, no multi-tenant service, and no vendor-side copy of your sales data.")

shot_slide("01", "Introduction", "The product at rest",
           "The home screen answers the two\nquestions a booth owner actually asks.",
           "How are we doing right now, and who is owed what. Net sales, transactions and gross profit for the running event sit above a live per-seller split.",
           ["Four shortcut tiles jump straight to a new sale, a new pre-order, a stock adjustment or a new product.",
            "Results per seller updates as you trade — there is no report to run.",
            "Low-stock and pending pre-order counters flag what needs attention early."],
           "02-dashboard",
           "DEMO — the amber badge means this installation is in practice mode. Everything shown is demo data that never touches the real books.", "warn")

cards_slide("01", "Introduction", "Who it is for", "Three shapes of booth, one product.", [
    ("Audience A", "The consignment host",
     "You rent the booth and carry other artists' merchandise. You need per-seller accounting you can defend, and you need it the same evening — not next week.   → Master licence"),
    ("Audience B", "The solo maker",
     "Everything in the booth is yours. You want a fast till, honest stock and a profit figure per event, without paying for multi-artist features you will never open.   → Pro licence"),
    ("Audience C", "The circuit regular",
     "A dozen events a year, pre-orders taken at one and handed over at the next, and last year's numbers when you decide which events to book again.   → Master licence"),
], tail="On the day: an owner sets it up, a cashier who may be a friend doing you a favour runs the till, an inventory helper counts stock but never sees margins. Access is granted screen by screen.")

# 02 PROBLEM ------------------------------------------------------------------
chapter_slide("02", "The problem",
              "Every pain here is taken from the product requirements document's own problem statement — the situations that made this software worth building rather than buying.", "02")

table_slide("02", "Problem", "Booth-day reality", "Six ways a busy weekend goes wrong.",
            ["The pain", "What BoothPOS does"],
            [["**The paper sales book at a packed booth",
              "At the busiest hour you are scribbling every sale into a notebook. The till writes the sale, its lines, its payments and its stock changes in one all-or-nothing step."],
             ["**Splitting the day's takings between artists",
              "Several artists, one cash box, days of receipt-sorting. Every sold line remembers its seller, so the recap recalculates from the actual lines whenever you open it."],
             ["**When an artist questions the numbers",
              "All you have to defend yourself with is your own handwriting. Click any seller's row for the individual transactions behind the total."],
             ["**Venue wifi that does not work",
              "The signal drops when the queue is longest. Nothing in the selling path makes an outbound call — trade all day with the wifi off."],
             ["**Stock nobody trusts, and the limited item you sold twice",
              "Stock is per variant, and every change goes through one write path that locks the row, so two simultaneous sales cannot both take the last unit."],
             ["**One cash box, several pairs of hands",
              "Nobody can explain the shortfall. Shifts open with a counted float and close with a counted drawer; a difference demands a written note."]],
            [4.3, 7.59],
            tail="Source: docs/PRD-POS-Event-Multivendor.md §2 (problems P1–P5) and §12 (named risks).")

cards_slide("02", "Problem", "Why a generic POS does not fit", "The four assumptions ordinary till software makes.", [
    ("Assumption 01", "\"One shop owns all the stock\"",
     "So there is nowhere to record that the keychain belongs to one artist and the standee to another — and no way to split one basket between them."),
    ("Assumption 02", "\"Trading is continuous\"",
     "So there is no notion of an event: no per-event float, no per-event profit after the booth fee, no way to compare one convention against another."),
    ("Assumption 03", "\"The internet is there\"",
     "So the moment the hall's wifi drops, the queue stops. Cloud tills degrade exactly when a booth is busiest."),
    ("Assumption 04", "\"You pay monthly, forever\"",
     "A subscription priced for a shop open 300 days a year is poor value for a booth trading on six weekends."),
], cols=4,
   tail="The cost of getting this wrong is not abstract: the requirements document names artist payout disputes as a top project risk, and permanent data loss from an un-backed-up device as another.", tail_kind="stop")

# 03 GOALS --------------------------------------------------------------------
chapter_slide("03", "Goals",
              "What the product committed to doing — and, just as deliberately, what it committed to leaving out.", "03")

cards_slide("03", "Goals", "The promises", "Seven commitments the build was measured against.", [
    ("Goal 01", "Sell without the internet", "The complete stack runs on the shop's machine. Reports, receipts and stock behave identically offline and online."),
    ("Goal 02", "Settle the same evening", "Per-seller payouts recalculated from live sale lines, with a transaction drill-down and an Excel export per artist."),
    ("Goal 03", "Never let the till decide what it earned", "Prices, totals, discounts, cost of goods and stock deltas are computed server-side. A client-supplied amount is never trusted."),
    ("Goal 04", "Stock you can sell limited runs on", "Per-variant stock, an append-only movement history, and a row lock that stops two sales overselling the last unit."),
    ("Goal 05", "Answer \"was this event worth it?\"", "Cost and sell price frozen onto each sold line, and the event's own cost subtracted to give a net figure."),
    ("Goal 06", "Safe in the hands of a volunteer", "Access granted screen by screen, three server-side authorization layers, and a practice mode for rehearsal."),
], cols=3,
   tail="Goal 07 — Cost one payment, not a subscription. A one-time licence in two tiers running from the same build: Pro for a single-seller booth, Master for unlimited sellers. Switching is one setting; nothing recorded is lost.")

table_slide("03", "Goals", "Deliberate non-goals", "What was cut on purpose, and why.",
            ["Cut from scope", "The documented reasoning", "Status"],
            [["**QR / barcode scanning", "With dozens of SKUs, searching a touchscreen grid is as fast and far cheaper to build.", "Still cut"],
             ["**Flash sales & rule-based discounts", "A manually entered discount at the till covers the first event's needs.", "Still cut"],
             ["**Printed / PDF catalogue", "Re-confirmed as cut after pre-orders were pulled into the MVP.", "Still cut"],
             ["**Excel import of sales transactions", "Importing historical money was judged too risky to reconcile; master data was not.", "Still cut"],
             ["**Artist self-service portal", "Would require a network tier the product deliberately does not have.", "Still cut"],
             ["**Cloud tier / online storefront", "The whole value proposition is a local install with no vendor-held data.", "Still cut"],
             ["**Master-data Excel import", "Un-cut on 2026-09-01 — migrating from an existing spreadsheet was blocking adoption.", "**Built"],
             ["**Vendor / material / BOM tracking", "Added post-MVP on 2026-09-01, deliberately narrower than the original cut.", "**Built"]],
            [3.3, 7.09, 1.5],
            lede="These are documented scope decisions, not oversights. Two were later un-cut, on the record, when the product owner asked.")

# 04 METHODOLOGY --------------------------------------------------------------
chapter_slide("04", "Methodology",
              "Why you should trust software you have not yet run: how it was specified, how it was tested, and what it does with your money when nobody is watching.", "04")

cards_slide("04", "Methodology", "How it was built", "Specification first, every single time.", [
    ("Practice 01", "Nothing is built without a written spec", "All 19 features live in a numbered folder with a specification, plan, research note and task list — approved before any production code."),
    ("Practice 02", "Rejected alternatives are recorded", "Research notes are numbered decisions in Decision / Rationale / Alternatives form. A surprising report figure has a documented reason."),
    ("Practice 03", "A ratified engineering constitution", "Five binding principles on code quality, testing, UX, security and performance, with formal amendment rules and written exceptions."),
    ("Practice 04", "Tests run against real MySQL", "Not a convenient in-memory fake. Enforced, because several migrations use raw MySQL-only CHECK constraints a substitute cannot execute."),
    ("Practice 05", "Nothing is done until driven in a browser", "Screens are exercised against the live API with the console checked. Three real reporting defects were caught this way that the test suite passed."),
    ("Practice 06", "A published bug log", "27 defects logged with root cause and fix, each proven by a test that failed first and passed after."),
], cols=3)

shot_slide("04", "Methodology", "Practice mode",
           "A whole second world on the\nsame laptop.",
           "One store-wide setting switches BoothPOS between DEMO and LIVE. Twenty transactional record types are stamped with the mode they were created in and filtered on every read.",
           ["Train a cashier on a full, realistic shop — on the machine you will use for real money that weekend.",
            "Rehearse an entire event day, including closing and reconciling the drawer.",
            "Switch back to LIVE and every practice record disappears from every list and report."],
           "17-activity-log",
           "Pictured: the activity log. Entries are written inside the same transaction as the change they record, so a rolled-back deletion can never leave a log claiming it happened.", "ok", flip=True)

cards_slide("04", "Methodology", "Where the money is decided", "The cashier's screen never says what it earned.", [
    ("What the till sends", "Only which variant, and how many",
     "Plus an optional discount and a one-time reference code. Nothing else from the screen reaches the ledger."),
    ("What the server decides", "Everything that is money",
     "The price of each line from master data, the line total, whether the discount is legal, the order total, the cost of goods frozen onto the line, the change due, the stock delta and the receipt number."),
    ("Why it matters", "Three commercial consequences",
     "Settlements cannot be manipulated from the counter. Old reports do not drift, because cost and price are snapshotted per line. A lost connection cannot double-charge — a repeated reference returns the original sale."),
], cols=3,
   tail="The same rule governs stock: one sanctioned write path locks the variant row, refuses a movement below zero, and records the level before and after — including for the bulk spreadsheet import.")

# 05 FEATURES -----------------------------------------------------------------
chapter_slide("05", "Features\n& how to use them",
              "143 catalogued capabilities across eight areas. What follows is the working tour — every screenshot is the real application, not a mockup.", "05")

table_slide("05", "Features", "The map", "Eight areas, 27 screens.",
            ["Area", "What it covers", "Features"],
            [["**Point of sale", "Cashier shifts, per-seller float, product grid, variant picker, cart, split payment, payment proof, receipt, sales list, drafts", "19"],
             ["**Inventory & master data", "Products and variants, generated codes, images, sellers, categories, customers, events, stock movements, bulk Excel", "19"],
             ["**Pre-orders", "Five-stage lifecycle, deposits and instalments, arrival, guarded handover, shipments, invoices, email notification", "14"],
             ["**Reports & settlement", "Sales, cost & profit, seller recap and payouts, seller cost, purchases, stock by seller, pre-order report, Excel export", "20"],
             ["**Purchasing & costing", "Suppliers, materials, multi-vendor prices, preferred supplier, per-variant recipes, cost breakdown, purchase orders", "19"],
             ["**Administration", "Custom roles, staff accounts, store branding, payment channels, activity log, backup, DEMO/LIVE, language", "19"],
             ["**Licensing", "Offline activation bound to the machine, Pro/Master tiers, licence catalogue, company onboarding, invoices", "18"],
             ["**Deployment", "Native install, Docker store image, offline USB delivery, backup to external drive, upgrades and rollback", "15"]],
            [2.7, 8.09, 1.1])

shot_slide("05.1", "Point of sale", "Opening the till",
           "A shift with a counted float, tied to\na named person and a named event.",
           "No sale can be rung up outside an open shift, one person cannot have two shifts open, and a shift only opens against an event that is actually running.",
           ["Open Cashier Session, pick today's event and enter the cash in the drawer.",
            "Optionally split that float by seller — the rows must sum exactly to the total.",
            "Trade. Transaction count, total sales and cash taken are visible at any moment.",
            "At close, count the tin and type the figure. A difference demands a written note."],
           "04-cashier-session",
           "Worth knowing: the live payment-method breakdown reports verified cash only. Transfer and QRIS are recorded but stay pending, so they do not appear in that breakdown.", "warn")

shot_slide("05.1", "Point of sale", "Finding the item",
           "A touch grid, narrowed by\nseller and category.",
           "At a booth carrying three artists' ranges, the fastest path to the right item is to eliminate the other two. Filters are multi-select; search matches name or code as you type.",
           ["Tap a card, or type a name or product code into the search box.",
            "Products with several sizes or colours open a variant picker with each variant's item code, price and stock.",
            "Pick the variant — it lands in the cart at the price the server holds, never one the screen supplied."],
           "27-pos-variant-picker", flip=True)

shot_slide("05.1", "Point of sale", "The multi-seller basket",
           "One customer, one payment, three\ndifferent artists paid correctly.",
           "This is the feature the whole product exists for. The basket holds a keychain, an enamel pin and an acrylic standee from three different sellers. The buyer pays once.",
           ["Add items from any sellers, in any order — nothing asks you to keep them separate.",
            "Attach a customer, or leave it as a walk-in sale.",
            "Enter an order-level discount; one larger than the subtotal is refused.",
            "Park the basket with Save as draft and pick it up later."],
           "28-pos-cart-three-sellers",
           "Worth knowing: resuming a saved draft restores the items and discount, but not an attached customer — re-attach them before taking payment.", "warn")

shot_slide("05.1", "Point of sale", "Taking the money",
           "Split one sale across cash,\ntransfer and QRIS.",
           "Half in cash and the rest by QR is an ordinary request at an event, and the till treats it as ordinary.",
           ["Cash offers tendered-amount shortcuts and computes change automatically.",
            "Enter less than the total and the button becomes Add & continue — commit that part, then choose another method.",
            "For transfer or QRIS a photo of the proof is required before the sale can be saved.",
            "Payments totalling less than the amount due are refused by the server."],
           "30-pos-split-payment",
           "Pictured: Rp 50.000 already recorded in cash, Rp 72.000 remaining, QRIS selected and the confirm button held until proof is attached.", "ok", flip=True)

shot_slide("05.1", "Point of sale", "The receipt",
           "No thermal printer, no paper rolls,\nno power socket you do not have.",
           "The receipt is drawn on screen in large, high-contrast type intended to be photographed by the buyer from their side of the table.",
           ["It carries your store identity, the event's name, location and dates, the transaction number and the cashier.",
            "Split payments are itemised — the example shows Rp 20.000 by transfer and Rp 15.000 in cash.",
            "Save it as an image or PDF, or re-open any past receipt from Sales."],
           "36-sales-receipt-modal",
           "Worth knowing: receipts always print in Indonesian, whatever language the interface is set to. The login and activation screens are too.", "warn")

shot_slide("05.1", "Point of sale", "Afterwards",
           "Every sale, searchable, with\nthe sellers named on the row.",
           "Search filters instantly across transaction number, customer, cashier and seller. One transaction can list two sellers.",
           ["Click the transaction number, or View items, for the products sold.",
            "Click View receipt to reopen the printable receipt.",
            "Click a seller's name to filter the list to their transactions.",
            "Export .xlsx downloads the event's sales grouped by product."],
           "05-sales",
           "Read the tiles carefully: the four headline figures include money already collected on pre-orders, while the list below is counter sales only — so the two will not reconcile at an event that took pre-orders.", "warn", flip=True)

shot_slide("05.2", "Inventory", "Products",
           "One product, many variants — each with\nits own code, price and stock.",
           "A keychain design in Standard, Special and Glow is one product with three variants. Price and stock live on the variant, because that is what actually sells out.",
           ["Give each seller and category a short code once; product codes are then generated for you.",
            "The list shows the 8-character product code; each variant's 12-character item code appears in the edit panel and Detail pop-up.",
            "Rename the product, seller or category later — the codes never move, so price lists and old receipts stay valid."],
           "07-products")

shot_slide("05.2", "Inventory", "Stock you can defend",
           "An append-only history,\nnot just a number.",
           "Every movement records the level before and after, who did it, and what caused it. Six movement types cover the whole life of an item.",
           ["Filter the history by type to see only sales, only adjustments, or only pre-order handovers.",
            "Correct a miscount with Stock adjustment — a reason is mandatory and the entry is audited.",
            "A movement that would push stock below zero is refused, naming the item and what is available."],
           "08-stock",
           "Worth knowing: a multi-line adjustment saves line by line, not as one batch — if line three is rejected, lines one and two are already saved. Low-stock thresholds are set through the Excel import, not on screen.", "warn", flip=True)

shot_slide("05.2", "Inventory", "Moving in from a spreadsheet",
           "One workbook, ten sheets,\nprocessed in dependency order.",
           "\"I already keep everything in a spreadsheet\" is the most common reason a booth never switches systems. Sellers, categories, products, stock, suppliers, materials, prices, recipes, roles and users all arrive in one file.",
           ["Download the template — it ships with a worked example that imports as-is.",
            "Paste your rows in and upload with preview ticked: a dry run reports every problem by sheet, row, column and reason, changing nothing.",
            "Fix what it names, then upload for real. Exports round-trip back through import."],
           "40-master-data-import",
           "All-or-nothing by design. A 500-row file with one typo imports nothing rather than leaving you half-migrated. The dry run is what makes that safe.", "ok")

gallery_slide("05.2", "Supporting records", "Sellers, categories, customers, events.", [
    ("09-sellers", "Sellers. The artists sharing your booth. On a Pro licence only one may be active; the limit is shown before you hit it."),
    ("10-categories", "Categories. Including sub-categories and display order, which drives grouping on the till's grid."),
    ("11-customers", "Customers. Contact details plus a full purchase history, counter sales and pre-orders together."),
    ("12-events", "Events. Name, location, dates and the event's own cost — the figure that turns gross profit into a real answer."),
], tail="Deleting is guarded throughout: a record still referenced by real history cannot be removed, only deactivated. Removed records are soft-deleted, so history survives.")

shot_slide("05.3", "Pre-orders", "The lifecycle",
           "Take money now for goods\nthat do not exist yet.",
           "A pre-order runs through five guarded stages — Ordered, Deposit paid, Arrived, Settled, Handed over — each checked against a strict state machine so it cannot be skipped.",
           ["Record the customer, items, expected date and whether it is collection or courier.",
            "Take a deposit; the order moves itself to Deposit paid on the first payment.",
            "Mark Arrived when the goods land from the supplier.",
            "Take the balance, hand over, and add courier and tracking details if shipping."],
           "38-preorder-detail",
           "The rule people get backwards: creating a pre-order does NOT touch stock — the goods do not physically exist. Stock goes UP on arrival and DOWN on handover.", "ok", flip=True)

gallery_slide("05.3", "Pre-order paperwork", "Deposits, instalments, and a document for every stage.", [
    ("06-preorders", "The list. Searchable by customer, filterable by status, fulfilment and seller, with a live summary of counts and outstanding balance."),
    ("39-preorder-invoice", "The document. Heading switches between Invoice, Receipt and Cancelled, with a live status pill, per-line seller names and PDF download."),
    ("49-preorder-record-payment", "The ledger. Deposits and instalments accumulate on one running balance; each payment can produce its own receipt."),
    ("35-report-preorder", "The report. Grouped by stage against how much has actually been paid, with a per-seller breakdown and drill-down."),
], tail="Handover is blocked until fully paid — and blocked again if the item sold out over the counter after arriving, rather than driving stock negative.")

shot_slide("05.4", "Reports", "The one that settles arguments",
           "What you owe each artist, recalculated\nfrom live sale lines.",
           "The stored settlement table is a payment-status record and a cache — never the source of truth for sales value. Open the report and every figure is re-derived first.",
           ["Pick the event. Every seller shows units, sales, payable, paid, outstanding and status.",
            "Click a row for the individual transactions behind the total — counter sales and pre-orders together.",
            "Record what you have handed over; the status re-derives to part-paid or paid.",
            "Export .xlsx and send each artist their own copy, transaction detail included."],
           "16-reports",
           "Every active seller is listed, even those who sold nothing — a zero row is a checkable answer, where a missing row is an unanswered question.", "ok")

gallery_slide("05.4", "The other report tabs", "Six report tabs, seven Excel exports.", [
    ("31-report-cost-profit", "Cost & Profit. Revenue, cost of goods, gross profit, the event's own cost and net profit."),
    ("32-report-seller-cost", "Seller Cost. The same profit maths per artist, so you see which ranges carry margin."),
    ("34-report-stock-by-seller", "Stock by Seller. What each artist still has on your shelves, with a per-SKU drill-down."),
    ("33-report-purchases", "Purchases. Every supplier order. The total spans all statuses including draft and cancelled — filter for committed spend."),
], tail="Under all of them: pre-order money counts only once it is really in the till, prorated across the order's lines by value share. Cancelled orders contribute nothing.")

shot_slide("05.5", "Purchasing", "What an item really costs",
           "A recipe per variant, priced from what\nyour suppliers actually charge.",
           "Record which suppliers sell each material and at what price, then attach the materials one unit of a specific variant needs. Recipes hang off the variant, not the product.",
           ["Add suppliers and materials, then record a price per supplier-material pair.",
            "Flag your usual supplier as preferred; flagging one un-flags any other for that material.",
            "Build the recipe on the variant. Quantities may be fractional — 0.25 of a sheet is valid.",
            "Open the cost breakdown to see material cost beside the cost price you typed in."],
           "42-variant-bom-cost",
           "Deliberately read-only. The recipe cost never overwrites the cost price your profit reports and settlements already use. With several suppliers, the preferred price is used — otherwise the cheapest.", "ok", flip=True)

gallery_slide("05.5", "Restocking", "Suppliers, materials and purchase orders.", [
    ("14-vendors", "Suppliers. Contact details and the materials each one sells. A supplier with registered prices cannot be deleted."),
    ("15-materials", "Materials. Raw stock with its own unit and price list, separate from sellable merchandise."),
    ("44-material-vendor-prices", "Vendor prices. Several suppliers per material; the preferred one sets the cost reference."),
    ("48-purchase-order-detail", "Purchase orders. Draft → Ordered → Received → Paid. Receiving tops up material stock automatically."),
], tail="Honest limits: material stock has no screen of its own yet, non-cash supplier payments do not complete, and re-editing an existing draft's lines is unreliable.")

shot_slide("05.6", "Administration", "Who can see what",
           "Roles are a list of screens, not\na fixed four-way choice.",
           "Four roles ship ready to use — Owner, Admin, Cashier and Inventory — but a role is just a set of menu permissions, so you can build your own.",
           ["The default Cashier gets the till, shift, events, customers, pre-orders and sales list — and pointedly not the profit or settlement reports.",
            "Tick or untick individual screens to match how your booth actually runs.",
            "Staff accounts carry a photo and last-access time; each person manages their own password."],
           "51-role-permissions",
           "Enforced on the server in three layers — 45 request-level gates, 15 policy classes and inline role checks. A cashier cannot reach your margins even by calling the API directly.", "ok")

gallery_slide("05.6", "Making it yours", "Store identity, payment channels and the safety net.", [
    ("20-settings", "Store profile. Name, address, contact, logo and receipt footer, plus the DEMO/LIVE switch and Pro/Master tier."),
    ("52-settings-store-profile", "Brand accent. This demo store runs purple (#8b2f9d); the shipped default is green. Applied live, not by rebuilding."),
    ("18-users", "Staff. Accounts with photos, roles and last-access tracking, searchable and filterable."),
    ("21-settings-payment", "Payment channels & invoice details. QR images uploaded once; account numbers masked to the last four digits for non-admins."),
], tail="Backup: one command copies the database AND the payment-proof images to an external drive. The backup is an ordinary MySQL dump — never a proprietary format.")

shot_slide("05.7", "Licensing", "How the product is protected",
           "One licence, one installation —\nverified entirely offline.",
           "A brand-new install is locked. Every screen bounces to activation, and the server refuses every request — including login itself — until a valid key is entered.",
           ["The vendor generates a key signed with a private key that never ships to a customer.",
            "You paste it in; the signature is checked against a public key baked into the app — no internet required.",
            "On success the licence binds to this machine's hardware fingerprint, stored one-way hashed.",
            "Copying an activated installation's data to another machine fails closed."],
           "53-license-activation",
           "Two disclosures: activation reads the fingerprint from Linux or macOS only — a WINDOWS MACHINE CANNOT CURRENTLY BE ACTIVATED. And an offline design cannot detect one key activated on two fresh machines.", "stop", flip=True)

cards_slide("05.7", "Licensing", "Pro vs Master", "Two tiers, one build.", [
    ("Tier — Pro", "A single-seller booth",
     "Everything in the product except multiple active sellers. Exactly one active seller is permitted, representing the shop itself; a second is refused.  Full till · pre-orders · profit reports · Excel import/export · purchasing & BOM."),
    ("Tier — Master", "A booth hosting other artists",
     "No limit on sellers, plus the per-seller settlement machinery that makes a consignment booth work.  Unlimited sellers · per-seller recap and payouts · per-seller float · seller cost report · stock by seller."),
], cols=2,
   tail="It is one setting, not two products. Switching changes what is permitted, not what is installed, and nothing recorded is lost either way. The limit is enforced on the server — including inside the Excel import, so a spreadsheet cannot be used as a free upgrade.")

# 06 FLOWS --------------------------------------------------------------------
chapter_slide("06", "End-to-end\nflow",
              "Four journeys traced from the first tap to the last database write — including, for the pre-order flow, exactly where stock moves and where it deliberately does not.", "06")

flow_slide("06.1", "Event day", "Open the till, sell, reconcile, close.", [
    ("Cashier", "Signs in with a username and password", "Login time stamped, device token issued", ""),
    ("Cashier", "Opens a shift: picks the event, counts the float, optionally splits it per seller", "Refused if a shift is open, or the event is not active", ""),
    ("System", "Creates the session in one all-or-nothing transaction", "Session row + per-seller float rows written together", "up"),
    ("Cashier", "Adds items from three different sellers and taps Pay", "Blocked entirely if no shift is open", ""),
    ("Cashier", "Takes Rp 50.000 cash, then the balance by QRIS with a photo of the proof", "Proof stored privately under a random filename", ""),
    ("System", "Checks the one-time reference before writing anything", "A repeat tap returns the original sale — never charges twice", "block"),
    ("System", "Prices the basket from master data, locking each variant row", "Client-supplied prices ignored entirely", ""),
    ("System", "Writes the order, its lines and its payments", "Stock DECREASES — one 'sale' movement per line", "down"),
    ("Cashier", "Shows the on-screen receipt for the buyer to photograph", "Store identity, event name, location and dates included", ""),
    ("Cashier", "At close, counts the drawer and enters the figure", "A difference requires a written note", ""),
    ("System", "Independently recomputes expected cash and the variance", "Session closed with opening, counted, expected and difference", ""),
])

flow_slide("06.2", "Pre-order across two events", "Where stock moves — and where it must not.", [
    ("Cashier", "Creates the pre-order: customer, items, expected date, pickup or courier", "NO stock movement — the goods do not exist yet", ""),
    ("System", "Writes the order and its lines, numbered PO-YYYYMMDD-0001", "Number unique across DEMO and LIVE alike", ""),
    ("Customer", "Pays a deposit — itself splittable across methods", "Status moves to Deposit paid. Still no stock movement", ""),
    ("Owner", "Weeks later the goods arrive; marks the order Arrived", "Stock INCREASES — a 'purchase' movement per line", "up"),
    ("System", "Emails the customer that the goods have landed", "Every send attempt logged — sent, failed or skipped", ""),
    ("Customer", "At the next event, pays the outstanding balance", "Status re-derives to Settled once fully paid", ""),
    ("System", "Handover requested", "REFUSED if any balance remains, or the item has sold out", "block"),
    ("Cashier", "Hands the goods over", "Stock DECREASES — a 'preorder_handover' movement per line", "down"),
    ("Cashier", "If shipping instead, records courier, tracking and address", "Shipment moves Pending → Packed → Shipped → Delivered", ""),
], tail="Net effect across the whole life of a pre-order: zero. One positive movement on arrival, one negative on handover — so pre-ordered goods pass through your stock ledger honestly.")

flow_slide("06.3", "Settling with the sellers", "From raw sale lines to a figure you can hand over.", [
    ("System", "Owner opens the Seller Recap for the finished event", "Refused (403) to anyone without report access", "block"),
    ("System", "Zeroes the existing settlement rows before re-aggregating", "A seller whose only order was voided cannot keep a stale figure", ""),
    ("System", "Sums counter sales per seller from the frozen order lines", "Uses the price and cost as they were at the moment of sale", ""),
    ("System", "Adds pre-order money — but only cash actually collected", "Read from real payment rows, never the cached paid-amount", ""),
    ("System", "Prorates that cash across the pre-order's lines by value share", "A half-paid order credits each seller exactly half", ""),
    ("System", "Produces one payable figure per seller", "Every active seller listed, including those who sold nothing", ""),
    ("Owner", "Clicks a seller to see the transactions behind the total", "Counter sales and pre-orders, itemised", ""),
    ("Owner", "Hands over the money and records the payout", "Status re-derives to Partial or Paid; export sends their copy", ""),
])

flow_slide("06.4", "First run on a new machine", "From a locked screen to ready to trade.", [
    ("Owner", "Opens the app; every route bounces to activation", "Server returns 423 Locked on every endpoint, login included", "block"),
    ("Owner", "Pastes the vendor-issued licence key", "Signature verified offline; bound to this machine's fingerprint", ""),
    ("Owner", "Signs in and fills in the store profile and logo", "Each setting change written to the activity log", ""),
    ("Owner", "Chooses the edition (Pro or Master) and mode (DEMO or LIVE)", "Two settings rows; every later record stamped with the mode", ""),
    ("Owner", "Creates roles and staff accounts", "Passwords hashed; menu access assigned per role", ""),
    ("Owner", "Downloads the master-data template and fills in the sheets", "Ten sheets in dependency order, with a worked example", ""),
    ("Owner", "Uploads with preview ticked", "Dry run reports every problem row, changing nothing", ""),
    ("System", "Real import runs in one transaction", "SKUs generated server-side; opening stock written as movements", "up"),
    ("Owner", "Creates the first event and hands the laptop to the cashier", "Ready to trade", ""),
])

# 07 RESULT -------------------------------------------------------------------
chapter_slide("07", "Result",
              "What actually exists today, measured rather than asserted. Every figure on the next slide was produced by running the command, not by quoting a document.", "07")

metrics_slide()

table_slide("07", "Result", "Ways to run it", "Three deployment paths, at three levels of maturity.",
            ["Path", "What it is", "Maturity"],
            [["**Native install", "PHP and MySQL 8 installed directly on the shop's laptop, served over localhost. The original and best-exercised path.", "**Shipped & proven"],
             ["**Docker store image", "A self-contained image with the build baked in, plus MySQL on a named volume so an upgrade never touches your data. Delivered by registry pull or as an offline .tar on a USB stick.", "Shipped, rough edges"],
             ["**Android tablet", "A design and code scaffold for running the whole stack on-device. Nothing has been compiled, signed or run on a device.", "Not a product yet"]],
            [2.5, 7.89, 1.5],
            tail="Across all of them: MySQL 8 is required (several migrations use raw MySQL-only CHECK constraints). Nothing phones home. The database is not reachable from outside the machine. Upgrades are a deliberate operator action and roll back by pointing the version tag at the previous image.")

# 08 GAP ----------------------------------------------------------------------
chapter_slide("08", "Gap",
              "A customer discovering an undisclosed limitation after paying is worse than being told upfront. 47 gaps were catalogued; these are the ones that would change a buying decision.", "08")

gaps_slide("08.1", "Before you buy", "Five things to check against your own situation.", [
    ("Blocker", "sec", "Windows machines cannot be activated",
     "The licence binds to a hardware fingerprint read only from Linux or macOS. On any other platform activation throws and the installation stays permanently locked."),
    ("Operational", "lim", "Backup is a command, not a button",
     "There is no backup or restore in the interface, and nothing is scheduled. Restore from a real external drive has not yet been exercised end to end."),
    ("Operational", "lim", "MySQL 8 must exist on the machine",
     "There is no lightweight embedded-database option. Either install and maintain MySQL 8 yourself, or take the Docker path, which bundles it."),
    ("Setup", "lim", "Email needs configuration or it silently does nothing",
     "Pre-order notifications only work if mail settings are filled in at install. Miss it and the attempt is logged as skipped rather than failing loudly. Mail is sent synchronously, so a slow server makes saving feel like a hang."),
    ("Security", "sec", "Change the seeded passwords",
     "The development seeder creates five accounts sharing one publicly documented password. No independent penetration test has been performed; the review to date is white-box code review only."),
])

gaps_slide("08.2", "Rough edges", "Things that work, but not as completely as they look.", [
    ("Payments", "lim", "Payment proofs are collected but never verified in-app",
     "Non-cash payments stay 'pending' and nothing marks them verified. The image is stored safely but no screen displays it back. As a result the shift's payment-method breakdown reports cash only."),
    ("Purchasing", "lim", "Material stock has no screen",
     "Receiving an order correctly increases material stock and writes its history, but there is nowhere in the interface to view either. Non-cash supplier payments do not complete."),
    ("Costing", "lim", "Recipe cost does not feed profit reports",
     "Deliberate — overwriting the cost price that settlements depend on would be a correctness risk. But an accurate bill of materials does not by itself improve any profit figure."),
    ("Exports", "lim", "Six screens have an export button; four exports have none",
     "Vendor prices, recipes, roles and users are exportable only by calling the endpoint directly. The invoice import template also ships with a dummy row that must be deleted first."),
    ("Billing", "lim", "Saved bank details never reach the invoice",
     "Settings → Payment stores bank name, account number and holder, but only the free-text instructions box is copied onto an invoice."),
])

cards_slide("08.3", "Gap", "Roadmap", "What the gaps point to next.", [
    ("Near term", "Close the honesty gaps",
     "Windows fingerprint support. A backup button in the interface. Payment-proof viewing and verification. A material stock screen. The missing export buttons."),
    ("Medium term", "Operational hardening",
     "Scheduled backups, restore rehearsed against real removable media, dependency vulnerability scanning, security headers, and a CI pipeline so a release never depends on someone remembering to run the tests."),
    ("Longer term", "The Android tablet build",
     "Fully specified with two rejected alternatives documented, but the runtime binaries are not sourced and the bundled database's licensing obligations are unresolved. Treat any date as unestimated."),
], cols=3,
   tail="Deliberately NOT on this roadmap: a cloud tier, an online storefront, a subscription, or an artist self-service portal. Those would change what BoothPOS is — a local, one-time-licence till that holds your data on your own machine.")

gaps_slide("08.4", "Internal only — remove before sending to a customer", "Delivery-side findings.", [
    ("Verification", "sec", "Feature 019 (billing & invoicing) shipped without its manual verification steps completed",
     "The least-verified area in the product. The fact-check preparing this deck independently found eight inaccuracies there."),
    ("Process", "sec", "No CI pipeline",
     "Every release depends on a developer remembering to run the suite locally. Feature 015 showed the risk when ten always-broken tests went unnoticed."),
    ("Docs", "lim", "Project documentation carries stale claims",
     "CLAUDE.md states 214 backend and 44 frontend tests; the measured figures are 492 and 223. The schema document covers roughly half the current schema."),
    ("Security", "sec", "Unscanned and unreviewed areas",
     "Dependency vulnerability scanning has never been run. Formula injection is possible on every Excel export. Payment-proof images have no retention or deletion path."),
    ("Docker", "lim", "Two concrete defects in the store deployment path",
     "The release tarball's image tag does not match the compose file default, so the offline start command falls back to building from source. And storage:link is never run, so uploaded images 404."),
])

# Appendix gallery ------------------------------------------------------------
gallery_slide("A1", "Every remaining screen · 1 of 2", "Selling, stock and the day's paperwork.", [
    ("01-login", "Sign in. A username, not an email. Always Indonesian, whatever the interface language."),
    ("03-pos", "The till at rest. Product grid, seller and category filters, empty cart, draft controls."),
    ("29-pos-payment", "Payment, single method. Tendered-cash shortcuts and automatic change."),
    ("47-session-overview", "Shift overview. Live transaction count, takings and float while trading."),
    ("37-sales-items-popup", "Products sold. The line detail behind any past transaction."),
    ("41-product-detail", "Product detail. Every variant with its own item code, price and stock."),
    ("43-stock-adjustment", "Stock adjustment. A reason is mandatory; each line writes one movement."),
    ("50-event-detail", "Event. Name, location, dates and the event cost used by the profit report."),
])

gallery_slide("A2", "Every remaining screen · 2 of 2", "Purchasing, administration and the business side.", [
    ("13-purchase-orders", "Purchase orders. Supplier, status, date and value per order."),
    ("19-roles", "Roles. Four ship ready; a role is just a set of screen permissions."),
    ("22-profile", "Profile. Each person changes their own password and photo."),
    ("25-invoices", "Invoice list. Summary tiles above, status filter on the list."),
    ("23-companies", "Companies. An internal onboarding tracker, not a multi-tenant runtime."),
    ("24-licenses", "Licence catalogue. Each package with tier, price and payment label."),
    ("45-invoice-document", "Invoice. Generated number, frozen money totals, PDF download."),
    ("46-company-detail", "Company. Business type, licence package, contact and the owner account."),
], tail="All 53 screenshots were captured from the running application in a real browser at 1440x900, against the seeded demo dataset. No mockups, no composites, no retouching.")

# Close -----------------------------------------------------------------------
s = slide(dark=True)
rect(s, MX, In(0.62), In(0.3), In(0.3), fill=BRAND, line_col=None, radius=0.22)
_m = box(s, MX, In(0.665), In(0.3), In(0.22))
_p = para(_m, "B", 12, INK, font=DISPLAY, bold=True, first=True); _p.alignment = PP_ALIGN.CENTER
tf = box(s, MX + In(0.42), In(0.66), In(4), In(0.26))
para(tf, "B o o t h P O S", 10.5, MINT, font=MONO, bold=True, first=True)
tf = box(s, MX, In(1.7), In(7.2), In(2.2))
para(tf, "Sell all weekend.\nSettle the same evening.", 40, WHITE, font=DISPLAY, bold=True, first=True, line=1.05)
tf = box(s, MX, In(3.75), In(6.8), In(1.2))
para(tf, "One laptop, one licence, no subscription and no cloud. Every sale tied to its event "
         "and its seller, every figure computed by the server, and an honest list of what it "
         "does not yet do.", 13, DARKMUT, first=True, line=1.42)
rect(s, In(8.42), In(2.5), In(4.2), In(2.5), fill=INK2, line_col=None)
tf = box(s, In(8.62), In(2.7), In(3.8), In(2.1))
para(tf, "Try it before you commit", 8, MINT, font=MONO, bold=True, first=True, caps=True)
para(tf, "Switch the installation to DEMO mode and rehearse a complete event day — open a shift, "
         "take split payments, run a pre-order through arrival and handover, settle every seller — "
         "on data that never touches your real books.", 11, WHITE, space_before=8, line=1.4)
para(tf, "Then switch to LIVE and start trading.", 10, MINT, space_before=8)
footer(s, "Deck compiled 10 September 2026 from the running application. Feature claims checked against source: 66 confirmed, 41 corrected before publication.", dark=True)

prs.save(str(OUT))
print(f"saved {OUT}  ({OUT.stat().st_size/1024/1024:.2f} MB, {len(prs.slides.__iter__.__self__._sldIdLst)} slides)")
