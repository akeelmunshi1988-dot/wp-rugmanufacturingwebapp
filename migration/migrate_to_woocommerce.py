#!/usr/bin/env python3
"""
One-time importer: original app's PostgreSQL database -> the new
WooCommerce site. Per the migration plan (Phase 9), writes go through the
WooCommerce REST API (products/orders) and dreamrugs-core's own REST routes
(for wp_drc_quotes / wp_drc_materials / customers-with-a-legacy-hash, which
have no usable native surface) rather than direct MySQL inserts, so all the
side effects those APIs trigger (variation creation, term assignment,
lookup-table sync, password-hash handling) happen correctly.

Idempotent: every migrated row carries a back-reference to its source id —
`legacy_id` (materials), `legacy_quote_id` (quotes), the product SKU
`legacy-rug-{id}` (catalog), or a `_legacy_customer_id` / `_legacy_order_id`
meta key. Re-running is safe: already-imported rows return HTTP 409 and are
skipped, and the script rebuilds its in-run id maps from those 409 bodies so
later phases still resolve their foreign keys on a re-run.

Order of phases matters — each builds an id map the next one needs:
    materials -> catalog -> customers -> quotes -> orders

Run against a STAGING WooCommerce instance first (per the plan's
Verification section): diff row counts per table and spot-check
total_amount / GST on a sample of migrated orders against the source before
pointing this at production.
"""

import argparse
import os
import re
import sys

import psycopg
import requests
from dotenv import load_dotenv

load_dotenv()

PG_DSN = os.environ.get("SOURCE_POSTGRES_DSN")  # e.g. postgresql://user:pass@host/dbname
WP_BASE_URL = os.environ.get("WP_BASE_URL", "http://localhost:8080").rstrip("/")
WP_APP_USER = os.environ.get("WP_APP_USER")
WP_APP_PASSWORD = os.environ.get("WP_APP_PASSWORD")  # WordPress Application Password, not the login password
WC_CONSUMER_KEY = os.environ.get("WC_CONSUMER_KEY")
WC_CONSUMER_SECRET = os.environ.get("WC_CONSUMER_SECRET")

# Populated as phases run; keyed by the *source* (PostgreSQL) row id.
MATERIAL_MAP: dict[int, int] = {}   # legacy material id -> wp_drc_materials.id
RUG_PRODUCT_MAP: dict[int, dict] = {}  # legacy rug id -> {"product_id": int, "variations": {size_key: variation_id}}
CUSTOMER_USER_MAP: dict[int, int] = {}  # legacy customer id -> wp_users.ID
QUOTE_MAP: dict[int, int] = {}      # legacy quote id -> wp_drc_quotes.id


def pg_connect() -> psycopg.Connection:
    if not PG_DSN:
        sys.exit("SOURCE_POSTGRES_DSN is not set — see migration/.env.example")
    return psycopg.connect(PG_DSN, row_factory=psycopg.rows.dict_row)


def drc_api(method: str, path: str, **kwargs) -> requests.Response:
    """Calls a dreamrugs-core custom REST route, authenticated as a WP
    Application Password user (Settings -> Users -> your profile -> Application
    Passwords, in the target WordPress site)."""
    url = f"{WP_BASE_URL}/wp-json/dreamrugs/v1{path}"
    return requests.request(method, url, auth=(WP_APP_USER, WP_APP_PASSWORD), timeout=60, **kwargs)


def wc_api(method: str, path: str, **kwargs) -> requests.Response:
    """Calls WooCommerce's own REST API (products, orders)."""
    url = f"{WP_BASE_URL}/wp-json/wc/v3{path}"
    return requests.request(method, url, auth=(WC_CONSUMER_KEY, WC_CONSUMER_SECRET), timeout=60, **kwargs)


def _size_key(entry: dict) -> str:
    """Stable key for one sizes[] entry — the ft label if present, else cm."""
    return (entry.get("ft") or entry.get("cm") or "").strip().lower()


