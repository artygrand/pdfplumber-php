"""Dump what pdfplumber extracts from a PDF as JSON, for comparison with pdfplumber-php."""
import json
import sys

import pdfplumber

REGIONS = [(0, 0, 0.5, 0.5), (0.25, 0.25, 0.75, 0.75), (0, 0.8, 1, 1), (0, 0, 0.5, 1)]


def r(value):
    return float(value)


def region(page, rel):
    x0, top, x1, bottom = page.bbox
    w, h = x1 - x0, bottom - top
    return (x0 + rel[0] * w, top + rel[1] * h, x0 + rel[2] * w, top + rel[3] * h)


def main(path, password):
    out = {"pages": [], "error": None}
    try:
        with pdfplumber.open(path, password=password or None) as pdf:
            for page in pdf.pages:
                data = {
                    "width": r(page.width),
                    "height": r(page.height),
                    "bbox": [r(v) for v in page.bbox],
                    "chars": [[c["text"], r(c["x0"]), r(c["top"]), r(c["x1"]), r(c["bottom"]), c["fontname"],
                               r(c["size"]), bool(c["upright"])] for c in page.chars],
                    "text": page.extract_text(),
                    "layout": page.extract_text(layout=True),
                    "words": [[w["text"], r(w["x0"]), r(w["top"]), r(w["x1"]), r(w["bottom"])]
                              for w in page.extract_words()],
                    "crop": [page.crop(region(page, rel)).extract_text() for rel in REGIONS],
                    "within": [page.within_bbox(region(page, rel)).extract_text() for rel in REGIONS],
                    "graphics": [[g["object_type"], r(g["x0"]), r(g["top"]), r(g["x1"]), r(g["bottom"]),
                                  r(g["linewidth"]), [[r(x), r(y)] for x, y in g["pts"]]]
                                 for g in page.lines + page.rects + page.curves],
                    "tables": page.extract_tables(),
                    "tables_text": page.extract_tables({"vertical_strategy": "text", "horizontal_strategy": "text"}),
                    "tables_crop": page.crop(region(page, REGIONS[3])).extract_tables(),
                }
                out["pages"].append(data)
                page.close()
    except Exception as e:  # noqa: BLE001
        out["error"] = f"{type(e).__name__}: {e}"
    json.dump(out, sys.stdout, ensure_ascii=False)


main(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else "")
