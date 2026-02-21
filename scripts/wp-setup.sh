#!/bin/bash
# =============================================================================
# WordPress initial setup via WP-CLI
# Run inside the wpcli container:
#   docker compose exec wpcli bash /scripts/wp-setup.sh
# =============================================================================

set -euo pipefail

WP="wp --allow-root --path=/var/www/html"

echo "============================================"
echo " WordPress + WooCommerce initial setup"
echo "============================================"

# Wait for WordPress to be reachable.
echo "[1/7] Waiting for database…"
until $WP db check &>/dev/null; do
  sleep 3
done

# Install WordPress if not already installed.
if ! $WP core is-installed 2>/dev/null; then
  echo "[2/7] Installing WordPress core…"
  $WP core install \
    --url="${WP_SITE_URL}" \
    --title="${WP_SITE_TITLE}" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email
else
  echo "[2/7] WordPress already installed – skipping."
fi

# Install & activate WooCommerce.
echo "[3/7] Installing WooCommerce…"
$WP plugin install woocommerce --activate --quiet || true

# Activate our custom plugin.
echo "[4/7] Activating wc-meilisearch plugin…"
$WP plugin activate wc-meilisearch 2>/dev/null || \
  echo "       (skipped – run composer install inside the plugin first)"

# Set pretty permalinks (required for WooCommerce REST API).
echo "[5/7] Setting permalink structure…"
$WP rewrite structure "/%postname%/" --hard

# Run WooCommerce setup wizard defaults.
echo "[6/7] Configuring WooCommerce basics…"
$WP option update woocommerce_store_address  "Calle Falsa 123"
$WP option update woocommerce_store_city     "Bogotá"
$WP option update woocommerce_default_country "CO"
$WP option update woocommerce_currency        "COP"
$WP option update woocommerce_currency_pos    "left"

# Set plugin environment options (fallback if env vars not picked up).
echo "[7/7] Configuring Meilisearch options…"
$WP option update wcm_meili_host  "${MEILI_HOST:-http://meilisearch:7700}"
$WP option update wcm_meili_key   "${MEILI_MASTER_KEY:-}"
$WP option update wcm_redis_host  "${REDIS_HOST:-redis}"
$WP option update wcm_redis_port  "${REDIS_PORT:-6379}"

echo ""
echo "============================================"
echo " Setup complete!"
echo " Admin: ${WP_SITE_URL}/wp-admin"
echo " User : ${WP_ADMIN_USER}"
echo " Pass : ${WP_ADMIN_PASSWORD}"
echo "============================================"
