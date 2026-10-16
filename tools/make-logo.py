# KİNETİK logo üretici.
# Barlow Condensed Black Italic harf kontürlerini fontTools ile okur, skia-pathops ile "hız kesiği"
# (soldan sağa incelen kama) harflerden boolean olarak çıkarır ve sonucu düz SVG yollarına yazar —
# çıktıda font bağımlılığı yok, maske yok; tişört baskısına/nakışa doğrudan gidebilir.
#
# Kurulum (proje dışında bir venv önerilir):
#   python3 -m venv /tmp/kinetik-logo && /tmp/kinetik-logo/bin/pip install fonttools skia-pathops pillow
#   /tmp/kinetik-logo/bin/python tools/make-logo.py
# Fontlar yoksa tools/fonts/ altına Google Fonts deposundan indirilir (OFL).
# PNG/ICO için sistemdeki Google Chrome (headless) kullanılır; Pillow küçültür ve .ico üretir.
#
# Çıktı: themes/kinetik/assets/logo/
#   kinetik-wordmark.svg / -light.svg / -volt.svg   yatay wordmark (koyu harf / açık harf), volt İ noktaları
#   kinetik-mark.svg / -light.svg / -mono.svg       "K" monogramı (göğüs baskısı için -mono tek renk)
#   kinetik-inline.svg, kinetik-mark-inline.svg     currentColor + .logo-accent sınıfı (tema header'ı)
#   favicon.svg, favicon.ico (16/32/48), favicon-32.png, apple-touch-icon.png (180), icon-512.png
#   kinetik-wordmark.png / -light.png (2400px), kinetik-mark.png / -light.png (1024px), og-logo.png
#
# Başka bir marka adı için WORD / MARK sabitlerini değiştirin (İ için "I" yazın, DOTTED listesine konumunu ekleyin).
import math, os, subprocess, sys, tempfile, urllib.request
from fontTools.ttLib import TTFont
from fontTools.pens.transformPen import TransformPen
from fontTools.pens.svgPathPen import SVGPathPen
import pathops
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "themes/kinetik/assets/logo")
FONT_DIR = os.path.join(ROOT, "tools/fonts")
NAME = "KİNETİK"
WORD = "KINETIK"      # İ'ler noktasız I glifi + volt nokta olarak çizilir
DOTTED = [1, 5]       # WORD içinde noktalı İ konumları
MARK = "K"
FONT = os.path.join(FONT_DIR, "BarlowCondensed-BlackItalic.ttf")
CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"

INK, PAPER, VOLT = "#0D0D0D", "#F3F1EC", "#C8FF2E"

os.makedirs(OUT, exist_ok=True)
if not os.path.exists(FONT):
    os.makedirs(FONT_DIR, exist_ok=True)
    urllib.request.urlretrieve("https://github.com/google/fonts/raw/main/ofl/barlowcondensed/BarlowCondensed-BlackItalic.ttf", FONT)

TT = TTFont(FONT)
GS = TT.getGlyphSet()
CMAP = TT.getBestCmap()
CAP = 700
SHEAR = math.tan(math.radians(6))  # fontun 7°'lik eğimine ek 6° → toplam ~13°, daha atak


def glyph_path(ch, x):
    """Glif kontürü, y aşağı koordinatlarda (taban çizgisi y=0), ek eğimle."""
    p = pathops.Path()
    g = GS[CMAP[ord(ch)]]
    # x' = x + shear*y_font ; y' = -y_font
    g.draw(TransformPen(p.getPen(), (1, 0, SHEAR, -1, x, 0)))
    return p, g.width


def word(txt, track):
    out = pathops.Path()
    x = 0
    spans = []
    for ch in txt:
        if ch == " ":
            x += 150
            spans.append(None)
            continue
        p, adv = glyph_path(ch, x)
        out = pathops.op(out, p, pathops.PathOp.UNION)
        spans.append((x, adv))
        x += adv + track
    return out, x - track, spans


def poly(points):
    p = pathops.Path()
    pen = p.getPen()
    pen.moveTo(points[0])
    for pt in points[1:]:
        pen.lineTo(pt)
    pen.closePath()
    return p


def wedge(x0, x1, y_mid, h0):
    """Hız kesiği: x0'da h0 kalınlığında, x1'de sıfıra inen kama. Uçları eğimle paralel."""
    top, bot = y_mid - h0 / 2, y_mid + h0 / 2
    return poly([(x0 + SHEAR * -top, top), (x1, y_mid), (x0 + SHEAR * -bot, bot)])


