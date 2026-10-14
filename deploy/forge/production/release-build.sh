#!/usr/bin/env bash
# Build steps for ONE new Forge zero-downtime release of the PRODUCTION site, getmotiongrove.com.
#
# Run from the Forge deployment script (deploy/forge/production/deploy.sh) INSIDE the new release
# directory, after Forge has cloned the release and BEFORE it activates it. The live release keeps
# serving until activation; the only shared, live state touched before then is the database migration.
#
# This is the production sibling of deploy/forge/release-build.sh (staging). The build steps are the same
# on purpose; ONLY the guards differ, and they live in preflight.sh. Staging's script is not modified and
# stays test-only. If you change a build step here, make the same change in the staging script (and vice
# versa) in a deliberate, reviewed commit.
#
# Forge variables used: FORGE_SITE_ROOT, FORGE_RELEASE_DIRECTORY, FORGE_SITE_BRANCH, FORGE_PHP, FORGE_COMPOSER.
# FORGE_SITE_PATH is <site root>/current, which does not exist on a first deploy, so it is never used.
#
# Uploads: customer uploads are written under public/, which belongs to ONE release, so each upload
# directory is symlinked into the SHARED storage/ (which survives every deploy and rollback).
#
# Migrations run before activation against the live database while the previous release is still serving:
# they must be additive and backward compatible (drop or rename only in a later release).
#
# Never add here: platform:install or any seeder (first install is a separate, guarded, one-time step; and
# PaymentMethodsSeeder truncates payment_methods), migrate:fresh/refresh, db:wipe, key:generate, git clean,
# or an npm/mix build (compiled assets are committed).

set -euo pipefail

: "${FORGE_SITE_ROOT:?FORGE_SITE_ROOT is not set}"
: "${FORGE_RELEASE_DIRECTORY:?FORGE_RELEASE_DIRECTORY is not set}"
: "${FORGE_SITE_BRANCH:?FORGE_SITE_BRANCH is not set}"
: "${FORGE_PHP:?FORGE_PHP is not set}"
: "${FORGE_COMPOSER:?FORGE_COMPOSER is not set}"

cd "$FORGE_RELEASE_DIRECTORY"

fail() { echo "Refusing to deploy to production: $*" >&2; exit 1; }

# --- Guards FIRST: nothing below may run (not even linking shared state) if the site is wrong ---------------
# Site root and branch are checked before any filesystem change, so a mis-pointed Forge site cannot create
# directories or links anywhere.
PROD_SITE_ROOT="/home/forge/getmotiongrove.com"
[ "$FORGE_SITE_ROOT" = "$PROD_SITE_ROOT" ] || fail "FORGE_SITE_ROOT is '$FORGE_SITE_ROOT', expected $PROD_SITE_ROOT."
[ "$FORGE_SITE_BRANCH" = "main" ] || fail "this site must track the 'main' branch, not '$FORGE_SITE_BRANCH'."

# A release that ships its own .env would shadow the shared one; the shared file is the only source.
SHARED_ENV="$FORGE_SITE_ROOT/.env"
[ -f "$SHARED_ENV" ] || fail "no $SHARED_ENV (create it in Forge -> Environment first)."
if [ -e .env ] && [ ! -L .env ]; then fail "release contains a real .env file; it must be the shared one."; fi

# --- Shared .env and storage (Forge normally links both; make it true regardless) -------------------------
SHARED_STORAGE="$FORGE_SITE_ROOT/storage"

[ -e .env ] || ln -sfn "$SHARED_ENV" .env

if [ ! -L storage ]; then
    mkdir -p "$SHARED_STORAGE"
    if [ -d storage ]; then cp -an storage/. "$SHARED_STORAGE"/; rm -rf storage; fi
    ln -sfn "$SHARED_STORAGE" storage
fi

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/tmp

# --- Environment guards (read-only; see preflight.sh for the full list) -----------------------------------------
bash deploy/forge/production/preflight.sh "$SHARED_ENV" "$FORGE_SITE_ROOT" "$FORGE_SITE_BRANCH" || fail "environment guards failed (above)."

# /robots.txt is answered by Laravel per host (customer custom domains carry their own Sitemap line).
if [ -e public/robots.txt ]; then fail "public/robots.txt exists (see docs/product/DEPLOYMENT-READINESS.md 6.4)."; fi

# --- PHP dependencies (production autoloader, no dev packages) ---------------------------------------------------
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# --- Persistent customer uploads -------------------------------------------------------------------------------------
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

# --- Deployment-safe caches (built in this release, which is not live yet) ----------------------------------------
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan event:cache

# --- Database: additive migrations only ----------------------------------------------------------------------------------
$FORGE_PHP artisan migrate --force

echo "Release $(basename "$FORGE_RELEASE_DIRECTORY") built; ready to activate."
