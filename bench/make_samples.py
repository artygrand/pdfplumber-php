"""Generate sample PDFs for comparing pdfplumber-php with pdfplumber.

Fonts that are not embedded (core 14 and the Adobe CID fonts) go to tests/fixtures and can be
committed; files embedding a macOS font go to bench/samples, which is not committed.
"""
import os
import random

from fpdf import FPDF
from reportlab.lib.pagesizes import A4, landscape
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.cidfonts import UnicodeCIDFont
from reportlab.pdfgen import canvas

ROOT = os.path.dirname(os.path.abspath(__file__))
FIXTURES = os.path.join(ROOT, "..", "tests", "fixtures")
SAMPLES = os.path.join(ROOT, "samples")
ARIAL_UNICODE = "/System/Library/Fonts/Supplemental/Arial Unicode.ttf"

for name in ("HeiseiMin-W3", "HeiseiKakuGo-W5", "STSong-Light"):
    pdfmetrics.registerFont(UnicodeCIDFont(name))
_vertical = UnicodeCIDFont("HeiseiMin-W3", isVertical=True)
_vertical.name = _vertical.fontName = "HeiseiMin-W3-V"
pdfmetrics.registerFont(_vertical)

random.seed(7)


def invoice_jp(path):
    c = canvas.Canvas(path, pagesize=A4)
    w, h = A4
    c.setFont("HeiseiKakuGo-W5", 20)
    c.drawString(250, h - 70, "請求書")
    c.setFont("HeiseiMin-W3", 10)
    c.drawString(40, h - 110, "株式会社ロシャ運輸 御中")
    c.drawRightString(w - 40, h - 110, "発行日 2026年10月27日")
    c.drawRightString(w - 40, h - 125, "請求番号 INV-2026-0042")
    c.drawString(40, h - 150, "下記の通りご請求申し上げます。")
    c.setFont("HeiseiKakuGo-W5", 14)
    c.drawString(40, h - 180, "ご請求金額 ¥1,234,560-")
    y = h - 230
    c.setFont("HeiseiKakuGo-W5", 9)
    for x, label in ((40, "品名"), (300, "数量"), (380, "単価"), (480, "金額")):
        c.drawString(x, y, label)
    c.line(40, y - 4, w - 40, y - 4)
    c.setFont("HeiseiMin-W3", 9)
    items = ["タイヤ交換 195/65R15", "オイル交換(エンジン)", "ブレーキパッド 前輪", "車検整備一式",
             "陸送費 横浜港→名古屋", "保管料 10月分", "書類作成手数料", "洗車・室内清掃"]
    for i, item in enumerate(items):
        y -= 18
        qty = random.randint(1, 8)
        price = random.randint(10, 900) * 100
        c.drawString(40, y, item)
        c.drawRightString(330, y, str(qty))
        c.drawRightString(440, y, f"{price:,}")
        c.drawRightString(w - 40, y, f"{qty * price:,}")
    y -= 30
    c.drawRightString(440, y, "小計")
    c.drawRightString(w - 40, y, "1,122,327")
    c.drawRightString(440, y - 15, "消費税(10%)")
    c.drawRightString(w - 40, y - 15, "112,233")
    c.setFont("Helvetica", 8)
    c.drawString(40, 60, "Bank: MUFG Bank, Yokohama Branch, Account 1234567 (Futsu)")
    c.showPage()

    # Vertical writing.
    c.setFont("HeiseiMin-W3-V", 16)
    c.drawString(w - 80, h - 80, "縦書きのテキストです。")
    c.drawString(w - 110, h - 80, "二〇二六年十月二十七日")
    c.setFont("HeiseiMin-W3", 12)
    c.drawString(40, 100, "横書きの行も同じページにあります。")
    c.showPage()
    c.save()


def customs_cn(path):
    c = canvas.Canvas(path, pagesize=A4)
    w, h = A4
    for page in range(3):
        c.setFont("STSong-Light", 16)
        c.drawString(200, h - 60, "中华人民共和国海关出口货物报关单")
        c.setFont("STSong-Light", 9)
        c.drawString(40, h - 100, f"预录入编号: 2210202600012{page}  海关编号: 221020260001234567")
        c.drawString(40, h - 115, "境内发货人 上海汽车出口有限公司    运输方式 水路运输    贸易方式 一般贸易")
        y = h - 280 if page == 0 else h - 100
        for i in range(10):
            vin = "LSV" + "".join(random.choice("ABCDEFGHJKLMNPRSTUVWXYZ0123456789") for _ in range(14))
            c.drawString(90, y, f"{i + 1}  小轿车  机壳 {vin}  1辆  美元 {random.randint(9, 30)}000.00")
            y -= 16
        c.showPage()
    c.save()


