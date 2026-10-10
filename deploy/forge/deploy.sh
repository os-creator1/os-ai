#!/usr/bin/env bash
# Laravel Forge ZERO-DOWNTIME deployment script for MotionGrove staging (staging.getmotiongrove.com).
#
# Paste into Forge -> Site -> Deployments -> Deployment Script (replace the pre-filled one, keeping
# its macro lines exactly as Forge wrote them: $CREATE_RELEASE(), $ACTIVATE_RELEASE(),
# $RESTART_QUEUES()). Those three are Forge macros, not bash, so this file is not runnable by hand.
#
# Forge's lifecycle for a zero-downtime site (this script follows it in order):
#   $CREATE_RELEASE()   clones branch $FORGE_SITE_BRANCH into a NEW directory under releases/ and links
#                       the shared .env and storage/ into it. The live site is untouched. There is no
#                       `git pull`: every release is a fresh checkout, so nothing mutable is assumed.
#   ... build steps ... run INSIDE the new release (cd $FORGE_RELEASE_DIRECTORY). Nothing here touches
#                       the live `current` release, except the database (see "Migrations").
#   $ACTIVATE_RELEASE() atomically repoints `current` at the new release and reloads PHP-FPM.
#   $RESTART_QUEUES()   restarts the Forge queue daemons so they load the new release.
#
# Facts this script relies on (confirm each once in the FIRST deployment log, section 2 of
# docs/product/FORGE-STAGING.md): $FORGE_SITE_PATH is the site root that contains current/,
# releases/, the shared storage/ and the shared .env; $FORGE_RELEASE_DIRECTORY is the new release.
#
# Customer uploads: the app writes uploads under public/ (images/websites, images/business,
# images/branding/**, images/logo, mms, voice, senderid_docs). public/ belongs to ONE release, so a
# deploy or a rollback would silently drop them. Those directories are therefore symlinked into the
# SHARED storage/ directory below, which Forge keeps across releases. They survive every deploy and
# every rollback. Do not remove that block.
#
# Migrations: they run BEFORE activation, against the live database, while the previous release is
# still serving. They must therefore be additive and backward compatible (add columns/tables; drop or
# rename only in a later release). That is also what makes a Forge rollback safe.
#
# Never add here: platform:install or any seeder (first install is a separate one-time step, and
# PaymentMethodsSeeder truncates payment_methods), migrate:fresh/refresh, db:wipe, key:generate
# (rotating APP_KEY makes stored webhook payloads unreadable), git clean, or an npm/mix build
# (compiled assets are committed).

$CREATE_RELEASE()

set -euo pipefail
cd "$FORGE_RELEASE_DIRECTORY"

# --- Guards (run in the new release, before anything is built or activated) -----------------------
ENV_FILE=.env
[ -e "$ENV_FILE" ] || ENV_FILE="$FORGE_SITE_PATH/.env"
if [ ! -e "$ENV_FILE" ]; then
    echo "Refusing to deploy: no .env (set it in Forge -> Environment first)." >&2; exit 1
fi

if [ "${FORGE_SITE_BRANCH:-}" != "staging" ]; then
    echo "Refusing to deploy: this site must track the 'staging' branch, not '${FORGE_SITE_BRANCH:-?}'." >&2; exit 1
fi

# The app's test suite wipes whatever database it is pointed at.
if grep -Eq '^DB_DATABASE=.*_testing' "$ENV_FILE"; then
    echo "Refusing to deploy: DB_DATABASE looks like a test database." >&2; exit 1
fi

if grep -Eq '^APP_DEBUG=["'\'']?true' "$ENV_FILE"; then
    echo "Refusing to deploy: APP_DEBUG=true on a server." >&2; exit 1
fi

if ! grep -Eq '^APP_KEY=.+' "$ENV_FILE"; then
    echo "Refusing to deploy: APP_KEY is empty (generate one once with 'php artisan key:generate --show' and set it in Forge -> Environment)." >&2; exit 1
fi

# Staging takes Stripe TEST payments only.
if grep -Eq '^STRIPE_MODE=["'\'']?live' "$ENV_FILE" || grep -Eq '^STRIPE_(KEY|SECRET)=["'\'']?(sk|pk|rk)_live_' "$ENV_FILE"; then
    echo "Refusing to deploy: staging must stay in Stripe test mode (STRIPE_MODE=test, no live keys)." >&2; exit 1
fi

# /robots.txt is answered by Laravel per host (customer custom domains carry their own Sitemap line).
if [ -e public/robots.txt ]; then
    echo "Refusing to deploy: public/robots.txt exists (see docs/product/DEPLOYMENT-READINESS.md 6.4)." >&2; exit 1
fi

# --- PHP dependencies (production autoloader, no dev packages) ------------------------------------
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# --- Shared state: storage skeleton and persistent customer uploads ---------------------------------
mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/tmp

SHARED_UPLOADS="$FORGE_SITE_PATH/storage/app/shared-public"

share_dir() {
    # $1 = path under public/. The real directory lives in shared storage; this release gets a symlink.
    local rel="$1" shared="$SHARED_UPLOADS/$1" target="public/$1"
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

# --- Deployment-safe caches (built in this release, which is not live yet) ---------------------------
# Settings saved from the Platform Owner screens rewrite .env and clear the config cache, so these are
# rebuilt on every deploy. Cache builds never touch uploads, sessions or the database.
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan event:cache

# --- Database: additive migrations only -----------------------------------------------------------------
$FORGE_PHP artisan migrate --force

$ACTIVATE_RELEASE()

$RESTART_QUEUES()

echo "Deployed release $(basename "$FORGE_RELEASE_DIRECTORY") ($(git rev-parse --short HEAD 2>/dev/null || echo 'no git metadata'))"