def tab(cx, cy, w, h):
    """Volt sekme: eğimle aynı açıda paralelkenar (İ noktası / monogram çizgisi)."""
    dx = SHEAR * h / 2 + h * 0.18
    return [(cx - w / 2 + dx, cy - h / 2), (cx + w / 2 + dx, cy - h / 2), (cx + w / 2 - dx, cy + h / 2), (cx - w / 2 - dx, cy + h / 2)]


def svgd(path):
    pen = SVGPathPen(None, ntos=lambda v: ("%.1f" % v).rstrip("0").rstrip("."))
    path.draw(pen)
    return pen.getCommands()


def pts_d(pts):
    return "M" + " L".join("%.1f,%.1f" % p for p in pts) + " Z"


# ---------------------------------------------------------------- wordmark
TRACK = 18
letters, wend, spans = word(WORD, TRACK)
# İ: "I" glifinin üstüne volt paralelkenar nokta
SLANT = math.tan(math.radians(7)) + SHEAR  # fontun kendi eğimi + ek eğim
dots = []
for k in DOTTED:
    ib = glyph_path("I", spans[k][0])[0].bounds
    i_cx = (ib[0] + ib[2]) / 2 + SLANT * (845 - CAP / 2)  # gövdenin eğik ekseni üzerinde, nokta yüksekliğinde
    dots.append(tab(i_cx, -845, 150, 92))
# Hız kesiği: K'nin solundan başlayıp "Kİ"nin sonunda sıfırlanan kama, harf yüksekliğinin %34'ünde
cut = wedge(-80, spans[2][0] - 10, -CAP * 0.34, 72)
letters = pathops.op(letters, cut, pathops.PathOp.DIFFERENCE)
WM_D = svgd(letters)
DOTS_D = " ".join(pts_d(d) for d in dots)
b = letters.bounds
WM_BOX = (b[0] - 40, -845 - 46 - 40, (b[2] + 40) - (b[0] - 40), (b[3] + 40) - (-845 - 46 - 40))


def wordmark(fill, acc, cls=False, size=None, bg=None):
    x, y, w, h = WM_BOX
    sz = f' width="{size[0]}" height="{size[1]}"' if size else ""
    lc = ' class="logo-letters"' if cls else ""
    ac = ' class="logo-accent"' if cls else ""
    bgr = f'<rect x="{x:.0f}" y="{y:.0f}" width="{w:.0f}" height="{h:.0f}" fill="{bg}"/>' if bg else ""
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{x:.0f} {y:.0f} {w:.0f} {h:.0f}"{sz} role="img" aria-label="{NAME}">'
            f'<title>{NAME}</title>{bgr}<path{lc} fill="{fill}" d="{WM_D}"/><path{ac} fill="{acc}" d="{DOTS_D}"/></svg>')


# ---------------------------------------------------------------- monogram K
m_letters, m_end, m_spans = word(MARK, -28)
mcut = wedge(-60, m_spans[-1][0] + m_spans[-1][1] * 1.05, -CAP * 0.34, 84)
m_letters = pathops.op(m_letters, mcut, pathops.PathOp.DIFFERENCE)
mb = m_letters.bounds
bar = tab((mb[0] + mb[2]) / 2 - 10, 120, (mb[2] - mb[0]) * 0.86, 84)
M_D = svgd(m_letters)
m_minx = min(mb[0], min(p[0] for p in bar))
m_maxx = max(mb[2], max(p[0] for p in bar))
m_miny, m_maxy = mb[1], max(p[1] for p in bar)


