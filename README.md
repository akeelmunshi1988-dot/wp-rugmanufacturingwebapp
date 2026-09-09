# DreamRugsCreation — WordPress + WooCommerce

New WordPress + WooCommerce build of the DreamRugsCreation storefront,
living alongside (not replacing) the original FastAPI + React app at
`/Applications/RugManufactureCustomApp` until cutover. See the migration
plan at `/Users/user/.claude/plans/gentle-doodling-origami.md` for the full
architecture, scope decisions, and phase sequence this implements.

## Local development

Requires Docker Desktop running.

```sh
docker compose up -d
```

This starts:
- `db` — MySQL 8
- `wordpress` — WordPress (php8.3-apache), http://localhost:8080
- `wpcli` — one-shot WP-CLI, run via `docker compose run --rm wpcli <command>`
- `room-visualizer` — the ported room-visualizer microservice, http://localhost:8100

First boot takes a few minutes (MySQL data directory initialization is
noticeably slower under Docker Desktop's macOS filesystem than on Linux —
this is expected, not a hang; use `docker inspect <container> --format
'{{.State.Health.Status}}'` to watch for `healthy`).

### First-time WordPress install

Once `db` and `wordpress` are up, run `./setup.sh` (idempotent). It installs
WordPress, WooCommerce, the third-party plugins the migration plan calls for
(Rank Math SEO, Nextend Social Login, WooCommerce PDF Invoices & Packing
Slips), activates `dreamrugs-core` + the `dreamrugs` theme, and zeroes
WooCommerce's native tax engine (GST is computed display-only by the plugin).

### Social login credentials (manual, one-time)

Nextend Social Login is installed and active but inert until you add OAuth
app credentials it can't ship with:

- **Google** — create an OAuth client at
  <https://console.cloud.google.com/apis/credentials>, authorized redirect
  URI `http://localhost:8080/wp-login.php?loginSocial=google` (swap the host
  for production), then paste the client id/secret under
  **Settings → Nextend Social Login → Google**.
- **Facebook** — same idea via <https://developers.facebook.com/apps/>,
  redirect `http://localhost:8080/wp-login.php?loginSocial=facebook`.

The storefront's `SocialLoginButtons` equivalent renders automatically on
`wp-login.php` once a provider is configured.

## Structure

- `wp-content/plugins/dreamrugs-core/` — the custom plugin: quote engine,
  quote-negotiation lifecycle, GST invoice data, size formatting, settings,
  REST routes, admin menu. See the plugin's own file headers for what's
  ported from which original-app source file.
- `wp-content/themes/dreamrugs/` — the storefront theme, a page-for-page
  port of the original React storefront's exact HTML/Tailwind markup (see
  the migration plan's "Custom theme: pixel-identical storefront" section)
  — not a new design.
- `room-visualizer/` — standalone FastAPI + OpenCV microservice porting
  `room_composer.py`'s perspective-warp compositing. Called by the plugin's
  `/room-preview` REST route over the Docker network.
- `migration/` — the one-time PostgreSQL → WooCommerce data importer
  (`migrate_to_woocommerce.py`): materials, catalog → variable products,
  customers → WP users (legacy bcrypt hashes preserved, see
  `DRC_Legacy_Password`), quotes → `wp_drc_quotes`, orders → WooCommerce
  orders with the total force-set from the source. Idempotent; run against
  staging first. See its module docstring.
- `tests/quote-engine-parity/` — automated numeric parity check of the PHP
  pricing engine against the original Python one (`./run.sh`).

## Verifying the quote engine port

`wp-content/plugins/dreamrugs-core/includes/class-quote-engine.php` is a
line-for-line port of `backend/app/services/quote_engine.py`.
`tests/quote-engine-parity/run.sh` runs a shared input matrix through both
and diffs every output field — see that directory's README.
