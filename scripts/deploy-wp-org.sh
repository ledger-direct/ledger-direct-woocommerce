#!/usr/bin/env bash
set -euo pipefail

# Configuration (override via environment if needed)
PLUGIN_SLUG=${PLUGIN_SLUG:-ledger-direct}
PLUGIN_DIR=${PLUGIN_DIR:-$(pwd)}
PLUGIN_MAIN=${PLUGIN_MAIN:-ledger-direct.php}
SVN_URL=${SVN_URL:-"https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"}
BUILD_DIR=${BUILD_DIR:-"${PLUGIN_DIR}/.build"}
DIST_DIR=${DIST_DIR:-"${BUILD_DIR}/dist"}
SVN_DIR=${SVN_DIR:-"${BUILD_DIR}/svn"}
VERSION=${VERSION:-}
DRY_RUN=${DRY_RUN:-}   # set to 1 to build and verify without touching SVN
SCOPED_DIR=${SCOPED_DIR:-"${BUILD_DIR}/scoped"}
TOOLS_DIR=${TOOLS_DIR:-"${PLUGIN_DIR}/.build/tools"}
PHP_SCOPER_VERSION=${PHP_SCOPER_VERSION:-0.18.19}
PHP_SCOPER_SHA256=${PHP_SCOPER_SHA256:-170fb84bd3390defb30f99f7dc39c9a89d10c29973accc26f31c00abc5b25933}
SCOPER_PREFIX="LedgerDirect\\Vendor"

info() { echo -e "\033[1;34m[info]\033[0m $*"; }
warn() { echo -e "\033[1;33m[warn]\033[0m $*"; }
err()  { echo -e "\033[1;31m[err ]\033[0m $*"; }

# Ensure required tools
command -v svn >/dev/null || { err "svn not found"; exit 1; }
command -v rsync >/dev/null || { err "rsync not found"; exit 1; }
command -v php >/dev/null || { err "php not found"; exit 1; }

