#!/usr/bin/env bash
# Toronto Photo Booth: guarded production import. Run ON the Forge server as `forge` (see README.md).
#   bash ~/run-on-forge.sh            -> read-only: verify target, bundle and snapshot, take/validate a backup, dry-run the plan
#   bash ~/run-on-forge.sh --apply    -> the same, re-verify the backup, then WRITE (asks you to type the confirmation phrase)
# Every validation fails closed: any failed check exits non-zero before anything is written.
set -Eeuo pipefail
trap 'echo "ABORT: command failed at line $LINENO (nothing further was run)." >&2' ERR
die() { echo "ABORT: $*" >&2; exit 1; }
# Remove ONLY the backup file this run created, and only while it has not passed verification. Earlier backups are never touched.
BACKUP_OWNED=0; BACKUP_VALID=0; BACKUP_REF=""
cleanup() {
  if [ "$BACKUP_OWNED" = 1 ] && [ "$BACKUP_VALID" != 1 ] && [ -n "$BACKUP_REF" ] && [ -f "$BACKUP_REF" ]; then
    rm -f -- "$BACKUP_REF"; echo "Removed the incomplete backup created by this run: $BACKUP_REF" >&2
  fi
}
trap cleanup EXIT

APP_DIR="${APP_DIR:-/home/forge/getmotiongrove.com/current}"
BUNDLE="${BUNDLE:-$HOME/toronto-prod-bundle.tgz}"
EXPECTED_BUNDLE_SHA256="${EXPECTED_BUNDLE_SHA256:-7a7bd65cf43ab6c6c3246de98dd87aa96d009a79812362b730dfca9871b63c77}"
OWNER_EMAIL="${OWNER_EMAIL:-milojazminm+torontopb@gmail.com}"   # agency-controlled; NEVER the customer's address
GHL_LOCATION="9Yjeolqf4nEkFxyPmqJY"
APPLY=0
case "${1:-}" in "") ;; "--apply") APPLY=1 ;; *) die "unknown argument [$1] (only --apply is accepted)" ;; esac