def mark(fill, acc, cls=False, pad=60, square=False, bg=None, radius=0, size=None, scale=1.0):
    w = m_maxx - m_minx
    h = m_maxy - m_miny
    if square:
        side = max(w, h) / scale + pad * 2
        x = (m_minx + m_maxx) / 2 - side / 2
        y = (m_miny + m_maxy) / 2 - side / 2
        vb = (x, y, side, side)
    else:
        vb = (m_minx - pad, m_miny - pad, w + pad * 2, h + pad * 2)
    sz = f' width="{size}" height="{size}"' if size else ""
    lc = ' class="logo-letters"' if cls else ""
    ac = ' class="logo-accent"' if cls else ""
    bgr = f'<rect x="{vb[0]:.0f}" y="{vb[1]:.0f}" width="{vb[2]:.0f}" height="{vb[3]:.0f}" rx="{radius * vb[2]:.0f}" fill="{bg}"/>' if bg else ""
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb[0]:.0f} {vb[1]:.0f} {vb[2]:.0f} {vb[3]:.0f}"{sz} role="img" aria-label="{NAME}">'
            f'<title>{NAME}</title>{bgr}<path{lc} fill="{fill}" d="{M_D}"/><path{ac} fill="{acc}" d="{pts_d(bar)}"/></svg>')


def write(name, svg):
    with open(os.path.join(OUT, name), "w") as f:
        f.write(svg)


write("kinetik-wordmark.svg", wordmark(INK, INK))           # tek renk siyah (açık zemin): nokta da siyah — volt açık zeminde kaybolur
write("kinetik-wordmark-volt.svg", wordmark(INK, VOLT))     # siyah harf + volt nokta (koyu kenarlı volt zemin dışı kullanım için)
write("kinetik-wordmark-light.svg", wordmark(PAPER, VOLT))  # koyu zemin: kırık beyaz + volt nokta (ana kullanım)
write("kinetik-inline.svg", wordmark("currentColor", "currentColor", cls=True))
write("kinetik-mark.svg", mark(INK, INK))
write("kinetik-mark-light.svg", mark(PAPER, VOLT))
write("kinetik-mark-mono.svg", mark("#000", "#000"))
write("kinetik-mark-inline.svg", mark("currentColor", "currentColor", cls=True))
FAV = mark(PAPER, VOLT, square=True, bg=INK, radius=0.18, pad=0, scale=0.78)
write("favicon.svg", FAV)


# ---------------------------------------------------------------- raster (Chrome headless)
def render(svg, w, h, name, bg="transparent"):
    with tempfile.TemporaryDirectory() as td:
        html = os.path.join(td, "r.html")
        with open(html, "w") as f:
            f.write(f'<html><body style="margin:0;background:{bg}"><div style="width:{w}px;height:{h}px">'
                    + svg.replace("<svg ", f'<svg width="{w}" height="{h}" ', 1) + "</div></body></html>")
        out = os.path.join(OUT, name)
        subprocess.run([CHROME, "--headless=new", "--disable-gpu", "--hide-scrollbars", "--force-device-scale-factor=1",
                        "--default-background-color=00000000", f"--window-size={w},{h}", f"--screenshot={out}", "file://" + html],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        im = Image.open(out).convert("RGBA").crop((0, 0, w, h))
        im.save(out)
        return im


x, y, w, h = WM_BOX
W = 2400
H = round(W * h / w)
render(wordmark(INK, INK), W, H, "kinetik-wordmark.png")
render(wordmark(PAPER, VOLT), W, H, "kinetik-wordmark-light.png")
render(mark(INK, INK, square=True, pad=40), 1024, 1024, "kinetik-mark.png")
render(mark(PAPER, VOLT, square=True, pad=40), 1024, 1024, "kinetik-mark-light.png")
big = render(FAV, 1024, 1024, "_fav-1024.png")
big.resize((512, 512), Image.LANCZOS).save(os.path.join(OUT, "icon-512.png"))
big.resize((180, 180), Image.LANCZOS).save(os.path.join(OUT, "apple-touch-icon.png"))
big.resize((32, 32), Image.LANCZOS).save(os.path.join(OUT, "favicon-32.png"))
big.save(os.path.join(OUT, "favicon.ico"), sizes=[(16, 16), (32, 32), (48, 48)])
os.remove(os.path.join(OUT, "_fav-1024.png"))
# OG / e-posta için koyu zeminli wordmark (1200×630)
og = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630"><rect width="1200" height="630" fill="{INK}"/>'
      f'<g transform="translate(150,215) scale({900 / w:.4f}) translate({-x:.0f},{-y:.0f})">'
      f'<path fill="{PAPER}" d="{WM_D}"/><path fill="{VOLT}" d="{DOTS_D}"/></g></svg>')
render(og, 1200, 630, "og-logo.png")
print("logo set →", OUT)
for fn in sorted(os.listdir(OUT)):
    print("  ", fn, os.path.getsize(os.path.join(OUT, fn)))
