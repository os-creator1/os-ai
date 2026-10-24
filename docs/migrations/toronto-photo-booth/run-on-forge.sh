#!/usr/bin/env bash
# Toronto Photo Booth: guarded production import. Run ON the Forge server as `forge` (see README.md).
#   bash ~/run-on-forge.sh            -> read-only: verify target + checksums, take/validate a backup, dry-run the import plan
#   bash ~/run-on-forge.sh --apply    -> the same, then WRITE (asks you to type the confirmation phrase)
set -euo pipefail

APP_DIR="${APP_DIR:-/home/forge/getmotiongrove.com/current}"
BUNDLE="${BUNDLE:-$HOME/toronto-prod-bundle.tgz}"
STAGE="$HOME/private/toronto-photo-booth"
OWNER_EMAIL="${OWNER_EMAIL:-milojazminm+torontopb@gmail.com}"   # agency-controlled; NEVER the customer's address
APPLY=0; [ "${1:-}" = "--apply" ] && APPLY=1

cd "$APP_DIR"
envval() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
[ "$(envval APP_ENV)" = "production" ]            || { echo "ABORT: APP_ENV is not production"; exit 1; }
[ "$(envval DB_DATABASE)" = "GetMotionGrove" ]    || { echo "ABORT: DB_DATABASE is not GetMotionGrove"; exit 1; }
APP_HOST="$(envval APP_URL | sed -E 's#^https?://([^/]+).*#\1#')"
[ "$APP_HOST" = "getmotiongrove.com" ]            || { echo "ABORT: APP_URL host is [$APP_HOST]"; exit 1; }
php artisan list --raw | grep -q '^toronto:production-provision ' || { echo "ABORT: the toronto:production-provision command is not deployed on this release. Merge and deploy the branch first."; exit 1; }
echo "Target OK: production / getmotiongrove.com / GetMotionGrove / $(pwd -P)"

umask 077; mkdir -p "$STAGE"; chmod 700 "$HOME/private" "$STAGE"
tar -xzf "$BUNDLE" -C "$STAGE"
( cd "$STAGE" && sha256sum -c SHA256SUMS --quiet ) && echo "Bundle checksums verified."
jq -e '.location.status==200 and .contacts.count==8' "$STAGE/snapshot/_report.json" >/dev/null 2>&1 || grep -q '"count": 8' "$STAGE/snapshot/_report.json" || { echo "ABORT: snapshot report does not match the verified export"; exit 1; }

BACKUP_REF="$HOME/backups/motiongrove-pre-toronto-$(date +%Y%m%d-%H%M%S).sql.gz"
mkdir -p "$HOME/backups"
DB_PORT_VAL="$(envval DB_PORT)"
MYSQL_PWD="$(envval DB_PASSWORD)" mysqldump -h"$(envval DB_HOST)" -P"${DB_PORT_VAL:-3306}" -u"$(envval DB_USERNAME)" --single-transaction --quick --routines --triggers "$(envval DB_DATABASE)" | gzip > "$BACKUP_REF"
gzip -t "$BACKUP_REF"
TABLES="$(zcat "$BACKUP_REF" | grep -c '^CREATE TABLE')"
[ "$TABLES" -gt 100 ] || { echo "ABORT: backup looks wrong ($TABLES tables)"; exit 1; }
echo "Backup validated: $BACKUP_REF ($(du -h "$BACKUP_REF" | cut -f1), $TABLES tables)"

COMMON=(--owner-email="$OWNER_EMAIL" --snapshot="$STAGE/snapshot" --assets="$STAGE/assets")
echo "---- READ-ONLY DRY RUN ----"
php artisan toronto:production-provision "${COMMON[@]}"

if [ "$APPLY" -ne 1 ]; then echo "Dry run only. Re-run with --apply to write."; exit 0; fi
echo; echo "About to WRITE to production database GetMotionGrove (additive; sends nothing)."
read -r -p 'Type exactly  CREATE TORONTO PHOTO BOOTH IN PRODUCTION  to continue: ' PHRASE
TORONTO_PRODUCTION_PROVISIONING=true php artisan toronto:production-provision "${COMMON[@]}" \
  --confirm-database=GetMotionGrove --backup-reference="$BACKUP_REF" --confirm-phrase="$PHRASE" --apply
echo "Done. Backup kept at $BACKUP_REF. The staged export in $STAGE contains customer data: remove it when verified (rm -rf $STAGE)."
