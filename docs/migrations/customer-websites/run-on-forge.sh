#!/usr/bin/env bash
# Customer website build: guarded production run. Run ON the Forge server as `forge` (see README.md).
#   bash ~/run-on-forge.sh <key>            -> read-only: verify target + bundle, take/validate a backup, dry-run the plan
#   bash ~/run-on-forge.sh <key> --apply    -> the same, re-verify the backup, then WRITE (asks you to type the confirmation phrase)
# <key> is one of: kaleb-hill | booths-to-the-max | greenbelt | transparent. Every validation fails closed before anything is written.
set -Eeuo pipefail
trap 'echo "ABORT: command failed at line $LINENO (nothing further was run)." >&2' ERR
# Remove ONLY the backup file this run created, and only while it has not passed verification. Earlier backups are never touched.
BACKUP_OWNED=0; BACKUP_VALID=0; BACKUP_REF=""
cleanup() {
  if [ "$BACKUP_OWNED" = 1 ] && [ "$BACKUP_VALID" != 1 ] && [ -n "$BACKUP_REF" ] && [ -f "$BACKUP_REF" ]; then
    rm -f -- "$BACKUP_REF"; echo "Removed the incomplete backup created by this run: $BACKUP_REF" >&2
  fi
}
trap cleanup EXIT
die() { echo "ABORT: $*" >&2; exit 1; }

KEY="${1:-}"; APPLY=0
case "$KEY" in kaleb-hill|booths-to-the-max|greenbelt|transparent) ;; *) die "first argument must be kaleb-hill, booths-to-the-max, greenbelt or transparent" ;; esac
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
[ ! -e "$BACKUP_REF" ] || die "backup path already exists: $BACKUP_REF"
BACKUP_OWNED=1
# --no-tablespaces: the DB user lacks the PROCESS privilege (tablespace metadata is not needed to restore this dump).
set +e
MYSQL_PWD="$(envval DB_PASSWORD)" mysqldump --no-tablespaces -h"$DB_HOST_V" -P"$DB_PORT_V" -u"$DB_USER_V" --single-transaction --quick --routines --triggers "$(envval DB_DATABASE)" | gzip > "$BACKUP_REF"
DUMP_STATUS=("${PIPESTATUS[@]}")
set -e
{ [ "${DUMP_STATUS[0]}" -eq 0 ] && [ "${DUMP_STATUS[1]}" -eq 0 ]; } || die "mysqldump/gzip failed (exit ${DUMP_STATUS[0]}/${DUMP_STATUS[1]})"
live_tables() {   # base tables in the production schema (read-only information_schema query; no PROCESS privilege needed)
  MYSQL_PWD="$(envval DB_PASSWORD)" mysql -h"$DB_HOST_V" -P"$DB_PORT_V" -u"$DB_USER_V" -N -B \
    -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$(envval DB_DATABASE)' AND table_type='BASE TABLE' ORDER BY table_name" | sort -u
}
verify_backup() {
  # Completeness is judged against the live schema, not against an arbitrary size: a small production database
  # legitimately compresses to well under 1 MB. Every pipeline here is pipefail/SIGPIPE-safe (output is captured first).
  [ -s "$BACKUP_REF" ] || die "backup file is missing or empty"
  gzip -t "$BACKUP_REF" || die "backup is not a valid gzip stream"
  local c u t live dump tail5 missing
  c="$(stat -c %s "$BACKUP_REF")"; u="$(zcat "$BACKUP_REF" | wc -c)"
  [ "$u" -gt 65536 ] || die "backup expands to only $u bytes (compressed $c)"
  tail5="$(zcat "$BACKUP_REF" | tail -n 5)"
  [ "$(printf '%s\n' "$tail5" | grep -c -- '-- Dump completed' || true)" -ge 1 ] || die "backup is truncated (no completion marker)"
  dump="$(zcat "$BACKUP_REF" | grep -o '^CREATE TABLE `[^`]*`' | sed -e 's/^CREATE TABLE `//' -e 's/`$//' | sort -u)"
  t="$(printf '%s\n' "$dump" | grep -c . || true)"
  printf '%s\n' "$dump" | grep -qx 'users' || die "backup has no users table"
  live="$(live_tables)" || die "could not read the live table list"
  [ "$(printf '%s\n' "$live" | grep -c . || true)" -gt 0 ] || die "live schema reports no tables"
  missing="$(comm -23 <(printf '%s\n' "$live") <(printf '%s\n' "$dump") | head -n 5 | tr '\n' ' ')"
  [ -z "$missing" ] || die "backup is missing live tables, e.g.: $missing"
  echo "Backup validated: $BACKUP_REF (compressed $c bytes, uncompressed $u bytes, $t tables in dump, $(printf '%s\n' "$live" | grep -c .) live tables, all present)"
}
verify_backup
BACKUP_VALID=1
BACKUP_SHA="$(sha256sum "$BACKUP_REF" | cut -d' ' -f1)"
printf '%s  %s\n' "$BACKUP_SHA" "$(basename "$BACKUP_REF")" > "$BACKUP_REF.sha256"
echo "Backup SHA-256 recorded: $BACKUP_SHA ($BACKUP_REF.sha256)"

if [ "$APPLY" -eq 0 ]; then
  php artisan customer-websites:build "$KEY" --assets="$STAGE/assets"
  echo "DRY RUN complete: nothing was written. Re-run with --apply to build."
  exit 0
fi

PHRASE="BUILD CUSTOMER WEBSITES IN PRODUCTION"
read -r -p "Type exactly [$PHRASE] to build $KEY: " TYPED
[ "$TYPED" = "$PHRASE" ] || die "confirmation phrase did not match"
[ "$(sha256sum "$BACKUP_REF" | cut -d' ' -f1)" = "$BACKUP_SHA" ] || die "backup changed after validation"
verify_backup
CUSTOMER_SITES_PRODUCTION_WRITE=true php artisan customer-websites:build "$KEY" --assets="$STAGE/assets" \
  --confirm-database=GetMotionGrove --backup-reference="$BACKUP_REF" --confirm-phrase="$PHRASE" ${ACCEPT_CONTACT_MISMATCH:+--accept-contact-mismatch} --apply
echo "Done. Backup kept at $BACKUP_REF. Open the preview_url printed in REPORT above to review the site."
