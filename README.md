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

Once `db` and `wordpress` are up:

```sh
docker compose run --rm wpcli core install \
  --url="http://localhost:8080" \
  --title="DreamRugsCreation" \
  --admin_user="admin" \
  --admin_password="admin_dev_password" \
  --admin_email="admin@dreamrugscreation.local" \
  --skip-email

docker compose run --rm wpcli plugin install woocommerce --activate
docker compose run --rm wpcli plugin activate dreamrugs-core
docker compose run --rm wpcli theme activate dreamrugs
```

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
- `migration/` — the one-time PostgreSQL → WooCommerce data importer (not
  yet built — Phase 9 of the plan).

## Verifying the quote engine port

`wp-content/plugins/dreamrugs-core/includes/class-quote-engine.php` is a
line-for-line port of the original app's `backend/app/services/quote_engine.py`.
Before trusting it, run the same set of test inputs through both and diff
the outputs — see the migration plan's "Verification" section.
