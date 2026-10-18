#!/usr/bin/env bash
# One-time, guarded first installation of the PRODUCTION platform (getmotiongrove.com).
#
# NEVER run by a deploy. Run by a human, once, over SSH as the site user, AFTER the first successful
# deploy (which has already migrated the empty database to the current schema):
#
#   cd /home/forge/getmotiongrove.com/current
#   bash deploy/forge/production/install-production.sh --check-only                  # read-only verification
#   bash deploy/forge/production/install-production.sh --owner-email=you@example.com # the install
#
# Why a wrapper: `platform:install` ends in `db:seed`, whose PaymentMethodsSeeder TRUNCATES
# `payment_methods`, and its AppConfigSeeder truncates `app_config`. On an empty database that loses
# nothing; on a live one it destroys settings. So the wrapper refuses unless the database is verifiably
# pristine, refuses to run twice (marker file), and needs an interactive terminal because the Platform
# Owner password is only ever typed at a concealed prompt (never an argument, never in this script).
#
# --check-only runs every guard and the read-only database inspection, then stops. It writes nothing.
# Exit codes: 0 ok / installed, 1 refused or failed, 2 usage.

set -euo pipefail

PROD_SITE_ROOT="/home/forge/getmotiongrove.com"
PHP_BIN="${FORGE_PHP:-php8.3}"
CHECK_ONLY=0
OWNER_EMAIL=""

for arg in "$@"; do
    case "$arg" in
        --check-only) CHECK_ONLY=1 ;;
        --owner-email=*) OWNER_EMAIL="${arg#--owner-email=}" ;;
        *) echo "usage: $0 [--check-only] --owner-email=<email>" >&2; exit 2 ;;
    esac
done

fail() { echo "REFUSED: $*" >&2; exit 1; }

# Resolve where we are: this script must be the one inside the live production release.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd -P)"
SITE_ROOT="${MG_SITE_ROOT_OVERRIDE:-$PROD_SITE_ROOT}"
case "$HERE" in
    "$SITE_ROOT"/current|"$SITE_ROOT"/releases/*) : ;;
    *) fail "run this from $SITE_ROOT/current (found it in '$HERE')." ;;
esac
cd "$HERE"

ENV_FILE="$SITE_ROOT/.env"
MARKER="$SITE_ROOT/storage/app/.platform-installed"

[ -f "$ENV_FILE" ] || fail "no $ENV_FILE."
[ ! -e "$MARKER" ] || fail "platform was already installed ($MARKER exists). Never re-run the installer on a live instance; upgrades are deploys only."
[ "$CHECK_ONLY" -eq 1 ] || [ -n "$OWNER_EMAIL" ] || fail "--owner-email=<email> is required."
case "$OWNER_EMAIL" in ""|*@*.*) : ;; *) fail "--owner-email does not look like an email address." ;; esac

# 1. The same environment guards a deploy applies (production URL, DB name, Stripe mode/key agreement, ...).
bash deploy/forge/production/preflight.sh "$ENV_FILE" "$SITE_ROOT" "main" || fail "environment guards failed (above)."

# 2. The target must be the production database, read straight from .env (not from a shell variable).
DB_NAME="$(tr -d '\r' < "$ENV_FILE" | grep -E '^DB_DATABASE=' | tail -n 1 | cut -d= -f2- | tr -d "\"'")"
echo "Target database: $DB_NAME (host/credentials from $ENV_FILE)"

# 3. Read-only inspection. The database must be migrated (the first deploy did that) and PRISTINE:
#    no users, no administrator, no payment methods, no businesses. Anything else means this is not a first install.
STATE="$("$PHP_BIN" artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
$n = fn (string $t): int => Schema::hasTable($t) ? (int) DB::table($t)->count() : -1;
echo "STATE users=".$n("users")." payment_methods=".$n("payment_methods")." businesses=".$n("businesses")." migrations=".$n("migrations");
' 2>/dev/null | grep -E '^STATE ' | tail -n 1)" || fail "could not read the database state (is the first deploy done and the .env database reachable?)."

[ -n "$STATE" ] || fail "database inspection returned nothing."
echo "Database state: ${STATE#STATE }"

val() { printf '%s' "$STATE" | tr ' ' '\n' | grep -E "^$1=" | cut -d= -f2; }
[ "$(val migrations)" -gt 0 ] 2>/dev/null || fail "migrations table is missing or empty: deploy the release first (it migrates)."
[ "$(val users)" = "0" ]           || fail "users table is not empty (or missing). This is not a pristine production database."
[ "$(val businesses)" = "0" ]      || fail "businesses table is not empty (or missing)."
[ "$(val payment_methods)" -le 0 ] 2>/dev/null || fail "payment_methods has rows; the installer's seeder would TRUNCATE them."

echo "All guards passed: pristine, migrated production database."
if [ "$CHECK_ONLY" -eq 1 ]; then echo "--check-only: nothing was changed."; exit 0; fi

# 4. Human gate. The installer needs a real terminal for the concealed password prompt, and a typed confirmation.
[ -t 0 ] && [ -t 1 ] || fail "needs an interactive terminal (the owner password is a concealed prompt)."
echo
echo "About to run: platform:install --owner-email=$OWNER_EMAIL  against database '$DB_NAME'."
printf "Type exactly  INSTALL getmotiongrove.com  to continue: "
read -r reply
[ "$reply" = "INSTALL getmotiongrove.com" ] || fail "confirmation did not match; nothing was changed."

"$PHP_BIN" artisan platform:install "--owner-email=$OWNER_EMAIL"

mkdir -p "$(dirname "$MARKER")"
date -u +"installed %Y-%m-%dT%H:%M:%SZ by $(id -un)" > "$MARKER"
echo "Installed. Marker written: $MARKER"
echo "Next: sign in at $(tr -d '\r' < "$ENV_FILE" | grep -E '^APP_URL=' | tail -n 1 | cut -d= -f2-)/login, then run the post-deploy check."
