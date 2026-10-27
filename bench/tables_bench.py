"""Median time and peak memory of extract_tables() on every page, pdfplumber against pdfplumber-php.

Run inside the PHP container:  php -r 'passthru("PYTHONPATH=.pyvendor-linux python3 bench/tables_bench.py");'
"""
import json
import os
import statistics
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
FILES = [os.path.join(ROOT, ".src/pdfplumber/tests/pdfs", f) for f in (
    "nics-background-checks-2015-11.pdf", "pr-138-example.pdf", "senate-expenditures.pdf", "issue-140-example.pdf",
)] + [os.path.join(ROOT, "tests/fixtures/tables.pdf")]
PY = """
import resource, sys, time, pdfplumber
t = time.perf_counter()
with pdfplumber.open(sys.argv[1]) as pdf:
    n = sum(len(p.extract_tables()) for p in pdf.pages)
print((time.perf_counter() - t) * 1000, resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024, n)
"""
PHP = """<?php
require '%s/vendor/autoload.php';
$t = hrtime(true);
$n = 0;
foreach (Artygrand\\Pdfplumber\\Pdf::open($argv[1])->pages() as $page) { $n += count($page->extractTables()); $page->close(); }
echo (hrtime(true) - $t) / 1e6, ' ', memory_get_peak_usage(true) / 1048576, ' ', $n, "\\n";
""" % ROOT


def measure(cmd):
    runs = [subprocess.run(cmd, capture_output=True, text=True).stdout.split() for _ in range(5)]
    return statistics.median(float(r[0]) for r in runs), max(float(r[1]) for r in runs), runs[0][2]


with open("/tmp/tables_bench.php", "w") as f:
    f.write(PHP)
rows = []
for path in FILES:
    py = measure([sys.executable, "-c", PY, path])
    php = measure(["php", "-n", "-d", "memory_limit=-1", "/tmp/tables_bench.php", path])
    rows.append({"file": os.path.basename(path), "py_ms": py[0], "py_mb": py[1], "php_ms": php[0], "php_mb": php[1],
                 "tables": [py[2], php[2]]})
    print(json.dumps(rows[-1]))
