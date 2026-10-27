"""Time and memory of pdfplumber vs pdfplumber-php: open a PDF and extract the text of every page.

Each run is a fresh process; the table shows the median of N runs. Run inside the PHP container:
    php -r 'passthru("PYTHONPATH=.pyvendor-linux python3 bench/bench.py");'
"""
import json
import os
import statistics
import subprocess
import sys
import time

from corpus import ROOT, password

FILES = [
    "tests/fixtures/latin.pdf",
    "tests/fixtures/jp-cid.pdf",
    "tests/fixtures/cn-cid.pdf",
    "bench/samples/ttf-embedded.pdf",
    ".src/pdfplumber/tests/pdfs/nics-background-checks-2015-11.pdf",
    ".src/pdfplumber/tests/pdfs/federal-register-2020-17221.pdf",
    ".src/pdfplumber/tests/pdfs/WARN-Report-for-7-1-2015-to-03-25-2016.pdf",
    ".src/pdfplumber/tests/pdfs/chelsea_pdta.pdf",
    ".src/pdfminer.six/samples/nonfree/i1040nr.pdf",
    ".src/pdfminer.six/samples/nonfree/kampo.pdf",
    "bench/samples/report-300.pdf",
]
RUNS = int(os.environ.get("RUNS", "3"))
PHP = ["php", "-n", "-d", "memory_limit=-1"]
VARIANTS = {
    "pdfplumber": lambda f, pw: [sys.executable, os.path.join(ROOT, "bench/run_py.py"), f, pw],
    "php": lambda f, pw: PHP + [os.path.join(ROOT, "bench/run_php.php"), f, pw],
    "php+jit": lambda f, pw: PHP + ["-d", "opcache.enable_cli=1", "-d", "opcache.jit=tracing",
                                    "-d", "opcache.jit_buffer_size=128M", os.path.join(ROOT, "bench/run_php.php"), f, pw],
}


def measure(cmd):
    start = time.perf_counter()
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            env=dict(os.environ, PYTHONPATH=os.path.join(ROOT, ".pyvendor-linux")))
    out, err = proc.communicate()
    _, _, usage = os.wait4(proc.pid, 0) if False else (None, None, None)
    wall = (time.perf_counter() - start) * 1000
    return wall, out, err


def run(cmd):
    start = time.perf_counter()
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            env=dict(os.environ, PYTHONPATH=os.path.join(ROOT, ".pyvendor-linux")))
    out = b""
    while True:
        chunk = proc.stdout.read()
        if not chunk:
            break
        out += chunk
    _, status, usage = os.wait4(proc.pid, 0)
    wall = (time.perf_counter() - start) * 1000
    data = json.loads(out.decode() or "{}")
    return wall, usage.ru_maxrss / 1024, data


def main():
    rows = []
    print(f"{'file':52} {'pages':>5} | {'variant':10} {'wall ms':>9} {'work ms':>9} {'max RSS MB':>10}")
    for rel in FILES:
        path = os.path.join(ROOT, rel)
        if not os.path.exists(path):
            continue
        pw = password(path)
        pages = None
        for name, build in VARIANTS.items():
            results = [run(build(path, pw)) for _ in range(RUNS)]
            wall = statistics.median(r[0] for r in results)
            rss = statistics.median(r[1] for r in results)
            work = statistics.median(r[2].get("work_ms", 0) for r in results)
            text_len = results[0][2].get("text_len")
            rows.append({"file": rel, "variant": name, "wall_ms": wall, "work_ms": work, "rss_mb": rss, "text_len": text_len})
            print(f"{os.path.basename(rel)[:52]:52} {'':>5} | {name:10} {wall:9.0f} {work:9.0f} {rss:10.1f}  text={text_len}")
            sys.stdout.flush()
    with open(os.path.join(ROOT, "bench", "out", "bench.json"), "w") as f:
        json.dump(rows, f, indent=1)


main()
