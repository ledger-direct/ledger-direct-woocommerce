#!/usr/bin/env bash
#
# Every start of the try-it shop:
# - first start: install WordPress against the stack's database, activate WooCommerce and LedgerDirect
# - apply the LedgerDirect settings (receiving account, payment methods) — on every start, so a
#   changed LD_TESTNET_ACCOUNT takes effect without a reset
# - when the image brings a new catalogue: the demo category and article, the miniatures, the home page
# - after an image update: the database updates of WordPress, WooCommerce and the plugin
# - then WordPress's cron in the background (it runs the plugin's settlement job), and the shop on Apache
#
# The shop's state lives in the stack's volumes (database, uploads, and the state volume with the keys,
# the testnet wallet and what has been set up), so recreating this container keeps it.
# `docker compose down -v` is the reset.
set -euo pipefail
cd /var/www/html

: "${LD_BASE_URL:=http://localhost:8092}"
: "${LD_DB_HOST:=db}"
: "${LD_DB_NAME:=wordpress}"
: "${LD_DB_USER:=wordpress}"
: "${LD_DB_PASSWORD:=wordpress}"
# A demo login for a shop that only listens on this machine. Not a secret, never reuse it.
: "${LD_ADMIN_USER:=demo}"
: "${LD_ADMIN_PASSWORD:=demo-ledgerdirect-1}"
LD_BASE_URL=${LD_BASE_URL%/}
export LD_BASE_URL LD_DB_HOST LD_DB_NAME LD_DB_USER LD_DB_PASSWORD

STATE=/var/lib/ledger-direct-try
KEYS=$STATE/keys.php                 # WordPress's keys and salts, one set per stack
INSTALLING=$STATE/installing         # wp core install started
INSTALLED=$STATE/installed           # ... and finished, plugins active
CATALOG=$STATE/catalog               # the catalogue (catalog-id) last imported
BUILD=$STATE/build                   # the image the shop was last set up with
SETUP=/usr/local/share/ledger-direct
log() { printf '[ledger-direct-try] %s\n' "$*"; }

wait_for() {
  local what=$1 tries=120
  shift
  until "$@" >/dev/null 2>&1; do
    tries=$((tries - 1))
    if [ "$tries" -le 0 ]; then log "gave up waiting for $what"; exit 1; fi
    sleep 2
  done
}
db() {
  php -r '$db = new mysqli(getenv("LD_DB_HOST"), getenv("LD_DB_USER"), getenv("LD_DB_PASSWORD"), getenv("LD_DB_NAME"));' "$@"
}
db_has_shop() {
  php -r '$db = new mysqli(getenv("LD_DB_HOST"), getenv("LD_DB_USER"), getenv("LD_DB_PASSWORD"), getenv("LD_DB_NAME"));
          exit($db->query("SHOW TABLES LIKE \"wp_options\"")->num_rows === 0 ? 1 : 0);'
}
db_drop_tables() {
  php -r '$db = new mysqli(getenv("LD_DB_HOST"), getenv("LD_DB_USER"), getenv("LD_DB_PASSWORD"), getenv("LD_DB_NAME"));
          $db->query("SET FOREIGN_KEY_CHECKS = 0");
          foreach ($db->query("SHOW TABLES")->fetch_all() as [$table]) { $db->query("DROP TABLE `$table`"); }'
}

wait_for "the database" db
started=$(date +%s)

if [ ! -f "$INSTALLED" ]; then
  if [ -f "$INSTALLING" ]; then
    # A previous first start began installing but did not finish: nothing in the database belongs to
    # a usable shop yet, so starting over is safe.
    log "an earlier installation did not finish; starting it over"
    db_drop_tables
  elif db_has_shop; then
    # A shop in the database, but this container has no state for it (its state volume was removed):
    # its keys and the testnet wallet that receives its payments are gone. Refuse instead of carrying
    # on with a new wallet behind the shop's back.
    log "the database already holds a shop, but the stack's state volume (keys, testnet wallet) is missing."
    log "reset the whole stack with: docker compose down -v"
    exit 1
  fi

  php -r 'echo "<?php\n"; foreach (["AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY", "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT"] as $key) { printf("define(%s, %s);\n", var_export($key, true), var_export(bin2hex(random_bytes(32)), true)); }' > "$KEYS.tmp"
  chmod 0600 "$KEYS.tmp"
  mv "$KEYS.tmp" "$KEYS"
  touch "$INSTALLING"

  log "installing WordPress and WooCommerce (well under a minute)"
  wp core install --url="$LD_BASE_URL" --title="LedgerDirect demo shop" \
    --admin_user="$LD_ADMIN_USER" --admin_password="$LD_ADMIN_PASSWORD" --admin_email=demo@example.invalid \
    --skip-email --quiet
  wp plugin activate woocommerce ledger-direct --quiet
  touch "$INSTALLED"
  rm -f "$INSTALLING"
  cp "$SETUP/build-id" "$BUILD"
elif ! cmp -s "$SETUP/build-id" "$BUILD" 2>/dev/null; then
  log "the image changed since the last start: database updates"
  wp core update-db --quiet
  wp plugin activate woocommerce ledger-direct --quiet
  wp wc update --quiet 2>/dev/null || true
  cp "$SETUP/build-id" "$BUILD"
fi

php "$SETUP/install.php" config

if ! cmp -s "$SETUP/catalog-id" "$CATALOG" 2>/dev/null; then
  php "$SETUP/install.php" catalog
  cp "$SETUP/catalog-id" "$CATALOG"
fi
wp cache flush --quiet
log "ready in $(( $(date +%s) - started )) s"

# WordPress's cron, which also drives WooCommerce's Action Scheduler: the plugin's settlement job
# settles orders whose payment arrived while no payment page was open.
( while sleep 60; do wp cron event run --due-now --quiet >/dev/null 2>&1 || true; done ) &

log "shop:  ${LD_BASE_URL}/"
log "admin: ${LD_BASE_URL}/wp-admin/  (demo login ${LD_ADMIN_USER} / ${LD_ADMIN_PASSWORD} — local demo only)"
log "receiving account: $(cat "$STATE/account" 2>/dev/null || echo unknown)"
exec apache2-foreground
