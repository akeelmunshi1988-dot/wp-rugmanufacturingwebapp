#!/usr/bin/env python3
"""
Runs the ORIGINAL app's pricing engine (backend/app/services/quote_engine.py,
completely unmodified) over the shared cases.json matrix and writes
reference_out.json.

The engine only touches its `db` through .query(Model).filter(...).first()/.all()
and reads a Tenant object for settings — so a tiny in-memory fake session plus
plain attribute-bag fixtures is enough to exercise the real code path without a
database. This is the authoritative side of the parity check: run_php.php must
match this.

Usage:
    <repo>/backend/venv/bin/python run_reference.py            # writes reference_out.json
    BACKEND=/path/to/backend <any python with sqlalchemy> run_reference.py
"""
import json
import os
import sys
from pathlib import Path
from types import SimpleNamespace

HERE = Path(__file__).resolve().parent
CASES = json.loads((HERE / "cases.json").read_text())

BACKEND = os.environ.get(
    "BACKEND",
    str(HERE.parent.parent.parent / "RugManufactureCustomApp" / "backend"),
)
sys.path.insert(0, BACKEND)

from app.models.models import (  # noqa: E402
    RugCatalog, Material, PricingRule, MOQRule, ProductionTimeline, Tenant,
)
from app.services.quote_engine import QuoteEngine, build_manual_price_result  # noqa: E402


class _Q:
    """Minimal in-memory query stub. Understands just the shape the engine
    actually uses: `.filter(Column == literal)` (equality against a bind
    param), `.first()`, `.all()`. Enough to disambiguate rug id 1 vs 2,
    material id, MOQ rug_type, and timeline order_type."""

    def __init__(self, rows):
        self._rows = list(rows)

    def filter(self, *exprs):
        for ex in exprs:
            try:
                col = ex.left.key
                val = ex.right.value
            except AttributeError:
                continue  # not a simple Column == literal — ignore
            self._rows = [r for r in self._rows if getattr(r, col, None) == val]
        return self

    def first(self):
        return self._rows[0] if self._rows else None

    def all(self):
        return list(self._rows)


class _DB:
    def __init__(self, data):
        self._data = data

    def query(self, model):
        return _Q(self._data.get(model, []))


def build_db():
    s = CASES["settings"]
    tenant = Tenant(
        id=1,
        default_profit_margin_pct=s["default_profit_margin_pct"],
        rush_surcharge_pct=s["rush_surcharge_pct"],
        default_gst_pct=s["default_gst_pct"],
        large_format_threshold_sqm=s["large_format_threshold_sqm"],
        large_format_surcharge_pct=s["large_format_surcharge_pct"],
        base_currency=s["currency"],
        exchange_rates={},
    )
    materials = [Material(tenant_id=1, **m) for m in CASES["materials"]]
    rules = [PricingRule(**r) for r in CASES["pricing_rules"]]
    moq = [MOQRule(**m) for m in CASES["moq_rules"]]
    timelines = [ProductionTimeline(**t) for t in CASES["production_timelines"]]
    rugs = []
    for r in CASES["rugs"]:
        rugs.append(RugCatalog(
            id=r["id"], tenant_id=1, name=f"Rug {r['id']}", slug=f"rug-{r['id']}",
            sizes=r["sizes"], base_price=r["base_price"],
            base_price_currency=r["base_price_currency"], material_id=1,
            weave_type=r["weave_type"], lead_time_days=r["lead_time_days"],
        ))
    return _DB({
        Tenant: [tenant], Material: materials, PricingRule: rules,
        MOQRule: moq, ProductionTimeline: timelines, RugCatalog: rugs,
    }), tenant


def size_to_metres(label):
    w, h = label.lower().split("x")
    return float(w) * 0.3048, float(h) * 0.3048


def run_case(db, tenant, case, gst_inclusive):
    tenant.gst_inclusive = gst_inclusive
    engine = QuoteEngine(db, tenant_id=1)

    if case["kind"] == "catalog":
        w, h = size_to_metres(case["size"])
        return engine.calculate_quote(
            rug_id=case["rug_id"], size_w=w, size_h=h,
            material_id=case["material_id"], qty=case.get("qty", 1),
            rush_order=case.get("rush", False),
            manual_discount_pct=case.get("manual_discount_pct"),
            shape=case.get("shape", "rect"),
        )

    if case["kind"] == "custom":
        quote = SimpleNamespace(
            custom_size_w=case["size_w"], custom_size_h=case["size_h"],
            qty=case.get("qty", 1), rug_shape=case.get("shape", "rect"),
            manual_discount_pct=case.get("discount_pct"),
            shipping_cost=case.get("shipping_cost", 0.0),
            rush_order=case.get("rush", False),
            expected_delivery_days=case.get("expected_delivery_days"),
            price_currency=CASES["settings"]["currency"],
        )
        return engine.calculate_custom_quote(quote, material_id=case["material_id"])

    if case["kind"] == "manual":
        quote = SimpleNamespace(
            final_price=case["final_price"], gst_pct=case.get("gst_pct"),
            custom_size_w=case.get("size_w"), custom_size_h=case.get("size_h"),
            qty=case.get("qty", 1), rug_shape=case.get("shape", "rect"),
            price_currency=CASES["settings"]["currency"],
            expected_delivery_days=case.get("expected_delivery_days"),
        )
        return build_manual_price_result(quote, tenant)

    raise ValueError(f"unknown case kind {case['kind']!r}")


def main():
    db, tenant = build_db()
    out = {}
    for gst in CASES["gst_modes"]:
        for case in CASES["cases"]:
            key = f"{case['id']}::gst_{'on' if gst else 'off'}"
            result = run_case(db, tenant, case, gst)
            out[key] = {k: result.get(k) for k in CASES["compare_fields"] if k in result}
    (HERE / "reference_out.json").write_text(json.dumps(out, indent=2, sort_keys=True))
    print(f"reference_out.json written — {len(out)} rows")


if __name__ == "__main__":
    main()
