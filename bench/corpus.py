"""The comparison corpus: sample PDFs and their passwords."""
import glob
import os

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
PASSWORDS = {
    "password-example.pdf": "test",
    "aes-256-r6.pdf": "usersecret",
}


def corpus():
    patterns = [
        "tests/fixtures/*.pdf",
        "bench/samples/*.pdf",
        ".src/pdfplumber/tests/pdfs/*.pdf",
        ".src/pdfplumber/tests/pdfs/from-oss-fuzz/load/*.pdf",
        ".src/pdfminer.six/samples/*.pdf",
        ".src/pdfminer.six/samples/*/*.pdf",
    ]
    files = []
    for pattern in patterns:
        files += sorted(glob.glob(os.path.join(ROOT, pattern)))
    return files


def password(path):
    name = os.path.basename(path)
    if "/encryption/" in path:
        return PASSWORDS.get(name, "foo")
    return PASSWORDS.get(name, "")
