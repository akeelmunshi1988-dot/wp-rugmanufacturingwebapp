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
    -> promo_codes -> reviews -> newsletter -> content -> inventory
    -> settings -> staff -> masters -> email_templates -> rules
    -> order_history -> promo_redemptions -> stories

Tenant scoping: the source tables are multi-tenant; only one tenant's rows
are imported — --tenant-id, defaulting to the first tenant (the storefront's
own `db.query(Tenant).first()` resolution). Without this a stray/orphaned
tenant's data would land on the new site.

Media: relative source URLs (/static/...) are made absolute with
SOURCE_MEDIA_BASE_URL (e.g. https://dreamrugscreation.com) so WordPress can
copy the files into its media library.

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
SOURCE_MEDIA_BASE_URL = os.environ.get("SOURCE_MEDIA_BASE_URL", "").rstrip("/")

TENANT_ID: int | None = None  # set in main()

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


def media_url(url):
    """Absolute URL for a source media path, or None."""
    if not url:
        return None
    if url.startswith(("http://", "https://")):
        return url
    if not SOURCE_MEDIA_BASE_URL:
        sys.exit("SOURCE_MEDIA_BASE_URL is not set but the source has relative media URLs (e.g. %s)" % url)
    return SOURCE_MEDIA_BASE_URL + ("" if url.startswith("/") else "/") + url


def fetch(pg_conn, sql: str, params: tuple = ()) -> list:
    """Runs a tenant-scoped query: every source SELECT here has a
    `tenant_id = %(tenant)s` filter (CLAUDE.md's multi-tenant rule)."""
    with pg_conn.cursor() as cur:
        cur.execute(sql, {"tenant": TENANT_ID, **dict(params)})
        return cur.fetchall()


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
            "FROM materials WHERE tenant_id = %(tenant)s ORDER BY id", {"tenant": TENANT_ID}
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
            "FROM rug_catalog WHERE tenant_id = %(tenant)s ORDER BY id", {"tenant": TENANT_ID}
        )
        rugs = cur.fetchall()

    # image rows keyed by rug for the product gallery
    with pg_conn.cursor() as cur:
        cur.execute(
            "SELECT ri.rug_catalog_id, ri.image_url FROM rug_images ri "
            "JOIN rug_catalog r ON r.id = ri.rug_catalog_id WHERE r.tenant_id = %(tenant)s "
            "ORDER BY ri.rug_catalog_id, ri.sort_order, ri.id", {"tenant": TENANT_ID}
        )
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
            "images": [{"src": media_url(u)} for u in gallery if u],
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
            "is_export_buyer, account_type, hashed_password, is_verified, oauth_provider "
            "FROM customers WHERE tenant_id = %(tenant)s ORDER BY id", {"tenant": TENANT_ID}
        )
        rows = cur.fetchall()

    imported, skipped, guests, failed = 0, 0, 0, 0
    for row in rows:
        # Guests (no password, no social login) get no WP user — their
        # name/email ride along on quotes/orders and are claimed if they
        # register later with the same email.
        if not row["hashed_password"] and not row["oauth_provider"]:
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
            "is_verified": bool(row["is_verified"]),
            "oauth_provider": row["oauth_provider"],
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
            WHERE q.tenant_id = %(tenant)s
            ORDER BY q.id
        """, {"tenant": TENANT_ID})
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
            "reference_image_urls": [media_url(u) for u in (q["reference_image_urls"] or []) if u],
            "vendor_sample_image_urls": [media_url(u) for u in (q["vendor_sample_image_urls"] or []) if u],
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

# The original's statuses (backend orders.py VALID_STATUSES) — the custom
# ones exist in WooCommerce too (DRC_Order_Statuses). An order there is only
# created once paid, so "pending" means "paid, not started" → processing,
# unless there's no payment on record.
_STATUS_MAP = {
    "pending": "processing",
    "in_production": "in_production",
    "quality_check": "quality_check",
    "shipped": "shipped",
    "delivered": "delivered",
    "cancelled": "cancelled",
    "canceled": "cancelled",
    "refunded": "refunded",
}


def _iso(value):
    return value.isoformat() if value else None


def migrate_orders(pg_conn: psycopg.Connection) -> None:
    """Order -> WooCommerce order via POST /dreamrugs/v1/admin/import/orders
    (a custom route: it uses wc_create_order() + $order->set_total() so the
    already-inclusive total_amount is preserved exactly, never recomputed).
    Carries the billing contact (guests too), the Razorpay payment id,
    delivery dates and refund details, and links the imported quotes both
    ways (quote.wc_order_id / order _drc_quote_id).
    """
    orders = fetch(pg_conn, "SELECT * FROM orders WHERE tenant_id = %(tenant)s ORDER BY id")

    items_by_order: dict[int, list] = {}
    for r in fetch(pg_conn, """
        SELECT oi.order_id, oi.quote_id FROM order_items oi
        JOIN orders o ON o.id = oi.order_id WHERE o.tenant_id = %(tenant)s
        ORDER BY oi.order_id, oi.id
    """):
        items_by_order.setdefault(r["order_id"], []).append(r["quote_id"])

    quote_info = {r["id"]: r for r in fetch(pg_conn, """
        SELECT q.id, q.final_price, q.qty, q.rug_catalog_id, q.customer_id,
               c.name, c.email, c.phone, c.company, c.country, c.gstin, c.state_code
        FROM quotes q LEFT JOIN customers c ON c.id = q.customer_id
        WHERE q.tenant_id = %(tenant)s
    """)}

    imported, skipped, failed = 0, 0, 0
    for o in orders:
        # Older single-quote orders have only orders.quote_id, no order_items.
        quote_ids = items_by_order.get(o["id"]) or ([o["quote_id"]] if o.get("quote_id") else [])
        line_items = []
        for qid in quote_ids:
            qi = quote_info.get(qid) or {}
            rug = RUG_PRODUCT_MAP.get(qi.get("rug_catalog_id")) if qi.get("rug_catalog_id") else None
            line_items.append({
                "product_id": rug["product_id"] if rug else None,
                "name": f"Quote #{qid}",
                "qty": qi.get("qty") or 1,
                "total": qi.get("final_price") or 0,
                "quote_id": QUOTE_MAP.get(qid),
            })
        buyer = quote_info.get(quote_ids[0]) if quote_ids else None
        buyer = buyer or {}
        status = _STATUS_MAP.get((o["status"] or "").lower(), "processing")
        if status == "processing" and not o["razorpay_payment_id"]:
            status = "pending"

        payload = {
            "legacy_order_id": o["id"],
            "customer_user_id": CUSTOMER_USER_MAP.get(buyer.get("customer_id")),
            "status": status,
            "price_currency": o["price_currency"],
            "total_amount": o["total_amount"] or 0,
            "shipping_cost": o["shipping_cost"],
            "discount_amount": o["discount_amount"],
            "promo_code": o["promo_code"],
            "shipping_address": o["shipping_address"],
            "billing_name": buyer.get("name"),
            "billing_email": buyer.get("email"),
            "billing_phone": buyer.get("phone"),
            "billing_company": buyer.get("company"),
            "billing_country": buyer.get("country"),
            "gstin": buyer.get("gstin"),
            "state_code": buyer.get("state_code"),
            "transaction_id": o["razorpay_payment_id"],
            "estimated_delivery": _iso(o["estimated_delivery"]),
            "actual_delivery": _iso(o["actual_delivery"]),
            "refund_id": o["refund_id"],
            "refund_status": o["refund_status"],
            "refund_amount": o["refund_amount"],
            "refunded_at": _iso(o["refunded_at"]),
            "quote_ids": [QUOTE_MAP[q] for q in quote_ids if q in QUOTE_MAP],
            "line_items": line_items,
            "created_at": _iso(o["created_at"]),
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
# Phase 6+ — promo codes, reviews, newsletter, site content, inventory
# history (DRC_Import_Routes)
# ─────────────────────────────────────────────────────────────────────────

def _post_each(label: str, rows: list, path: str, build) -> None:
    imported, skipped, failed = 0, 0, 0
    for row in rows:
        payload = build(row)
        if payload is None:
            failed += 1
            continue
        resp = drc_api("POST", path, json=payload)
        if resp.status_code == 201:
            imported += 1
            for w in (resp.json() or {}).get("warnings") or []:
                print(f"  ~ {label} {row['id']}: {w}", file=sys.stderr)
        elif resp.status_code == 409:
            skipped += 1
        else:
            failed += 1
            print(f"  ! {label} {row['id']} failed: {resp.status_code} {resp.text}", file=sys.stderr)
    print(f"{label}: {imported} imported, {skipped} already present, {failed} failed")


def migrate_promo_codes(pg_conn: psycopg.Connection) -> None:
    rows = fetch(pg_conn, """
        SELECT p.*, (SELECT COUNT(*) FROM orders o WHERE o.tenant_id = p.tenant_id AND upper(o.promo_code) = upper(p.code)) AS used_count
        FROM promo_codes p WHERE p.tenant_id = %(tenant)s ORDER BY p.id
    """)
    _post_each("promo codes", rows, "/admin/import/promo-codes", lambda p: {
        "legacy_promo_id": p["id"],
        "code": p["code"],
        "discount_type": p["discount_type"],
        "discount_value": p["discount_value"],
        "min_order_value": p["min_order_value"],
        "max_uses": p["max_uses"],
        "one_per_customer": bool(p["one_per_customer"]),
        "starts_at": _iso(p["starts_at"]),
        "expires_at": _iso(p["expires_at"]),
        "is_active": bool(p["is_active"]),
        "used_count": p["used_count"],
    })


def migrate_reviews(pg_conn: psycopg.Connection) -> None:
    rows = fetch(pg_conn, "SELECT * FROM rug_reviews WHERE tenant_id = %(tenant)s ORDER BY id")

    def build(r):
        rug = RUG_PRODUCT_MAP.get(r["rug_id"])
        if not rug:
            print(f"  ! review {r['id']}: rug {r['rug_id']} wasn't imported", file=sys.stderr)
            return None
        return {
            "legacy_review_id": r["id"],
            "product_id": rug["product_id"],
            "customer_user_id": CUSTOMER_USER_MAP.get(r["customer_id"]),
            "name": r["name"], "email": r["email"], "rating": r["rating"],
            "title": r["title"], "body": r["body"], "status": r["status"],
            "is_verified_buyer": bool(r["is_verified_buyer"]),
            "created_at": _iso(r["created_at"]),
        }
    _post_each("reviews", rows, "/admin/import/reviews", build)


def migrate_newsletter(pg_conn: psycopg.Connection) -> None:
    rows = fetch(pg_conn, "SELECT email, source, subscribed_at FROM newsletter_subscribers WHERE tenant_id = %(tenant)s ORDER BY id")
    resp = drc_api("POST", "/admin/import/newsletter", json={"subscribers": [
        {"email": r["email"], "source": r["source"], "subscribed_at": _iso(r["subscribed_at"])} for r in rows
    ]})
    if resp.status_code == 201:
        body = resp.json()
        print(f"newsletter: {body['added']} imported, {body['skipped']} already present")
    else:
        print(f"  ! newsletter failed: {resp.status_code} {resp.text}", file=sys.stderr)


# source table -> (DRC_Import_Routes::CONTENT type, media columns)
_CONTENT_TABLES = {
    "workshop_photos": ("workshop_photo", ("image_url",)),
    "custom_rug_page_images": ("custom_rug_page_image", ("image_url",)),
    "showcase_videos": ("showcase_video", ("video_url", "poster_url")),
    "testimonials": ("testimonial", ("photo_url",)),
    "project_gallery_items": ("project_gallery", ("image_url",)),
    "rug_journey_steps": ("journey_step", ()),
    "announcement_messages": ("announcement", ()),
    "faqs": ("faq", ()),
    "trade_enquiries": ("trade_enquiry", ()),
    "homepage_enquiries": ("homepage_enquiry", ()),
}


def migrate_content(pg_conn: psycopg.Connection) -> None:
    extra_images: dict[int, list] = {}
    for r in fetch(pg_conn, """
        SELECT gi.project_gallery_item_id, gi.image_url FROM project_gallery_images gi
        JOIN project_gallery_items g ON g.id = gi.project_gallery_item_id
        WHERE g.tenant_id = %(tenant)s ORDER BY gi.project_gallery_item_id, gi.sort_order, gi.id
    """):
        extra_images.setdefault(r["project_gallery_item_id"], []).append(media_url(r["image_url"]))

    for table, (ctype, media_cols) in _CONTENT_TABLES.items():
        rows = fetch(pg_conn, f"SELECT * FROM {table} WHERE tenant_id = %(tenant)s ORDER BY id")

        def build(r, ctype=ctype, media_cols=media_cols):
            fields = {k: (_iso(v) if hasattr(v, "isoformat") else v) for k, v in r.items() if k not in ("id", "tenant_id")}
            for col in media_cols:
                fields[col] = media_url(fields.get(col))
            if ctype == "faq":
                rug = RUG_PRODUCT_MAP.get(fields.pop("rug_catalog_id", None))
                fields["product_id"] = rug["product_id"] if rug else 0
            if ctype == "project_gallery":
                fields["extra_image_urls"] = extra_images.get(r["id"], [])
            return {"type": ctype, "legacy_id": r["id"], "fields": fields}
        _post_each(table, rows, "/admin/import/content", build)


def migrate_inventory(pg_conn: psycopg.Connection) -> None:
    rows = fetch(pg_conn, "SELECT * FROM inventory_transactions WHERE tenant_id = %(tenant)s ORDER BY id")

    def build(t):
        material_id = MATERIAL_MAP.get(t["material_id"])
        if not material_id:
            print(f"  ! inventory transaction {t['id']}: material {t['material_id']} wasn't imported", file=sys.stderr)
            return None
        return {
            "legacy_id": t["id"], "material_id": material_id, "qty_change": t["qty_change"],
            "transaction_type": t["transaction_type"], "notes": t["notes"], "created_at": _iso(t["created_at"]),
        }
    _post_each("inventory history", rows, "/admin/import/inventory-transactions", build)



# ─────────────────────────────────────────────────────────────────────────
# Phase 11+ — business settings, staff, masters, templates, rules, order
# history, promo redemptions, design stories
# ─────────────────────────────────────────────────────────────────────────

_MEDIA_RE = re.compile(r"\.(jpe?g|png|gif|webp|avif|svg|mp4|webm|mov|pdf)(\?.*)?$", re.I)


def _absolutize_media(value):
    """Relative source media paths anywhere in a settings value -> absolute
    URLs WordPress can copy into its media library."""
    if isinstance(value, dict):
        return {k: _absolutize_media(v) for k, v in value.items()}
    if isinstance(value, list):
        return [_absolutize_media(v) for v in value]
    if isinstance(value, str) and value.startswith("/") and _MEDIA_RE.search(value):
        return media_url(value)
    return value


def _absolutize_html(html):
    """src/href="/static/..." inside rich text -> absolute URLs."""
    if not html:
        return html
    return re.sub(r'((?:src|href)=["\'])(/[^"\']+)', lambda m: m.group(1) + (media_url(m.group(2)) if _MEDIA_RE.search(m.group(2)) else m.group(2)), html)


# Tenant columns with the same name and meaning in DRC_Settings.
_SETTINGS_KEYS = (
    "gstin", "state_code", "address", "lut_number", "exchange_rates", "enabled_currencies",
    "exchange_rates_auto", "default_profit_margin_pct", "rush_surcharge_pct", "default_gst_pct",
    "gst_inclusive", "large_format_threshold_sqm", "large_format_surcharge_pct",
    "vendor_notification_email", "default_size_unit", "contact_emails", "contact_phones",
    "contact_address", "contact_hours", "social_links", "catalog_pdf_url", "hero_image_url",
    "hero_images", "hero_eyebrow", "hero_heading", "hero_cta_label",
    "homepage_full_bleed_image_url", "homepage_full_bleed_alt_text", "homepage_full_bleed_enabled",
    "homepage_values_eyebrow", "homepage_values_headline", "homepage_values_headline_accent",
    "homepage_values_description", "homepage_values_items", "homepage_gallery_titles",
    "homepage_values_enabled", "homepage_intro_title_line_one", "homepage_intro_title_line_two",
    "homepage_intro_label", "homepage_intro_description", "homepage_intro_cta_label",
    "homepage_intro_cta_url", "storefront_menu_labels", "storefront_menu_visibility",
    "homepage_intro_trusted_by_text", "homepage_intro_enabled", "homepage_contact_image_url",
    "homepage_contact_image_alt", "homepage_contact_heading", "homepage_contact_consent_text",
    "homepage_contact_button_label", "homepage_contact_success_message", "homepage_contact_enabled",
    "refund_cancellation_policy_html", "privacy_policy_html",
    "default_catalog_additional_information_html", "product_accordion_sections",
    "about_us_content_html", "about_page", "certifications", "order_tracking_eyebrow",
    "order_tracking_heading", "order_tracking_body", "order_tracking_shipping_policy_url",
    "order_tracking_carriers", "order_tracking_steps", "order_tracking_media_url",
    "order_tracking_media_type", "colour_matching_eyebrow", "colour_matching_heading",
    "colour_matching_body", "colour_matching_items", "default_shipping_rate",
    "cancellation_window_hours",
)


def migrate_settings(pg_conn: psycopg.Connection) -> None:
    """Tenant (Business Settings, homepage sections, contact, About page,
    policies, rates...) -> DRC_Settings, plus site name, logo and store
    currency. Media referenced by settings is copied to WordPress."""
    tenant = fetch(pg_conn, "SELECT * FROM tenants WHERE id = %(tenant)s")[0]
    settings = {k: _absolutize_media(tenant.get(k)) for k in _SETTINGS_KEYS if tenant.get(k) is not None}
    trending = [RUG_PRODUCT_MAP[r]["product_id"] for r in (tenant.get("trending_rug_ids") or []) if r in RUG_PRODUCT_MAP]
    resp = drc_api("POST", "/admin/import/settings", json={
        "settings": settings,
        "business_name": tenant.get("name"),
        "logo_url": media_url(tenant.get("logo_url")),
        "base_currency": tenant.get("base_currency") or tenant.get("currency"),
        "trending_product_ids": trending,
    })
    if resp.status_code == 201:
        body = resp.json()
        print(f"settings: {len(body['applied'])} applied")
        for w in body.get("warnings") or []:
            print(f"  ~ settings: {w}", file=sys.stderr)
    else:
        print(f"  ! settings failed: {resp.status_code} {resp.text}", file=sys.stderr)


def migrate_staff(pg_conn: psycopg.Connection) -> None:
    rows = fetch(pg_conn, "SELECT * FROM staff_users WHERE tenant_id = %(tenant)s ORDER BY id")
    _post_each("staff", rows, "/admin/import/staff", lambda u: {
        "legacy_staff_id": u["id"], "email": u["email"], "full_name": u["full_name"],
        "role": u["role"], "is_active": bool(u["is_active"]), "hashed_password": u["hashed_password"],
    })


def migrate_masters(pg_conn: psycopg.Connection) -> None:
    for kind, table, cols in (
        ("sizes", "catalog_size_master", "ft, cm, is_active"),
        ("weaves", "weave_type_master", "name, is_active"),
        ("piles", "pile_height_master", "name, is_active"),
        ("spaces", "space_master", "name, is_active"),
        ("moods", "mood_master", "name, is_active"),
    ):
        rows = fetch(pg_conn, f"SELECT {cols} FROM {table} WHERE tenant_id = %(tenant)s ORDER BY sort_order, id")
        if not rows:
            continue  # nothing curated in the source: keep WordPress's seeded list
        resp = drc_api("POST", "/admin/import/masters", json={"type": kind, "rows": rows})
        print(f"masters {kind}: {resp.status_code} {resp.text[:80]}" if resp.status_code != 201 else f"masters {kind}: {len(rows)} rows")

    displays = fetch(pg_conn, "SELECT category, enabled, images FROM collection_displays WHERE tenant_id = %(tenant)s")
    for d in displays:
        d["images"] = [{**img, "image_url": media_url(img.get("image_url"))} for img in (d["images"] or [])]
    _post_each("collection displays", [{"id": d["category"], **d} for d in displays],
               "/admin/import/collection-displays", lambda d: {k: d[k] for k in ("category", "enabled", "images")})


def migrate_email_templates(pg_conn: psycopg.Connection) -> None:
    rows = fetch(pg_conn, "SELECT id, key, subject, body_html, body_text, is_active FROM email_templates WHERE tenant_id = %(tenant)s ORDER BY id")
    _post_each("email templates", rows, "/admin/import/email-templates",
               lambda t: {k: t[k] for k in ("key", "subject", "body_html", "body_text", "is_active")})


def migrate_rules(pg_conn: psycopg.Connection) -> None:
    for kind, table, cols in (
        ("pricing", "pricing_rules", "name, rule_type, min_qty, max_qty, multiplier, flat_fee, description"),
        ("moq", "moq_rules", "rug_type, minimum_sqm, minimum_pieces, notes"),
        ("timeline", "production_timelines", "order_type, base_days, complexity_multiplier_per_sqm, notes"),
    ):
        rows = fetch(pg_conn, f"SELECT {cols} FROM {table} WHERE tenant_id = %(tenant)s ORDER BY id")
        if not rows:
            continue  # keep WordPress's defaults rather than emptying the table
        resp = drc_api("POST", "/admin/import/rules", json={"kind": kind, "rows": rows})
        print(f"rules {kind}: {resp.json().get('rows') if resp.status_code == 201 else resp.text}")


def migrate_order_history(pg_conn: psycopg.Connection) -> None:
    history: dict[int, list] = {}
    for h in fetch(pg_conn, """
        SELECT h.order_id, h.status, h.changed_at FROM order_status_history h
        JOIN orders o ON o.id = h.order_id WHERE o.tenant_id = %(tenant)s ORDER BY h.order_id, h.changed_at
    """):
        history.setdefault(h["order_id"], []).append({"status": h["status"], "changed_at": _iso(h["changed_at"])})
    _post_each("order history", [{"id": oid, "entries": e} for oid, e in history.items()],
               "/admin/import/order-history", lambda r: {"legacy_order_id": r["id"], "entries": r["entries"]})


def migrate_promo_redemptions(pg_conn: psycopg.Connection) -> None:
    used: dict[int, list] = {}
    for r in fetch(pg_conn, """
        SELECT r.promo_code_id, r.customer_id, c.email FROM promo_redemptions r
        JOIN promo_codes p ON p.id = r.promo_code_id LEFT JOIN customers c ON c.id = r.customer_id
        WHERE p.tenant_id = %(tenant)s ORDER BY r.id
    """):
        who = CUSTOMER_USER_MAP.get(r["customer_id"]) or r["email"]
        if who:
            used.setdefault(r["promo_code_id"], []).append(who)
    _post_each("promo redemptions", [{"id": pid, "used_by": u} for pid, u in used.items()],
               "/admin/import/promo-redemptions", lambda r: {"legacy_promo_id": r["id"], "used_by": r["used_by"]})


def migrate_stories(pg_conn: psycopg.Connection) -> None:
    rows = fetch(pg_conn, "SELECT * FROM rug_stories WHERE tenant_id = %(tenant)s ORDER BY sort_order, id")
    _post_each("design stories", rows, "/admin/import/stories", lambda st: {
        "legacy_story_id": st["id"], "slug": st["slug"], "title": st["title"],
        "inspiration": st["inspiration"], "body_html": _absolutize_html(st["body_html"]),
        "cover_image_url": media_url(st["cover_image_url"]),
        "media": [{**m, "url": media_url(m.get("url")), "poster_url": media_url(m.get("poster_url"))} for m in (st["media"] or [])],
        "product_id": (RUG_PRODUCT_MAP.get(st["rug_id"]) or {}).get("product_id"),
        "is_published": bool(st["is_published"]), "sort_order": st["sort_order"] or 0,
        "created_at": _iso(st["created_at"]),
    })


# ─────────────────────────────────────────────────────────────────────────

PHASES = ("materials", "catalog", "customers", "quotes", "orders",
          "promo_codes", "reviews", "newsletter", "content", "inventory",
          "settings", "staff", "masters", "email_templates", "rules",
          "order_history", "promo_redemptions", "stories")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--only", choices=(*PHASES, "all"), default="all",
                        help="run a single phase (earlier phases still run read-only to rebuild id maps)")
    parser.add_argument("--tenant-id", type=int, default=None,
                        help="source tenant to import (default: the first tenant, like the storefront)")
    args = parser.parse_args()

    for var in ("WP_APP_USER", "WP_APP_PASSWORD", "WC_CONSUMER_KEY", "WC_CONSUMER_SECRET"):
        if not os.environ.get(var):
            sys.exit(f"{var} is not set — see migration/.env.example")

    conn = pg_connect()
    global TENANT_ID
    try:
        with conn.cursor() as cur:
            if args.tenant_id:
                cur.execute("SELECT id, name FROM tenants WHERE id = %s", (args.tenant_id,))
            else:
                cur.execute("SELECT id, name FROM tenants ORDER BY id LIMIT 1")
            tenant = cur.fetchone()
        if not tenant:
            sys.exit("No such tenant in the source database.")
        TENANT_ID = tenant["id"]
        print(f"Importing tenant #{tenant['id']} ({tenant['name']})")
        # Phases are cumulative: to run just "quotes" we still need the
        # material / rug / customer id maps, which only get built by running
        # those phases. Re-running is safe (409 = skip), so "--only X" runs
        # every phase up to and including X.
        target = PHASES.index(args.only) if args.only != "all" else len(PHASES) - 1
        runners = (migrate_materials, migrate_catalog, migrate_customers, migrate_quotes, migrate_orders,
                   migrate_promo_codes, migrate_reviews, migrate_newsletter, migrate_content, migrate_inventory,
                   migrate_settings, migrate_staff, migrate_masters, migrate_email_templates, migrate_rules,
                   migrate_order_history, migrate_promo_redemptions, migrate_stories)
        for i in range(target + 1):
            runners[i](conn)
    finally:
        conn.close()


if __name__ == "__main__":
    main()
