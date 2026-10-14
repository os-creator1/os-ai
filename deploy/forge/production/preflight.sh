#!/usr/bin/env bash
# Production safety guards for getmotiongrove.com. READ-ONLY: it parses a .env file and a few
# values and prints problems. It never connects to a database, never runs artisan, writes nothing.
#
#   bash deploy/forge/production/preflight.sh <env-file> <site-root> <branch>
#
# Called by release-build.sh before anything is built or activated, and runnable by hand on the
# server (or locally against a copy of the file) to check an environment BEFORE deploying.
# Exit 0 only when every guard passes. Any failure is fatal: production fails closed.
#
# The staging scripts (deploy/forge/*.sh) are deliberately separate and unchanged: staging stays
# test-only (it refuses STRIPE_MODE=live); production stays live-capable but refuses every
# inconsistent Stripe combination and anything that looks like staging, a test database or a
# development setting.

ENV_FILE="${1:-}"
SITE_ROOT="${2:-}"
BRANCH="${3:-}"

PROD_HOST="getmotiongrove.com"
PROD_URL="https://${PROD_HOST}"
PROD_SITE_ROOT="/home/forge/${PROD_HOST}"

errors=0
bad() { echo "REFUSED: $*" >&2; errors=$((errors + 1)); }

[ -n "$ENV_FILE" ] && [ -f "$ENV_FILE" ] || { echo "REFUSED: env file '$ENV_FILE' not found." >&2; exit 1; }

# Parse the file ONCE with bash builtins (no subprocess per lookup: forks are very slow on Windows/Git Bash and
# this keeps the validation harness fast). Last assignment wins, as in dotenv. Strips CR, surrounding whitespace,
# one pair of quotes, and an inline " # comment" on unquoted values. Unset keys read as empty.
declare -A ENVV
while IFS= read -r raw || [ -n "$raw" ]; do
    raw="${raw%$'\r'}"
    [[ "$raw" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=(.*)$ ]] || continue
    k="${BASH_REMATCH[1]}"; v="${BASH_REMATCH[2]}"
    v="${v#"${v%%[![:space:]]*}"}"; v="${v%"${v##*[![:space:]]}"}"
    if [[ "$v" =~ ^\"(.*)\"$ ]]; then v="${BASH_REMATCH[1]}"
    elif [[ "$v" =~ ^\'(.*)\'$ ]]; then v="${BASH_REMATCH[1]}"
    else v="${v%%[[:space:]]#*}"; v="${v%"${v##*[![:space:]]}"}"; fi
    ENVV["$k"]="$v"
done < "$ENV_FILE"
env_get() { printf '%s' "${ENVV[$1]-}"; }
lower() { local s="${1,,}"; printf '%s' "$s"; }

# --- Where this is running --------------------------------------------------------------------------------
[ "$BRANCH" = "main" ] || bad "this site must deploy the 'main' branch, not '${BRANCH:-<empty>}'."
[ "$SITE_ROOT" = "$PROD_SITE_ROOT" ] || bad "site root must be exactly ${PROD_SITE_ROOT} (got '${SITE_ROOT:-<empty>}'); production must not share a directory with staging or anything else."

# --- Application ------------------------------------------------------------------------------------------
[ "$(env_get APP_ENV)" = "production" ] || bad "APP_ENV must be exactly 'production'."
[ "$(lower "$(env_get APP_DEBUG)")" = "false" ] || bad "APP_DEBUG must be explicitly 'false' (unset or any other value is refused)."
[ "$(env_get APP_URL)" = "$PROD_URL" ] || bad "APP_URL must be exactly ${PROD_URL} (no trailing slash, no path); got '$(env_get APP_URL)'."

app_key="$(env_get APP_KEY)"
[ -n "$app_key" ] || bad "APP_KEY is empty (generate once with 'php artisan key:generate --show'; never reuse the staging key)."
case "$app_key" in ""|base64:?*) : ;; *) bad "APP_KEY must be a 'base64:...' key from 'php artisan key:generate --show'." ;; esac

# --- Database: distinct, never a test or staging database -----------------------------------------------
[ "$(env_get DB_CONNECTION)" = "mysql" ] || bad "DB_CONNECTION must be 'mysql'."
db="$(env_get DB_DATABASE)"
[ -n "$db" ] || bad "DB_DATABASE is empty."
case "$(lower "$db")" in
    *test*|*staging*|*stage*|*local*|*dev*|*demo*|*sandbox*) bad "DB_DATABASE '$db' looks like a test/staging/dev database." ;;
esac
dbuser="$(env_get DB_USERNAME)"
[ -n "$dbuser" ] || bad "DB_USERNAME is empty."
[ "$dbuser" != "root" ] || bad "DB_USERNAME must be a dedicated least-privilege user, not root."
[ -n "$(env_get DB_PASSWORD)" ] || bad "DB_PASSWORD is empty."
case "$(lower "$(env_get DB_HOST)")" in
    *staging*) bad "DB_HOST points at a staging host." ;;
esac

# --- Runtime ----------------------------------------------------------------------------------------------
[ "$(env_get QUEUE_CONNECTION)" = "database" ] || bad "QUEUE_CONNECTION must be 'database' (never 'sync')."
case "$(lower "$(env_get CACHE_DRIVER)")" in
    ""|array|null) bad "CACHE_DRIVER must be set to a real shared driver (file/database/redis), never array/null." ;;
esac
[ "$(lower "$(env_get SESSION_SECURE_COOKIE)")" = "true" ] || bad "SESSION_SECURE_COOKIE must be 'true'."
case "$(lower "$(env_get MAIL_MAILER)")" in
    ""|log|array) bad "MAIL_MAILER must be a real mailer (smtp etc.); 'log'/'array'/unset silently drops customer email." ;;