# ─────────────────────────────────────────────────────────────────────────
# Phase 1 — materials
# ─────────────────────────────────────────────────────────────────────────

def migrate_materials(pg_conn: psycopg.Connection) -> None:
    """Material -> wp_drc_materials, via POST /dreamrugs/v1/admin/import/materials
    (idempotent on legacy_id)."""
    with pg_conn.cursor() as cur:
        cur.execute(
            "SELECT id, name, type, color, stock_meters, cost_per_sqm, cost_currency, is_available "
            "FROM materials ORDER BY id"
        )
        rows = cur.fetchall()

    imported, skipped, failed = 0, 0, 0
    for row in rows:
        resp = drc_api("POST", "/admin/import/materials", json={
            "legacy_id": row["id"],
            "name": row["name"],
            "type": row["type"],
            "color": row["color"],
            "stock_meters": row["stock_meters"],
            "cost_per_sqm": row["cost_per_sqm"],
            "cost_currency": row["cost_currency"],
            "is_available": row["is_available"],
        })
        if resp.status_code == 201:
            MATERIAL_MAP[row["id"]] = resp.json()["id"]
            imported += 1
        elif resp.status_code == 409:
            body = resp.json()
            new_id = (body.get("data") or {}).get("id") or body.get("id")
            if new_id:
                MATERIAL_MAP[row["id"]] = int(new_id)
            skipped += 1
        else:
            failed += 1
            print(f"  ! material {row['id']} ({row['name']}) failed: {resp.status_code} {resp.text}", file=sys.stderr)
    print(f"materials: {imported} imported, {skipped} already present, {failed} failed")


# ─────────────────────────────────────────────────────────────────────────
# Phase 2 — catalog (RugCatalog -> WooCommerce variable products)
# ─────────────────────────────────────────────────────────────────────────

