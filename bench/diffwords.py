import json, subprocess, sys, os
f = sys.argv[1]
env = dict(os.environ, PYTHONPATH=".pyvendor-linux")
py = json.loads(subprocess.run(["python3", "bench/dump_py.py", f], capture_output=True, env=env).stdout)
php = json.loads(subprocess.run(["php", "-n", "bench/dump_php.php", f], capture_output=True).stdout)
for i, (a, b) in enumerate(zip(py["pages"], php["pages"])):
    n = 0
    for x, y in zip(a["words"], b["words"]):
        if x != y:
            print(i + 1, x, y); n += 1
            if n > 4: break
    if len(a["words"]) != len(b["words"]): print("count", len(a["words"]), len(b["words"]))
    if n: break