[[ "$OWNER_EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || die "OWNER_EMAIL is not a valid address"
[ "${OWNER_EMAIL,,}" != "smiles@pbto.ca" ] || die "OWNER_EMAIL must be agency-controlled, not the customer's address"

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
php artisan list --raw | grep -q '^toronto:production-provision ' || die "toronto:production-provision is not deployed on this release; merge and deploy the branch first"
echo "Target OK: production / getmotiongrove.com / GetMotionGrove / $(pwd -P)"

# ---- bundle: pinned checksum BEFORE extraction, safe member names, inner checksums ----
[ -f "$BUNDLE" ] || die "bundle [$BUNDLE] not found"
ACTUAL="$(sha256sum "$BUNDLE" | cut -d' ' -f1)"
[ "$ACTUAL" = "$EXPECTED_BUNDLE_SHA256" ] || die "bundle checksum mismatch (got $ACTUAL)"
if tar -tzf "$BUNDLE" | grep -E '(^/|(^|/)\.\.(/|$))' >/dev/null; then die "bundle contains unsafe paths"; fi
umask 077; mkdir -p "$HOME/private"; chmod 700 "$HOME/private"
STAGE="$HOME/private/toronto-photo-booth-$(date +%Y%m%d-%H%M%S)"
mkdir "$STAGE"; chmod 700 "$STAGE"
tar -xzf "$BUNDLE" -C "$STAGE"
[ -f "$STAGE/SHA256SUMS" ] || die "bundle has no SHA256SUMS"
( cd "$STAGE" && sha256sum -c SHA256SUMS --quiet ) || die "extracted files do not match SHA256SUMS"
echo "Bundle checksums verified."
[ -d "$STAGE/snapshot" ] && [ -d "$STAGE/assets" ] || die "bundle lacks snapshot/ or assets/"

# ---- snapshot report: must PROVE completeness and the right GHL location (no fallbacks) ----
php -r '
$d=$argv[1]; $loc=$argv[2];
$r=json_decode(@file_get_contents("$d/_report.json"),true); if(!is_array($r)){fwrite(STDERR,"report unreadable\n");exit(1);}
foreach(["location","customFields","tags","pipelines","contacts","opportunities","conversations","messages"] as $n){
  $e=$r[$n]??null;
  if(!is_array($e)||($e["status"]??0)!==200||!is_int($e["count"]??null)||!array_key_exists("failures",$e)||$e["failures"]!==[]){fwrite(STDERR,"dataset $n not proven complete\n");exit(1);}
}
$l=json_decode(@file_get_contents("$d/location.json"),true);
if(($l["id"]??null)!==$loc){fwrite(STDERR,"snapshot is not from location $loc\n");exit(1);}
if($r["contacts"]["count"]<1){fwrite(STDERR,"no contacts\n");exit(1);}
' "$STAGE/snapshot" "$GHL_LOCATION" || die "snapshot report failed verification"
echo "Snapshot report verified for GHL location $GHL_LOCATION."

# ---- backup: dump, then prove it is complete and readable ----
mkdir -p "$HOME/backups"
BACKUP_REF="$HOME/backups/motiongrove-pre-toronto-$(date +%Y%m%d-%H%M%S).sql.gz"
[ ! -e "$BACKUP_REF" ] || die "backup path already exists: $BACKUP_REF"
BACKUP_OWNED=1
# --no-tablespaces: the DB user lacks the PROCESS privilege (tablespace metadata is not needed to restore this dump).
set +e
MYSQL_PWD="$(envval DB_PASSWORD)" mysqldump --no-tablespaces -h"$DB_HOST_V" -P"$DB_PORT_V" -u"$DB_USER_V" --single-transaction --quick --routines --triggers "$(envval DB_DATABASE)" | gzip > "$BACKUP_REF"
DUMP_STATUS=("${PIPESTATUS[@]}")
set -e
{ [ "${DUMP_STATUS[0]}" -eq 0 ] && [ "${DUMP_STATUS[1]}" -eq 0 ]; } || die "mysqldump/gzip failed (exit ${DUMP_STATUS[0]}/${DUMP_STATUS[1]})"
verify_backup() {
  [ -s "$BACKUP_REF" ] || die "backup file is missing or empty"
  gzip -t "$BACKUP_REF" || die "backup is not a valid gzip stream"
  [ "$(stat -c %s "$BACKUP_REF")" -gt 1048576 ] || die "backup is implausibly small"
  [ "$(zcat "$BACKUP_REF" | tail -n 5 | grep -c -- '-- Dump completed' || true)" -ge 1 ] || die "backup is truncated (no completion marker)"
  local t; t="$(zcat "$BACKUP_REF" | grep -c '^CREATE TABLE' || true)"
  [ "$t" -gt 100 ] || die "backup has only $t tables"
  [ "$(zcat "$BACKUP_REF" | grep -c '^CREATE TABLE `users`' || true)" -ge 1 ] || die "backup has no users table"
  echo "Backup validated: $BACKUP_REF ($(du -h "$BACKUP_REF" | cut -f1), $t tables)"
}
verify_backup
BACKUP_VALID=1
BACKUP_SHA="$(sha256sum "$BACKUP_REF" | cut -d' ' -f1)"

COMMON=(--owner-email="$OWNER_EMAIL" --snapshot="$STAGE/snapshot" --assets="$STAGE/assets")
echo "---- READ-ONLY DRY RUN (the command verifies snapshot, location and every website image itself) ----"
php artisan toronto:production-provision "${COMMON[@]}" || die "dry run failed; nothing was written"

if [ "$APPLY" -ne 1 ]; then echo "Dry run only. Re-run with --apply to write."; exit 0; fi

# ---- apply: re-verify the backup is unchanged, then require a human at a terminal ----
[ "$(sha256sum "$BACKUP_REF" | cut -d' ' -f1)" = "$BACKUP_SHA" ] || die "backup changed after validation"
verify_backup
[ -t 0 ] || die "--apply needs an interactive terminal (use ssh -t)"
echo; echo "About to WRITE to production database GetMotionGrove (additive; sends nothing)."
read -r -p 'Type exactly  CREATE TORONTO PHOTO BOOTH IN PRODUCTION  to continue: ' PHRASE
[ "$PHRASE" = "CREATE TORONTO PHOTO BOOTH IN PRODUCTION" ] || die "confirmation phrase did not match"
TORONTO_PRODUCTION_PROVISIONING=true php artisan toronto:production-provision "${COMMON[@]}" \
  --confirm-database="$(envval DB_DATABASE)" --backup-reference="$BACKUP_REF" --confirm-phrase="$PHRASE" --apply
echo "Done. Backup kept at $BACKUP_REF. The staged export in $STAGE contains customer data: remove it when verified (rm -rf $STAGE)."
