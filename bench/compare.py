"""Run pdfplumber and pdfplumber-php on every corpus file and report where they differ.

Run inside the PHP container so both run on the same machine:
    php -r 'passthru("PYTHONPATH=.pyvendor-linux python3 bench/compare.py");'
"""
import json
import os
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor

from corpus import ROOT, corpus, password

OUT = os.path.join(ROOT, "bench", "out")
os.makedirs(OUT, exist_ok=True)
PY_ENV = dict(os.environ, PYTHONPATH=os.path.join(ROOT, ".pyvendor-linux"))


def run(cmd, env=None):
    try:
        res = subprocess.run(cmd, capture_output=True, timeout=600, env=env)
        return json.loads(res.stdout.decode("utf-8", "replace") or "null") or {"pages": [], "error": res.stderr.decode()[-500:]}
    except Exception as e:  # noqa: BLE001
        return {"pages": [], "error": f"harness: {e}"}


def dump(path):
    pw = password(path)
    py = run([sys.executable, os.path.join(ROOT, "bench", "dump_py.py"), path, pw], PY_ENV)
    php = run(["php", "-n", "-d", "memory_limit=-1", os.path.join(ROOT, "bench", "dump_php.php"), path, pw])
    return path, py, php


def close(a, b, eps=0.0):
    return abs(a - b) <= eps


def compare(py, php):
    result = {"pages": len(py["pages"]), "py_error": py["error"], "php_error": php["error"]}
    stats = dict(text=0, layout=0, words=0, chars=0, crop=0, within=0, char_total=0, char_match=0, pages_cmp=0,
                 graphics=0, tables=0)
    first = []
    if len(py["pages"]) != len(php["pages"]):
        first.append(f"page count {len(py['pages'])} vs {len(php['pages'])}")
    for i, (a, b) in enumerate(zip(py["pages"], php["pages"])):
        stats["pages_cmp"] += 1
        for key in ("text", "layout"):
            if a[key] == b[key]:
                stats[key] += 1
            elif len(first) < 6:
                first.append(f"p{i + 1} {key}: py={a[key][:160]!r}\n      php={b[key][:160]!r}")
        if a["words"] == b["words"]:
            stats["words"] += 1
        if a["graphics"] == b["graphics"]:
            stats["graphics"] += 1
        elif len(first) < 6:
            first.append(f"p{i + 1} graphics {len(a['graphics'])} vs {len(b['graphics'])}: "
                         f"{next((x, y) for x, y in zip(a['graphics'] + [None], b['graphics'] + [None]) if x != y)}")
        if all(a[k] == b[k] for k in ("tables", "tables_text", "tables_crop")):
            stats["tables"] += 1
        elif len(first) < 6:
            k = next(k for k in ("tables", "tables_text", "tables_crop") if a[k] != b[k])
            first.append(f"p{i + 1} {k}: py={json.dumps(a[k])[:200]}\n      php={json.dumps(b[k])[:200]}")
        stats["crop"] += sum(x == y for x, y in zip(a["crop"], b["crop"])) == len(a["crop"])
        stats["within"] += sum(x == y for x, y in zip(a["within"], b["within"])) == len(a["within"])
        ca, cb = a["chars"], b["chars"]
        stats["char_total"] += len(ca)
        matched = 0
        for x, y in zip(ca, cb):
            if x[0] == y[0] and all(close(p, q) for p, q in zip(x[1:5], y[1:5])):
                matched += 1
            elif len(first) < 6:
                first.append(f"p{i + 1} char: py={x} php={y}")
        stats["char_match"] += matched
        if len(ca) == len(cb) and matched == len(ca):
            stats["chars"] += 1
        elif len(ca) != len(cb) and len(first) < 6:
            first.append(f"p{i + 1} char count {len(ca)} vs {len(cb)}")
    result.update(stats)
    result["diffs"] = first
    return result


def main():
    files = sys.argv[1:] or corpus()
    with ThreadPoolExecutor(max_workers=4) as pool:
        dumps = list(pool.map(dump, files))
    rows = []
    totals = dict(files=0, identical=0, pages=0, text=0, chars=0, char_total=0, char_match=0)
    report = []
    for path, py, php in dumps:
        res = compare(py, php)
        name = os.path.relpath(path, ROOT)
        n = res["pages_cmp"]
        identical = (not res["diffs"] and res["text"] == n == res["pages"] and res["layout"] == n
                     and res["chars"] == n and res["words"] == n and res["crop"] == n and res["within"] == n
                     and res["graphics"] == n and res["tables"] == n
                     and bool(res["py_error"]) == bool(res["php_error"]))
        totals["files"] += 1
        totals["identical"] += identical
        totals["pages"] += n
        totals["text"] += res["text"]
        totals["chars"] += res["chars"]
        totals["char_total"] += res["char_total"]
        totals["char_match"] += res["char_match"]
        status = "OK " if identical else "DIFF"
        rows.append(f"{status} {name}: pages {n}, text {res['text']}/{n}, layout {res['layout']}/{n}, words {res['words']}/{n}, "
                    f"chars {res['char_match']}/{res['char_total']}, crop {res['crop']}/{n}, within {res['within']}/{n}, "
                    f"graphics {res['graphics']}/{n}, tables {res['tables']}/{n}")
        if not identical:
            report.append(f"## {name}\n")
            if res["py_error"] or res["php_error"]:
                report.append(f"py_error: {res['py_error']}\nphp_error: {res['php_error']}\n")
            report.extend(f"    {d}" for d in res["diffs"])
            report.append("")
    print("\n".join(rows))
    print(json.dumps(totals))
    with open(os.path.join(OUT, "compare.md"), "w") as f:
        f.write("\n".join(rows) + "\n\n" + json.dumps(totals) + "\n\n" + "\n".join(report))


main()