# Detect version from plugin header if not supplied
if [[ -z "${VERSION}" ]]; then
  VERSION=$(php -r '
    $f = getenv("PLUGIN_MAIN") ?: "'"${PLUGIN_MAIN}"'";
    $c = file_get_contents($f);
    if (preg_match("/^\s*\*\s*Version:\s*([^\r\n]+)/m", $c, $m)) { echo trim($m[1]); } else { exit(1); }
  ')
fi

info "Preparing release ${VERSION}"

# Verify readme Stable tag matches plugin version
STABLE=$(php -r '
  $c = file_get_contents("readme.txt");
  if (preg_match("/^Stable tag:\s*([^\r\n]+)/mi", $c, $m)) { echo trim($m[1]); } else { exit(1); }
')
if [[ "${STABLE}" != "${VERSION}" ]]; then
  err "readme.txt Stable tag (${STABLE}) does not match plugin header Version (${VERSION})";
  exit 1
fi

# Only a committed state gets released: the dist is an rsync of the working
# tree, so anything uncommitted (or a stale build) would ship unnoticed.
if command -v git >/dev/null && git -C "${PLUGIN_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  if [[ -n "$(git -C "${PLUGIN_DIR}" status --porcelain --untracked-files=no)" ]]; then
    err "Working tree has uncommitted changes; commit or stash them before releasing."
    git -C "${PLUGIN_DIR}" status --short --untracked-files=no
    exit 1
  fi
fi

# Rebuild the checkout-block bundle from source and insist that the result
# matches what is committed - a forgotten `npm run build` must not ship.
if [[ -f "${PLUGIN_DIR}/package.json" ]]; then
  command -v npm >/dev/null || { err "npm not found (needed to verify the checkout-block build)"; exit 1; }
  info "Building checkout-block assets"
  ( cd "${PLUGIN_DIR}" && npm install --no-audit --no-fund --silent && npm run build --silent )
  if [[ -n "$(git -C "${PLUGIN_DIR}" status --porcelain -- includes/assets)" ]]; then
    err "Built assets differ from the committed ones; run 'npm run build' and commit includes/assets first."
    git -C "${PLUGIN_DIR}" status --short -- includes/assets
    exit 1
  fi
fi

# Translations: the block needs JSON catalogs generated from the .po files.
# Regenerate when WP-CLI is available; otherwise verify by content that the
# committed JSON matches the .po (file times are meaningless after a git
# checkout, so they are not used).
if command -v wp >/dev/null; then
  info "Regenerating JSON translation catalogs"
  ( cd "${PLUGIN_DIR}" && wp i18n make-json languages --no-purge --quiet )
  if [[ -n "$(git -C "${PLUGIN_DIR}" status --porcelain -- languages)" ]]; then
    err "Translation catalogs changed on regeneration; commit languages/ first."
    git -C "${PLUGIN_DIR}" status --short -- languages
    exit 1
  fi
else
  info "Verifying translation catalogs (wp-cli not available, comparing by content)"
  php -r '
    $dir = $argv[1];
    foreach (glob("$dir/*.po") as $po) {
      $locale = basename($po, ".po");
      if (!file_exists("$dir/$locale.mo")) { fwrite(STDERR, "missing $locale.mo - run msgfmt and commit\n"); exit(1); }
      $jsons = glob("$dir/$locale-*.json");
      if ($jsons === []) { fwrite(STDERR, "missing JSON catalog for $locale - run wp i18n make-json and commit\n"); exit(1); }
      // Minimal .po parser: msgid/msgstr pairs, single- or multi-line.
      $entries = []; $id = null; $str = null; $cur = null;
      foreach (file($po) as $line) {
        $line = rtrim($line, "\r\n");
        if (preg_match("/^msgid (\".*\")$/", $line, $m)) { if ($id !== null) { $entries[$id] = $str; } $id = json_decode($m[1]) ?? stripcslashes(trim($m[1], "\"")); $str = ""; $cur = "id"; continue; }
        if (preg_match("/^msgstr (\".*\")$/", $line, $m)) { $str = json_decode($m[1]) ?? stripcslashes(trim($m[1], "\"")); $cur = "str"; continue; }
        if (preg_match("/^(\".*\")$/", $line, $m)) { $v = json_decode($m[1]) ?? stripcslashes(trim($m[1], "\"")); if ($cur === "id") { $id .= $v; } elseif ($cur === "str") { $str .= $v; } continue; }
        if ($line === "" && $id !== null) { $entries[$id] = $str; $id = null; $cur = null; }
      }
      if ($id !== null) { $entries[$id] = $str; }
      foreach ($jsons as $json) {
        $data = json_decode(file_get_contents($json), true);
        $messages = $data["locale_data"]["messages"] ?? [];
        foreach ($messages as $msgid => $translations) {
          if ($msgid === "") { continue; }
          $expected = $entries[$msgid] ?? null;
          if ($expected === null || $expected !== ($translations[0] ?? null)) {
            fwrite(STDERR, "JSON catalog " . basename($json) . " is out of date for \"$msgid\" - run wp i18n make-json and commit\n");
            exit(1);
          }
        }
      }
    }
    echo "translation catalogs consistent\n";
  ' "${PLUGIN_DIR}/languages" || exit 1
fi

# Prepare build directories
rm -rf "${BUILD_DIR}" && mkdir -p "${DIST_DIR}" "${SVN_DIR}"

info "Installing composer dependencies (no-dev)"
if command -v composer >/dev/null; then
  COMPOSER_NO_INTERACTION=1 composer install --no-dev --no-scripts --optimize-autoloader
else
  warn "composer not found; skipping vendor installation. Ensure vendor/ exists."
fi

# If JS/CSS build is needed, run here (not currently used)
# npm ci && npm run build

info "Creating dist snapshot"
# Use .distignore as the single source of truth for exclusions, if present
RSYNC_ARGS=( -a --delete )
if [[ -f "${PLUGIN_DIR}/.distignore" ]]; then
  info "Using .distignore for rsync excludes"
  RSYNC_ARGS+=( --exclude-from="${PLUGIN_DIR}/.distignore" )
else
  warn ".distignore not found; no excludes will be applied"
fi
rsync "${RSYNC_ARGS[@]}" "${PLUGIN_DIR}/" "${DIST_DIR}/"

# Sanity checks
[[ -f "${DIST_DIR}/${PLUGIN_MAIN}" ]] || { err "Main plugin file missing in dist (${PLUGIN_MAIN})"; exit 1; }
[[ -f "${DIST_DIR}/readme.txt" ]] || { err "readme.txt missing in dist"; exit 1; }

# Developer documents never ship. .gitignore and export-ignore only protect
# what Composer fetches from Packagist; a path-repository install copies a
# package directory as it is, ignored files included, and 1.1.0 went out
# with a handover document under vendor/ that way.
STRAY_DOCS=$(find "${DIST_DIR}" -type f \( -name 'Handover-*.md' -o -name 'LedgerDirect-*.md' -o -name 'CLAUDE.md' -o -name 'MUSINGS.md' \) -print)
if [[ -n "${STRAY_DOCS}" ]]; then
  err "Developer documents in the dist tree; reinstall the package they belong to from Packagist:"
  echo "${STRAY_DOCS}"
  exit 1
fi

# ---------------------------------------------------------------------------
# Isolate the bundled dependencies (PHP-Scoper). See scoper.inc.php for why.
# The phar is kept outside the repo, pinned by version and checksum.
# ---------------------------------------------------------------------------
mkdir -p "${TOOLS_DIR}"
SCOPER="${TOOLS_DIR}/php-scoper-${PHP_SCOPER_VERSION}.phar"
if [[ ! -f "${SCOPER}" ]]; then
  info "Downloading php-scoper ${PHP_SCOPER_VERSION}"
  curl -sSL -o "${SCOPER}" "https://github.com/humbug/php-scoper/releases/download/${PHP_SCOPER_VERSION}/php-scoper.phar"
fi
ACTUAL_SHA=$(shasum -a 256 "${SCOPER}" | cut -d' ' -f1)
if [[ "${ACTUAL_SHA}" != "${PHP_SCOPER_SHA256}" ]]; then
  err "php-scoper checksum mismatch (${ACTUAL_SHA}); refusing to run an unverified phar."
  rm -f "${SCOPER}"
  exit 1
fi

info "Prefixing bundled dependencies with ${SCOPER_PREFIX}"
rm -rf "${SCOPED_DIR}"
# scoper.inc.php is not part of the dist (.distignore); hand it over explicitly.
php "${SCOPER}" add-prefix \
  --config="${PLUGIN_DIR}/scoper.inc.php" \
  --working-dir="${DIST_DIR}" \
  --output-dir="${SCOPED_DIR}" \
  --force --no-interaction --quiet

info "Rebuilding the Composer autoloader for the prefixed classes"
( cd "${SCOPED_DIR}" && COMPOSER_NO_INTERACTION=1 composer dump-autoload --no-dev --classmap-authoritative --quiet )

info "Verifying the scoped build"
# 1. Every PHP file still parses.
find "${SCOPED_DIR}" -name '*.php' -print0 | xargs -0 -n1 -P4 php -l >/dev/null || { err "Syntax error in the scoped build"; exit 1; }
# 2. The plugin's own code references only prefixed vendor symbols
#    (comments are ignored; a docblock is not a reference).
UNPREFIXED=$(grep -rnE '(^|[^\\A-Za-z])(Psr|Nyholm|Brick)\\|Hardcastle\\LedgerDirect\\Core\\' \
    "${SCOPED_DIR}/src" "${SCOPED_DIR}/includes" "${SCOPED_DIR}/${PLUGIN_MAIN}" \
  | grep -vF 'LedgerDirect\Vendor\' \
  | grep -vE '^[^:]+:[0-9]+:[[:space:]]*(\*|//|#|/\*)' || true)
if [[ -n "${UNPREFIXED}" ]]; then
  err "Unprefixed vendor references remain in the scoped plugin code:"
  echo "${UNPREFIXED}" | head -20
  exit 1
fi
# 3. No global symbol was prefixed: WordPress' and WooCommerce's classes,
#    functions and constants, and the plugin's own global classes, must
#    stay exactly as they are (a prefixed WC_Order is a fatal error).
GLOBALS_PREFIXED=$(grep -rhoE "LedgerDirect\\\\Vendor\\\\[A-Za-z_0-9]+([^A-Za-z_0-9\\\\]|$)" "${SCOPED_DIR}" --include='*.php' --exclude-dir=composer --exclude=scoper-autoload.php --exclude=autoload.php \
  | sed -E 's/[^A-Za-z_0-9\\]$//' | grep -vE '\\\\Composer$' | sort -u || true)
if [[ -n "${GLOBALS_PREFIXED}" ]]; then
  err "Global symbols were prefixed (see exclude-* in scoper.inc.php):"
  echo "${GLOBALS_PREFIXED}" | head -20
  exit 1
fi
# 4. The scoped autoloader resolves the core and its dependencies.
php -r '
  require $argv[1] . "/vendor/autoload.php";
  $p = "LedgerDirect\\Vendor\\";
  foreach ([
    $p . "Hardcastle\\LedgerDirect\\Core\\Payment\\SettlementPolicy",
    $p . "Hardcastle\\LedgerDirect\\Core\\Payment\\PaymentIntent",
    $p . "Hardcastle\\LedgerDirect\\Core\\Xrpl\\StablecoinRegistry",
    $p . "Nyholm\\Psr7\\Factory\\Psr17Factory",
    $p . "Brick\\Math\\BigDecimal",
    $p . "Psr\\Log\\LoggerInterface",
  ] as $class) {
    if (!class_exists($class) && !interface_exists($class)) { fwrite(STDERR, "missing: $class\n"); exit(1); }
  }
  $policy = new ($p . "Hardcastle\\LedgerDirect\\Core\\Payment\\SettlementPolicy")();
  $intent = ($p . "Hardcastle\\LedgerDirect\\Core\\Payment\\PaymentIntent")::quote("xrp-payment", "XRPL", "testnet", "XRP", "EUR", "XRP/EUR", 0.5, 2.0, "rAccount", 12345);
  if ($policy->isSettled($intent->withFulfillment("HASH", 2.0))) { echo "scoped autoload ok\n"; exit(0); }
  exit(1);
' "${SCOPED_DIR}" || { err "Scoped autoloader smoke test failed"; exit 1; }

# Ship the scoped build.
DIST_DIR="${SCOPED_DIR}"

if [[ -n "${DRY_RUN}" ]]; then
  info "DRY_RUN set - dist is ready in ${DIST_DIR}, not touching SVN."
  exit 0
fi

info "Checking out WordPress.org SVN"
svn checkout "${SVN_URL}" "${SVN_DIR}"

# Ensure expected structure
mkdir -p "${SVN_DIR}/trunk" "${SVN_DIR}/tags" "${SVN_DIR}/assets"

info "Syncing dist -> trunk/"
rsync -a --delete "${DIST_DIR}/" "${SVN_DIR}/trunk/"

TAG_DIR="${SVN_DIR}/tags/${VERSION}"
info "Creating tag ${VERSION}"
rm -rf "${TAG_DIR}" && mkdir -p "${TAG_DIR}"
rsync -a --delete "${DIST_DIR}/" "${TAG_DIR}/"

info "SVN add/remove"
svn status "${SVN_DIR}" | awk '/^\?/ {print $2}' | xargs -r svn add
svn status "${SVN_DIR}" | awk '/^!/{print $2}' | xargs -r svn rm --force

info "Committing to SVN"
if [[ -n "${WPORG_USER:-}" && -n "${WPORG_PASS:-}" ]]; then
  svn commit "${SVN_DIR}" --username "${WPORG_USER}" --password "${WPORG_PASS}" --non-interactive -m "Release ${PLUGIN_SLUG} ${VERSION}"
else
  svn commit "${SVN_DIR}" -m "Release ${PLUGIN_SLUG} ${VERSION}"
fi

info "Done. Deployed ${VERSION} to WordPress.org SVN."
