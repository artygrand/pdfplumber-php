import json, sys, time
t0 = time.perf_counter()
import pdfplumber
t1 = time.perf_counter()
chars = 0
with pdfplumber.open(sys.argv[1], password=(sys.argv[2] if len(sys.argv) > 2 else None) or None) as pdf:
    for page in pdf.pages:
        text = page.extract_text()
        chars += len(text)
        page.close()
t2 = time.perf_counter()
print(json.dumps({"import_ms": (t1 - t0) * 1000, "work_ms": (t2 - t1) * 1000, "text_len": chars}))
