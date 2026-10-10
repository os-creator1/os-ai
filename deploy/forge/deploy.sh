#!/usr/bin/env bash
# Laravel Forge DEPLOY SCRIPT for MotionGrove staging (staging.getmotiongrove.com).
#
# Paste this into Forge -> Site -> Deployments -> "Deployment Script". Forge supplies
# FORGE_SITE_PATH, FORGE_SITE_BRANCH, FORGE_PHP, FORGE_COMPOSER and FORGE_PHP_FPM.
# It is the standard single-directory Forge deploy (git pull in place, NOT zero-downtime),
# which is deliberate: customer uploads live untracked under public/images/** and
# storage/app, and an in-place `git pull` never touches untracked files.
#
# What this script must NEVER do (and why):
#   - run `platform:install`, `migrate:fresh`, `migrate:refresh`, `db:wipe` or any seeder
#     (first install is a one-time manual step; see docs/product/FORGE-STAGING.md);
#   - run `git clean`, `git reset --hard` or `rsync --delete` (they would delete uploads);
#   - run `key:generate` (rotating APP_KEY makes stored webhook payloads unreadable);
#   - run npm/mix (compiled assets are committed; see DEPLOYMENT-READINESS.md section 8 step 3).

set -euo pipefail

cd "$FORGE_SITE_PATH"

# --- Guards -------------------------------------------------------------------------------
if [ "${FORGE_SITE_BRANCH:-}" != "staging" ]; then
    echo "Refusing to deploy: this site must track the 'staging' branch, not '${FORGE_SITE_BRANCH:-?}'." >&2
    exit 1
fi

if [ ! -f .env ]; then
    echo "Refusing to deploy: .env is missing (set it in Forge -> Environment first)." >&2
    exit 1
fi

# The app's test suite wipes whatever database it is pointed at; never deploy onto one.
if grep -Eq '^DB_DATABASE=.*(ultimatesms_testing|_testing)' .env; then
    echo "Refusing to deploy: DB_DATABASE looks like a test database." >&2
    exit 1
fi

if grep -Eq '^APP_DEBUG=true' .env; then
    echo "Refusing to deploy: APP_DEBUG=true on a server." >&2
    exit 1
fi

# --- Code ---------------------------------------------------------------------------------
git pull origin "$FORGE_SITE_BRANCH"

# /robots.txt is answered by Laravel per host (customer Website custom domains carry their own
# Sitemap line). A static file would silently replace it for every site.
if [ -e public/robots.txt ]; then
    echo "Refusing to deploy: public/robots.txt exists (see DEPLOYMENT-READINESS.md section 6.4)." >&2
    exit 1
fi

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# --- PHP-FPM reload (Forge's own lock pattern) ----------------------------------------------
( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service "$FORGE_PHP_FPM" reload ) 9>/tmp/fpmlock

# --- Database: additive migrations only -----------------------------------------------------
$FORGE_PHP artisan migrate --force

# --- Storage link (idempotent) ----------------------------------------------------------------
[ -L public/storage ] || $FORGE_PHP artisan storage:link

# --- Deployment-safe caches -------------------------------------------------------------------
# Settings saved from the Platform Owner screens rewrite .env and clear the config cache, so
# re-cache here on every deploy. Cache clears never touch uploads, sessions or the database.
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan event:cache

# --- Queue: the persistent daemon (if enabled) must pick up new code ---------------------------
$FORGE_PHP artisan queue:restart

echo "Deployed $(git rev-parse --short HEAD) on $(date -u +%FT%TZ)"