def latin(path):
    c = canvas.Canvas(path, pagesize=A4)
    w, h = A4
    c.setFont("Helvetica", 11)
    c.drawString(50, h - 60, "Plain Helvetica: Invoice No. 2026/10-77, total EUR 1,234.50 (incl. 19% VAT)")
    c.setFont("Times-Roman", 11)
    c.drawString(50, h - 80, "Times with accents: Müller, café, naïve, Æsir, €, ß, ½, «quotes», — dash")
    c.setFont("Courier", 10)
    c.drawString(50, h - 100, "Courier 0123456789 |ID| 0000-1111-2222")
    t = c.beginText(50, h - 130)
    t.setFont("Helvetica-Bold", 12)
    t.setCharSpace(2)
    t.textLine("Character spacing 2pt")
    t.setCharSpace(0)
    t.setWordSpace(8)
    t.textLine("Word spacing eight points here")
    t.setWordSpace(0)
    t.setHorizScale(70)
    t.textLine("Horizontal scaling 70 percent")
    t.setHorizScale(100)
    t.setRise(4)
    t.textOut("Raised")
    t.setRise(0)
    t.textOut(" and back to baseline")
    c.drawText(t)
    c.saveState()
    c.translate(300, 400)
    c.rotate(30)
    c.setFont("Helvetica", 14)
    c.drawString(0, 0, "Rotated thirty degrees")
    c.restoreState()
    c.saveState()
    c.translate(100, 300)
    c.rotate(90)
    c.drawString(0, 0, "Vertical up text")
    c.restoreState()
    c.setFont("Helvetica", 9)
    for i in range(30):
        c.drawString(50 + (i % 3) * 170, 250 - (i // 3) * 12, f"Row {i:02d} amount {random.randint(1, 99999) / 100:.2f}")
    c.showPage()
    c.save()


def rotated(path):
    c = canvas.Canvas(path, pagesize=A4)
    w, h = A4
    for rotation in (0, 90, 180, 270):
        c.setPageRotation(rotation)
        c.setFont("Helvetica", 12)
        c.drawString(72, h - 72, f"Page rotated {rotation} degrees, top-left corner")
        c.drawString(72, 72, f"Bottom-left corner of the {rotation} page")
        c.drawRightString(w - 72, h / 2, "Right middle")
        c.showPage()
    c.setPageSize(landscape(A4))
    c.setPageRotation(0)
    c.drawString(72, 72, "Landscape page")
    c.showPage()
    c.save()


def boxes(path):
    c = canvas.Canvas(path, pagesize=(600, 800))
    c.setFont("Helvetica", 12)
    for i in range(3):
        c.drawString(100, 700 - i * 20, f"MediaBox and CropBox line {i}")
    c.drawString(40, 40, "Outside the crop box")
    c.setCropBox((50, 50, 550, 760))
    c.showPage()
    c.save()
    # Shift the MediaBox origin away from zero.
    data = open(path, "rb").read()
    data = data.replace(b"/MediaBox [ 0 0 600 800 ]", b"/MediaBox [ -20 30 580 830 ]")
    open(path, "wb").write(data)


def tables(path):
    """A ruled table with merged cells, a table drawn as filled rects, a borderless one, and a rotated page."""
    from reportlab.lib import colors
    from reportlab.platypus import Table, TableStyle

    def page(c, rotate):
        c.setPageRotation(rotate)
        c.setFont("HeiseiMin-W3", 10)
        c.drawString(40, 800, "輸出許可通知書 / Export permit")
        rows = [["No.", "車台番号", "型式", "重量 kg"]] + [
            [str(i), f"ZVW30-{random.randint(1000000, 9999999)}", "DAA-ZVW30", f"{random.randint(900, 1900):,}"]
            for i in range(1, 7)
        ] + [["合計", "", "", "8,450"]]
        table = Table(rows, colWidths=[40, 160, 110, 80])
        table.setStyle(TableStyle([
            ("FONT", (0, 0), (-1, -1), "HeiseiMin-W3", 9),
            ("GRID", (0, 0), (-1, -1), 0.5, colors.black),
            ("SPAN", (0, -1), (2, -1)),
            ("BACKGROUND", (0, 0), (-1, 0), colors.lightgrey),
        ]))
        table.wrapOn(c, 500, 400)
        table.drawOn(c, 40, 600)
        # Cell borders as thin filled rectangles, as many invoice generators draw them.
        for i, x in enumerate([40, 140, 260, 400]):
            c.rect(x, 420, 0.6, 120, stroke=0, fill=1)
        for y in [420, 460, 500, 540]:
            c.rect(40, y, 360.6, 0.6, stroke=0, fill=1)
        for row, y in enumerate([515, 475, 435]):
            for col, x in enumerate([45, 145, 265]):
                c.drawString(x, y, f"R{row}C{col} 値")
        # A table without rules, for the text strategy.
        for row in range(5):
            for col, x in enumerate([40, 180, 320]):
                c.drawString(x, 360 - row * 16, f"{['品名', '数量', '金額'][col]}{row}")
        c.showPage()

    c = canvas.Canvas(path, pagesize=A4)
    page(c, 0)
    page(c, 90)
    c.save()


def many_pages(path, pages):
    c = canvas.Canvas(path, pagesize=A4)
    w, h = A4
    words = ("lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut "
             "labore et dolore magna aliqua 2026 invoice total 1,234.56 JPY").split()
    for p in range(pages):
        c.setFont("Helvetica-Bold", 14)
        c.drawString(50, h - 50, f"Report page {p + 1}")
        c.setFont("Helvetica", 9)
        y = h - 80
        while y > 50:
            line = " ".join(random.choice(words) for _ in range(14))
            c.drawString(50, y, line)
            y -= 11
        c.showPage()
    c.save()


def embedded_ttf(path):
    pdf = FPDF()
    pdf.add_font("ArialUni", fname=ARIAL_UNICODE)
    pdf.add_page()
    pdf.set_font("ArialUni", size=12)
    lines = [
        "見積書 No. Q-2026-118 株式会社サンプル",
        "車台番号 ZVW30-1234567 型式 DAA-ZVW30 初度登録 平成27年3月",
        "整備内容: エンジンオイル交換、ワイパーブレード交換 合計 ¥48,400",
        "Счёт-фактура № 15 от 27.10.2026, ООО «Роша», сумма 12 345,00 ₽",
        "出口货物报关单 机壳 LSVAB4182E2049327 小轿车",
        "Mixed Latin and 日本語 in one line, then numbers 0123456789",
    ]
    for line in lines:
        pdf.cell(0, 10, line, new_x="LMARGIN", new_y="NEXT")
    pdf.set_font("ArialUni", size=8)
    for i in range(40):
        pdf.cell(60, 5, f"部品{i:03d}", border=1)
        pdf.cell(40, 5, f"{random.randint(1, 50)} 個", border=1)
        pdf.cell(40, 5, f"¥{random.randint(100, 99999):,}", border=1, new_x="LMARGIN", new_y="NEXT")
    pdf.output(path)


invoice_jp(os.path.join(FIXTURES, "jp-cid.pdf"))
customs_cn(os.path.join(FIXTURES, "cn-cid.pdf"))
latin(os.path.join(FIXTURES, "latin.pdf"))
rotated(os.path.join(FIXTURES, "rotated.pdf"))
boxes(os.path.join(FIXTURES, "boxes.pdf"))
tables(os.path.join(FIXTURES, "tables.pdf"))
many_pages(os.path.join(SAMPLES, "report-300.pdf"), 300)
if os.path.exists(ARIAL_UNICODE):
    embedded_ttf(os.path.join(SAMPLES, "ttf-embedded.pdf"))
print("done")


def encrypted():
    from pypdf import PdfReader, PdfWriter
    for algorithm, name in (("RC4-128", "encrypted-rc4.pdf"), ("AES-256", "encrypted-aes256.pdf")):
        writer = PdfWriter(clone_from=PdfReader(os.path.join(FIXTURES, "latin.pdf")))
        writer.encrypt(user_password="secret", owner_password="owner", algorithm=algorithm)
        with open(os.path.join(FIXTURES, name), "wb") as f:
            writer.write(f)


encrypted()
