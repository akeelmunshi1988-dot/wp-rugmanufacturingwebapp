# Quote-engine parity check

Proves the ported PHP pricing engine
(`wp-content/plugins/dreamrugs-core/includes/class-quote-engine.php`) produces
byte-for-byte the same numbers as the original FastAPI app's
`backend/app/services/quote_engine.py`, for a shared matrix of inputs — the
verification the migration plan calls for before trusting the port with real
money.

## Run it

```sh
# from repo root: stack must be up
docker compose up -d && ./setup.sh

cd tests/quote-engine-parity
./run.sh
```

`run.sh`:

1. `run_reference.py` drives the **unmodified** original engine (via the
   backend venv) over `cases.json` → `reference_out.json`. A tiny in-memory
   fake `db` session feeds it plain fixture objects — no database needed.
2. `run_php.php` runs inside the `wordpress` container: seeds the `wp_drc_*`
   config tables + `drc_settings` from the same `cases.json`, runs
   `DRC_Quote_Engine` over the same matrix → `php_out.json`.
3. `compare.py` diffs the two, field by field, with a 0.01 (one-paisa)
   float tolerance for PHP's round-half-away-from-zero vs Python's
   round-half-to-even. Exit 0 = parity.

## What's covered

`cases.json` → 12 input cases × GST-inclusive on/off = 24 rows, each compared
across ~25 output fields:

- catalog: basic, bulk-discount tier, rush surcharge (with the 7-day
  production floor), width/height-rotated size match, large-format surcharge,
  the `estimate_days` path (no per-size lead time — the case that regressed
  once), manual per-quote discount
- custom (cost-plus-margin): rect, circle (πr²), oval (ellipse), rush +
  discount + flat shipping-after-GST
- `build_manual_price_result` (vendor-typed flat price, GST backed out)

## Editing

Add rows to `cases.json`'s `cases` array. Both runners read it; keep the
fixture ids (`materials[].id`, `rugs[].id`) stable since cases reference them.
`reference_out.json` / `php_out.json` are regenerated each run and are
gitignored.
