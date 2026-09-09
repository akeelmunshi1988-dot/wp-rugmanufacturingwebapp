#!/bin/sh
# First-time WordPress install + plugin/theme activation. Run once db and
# wordpress containers are up (docker compose up -d). Safe to re-run —
# `wp core install` and `wp plugin activate` are themselves idempotent.
set -e

cd "$(dirname "$0")"

echo "Waiting for WordPress container to accept WP-CLI commands..."
until docker compose run --rm wpcli core is-installed >/dev/null 2>&1; do
  if docker compose run --rm wpcli core install \
    --url="http://localhost:8080" \
    --title="DreamRugsCreation" \
    --admin_user="admin" \
    --admin_password="admin_dev_password" \
    --admin_email="admin@dreamrugscreation.local" \
    --skip-email 2>&1; then
    break
  fi
  sleep 3
done

echo "Installing WooCommerce..."
docker compose run --rm wpcli plugin install woocommerce --activate

# Third-party plugins the migration plan calls for. All from the wp.org
# directory, so `plugin install` fetches them; `--activate` is idempotent.
#   - seo-by-rank-math  : SEO structure (the stated motivation for the WP move)
#   - nextend-facebook-connect : "Nextend Social Login" — Google/Facebook sign-in
#     (parity with the original app's SocialLoginButtons); Google needs an OAuth
#     client id/secret set in Settings → Nextend Social Login before it works.
#   - woocommerce-pdf-invoices-packing-slips : the PDF invoice delivery shell
#     (DRC_Invoice_Data supplies the GST content via its template hooks).
echo "Installing SEO / social-login / PDF-invoice plugins..."
docker compose run --rm wpcli plugin install \
  seo-by-rank-math \
  nextend-facebook-connect \
  woocommerce-pdf-invoices-packing-slips \
  --activate

echo "Activating dreamrugs-core..."
docker compose run --rm wpcli plugin activate dreamrugs-core

echo "Activating dreamrugs theme..."
docker compose run --rm wpcli theme activate dreamrugs

# Zero WooCommerce's tax engine — GST is computed as a display-only,
# inclusive-and-backed-out breakdown by dreamrugs-core, never as WC tax
# lines (see the migration plan's business-logic section).
echo "Disabling WooCommerce native tax calculation..."
docker compose run --rm wpcli option update woocommerce_calc_taxes no

echo "Done. Visit http://localhost:8080 (admin: http://localhost:8080/wp-admin, admin/admin_dev_password)."
