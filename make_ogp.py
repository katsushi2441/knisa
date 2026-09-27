#!/usr/bin/env python3
"""knisa の OGP 画像（1200x630）。数字は焼き込まない（制度が変わったら嘘になる）。
構図: 左に題、右に「枠」を表す3つの升（つみたて・成長・生涯）を置き、売った分が翌年に戻る矢印。
マスコットは縦横比を変えない。"""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont
ROOT = Path(__file__).resolve().parent
OUT = ROOT / "knisa-ogp.png"
LOGO = Path("/home/kojima/work/exbridge_jp/images/logo-mark-128.png")
MASCOT = Path("/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png")
BLACK = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
BOLD = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
REG = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"
f = lambda p, s: ImageFont.truetype(p, s, index=0)
W, H = 1200, 630
INK, SUB, ACC, SOFT, LINE = (27, 37, 48), (95, 108, 120), (31, 111, 159), (231, 241, 248), (223, 229, 234)
im = Image.new("RGB", (W, H), (247, 249, 251)); d = ImageDraw.Draw(im)
d.rectangle([0, 0, W, 88], fill=(255, 255, 255)); d.line([(0, 88), (W, 88)], fill=LINE, width=2)
logo = Image.open(LOGO).convert("RGBA").resize((40, 40), Image.LANCZOS); im.paste(logo, (56, 24), logo)
d.text((108, 26), "株式会社エクスブリッジ", font=f(BOLD, 28), fill=INK)
d.text((W - 56, 30), "Kurage NISA 枠計算・試算", font=f(BOLD, 24), fill=ACC, anchor="ra")
# 題
d.text((56, 138), "新NISAの枠は", font=f(BLACK, 66), fill=INK)
d.text((56, 222), "いくら残っている？", font=f(BLACK, 66), fill=INK)
d.text((56, 306), "売った分は、いつ戻る？", font=f(BLACK, 58), fill=ACC)
d.text((56, 400), "取引を入れて、制度のとおりに計算。", font=f(REG, 28), fill=SUB)
d.text((56, 440), "積立の試算は「配当＋利益の伸び", font=f(REG, 28), fill=SUB)
d.text((56, 480), "−手数料」から、幅で出す。", font=f(REG, 28), fill=SUB)
# 右: 3つの升
x0, y0 = 760, 150
for i, (lab, fill) in enumerate([("つみたて", 0.55), ("成長", 0.35), ("生涯の枠", 0.7)]):
    y = y0 + i * 92
    d.rounded_rectangle([x0, y, x0 + 330, y + 64], radius=12, fill=(255, 255, 255), outline=LINE, width=2)
    d.rounded_rectangle([x0 + 4, y + 4, x0 + 4 + int(322 * fill), y + 60], radius=10, fill=SOFT)
    d.text((x0 + 18, y + 14), lab, font=f(BOLD, 28), fill=INK)
# 売った分が生涯の枠に戻ることを、升の下に一行で
d.text((x0 + 4, y0 + 268), "売った分は、翌年に復活", font=f(BOLD, 26), fill=ACC)
# マスコット（縦横比そのまま）
m = Image.open(MASCOT).convert("RGBA"); r = 130 / m.width
m = m.resize((130, int(m.height * r)), Image.LANCZOS); im.paste(m, (W - 56 - 130, H - 30 - m.height), m)
d.text((56, H - 70), "proto.exbridge.jp/knisa.php", font=f(BOLD, 26), fill=SUB)
im.save(OUT); print(OUT, im.size)