def migrate_catalog(pg_conn: psycopg.Connection) -> None:
    """Each RugCatalog row becomes one WooCommerce **variable** product with
    one variation per sizes[] entry. sizes[].ft / sizes[].cm strings are
    copied VERBATIM into variation meta (_drc_size_ft / _drc_size_cm) — never
    recomputed from one another ("cm is only ever vendor-typed").

    Idempotency: the parent product SKU is `legacy-rug-{id}`; a GET by that
    SKU short-circuits an already-imported rug and still records its ids in
    RUG_PRODUCT_MAP so later phases resolve.
    """
    with pg_conn.cursor() as cur:
        cur.execute(
            "SELECT id, name, slug, description, about_content_html, additional_information_html, "
            "sizes, base_price, base_price_currency, material_id, pile_height, weave_type, "
            "lead_time_days, image_url, hsn_code, room_types, mood_tags, color_options, "
            "is_available, inventory_quantity "
            "FROM rug_catalog ORDER BY id"
        )
        rugs = cur.fetchall()

    # image rows keyed by rug for the product gallery
    with pg_conn.cursor() as cur:
        cur.execute("SELECT rug_catalog_id, image_url FROM rug_images ORDER BY rug_catalog_id, sort_order, id")
        images_by_rug: dict[int, list] = {}
        for r in cur.fetchall():
            images_by_rug.setdefault(r["rug_catalog_id"], []).append(r["image_url"])

    imported, skipped, failed = 0, 0, 0
    for rug in rugs:
        sku = f"legacy-rug-{rug['id']}"
        existing = wc_api("GET", "/products", params={"sku": sku})
        if existing.ok and existing.json():
            prod = existing.json()[0]
            RUG_PRODUCT_MAP[rug["id"]] = _index_variations(prod["id"])
            skipped += 1
            continue

        sizes = rug["sizes"] or []
        size_options = [(_size_key(s) or f"size-{i}") for i, s in enumerate(sizes)]

        gallery = []
        if rug["image_url"]:
            gallery.append(rug["image_url"])
        gallery.extend(images_by_rug.get(rug["id"], []))

        payload = {
            "name": rug["name"],
            "slug": rug["slug"] or None,
            "type": "variable",
            "sku": sku,
            "status": "publish" if rug["is_available"] else "private",
            "description": rug["about_content_html"] or rug["description"] or "",
            "short_description": rug["description"] or "",
            "catalog_visibility": "visible" if rug["is_available"] else "hidden",
            "images": [{"src": u} for u in gallery if u],
            "attributes": [{
                "name": "Size",
                "visible": True,
                "variation": True,
                "options": size_options or ["One size"],
            }],
            "meta_data": [
                {"key": "_drc_weave_type", "value": rug["weave_type"] or ""},
                {"key": "_drc_lead_time_days", "value": rug["lead_time_days"] or 21},
                {"key": "_drc_hsn_code", "value": rug["hsn_code"] or "5703"},
                {"key": "_drc_pile_height", "value": rug["pile_height"] or ""},
                {"key": "_drc_inventory_quantity", "value": rug["inventory_quantity"]},
                {"key": "_drc_additional_information_html", "value": rug["additional_information_html"] or ""},
                {"key": "_drc_color_options", "value": rug["color_options"] or []},
            ],
        }
        resp = wc_api("POST", "/products", json=payload)
        if not resp.ok:
            failed += 1
            print(f"  ! rug {rug['id']} ({rug['name']}) product failed: {resp.status_code} {resp.text}", file=sys.stderr)
            continue
        product_id = resp.json()["id"]

        # taxonomies (room_type / mood_tag) — set through the WP REST product
        # endpoint, which exposes every taxonomy registered show_in_rest.
        _assign_terms(product_id, "room_type", rug["room_types"] or [])
        _assign_terms(product_id, "mood_tag", rug["mood_tags"] or [])

        variations = {}
        for i, s in enumerate(sizes):
            price = s.get("price")
            if price is None:
                price = rug["base_price"]
            vresp = wc_api("POST", f"/products/{product_id}/variations", json={
                "regular_price": str(price),
                "attributes": [{"name": "Size", "option": size_options[i]}],
                "meta_data": [
                    {"key": "_drc_size_ft", "value": s.get("ft") or ""},
                    {"key": "_drc_size_cm", "value": s.get("cm") or ""},
                    {"key": "_drc_lead_time_days", "value": s.get("lead_time_days") or rug["lead_time_days"] or 21},
                ],
            })
            if vresp.ok:
                variations[_size_key(s) or f"size-{i}"] = vresp.json()["id"]
            else:
                print(f"  ! rug {rug['id']} variation {s.get('ft')} failed: {vresp.status_code} {vresp.text}", file=sys.stderr)

        RUG_PRODUCT_MAP[rug["id"]] = {"product_id": product_id, "variations": variations}
        imported += 1
    print(f"catalog: {imported} products imported, {skipped} already present, {failed} failed")


def _index_variations(product_id: int) -> dict:
    """Rebuild {size_key: variation_id} for an already-imported product so a
    re-run still resolves quote.rug_catalog_id -> variation."""
    out = {"product_id": product_id, "variations": {}}
    resp = wc_api("GET", f"/products/{product_id}/variations", params={"per_page": 100})
    if resp.ok:
        for v in resp.json():
            meta = {m["key"]: m["value"] for m in v.get("meta_data", [])}
            key = (meta.get("_drc_size_ft") or meta.get("_drc_size_cm") or "").strip().lower()
            if key:
                out["variations"][key] = v["id"]
    return out


def _assign_terms(product_id: int, taxonomy: str, values: list) -> None:
    if not values:
        return
    # WP core REST expects term IDs; create-or-get each by slug first.
    term_ids = []
    for name in values:
        slug = re.sub(r"[^a-z0-9]+", "-", str(name).strip().lower()).strip("-")
        got = requests.get(
            f"{WP_BASE_URL}/wp-json/wp/v2/{taxonomy}", params={"slug": slug},
            auth=(WP_APP_USER, WP_APP_PASSWORD), timeout=30,
        )
        if got.ok and got.json():
            term_ids.append(got.json()[0]["id"])
            continue
        made = requests.post(
            f"{WP_BASE_URL}/wp-json/wp/v2/{taxonomy}", json={"name": str(name), "slug": slug},
            auth=(WP_APP_USER, WP_APP_PASSWORD), timeout=30,
        )
        if made.ok:
            term_ids.append(made.json()["id"])
    if term_ids:
        requests.post(
            f"{WP_BASE_URL}/wp-json/wp/v2/product/{product_id}", json={taxonomy: term_ids},
            auth=(WP_APP_USER, WP_APP_PASSWORD), timeout=30,
        )


