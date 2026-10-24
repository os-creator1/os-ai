#!/usr/bin/env bash
# Customer website build: guarded production run. Run ON the Forge server as `forge` (see README.md).
#   bash ~/run-on-forge.sh <key>            -> read-only: verify target + bundle, take/validate a backup, dry-run the plan
#   bash ~/run-on-forge.sh <key> --apply    -> the same, re-verify the backup, then WRITE (asks you to type the confirmation phrase)
# <key> is one of: kaleb-hill | booths-to-the-max | greenbelt. Every validation fails closed before anything is written.
set -Eeuo pipefail
trap 'echo "ABORT: command failed at line $LINENO (nothing further was run)." >&2' ERR
die() { echo "ABORT: $*" >&2; exit 1; }

KEY="${1:-}"; APPLY=0
case "$KEY" in kaleb-hill|booths-to-the-max|greenbelt) ;; *) die "first argument must be kaleb-hill, booths-to-the-max or greenbelt" ;; esac
case "${2:-}" in "") ;; "--apply") APPLY=1 ;; *) die "unknown argument [${2}] (only --apply is accepted)" ;; esac

APP_DIR="${APP_DIR:-/home/forge/getmotiongrove.com/current}"
BUNDLE="${BUNDLE:-$HOME/$KEY-assets.tgz}"
PINNED="$APP_DIR/docs/migrations/customer-websites/bundles.sha256"

[ -d "$APP_DIR" ] || die "APP_DIR [$APP_DIR] does not exist"
cd "$APP_DIR"
[ -f .env ] || die ".env not found in $APP_DIR"
envval() { local v; v="$(grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//")"; printf '%s' "$v"; }
[ "$(envval APP_ENV)" = "production" ]         || die "APP_ENV is not production"
[ "$(envval DB_DATABASE)" = "GetMotionGrove" ] || die "DB_DATABASE is not GetMotionGrove"
APP_HOST="$(envval APP_URL | sed -E 's#^https?://([^/:]+).*#\1#')"
[ "$APP_HOST" = "getmotiongrove.com" ]         || die "APP_URL host is [$APP_HOST], not getmotiongrove.com"
DB_HOST_V="$(envval DB_HOST)"; DB_USER_V="$(envval DB_USERNAME)"; DB_PORT_V="$(envval DB_PORT)"; DB_PORT_V="${DB_PORT_V:-3306}"
[ -n "$DB_HOST_V" ] && [ -n "$DB_USER_V" ] || die "DB_HOST/DB_USERNAME missing from .env"
php artisan list --raw | grep -q '^customer-websites:build ' || die "customer-websites:build is not deployed on this release; merge and deploy the branch first"
[ -f "docs/migrations/customer-websites/$KEY/website-content.json" ] || die "no content file for [$KEY] in this release"
echo "Target OK: production / getmotiongrove.com / GetMotionGrove / $(pwd -P)"

# ---- bundle: checksum pinned in the release BEFORE extraction, safe member names, inner checksums ----
[ -f "$BUNDLE" ] || die "bundle [$BUNDLE] not found (upload $KEY-assets.tgz to the forge home directory)"
EXPECTED="$(grep -E "  $KEY-assets\.tgz\$" "$PINNED" | cut -d' ' -f1)"
[ -n "$EXPECTED" ] || die "no pinned checksum for $KEY in $PINNED"
ACTUAL="$(sha256sum "$BUNDLE" | cut -d' ' -f1)"
[ "$ACTUAL" = "$EXPECTED" ] || die "bundle checksum mismatch (got $ACTUAL, pinned $EXPECTED)"
if tar -tzf "$BUNDLE" | grep -E '(^/|(^|/)\.\.(/|$))' >/dev/null; then die "bundle contains unsafe paths"; fi
umask 077; mkdir -p "$HOME/private"; chmod 700 "$HOME/private"
STAGE="$HOME/private/$KEY-$(date +%Y%m%d-%H%M%S)"
mkdir "$STAGE"; chmod 700 "$STAGE"
tar -xzf "$BUNDLE" -C "$STAGE"
[ -f "$STAGE/SHA256SUMS" ] && [ -d "$STAGE/assets" ] || die "bundle lacks SHA256SUMS or assets/"
( cd "$STAGE" && sha256sum -c SHA256SUMS --quiet ) || die "extracted files do not match SHA256SUMS"
echo "Bundle checksums verified."

# ---- backup: dump, then prove it is complete and readable ----
mkdir -p "$HOME/backups"
BACKUP_REF="$HOME/backups/motiongrove-pre-$KEY-$(date +%Y%m%d-%H%M%S).sql.gz"
MYSQL_PWD="$(envval DB_PASSWORD)" mysqldump -h"$DB_HOST_V" -P"$DB_PORT_V" -u"$DB_USER_V" --single-transaction --quick --routines --triggers "$(envval DB_DATABASE)" | gzip > "$BACKUP_REF"
verify_backup() {
  [ -s "$BACKUP_REF" ] || die "backup file is missing or empty"
  gzip -t "$BACKUP_REF" || die "backup is not a valid gzip stream"
  [ "$(stat -c %s "$BACKUP_REF")" -gt 1048576 ] || die "backup is implausibly small"
  zcat "$BACKUP_REF" | tail -n 5 | grep -q -- '-- Dump completed' || die "backup is truncated (no completion marker)"
  local t; t="$(zcat "$BACKUP_REF" | grep -c '^CREATE TABLE')"
  [ "$t" -gt 100 ] || die "backup has only $t tables"
}
verify_backup
echo "Backup verified: $BACKUP_REF"

if [ "$APPLY" -eq 0 ]; then
  php artisan customer-websites:build "$KEY" --assets="$STAGE/assets"
  echo "DRY RUN complete: nothing was written. Re-run with --apply to build."
  exit 0
fi

PHRASE="BUILD CUSTOMER WEBSITES IN PRODUCTION"
read -r -p "Type exactly [$PHRASE] to build $KEY: " TYPED
[ "$TYPED" = "$PHRASE" ] || die "confirmation phrase did not match"
verify_backup
CUSTOMER_SITES_PRODUCTION_WRITE=true php artisan customer-websites:build "$KEY" --assets="$STAGE/assets" \
  --confirm-database=GetMotionGrove --backup-reference="$BACKUP_REF" --confirm-phrase="$PHRASE" --apply
echo "Done. Backup kept at $BACKUP_REF. Open the preview_url printed in REPORT above to review the site."
