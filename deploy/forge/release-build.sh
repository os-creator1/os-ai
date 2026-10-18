#!/usr/bin/env bash
# Build steps for ONE new Forge zero-downtime release of MotionGrove staging.
#
# Run from the Forge deployment script (deploy/forge/deploy.sh) INSIDE the new release directory,
# after Forge has cloned the release and BEFORE it activates it. Everything here therefore happens
# in the not-yet-live release; the live release keeps serving until activation. The only thing
# that touches shared, live state is the database migration (see below).
#
# This logic lives in a normal file on purpose: Forge's deployment editor is pre-processed for its
# release macros, and keeping this out of that editor means nothing in here can be rewritten.
#
# Forge variables used (documented by Forge):
#   FORGE_SITE_ROOT          /home/forge/<site>           holds current/, releases/, the shared storage/ and .env
#   FORGE_RELEASE_DIRECTORY  /home/forge/<site>/releases/<id>   the new release
#   FORGE_SITE_BRANCH, FORGE_PHP, FORGE_COMPOSER
# (FORGE_SITE_PATH is <site root>/current, NOT the site root. It is never used here, because on a
#  first deploy `current` does not exist yet.)
#
# Uploads: the app writes customer uploads under public/ (images/websites incl. responsive
# derivatives, images/business, images/branding/**, images/logo, mms, voice, senderid_docs). public/
# belongs to one release, so each upload directory is symlinked into the SHARED storage/ directory,
# which survives every deploy and rollback. Do not remove that block.
#
# Migrations run before activation, against the live database, while the previous release is still
# serving: they must be additive and backward compatible (drop or rename only in a later release).
#
# Never add here: platform:install or any seeder (first install is a separate one-time step and
# PaymentMethodsSeeder truncates payment_methods), migrate:fresh/refresh, db:wipe, key:generate,
# git clean, or an npm/mix build (compiled assets are committed).

set -euo pipefail

: "${FORGE_SITE_ROOT:?FORGE_SITE_ROOT is not set}"
: "${FORGE_RELEASE_DIRECTORY:?FORGE_RELEASE_DIRECTORY is not set}"
: "${FORGE_SITE_BRANCH:?FORGE_SITE_BRANCH is not set}"
: "${FORGE_PHP:?FORGE_PHP is not set}"
: "${FORGE_COMPOSER:?FORGE_COMPOSER is not set}"

cd "$FORGE_RELEASE_DIRECTORY"

fail() { echo "Refusing to deploy: $*" >&2; exit 1; }

# --- Shared .env and storage (Forge normally links both; make it true regardless) -------------------
SHARED_STORAGE="$FORGE_SITE_ROOT/storage"

if [ ! -e .env ] && [ -e "$FORGE_SITE_ROOT/.env" ]; then
    ln -sfn "$FORGE_SITE_ROOT/.env" .env
fi

if [ ! -L storage ]; then
    mkdir -p "$SHARED_STORAGE"
    if [ -d storage ]; then cp -an storage/. "$SHARED_STORAGE"/; rm -rf storage; fi
    ln -sfn "$SHARED_STORAGE" storage
fi

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/tmp

# --- Guards (before anything is built or activated) --------------------------------------------------
[ -e .env ] || fail "no .env (set it in Forge -> Environment first)."
[ "$FORGE_SITE_BRANCH" = "staging" ] || fail "this site must track the 'staging' branch, not '$FORGE_SITE_BRANCH'."

# The app's test suite wipes whatever database it is pointed at.
if grep -Eq '^DB_DATABASE=.*_testing' .env; then fail "DB_DATABASE looks like a test database."; fi
if grep -Eq '^APP_DEBUG=["'\'']?true' .env; then fail "APP_DEBUG=true on a server."; fi
if ! grep -Eq '^APP_KEY=.+' .env; then fail "APP_KEY is empty (generate one once with 'php artisan key:generate --show' and set it in Forge -> Environment)."; fi

# Staging takes Stripe TEST payments only.
if grep -Eq '^STRIPE_MODE=["'\'']?live' .env || grep -Eq '^STRIPE_(KEY|SECRET)=["'\'']?(sk|pk|rk)_live_' .env; then
    fail "staging must stay in Stripe test mode (STRIPE_MODE=test, no live keys)."
fi

# /robots.txt is answered by Laravel per host (customer custom domains carry their own Sitemap line).
if [ -e public/robots.txt ]; then fail "public/robots.txt exists (see docs/product/DEPLOYMENT-READINESS.md 6.4)."; fi

# --- PHP dependencies (production autoloader, no dev packages) ----------------------------------------
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# --- Persistent customer uploads ------------------------------------------------------------------------
SHARED_UPLOADS="$SHARED_STORAGE/app/shared-public"

share_dir() {
    # $1 = path under public/. The real directory lives in shared storage; this release gets a symlink.
    local shared="$SHARED_UPLOADS/$1" target="public/$1"
    mkdir -p "$shared" "$(dirname "$target")"
    if [ -d "$target" ] && [ ! -L "$target" ]; then
        # Files this release ships (e.g. demo logos) are copied in ONCE and never overwrite what is already there.
        cp -an "$target"/. "$shared"/
        rm -rf "$target"
    fi
    ln -sfn "$shared" "$target"
}

for dir in images/websites images/business images/logo \
           images/branding/logo images/branding/logo_compact images/branding/logo_dark \
           images/branding/favicon images/branding/auth_illustration images/branding/installer_illustration \
           images/branding/agency mms voice senderid_docs; do
    share_dir "$dir"
done

# A RELATIVE link, so it keeps resolving after this release is replaced or cleaned up.
[ -L public/storage ] || $FORGE_PHP artisan storage:link --relative

# --- Deployment-safe caches (built in this release, which is not live yet) ------------------------------
# Settings saved from the Platform Owner screens rewrite .env and clear the config cache, so these are
# rebuilt on every deploy. Cache builds never touch uploads, sessions or the database.
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan event:cache

# --- Database: additive migrations only -------------------------------------------------------------------
$FORGE_PHP artisan migrate --force

echo "Release $(basename "$FORGE_RELEASE_DIRECTORY") built; ready to activate."