# ─────────────────────────────────────────────────────────────────────────
# Phase 3 — customers
# ─────────────────────────────────────────────────────────────────────────

def migrate_customers(pg_conn: psycopg.Connection) -> None:
    """Registered customers (hashed_password NOT NULL) -> WP users via
    POST /dreamrugs/v1/admin/import/customers, which writes the bcrypt hash
    verbatim so DRC_Legacy_Password verifies + upgrades it on first login.
    Guest customers (hashed_password IS NULL) get NO WP user — their
    name/email ride along on the quote/order rows instead.
    """
    with pg_conn.cursor() as cur:
        cur.execute(
            "SELECT id, name, email, phone, company, gstin, state_code, address, country, "
            "is_export_buyer, account_type, hashed_password "
            "FROM customers ORDER BY id"
        )
        rows = cur.fetchall()

    imported, skipped, guests, failed = 0, 0, 0, 0
    for row in rows:
        if not row["hashed_password"]:
            guests += 1
            continue
        resp = drc_api("POST", "/admin/import/customers", json={
            "legacy_customer_id": row["id"],
            "name": row["name"],
            "email": row["email"],
            "phone": row["phone"],
            "company": row["company"],
            "gstin": row["gstin"],
            "state_code": row["state_code"],
            "address": row["address"],
            "country": row["country"] or "India",
            "is_export_buyer": bool(row["is_export_buyer"]),
            "account_type": row["account_type"] or "retail",
            "hashed_password": row["hashed_password"],
        })
        if resp.status_code == 201:
            CUSTOMER_USER_MAP[row["id"]] = resp.json()["user_id"]
            imported += 1
        elif resp.status_code == 409:
            body = resp.json()
            uid = (body.get("data") or {}).get("user_id") or body.get("user_id")
            if uid:
                CUSTOMER_USER_MAP[row["id"]] = int(uid)
            skipped += 1
        else:
            failed += 1
            print(f"  ! customer {row['id']} ({row['email']}) failed: {resp.status_code} {resp.text}", file=sys.stderr)
    print(f"customers: {imported} imported, {skipped} already present, {guests} guests skipped, {failed} failed")


# ─────────────────────────────────────────────────────────────────────────
# Phase 4 — quotes
# ─────────────────────────────────────────────────────────────────────────

