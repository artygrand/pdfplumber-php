"""Dump pdfplumber's metadata and every page's to_dict() as JSON, for objects_compare.py."""
import json
import sys

import itertools

import pdfminer.layout
import pdfplumber

# pdfminer breaks ties between equal box distances by id(), a memory address, so the reading order
# of such boxes changes from run to run; creation order makes it repeatable, as pdfplumber-php does.
_sequence = itertools.count(1)
_init = pdfminer.layout.LTComponent.__init__


def _numbered(self, *args, **kwargs):
    self._sequence = next(_sequence)
    _init(self, *args, **kwargs)


pdfminer.layout.LTComponent.__init__ = _numbered
pdfminer.layout.id = lambda obj: obj._sequence
from pdfplumber.convert import Serializer


def clean(page):
    # to_dict() fails on figures (Page has no .figures), so they are added by hand.
    types = [t for t in page.objects if t != "figure"] + ["annot"]
    data = page.to_dict(types)
    if "figure" in page.objects:
        data["figures"] = page.objects["figure"]
    data = Serializer().serialize(data)
    for annot in data.get("annots", []):
        annot.pop("data", None)
    for figure in data.get("figures", []):
        if figure["name"].isdigit() and len(figure["name"]) > 8:
            figure["name"] = "inline"
    for image in data.get("images", []):
        image.pop("stream", None)
        if image["name"].isdigit() and len(image["name"]) > 8:
            image["name"] = "inline"
    return data


def main(path, password, laparams=None):
    out = {"metadata": None, "pages": [], "error": None}
    try:
        with pdfplumber.open(path, password=password or None, laparams=laparams) as pdf:
            out["metadata"] = Serializer().serialize(pdf.metadata)
            for page in pdf.pages:
                out["pages"].append(clean(page))
                page.close()
    except Exception as e:  # noqa: BLE001
        out["error"] = f"{type(e).__name__}: {e}"
    json.dump(out, sys.stdout)


main(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else "", json.loads(sys.argv[3]) if len(sys.argv) > 3 else None)
