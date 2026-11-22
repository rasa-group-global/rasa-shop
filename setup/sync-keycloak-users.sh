#!/usr/bin/env bash
# ==============================================================================
# setup/sync-keycloak-users.sh — Bulk Sync WooCommerce Users <-> Rasa Keycloak SSO & RasaApi
# Run from host repo root (/var/www/RASA/wordpress-woo):
#   bash setup/sync-keycloak-users.sh
# ==============================================================================
set -euo pipefail

WEB="${WEB_CONTAINER:-shop-woo-web}"

echo "==> Running WooCommerce -> Rasa Keycloak SSO Bulk User Sync on container '$WEB'..."
docker exec -i "$WEB" php -d open_basedir= -d disable_functions= /usr/local/bin/wp eval "if (function_exists('rasa_sso_bulk_sync_all_woo_users')) { rasa_sso_bulk_sync_all_woo_users(); } else { echo 'ERROR: rasa-keycloak-sso.php not loaded in active theme.\n'; exit(1); }" --allow-root