def migrate_quotes(pg_conn: psycopg.Connection) -> None:
    """Quote -> wp_drc_quotes via POST /dreamrugs/v1/admin/import/quotes.
    Walked in ascending id order so revised_from_quote_id (a self-FK) can be
    resolved from QUOTE_MAP as we go. final_price is passed through untouched.
    """
    with pg_conn.cursor() as cur:
        cur.execute("""
            SELECT q.*, c.name AS customer_name, c.email AS customer_email
            FROM quotes q LEFT JOIN customers c ON c.id = q.customer_id
            ORDER BY q.id
        """)
        rows = cur.fetchall()

    imported, skipped, failed = 0, 0, 0
    for q in rows:
        rug = RUG_PRODUCT_MAP.get(q["rug_catalog_id"]) if q["rug_catalog_id"] else None
        product_id = rug["product_id"] if rug else None
        variation_id = None
        if rug and q["custom_size_w"] and q["custom_size_h"]:
            # best-effort: match a variation whose ft label parses to the
            # quote's stored metres (custom_size_* are in metres).
            variation_id = _closest_variation(rug, q["custom_size_w"], q["custom_size_h"])

        payload = {
            "legacy_quote_id": q["id"],
            "customer_user_id": CUSTOMER_USER_MAP.get(q["customer_id"]),
            "guest_name": None if CUSTOMER_USER_MAP.get(q["customer_id"]) else q["customer_name"],
            "guest_email": None if CUSTOMER_USER_MAP.get(q["customer_id"]) else q["customer_email"],
            "product_id": product_id,
            "variation_id": variation_id,
            "custom_size_w": q["custom_size_w"],
            "custom_size_h": q["custom_size_h"],
            "rug_shape": q["rug_shape"] or "rect",
            "material_id": MATERIAL_MAP.get(q["material_id"]) if q["material_id"] else None,
            "qty": q["qty"] or 1,
            "base_price": q["base_price"],
            "final_price": q["final_price"],
            "rush_order": bool(q["rush_order"]),
            "selected_color": q["selected_color"],
            "price_currency": q["price_currency"],
            "margin_pct": q["margin_pct"],
            "gst_pct": q["gst_pct"],
            "manual_discount_pct": q["manual_discount_pct"],
            "shipping_cost": q["shipping_cost"],
            "expected_delivery_days": q["expected_delivery_days"],
            "status": q["status"] or "draft",
            "notes": q["notes"],
            "vendor_notes": q["vendor_notes"],
            "customer_response_notes": q["customer_response_notes"],
            "review_request_count": q["review_request_count"] or 0,
            "is_custom_request": bool(q["is_custom_request"]),
            "room_type": q["room_type"],
            "material_preference": q["material_preference"],
            "budget_range": q["budget_range"],
            "expected_delivery": q["expected_delivery"],
            "request_group_id": q["request_group_id"],
            "reference_image_urls": q["reference_image_urls"] or [],
            "vendor_sample_image_urls": q["vendor_sample_image_urls"] or [],
            "revised_from_quote_id": QUOTE_MAP.get(q["revised_from_quote_id"]) if q["revised_from_quote_id"] else None,
            "created_at": q["created_at"].isoformat() if q["created_at"] else None,
        }
        resp = drc_api("POST", "/admin/import/quotes", json=payload)
        if resp.status_code == 201:
            QUOTE_MAP[q["id"]] = resp.json()["id"]
            imported += 1
        elif resp.status_code == 409:
            body = resp.json()
            nid = (body.get("data") or {}).get("id") or body.get("id")
            if nid:
                QUOTE_MAP[q["id"]] = int(nid)
            skipped += 1
        else:
            failed += 1
            print(f"  ! quote {q['id']} failed: {resp.status_code} {resp.text}", file=sys.stderr)
    print(f"quotes: {imported} imported, {skipped} already present, {failed} failed")


_FT_RE = re.compile(r"\s*[x×]\s*")


def _closest_variation(rug: dict, w_m: float, h_m: float, tol_m: float = 0.025):
    """Mirror the quote engine's 2.5cm fuzzy size match (incl. rotation) to
    pick a variation for a catalog quote."""
    for key, vid in rug["variations"].items():
        parts = _FT_RE.split(key)
        if len(parts) != 2:
            continue
        try:
            fw, fh = float(parts[0]) * 0.3048, float(parts[1]) * 0.3048
        except ValueError:
            continue
        if (abs(fw - w_m) <= tol_m and abs(fh - h_m) <= tol_m) or \
           (abs(fw - h_m) <= tol_m and abs(fh - w_m) <= tol_m):
            return vid
    return None


# ─────────────────────────────────────────────────────────────────────────
# Phase 5 — orders
# ─────────────────────────────────────────────────────────────────────────

_STATUS_MAP = {
    "pending": "pending",
    "confirmed": "processing",
    "in_production": "processing",
    "production": "processing",
    "shipped": "completed",
    "delivered": "completed",
    "completed": "completed",
    "cancelled": "cancelled",
    "canceled": "cancelled",
    "refunded": "refunded",
}