esac
[ -n "$(env_get MAIL_FROM_ADDRESS)" ] || bad "MAIL_FROM_ADDRESS is empty."

# Fixture / fake drivers must never be selected on the production host.
for k in GOOGLE_ADS_DRIVER META_ADS_DRIVER EXTERNAL_SITE_AUDIT_DRIVER; do
    [ "$(lower "$(env_get "$k")")" != "fake" ] || bad "$k=fake is a fixture driver; unset it or use http."
done

# Registration must be a DELIBERATE decision: the application default is OPEN (true), so an unset value
# would open public signup on the first deploy. Require the variable to be present and a boolean.
# (false = closed, the required state until the final release is approved; true = open.)
reg="$(lower "$(env_get ACCOUNT_CAN_REGISTER)")"
case "$reg" in
    true|false) : ;;
    *) bad "ACCOUNT_CAN_REGISTER must be set explicitly to 'true' or 'false' (unset means OPEN). Use 'false' until launch is approved." ;;
esac

# --- Stripe: mode and keys must agree -----------------------------------------------------------------------
mode="$(lower "$(env_get STRIPE_MODE)")"
key="$(env_get STRIPE_KEY)"
secret="$(env_get STRIPE_SECRET)"
case "$mode" in
    live)
        case "$key" in pk_live_?*) : ;; *) bad "STRIPE_MODE=live requires STRIPE_KEY to be a live publishable key (pk_live_...)." ;; esac
        case "$secret" in sk_live_?*|rk_live_?*) : ;; *) bad "STRIPE_MODE=live requires STRIPE_SECRET to be a live secret/restricted key (sk_live_/rk_live_...)." ;; esac
        for w in STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET STRIPE_WEBHOOK_SECRET; do
            case "$(env_get "$w")" in whsec_?*) : ;; *) bad "STRIPE_MODE=live requires $w (whsec_...) - webhooks cannot be verified without it." ;; esac
        done
        ;;
    test)
        # Allowed on production for a dry run, but never with live keys; and never with an empty key pair half set.
        case "$key" in ""|pk_test_?*) : ;; *) bad "STRIPE_MODE=test but STRIPE_KEY is not a test key (pk_test_...)." ;; esac
        case "$secret" in ""|sk_test_?*|rk_test_?*) : ;; *) bad "STRIPE_MODE=test but STRIPE_SECRET is not a test key (sk_test_/rk_test_...)." ;; esac
        ;;
    *) bad "STRIPE_MODE must be explicitly 'test' or 'live' (unset defaults to test and hides a misconfiguration)." ;;
esac
# Any Stripe key anywhere that disagrees with the mode is refused, whichever STRIPE_* variable holds it.
for k in "${!ENVV[@]}"; do
    [[ "$k" == STRIPE_* ]] || continue
    v="${ENVV[$k]}"
    if [ "$mode" = "live" ] && [[ "$v" =~ ^(sk|pk|rk)_test_ ]]; then bad "a test-mode Stripe key is present in $k while STRIPE_MODE=live."; fi
    if [ "$mode" = "test" ] && [[ "$v" =~ ^(sk|pk|rk)_live_ ]]; then bad "a live Stripe key is present in $k while STRIPE_MODE=test."; fi
done
# Any value shaped like a live key must not be committed-looking test data either; nothing else to check here.

# --- Paid / metered features default OFF until deliberately enabled -----------------------------------------
# (Informational only: these are not refusals; the deploy log prints what is on.)
echo "preflight: STRIPE_MODE=${mode:-<unset>} ACCOUNT_CAN_REGISTER=${reg:-<unset>} OPENAI_ACTIVE=$(env_get OPENAI_ACTIVE) SEO_RANK_TRACKING_ENABLED=$(env_get SEO_RANK_TRACKING_ENABLED) BUSINESS_ONBOARDING_ENABLED=$(env_get BUSINESS_ONBOARDING_ENABLED)"

if [ "$errors" -gt 0 ]; then
    echo "preflight: $errors problem(s); nothing was built or activated." >&2
    exit 1
fi
echo "preflight: all production guards passed."
