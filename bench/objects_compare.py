"""Compare pdfplumber's to_dict() with pdfplumber-php's toDict() on the corpus: every object and attribute.

Run inside the PHP container:  php -r 'passthru("PYTHONPATH=.pyvendor-linux python3 bench/objects_compare.py");'
"""
import json
import os
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor

from corpus import ROOT, corpus, password

PY_ENV = dict(os.environ, PYTHONPATH=os.path.join(ROOT, ".pyvendor-linux"))


def run(cmd, env=None):
    try:
        res = subprocess.run(cmd, capture_output=True, timeout=900, env=env)
        return json.loads(res.stdout.decode("utf-8", "replace") or "null") or {"pages": [], "error": res.stderr.decode()[-300:]}
    except Exception as e:  # noqa: BLE001
        return {"pages": [], "error": f"harness: {e}"}


LAPARAMS = os.environ.get("LAPARAMS")


def dump(path):
    pw = password(path)
    extra = [LAPARAMS] if LAPARAMS else []
    py = run([sys.executable, os.path.join(ROOT, "bench", "objects_py.py"), path, pw, *extra], PY_ENV)
    php = run(["php", "-d", "memory_limit=2G", os.path.join(ROOT, "bench", "objects_php.php"), path, pw, *extra])
    return path, py, php


def diff(a, b, where=""):
    """The first place where a and b differ, or None."""
    if isinstance(a, dict) and isinstance(b, dict):
        for key in list(a) + [k for k in b if k not in a]:
            if key not in a or key not in b:
                return f"{where}.{key}: {'missing in php' if key in a else 'extra in php'}"
            found = diff(a[key], b[key], f"{where}.{key}")
            if found:
                return found
        return None
    if isinstance(a, list) and isinstance(b, list):
        if len(a) != len(b):
            return f"{where}: {len(a)} items vs {len(b)}"
        for i, (x, y) in enumerate(zip(a, b)):
            found = diff(x, y, f"{where}[{i}]")
            if found:
                return found
        return None
    if a == b and (type(a) is bool) == (type(b) is bool):
        return None
    return f"{where}: py={json.dumps(a)[:120]} php={json.dumps(b)[:120]}"


def main():
    files = sys.argv[1:] or corpus()
    same = 0
    with ThreadPoolExecutor(max_workers=2) as pool:
        dumps = pool.map(dump, files)
        for path, py, php in dumps:
            same += report(path, py, php)
    print(json.dumps({"files": len(files), "identical": same}), flush=True)


def report(path, py, php):
    if True:
        name = os.path.relpath(path, ROOT)
        if py["error"] or php["error"]:
            status = "ERR " if bool(py["error"]) != bool(php["error"]) else "both-err"
            print(f"{status} {name}: py={py['error']} php={(php['error'] or '')[:200]}", flush=True)
            return 0
        found = diff(py["metadata"], php["metadata"], "metadata") or diff(py["pages"], php["pages"], "pages")
        if found:
            print(f"DIFF {name}: {found}", flush=True)
            return 0
        return 1


main()
