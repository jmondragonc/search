#!/bin/bash
# =============================================================================
# Install Composer dependencies for the wc-meilisearch plugin.
# Run inside the wordpress container:
#   docker compose exec wordpress bash /scripts/composer-install.sh
# =============================================================================

set -euo pipefail

PLUGIN_DIR="/var/www/html/wp-content/plugins/wc-meilisearch"

echo "Installing Composer for wc-meilisearch plugin…"

# Install Composer if not present.
if ! command -v composer &>/dev/null; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

cd "$PLUGIN_DIR"
composer install --no-dev --optimize-autoloader --no-interaction

echo "Done. Vendor directory created at $PLUGIN_DIR/vendor"
