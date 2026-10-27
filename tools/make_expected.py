"""Record pdfplumber's output for the test fixtures in tests/fixtures/expected/*.json.

Run with a Python that has pdfplumber installed:  .venv/bin/python tools/make_expected.py
"""
import glob
import itertools
import json
import os

import pdfminer.layout
import pdfplumber
from pdfplumber.convert import Serializer

# pdfminer breaks ties between equal box distances by id(), a memory address; creation order makes
# the reading order of such boxes repeatable, as pdfplumber-php does.
_sequence = itertools.count(1)
_init = pdfminer.layout.LTComponent.__init__


def _numbered(self, *args, **kwargs):
    self._sequence = next(_sequence)
    _init(self, *args, **kwargs)


pdfminer.layout.LTComponent.__init__ = _numbered
pdfminer.layout.id = lambda obj: obj._sequence

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
FIXTURES = os.path.join(ROOT, "tests", "fixtures")
REGIONS = [(0, 0, 0.5, 0.5), (0.25, 0.25, 0.75, 0.75), (0, 0.8, 1, 1)]
PASSWORDS = {"encrypted-rc4.pdf": "secret", "encrypted-aes256.pdf": "secret"}


def objects(page):
    """Every object of the page as to_dict() gives it, without annotation and image streams."""
    types = [t for t in page.objects if t != "figure"] + ["annot"]
    data = page.to_dict(types)
    if "figure" in page.objects:
        data["figures"] = page.objects["figure"]
    data = Serializer().serialize(data)
    for annot in data.get("annots", []):
        annot.pop("data", None)
    for key in ("figures", "images"):
        for item in data.get(key, []):
            item.pop("stream", None)
            if item["name"].isdigit() and len(item["name"]) > 8:
                item["name"] = "inline"
    return data


def region(page, rel):
    x0, top, x1, bottom = page.bbox
    w, h = x1 - x0, bottom - top
    return [x0 + rel[0] * w, top + rel[1] * h, x0 + rel[2] * w, top + rel[3] * h]


os.makedirs(os.path.join(FIXTURES, "expected"), exist_ok=True)
for path in sorted(glob.glob(os.path.join(FIXTURES, "*.pdf"))):
    name = os.path.basename(path)
    pages = []
    with pdfplumber.open(path, password=PASSWORDS.get(name)) as pdf:
        for page in pdf.pages:
            regions = [region(page, rel) for rel in REGIONS]
            pages.append({
                "width": page.width,
                "height": page.height,
                "bbox": list(page.bbox),
                "chars": len(page.chars),
                "text": page.extract_text(),
                "layout": page.extract_text(layout=True),
                "words": [[w["text"], w["x0"], w["top"], w["x1"], w["bottom"]] for w in page.extract_words()],
                "regions": regions,
                "crop": [page.crop(r).extract_text() for r in regions],
                "within": [page.within_bbox(r).extract_text() for r in regions],
                "outside": [page.outside_bbox(r).extract_text() for r in regions],
                "lines": [[l["text"], l["x0"], l["top"], l["x1"], l["bottom"]] for l in page.extract_text_lines()],
                "simple": page.extract_text_simple(),
                "dedupe": page.dedupe_chars().extract_text(),
                "graphics": [[g["object_type"], g["x0"], g["top"], g["x1"], g["bottom"]]
                             for g in page.lines + page.rects + page.curves],
                "edges": len(page.edges),
                "tables": page.extract_tables(),
                "tables_text": page.extract_tables({"vertical_strategy": "text", "horizontal_strategy": "text"}),
                "tables_layout": page.extract_tables({"text_layout": True}),
                "tables_crop": [page.crop(r).extract_tables() for r in regions],
                "table": page.extract_table(),
            })
    expected = {"password": PASSWORDS.get(name, ""), "pages": pages}
    with pdfplumber.open(path, password=PASSWORDS.get(name), laparams={}) as pdf:
        for page, want in zip(pdf.pages, pages):
            want["textboxes"] = [[b["object_type"], b["text"], b["x0"], b["top"], b["x1"], b["bottom"]]
                                 for b in page.objects.get("textboxhorizontal", [])
                                 + page.objects.get("textboxvertical", [])]
        if name == "objects.pdf":
            # The objects this fixture is made for, with and without layout analysis.
            expected["layout_objects"] = [objects(page) for page in pdf.pages]
    if name == "objects.pdf":
        with pdfplumber.open(path) as pdf:
            expected["metadata"] = Serializer().serialize(pdf.metadata)
            expected["objects"] = [objects(page) for page in pdf.pages]
            expected["hyperlinks"] = Serializer().serialize(pdf.hyperlinks)
            expected["structure"] = pdf.structure_tree
            expected["page_structure"] = [page.structure_tree for page in pdf.pages]
    with open(os.path.join(FIXTURES, "expected", name.replace(".pdf", ".json")), "w") as f:
        json.dump(expected, f, ensure_ascii=False)
    print(name, len(pages))
