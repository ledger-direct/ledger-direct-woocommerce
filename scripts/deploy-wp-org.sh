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
