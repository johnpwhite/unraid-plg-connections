#!/bin/bash
# install-engine.sh — Connected Clients installer engine.
#
# One install path for every route:
#   - the .plg install step (boot, Plugins page, ci/publish deploy):
#       ARCHIVE=/boot/config/plugins/unraid-connections/src-<version>.tar.gz
#   - the CI deploy stage (ci/runners/deploy.sh): ARCHIVE unset -> /tmp/aicli-src.tar.gz
#
# Environment:
#   NAME, VERSION   plugin name and version (VERSION is required)
#   EMHTTP_DEST     /usr/local/emhttp/plugins/unraid-connections
#   CONFIG_DIR      /boot/config/plugins/unraid-connections (optional; prunes old cached archives)
#   ARCHIVE         source tarball with a top-level src/ (default /tmp/aicli-src.tar.gz)
#   log_step, log_status, log_ok, log_warn, log_fail   optional; plain echo fallbacks below
#
# Exit 0 on success. A bad archive leaves the existing installation untouched.
set -u

declare -F log_step >/dev/null   || log_step()   { echo "  [>] $1"; }
declare -F log_status >/dev/null || log_status() { echo "    $1"; }
declare -F log_ok >/dev/null     || log_ok()     { echo "    [OK] $1"; }
declare -F log_warn >/dev/null   || log_warn()   { echo "    [!!] $1"; }
declare -F log_fail >/dev/null   || log_fail()   { echo "    [FAIL] $1"; }

NAME="${NAME:-unraid-connections}"
VERSION="${VERSION:?VERSION is required}"
EMHTTP_DEST="${EMHTTP_DEST:-/usr/local/emhttp/plugins/$NAME}"
ARCHIVE="${ARCHIVE:-/tmp/aicli-src.tar.gz}"
CONFIG_DIR="${CONFIG_DIR:-}"
RC="$EMHTTP_DEST/scripts/rc.$NAME"

# Files that must be in the archive (tests/php/ReleaseConsistencyTest.php checks this list against src/).
CC_REQUIRED=(
  ConnectedClients.page
  README.md
  unraid-connections.png
  state.php
  include/cc-common.php
  include/cc-logs.php
  include/cc-web.php
  include/cc-services.php
  include/cc-clients.php
  include/cc-snapshot.php
  scripts/collector.php
  scripts/rc.unraid-connections
  scripts/installer/install-engine.sh
  assets/cc.css
  assets/cc-model.js
  assets/cc-view.js
)

# This engine only ever replaces its own plugin directory.
case "$EMHTTP_DEST" in
  /usr/local/emhttp/plugins/unraid-connections) ;;
  *) log_fail "Refusing to install into an unexpected path: $EMHTTP_DEST"; exit 2 ;;
esac

log_step "Installing $NAME v$VERSION"
if [ ! -f "$ARCHIVE" ]; then
  log_fail "The source archive is not at $ARCHIVE."
  exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
if ! tar -xzf "$ARCHIVE" -C "$STAGE" 2>/dev/null; then
  log_fail "The source archive is unreadable: $ARCHIVE. The existing installation was not changed."
  exit 1
fi
missing=0
for f in "${CC_REQUIRED[@]}"; do
  if [ ! -s "$STAGE/src/$f" ]; then
    log_fail "src/$f is missing from the archive."
    missing=$((missing + 1))
  fi
done
if [ "$missing" -gt 0 ]; then
  log_fail "$missing required file(s) are missing. The existing installation was not changed."
  exit 1
fi
# A Windows checkout can add CR line endings; they break bash and PHP shebangs.
find "$STAGE/src" -type f \( -name '*.php' -o -name '*.page' -o -name '*.sh' -o -name '*.js' -o -name '*.css' -o -name 'rc.*' \) -exec sed -i 's/\r$//' {} +
log_ok "Archive verified (${#CC_REQUIRED[@]} required files)."

if [ -x "$RC" ]; then
  log_status "Stopping the running collector..."
  "$RC" stop >/dev/null 2>&1 || log_warn "The old collector did not stop cleanly."
fi

rm -rf "$EMHTTP_DEST"
mkdir -p "$EMHTTP_DEST"
if ! cp -r "$STAGE/src/." "$EMHTTP_DEST/"; then
  log_fail "The copy to $EMHTTP_DEST failed."
  exit 1
fi
find "$EMHTTP_DEST" -type d -exec chmod 755 {} +
find "$EMHTTP_DEST" -type f -exec chmod 644 {} +
chmod 755 "$EMHTTP_DEST/scripts/collector.php" "$RC" "$EMHTTP_DEST/scripts/installer/install-engine.sh"
log_ok "Files installed to $EMHTTP_DEST."

# Keep only this version's cached archive on flash.
if [ -n "$CONFIG_DIR" ] && [ -d "$CONFIG_DIR" ]; then
  find "$CONFIG_DIR" -maxdepth 1 -name 'src-*.tar.gz' ! -name "src-$VERSION.tar.gz" -delete 2>/dev/null
fi

if "$RC" start; then
  log_ok "Collector running."
else
  log_fail "The collector did not start. See /tmp/$NAME/collector.log."
  exit 1
fi
log_ok "$NAME v$VERSION installed."
exit 0