def migrate_orders(pg_conn: psycopg.Connection) -> None:
    """Order -> WooCommerce order via POST /dreamrugs/v1/admin/import/orders
    (a custom route: it uses wc_create_order() + $order->set_total() so the
    already-inclusive total_amount is preserved exactly, never recomputed).
    """
    with pg_conn.cursor() as cur:
        cur.execute("SELECT * FROM orders ORDER BY id")
        orders = cur.fetchall()

    with pg_conn.cursor() as cur:
        cur.execute("SELECT order_id, quote_id FROM order_items ORDER BY order_id, id")
        items_by_order: dict[int, list] = {}
        for r in cur.fetchall():
            items_by_order.setdefault(r["order_id"], []).append(r["quote_id"])

    # quote_id -> (final_price, qty, rug_catalog_id) for building line items
    with pg_conn.cursor() as cur:
        cur.execute("SELECT id, final_price, qty, rug_catalog_id FROM quotes")
        quote_info = {r["id"]: r for r in cur.fetchall()}

    # order -> customer id (via the first linked quote)
    with pg_conn.cursor() as cur:
        cur.execute("""
            SELECT DISTINCT ON (oi.order_id) oi.order_id, q.customer_id
            FROM order_items oi JOIN quotes q ON q.id = oi.quote_id
            ORDER BY oi.order_id, oi.id
        """)
        customer_by_order = {r["order_id"]: r["customer_id"] for r in cur.fetchall()}

    imported, skipped, failed = 0, 0, 0
    for o in orders:
        quote_ids = items_by_order.get(o["id"], [])
        line_items = []
        for qid in quote_ids:
            qi = quote_info.get(qid) or {}
            rug = RUG_PRODUCT_MAP.get(qi.get("rug_catalog_id")) if qi.get("rug_catalog_id") else None
            line_items.append({
                "product_id": rug["product_id"] if rug else None,
                "name": f"Quote #{qid}",
                "qty": qi.get("qty") or 1,
                "total": qi.get("final_price") or 0,
            })

        payload = {
            "legacy_order_id": o["id"],
            "customer_user_id": CUSTOMER_USER_MAP.get(customer_by_order.get(o["id"])),
            "status": _STATUS_MAP.get((o["status"] or "").lower(), "processing"),
            "price_currency": o["price_currency"],
            "total_amount": o["total_amount"] or 0,
            "shipping_cost": o["shipping_cost"],
            "discount_amount": o["discount_amount"],
            "promo_code": o["promo_code"],
            "shipping_address": o["shipping_address"],
            "line_items": line_items,
            "created_at": o["created_at"].isoformat() if o["created_at"] else None,
        }
        resp = drc_api("POST", "/admin/import/orders", json=payload)
        if resp.status_code == 201:
            imported += 1
        elif resp.status_code == 409:
            skipped += 1
        else:
            failed += 1
            print(f"  ! order {o['id']} failed: {resp.status_code} {resp.text}", file=sys.stderr)
    print(f"orders: {imported} imported, {skipped} already present, {failed} failed")


# ─────────────────────────────────────────────────────────────────────────

PHASES = ("materials", "catalog", "customers", "quotes", "orders")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--only", choices=(*PHASES, "all"), default="all",
                        help="run a single phase (earlier phases still run read-only to rebuild id maps)")
    args = parser.parse_args()

    for var in ("WP_APP_USER", "WP_APP_PASSWORD", "WC_CONSUMER_KEY", "WC_CONSUMER_SECRET"):
        if not os.environ.get(var):
            sys.exit(f"{var} is not set — see migration/.env.example")

    conn = pg_connect()
    try:
        # Phases are cumulative: to run just "quotes" we still need the
        # material / rug / customer id maps, which only get built by running
        # those phases. Re-running is safe (409 = skip), so "--only X" runs
        # every phase up to and including X.
        target = PHASES.index(args.only) if args.only != "all" else len(PHASES) - 1
        runners = (migrate_materials, migrate_catalog, migrate_customers, migrate_quotes, migrate_orders)
        for i in range(target + 1):
            runners[i](conn)
    finally:
        conn.close()


if __name__ == "__main__":
    main()
