#!/usr/bin/env python3
"""
Diffs reference_out.json (the original Python engine) against php_out.json
(the ported PHP engine), field by field. Exit 0 = parity, exit 1 = mismatch.

Floats are compared with a small absolute tolerance (default 0.01, i.e. one
paisa) to absorb PHP round-half-away-from-zero vs Python round-half-to-even
on the rare .xx5 boundary — anything larger is a real divergence.
"""
import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
TOL = 0.01

ref = json.loads((HERE / "reference_out.json").read_text())
php = json.loads((HERE / "php_out.json").read_text())

mismatches = []
missing = []

for key in sorted(ref):
    if key not in php:
        missing.append(key)
        continue
    r, p = ref[key], php[key]
    if "error" in p and not r:
        mismatches.append(f"{key}: PHP returned error {p['error']!r}, Python returned a result")
        continue
    for field, rv in r.items():
        pv = p.get(field, "<absent>")
        if isinstance(rv, (int, float)) and isinstance(pv, (int, float)):
            if abs(float(rv) - float(pv)) > TOL:
                mismatches.append(f"{key}.{field}: python={rv} php={pv} (Δ={abs(float(rv)-float(pv)):.4f})")
        elif isinstance(rv, bool) or isinstance(pv, bool):
            if bool(rv) != bool(pv):
                mismatches.append(f"{key}.{field}: python={rv} php={pv}")
        elif str(rv) != str(pv):
            mismatches.append(f"{key}.{field}: python={rv!r} php={pv!r}")

extra = sorted(set(php) - set(ref))

print(f"cases compared: {len(ref)}   fields/case: ~{len(next(iter(ref.values())))}")
if missing:
    print(f"\n  MISSING from php_out.json: {missing}")
if extra:
    print(f"\n  EXTRA in php_out.json (ignored): {extra}")

if mismatches:
    print(f"\n  {len(mismatches)} MISMATCH(ES):")
    for m in mismatches:
        print(f"    - {m}")
    sys.exit(1)

if missing:
    sys.exit(1)

print("\n  PARITY OK — every compared field matches within tolerance.")
