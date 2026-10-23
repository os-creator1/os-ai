#!/usr/bin/env bash
# One-time copy of the WORKING platform branding from the staging site to the production site.
# Run by a human over SSH as the Forge site user (both sites live on the same server).
#
#   bash copy-branding-from-staging.sh --staging-root=/home/forge/staging.getmotiongrove.com            # DRY RUN
#   bash copy-branding-from-staging.sh --staging-root=/home/forge/staging.getmotiongrove.com --apply     # writes
#
# What it does:
#   1. Reads exactly six keys from the STAGING .env (never anything else, nothing is printed except those six paths):
#        APP_LOGO APP_COMPACT_LOGO APP_DARK_LOGO APP_FAVICON APP_AUTH_ILLUSTRATION APP_INSTALLER_ILLUSTRATION
#   2. Copies each referenced image file from staging's public/ into PRODUCTION's SHARED storage
#      (<prod>/storage/app/shared-public/<path>), which the release symlinks into public/, so the files survive
#      every Forge deploy and rollback. Existing identical files are left alone; a different file with the same
#      name is refused (names are content hashes, so that would indicate corruption).
#   3. Sets exactly those six keys in the production .env (exact-key match, atomic replace, mode preserved; duplicates
#      of a key collapse to one; a key that staging leaves empty is set empty). Nothing else in the file is touched:
#      no credentials, database settings or APP_KEY.
#   4. Rebuilds the production config cache and invalidates the branding cache.
#   5. Verifies every non-empty path exists on production disk and is served over HTTPS as an image.
#
# Staging is only READ. No application code is deployed. No database is touched.
#
# Options:
#   --staging-root=DIR   required. The staging site directory (contains .env and current/ or public/).
#   --prod-root=DIR      default /home/forge/getmotiongrove.com
#   --base-url=URL       default https://getmotiongrove.com
#   --php=PATH           default php
#   --apply              actually write; without it only report
#   --skip-http          skip the HTTP checks (offline test only)

set -euo pipefail

STAGING_ROOT=""
PROD_ROOT="/home/forge/getmotiongrove.com"
BASE_URL="https://getmotiongrove.com"
PHP_BIN="php"
APPLY=0
SKIP_HTTP=0

for arg in "$@"; do
    case "$arg" in
        --staging-root=*) STAGING_ROOT="${arg#*=}" ;;
        --prod-root=*)    PROD_ROOT="${arg#*=}" ;;
        --base-url=*)     BASE_URL="${arg#*=}" ;;
        --php=*)          PHP_BIN="${arg#*=}" ;;
        --apply)          APPLY=1 ;;
        --skip-http)      SKIP_HTTP=1 ;;
        *) echo "Unknown argument: $arg" >&2; exit 2 ;;
    esac
done

fail() { echo "STOP: $*" >&2; exit 1; }
[ -n "$STAGING_ROOT" ] || fail "--staging-root=<staging site directory> is required."
STAGING_ROOT="${STAGING_ROOT%/}"; PROD_ROOT="${PROD_ROOT%/}"; BASE_URL="${BASE_URL%/}"
[ -d "$STAGING_ROOT" ] || fail "staging directory not found: $STAGING_ROOT"
[ -d "$PROD_ROOT" ] || fail "production directory not found: $PROD_ROOT"
[ "$(cd "$STAGING_ROOT" && pwd -P)" != "$(cd "$PROD_ROOT" && pwd -P)" ] || fail "staging and production are the same directory."

# --- locate the two .env files and the two public directories --------------------------------------------------
STG_ENV=""; for c in "$STAGING_ROOT/.env" "$STAGING_ROOT/current/.env"; do [ -f "$c" ] && { STG_ENV="$c"; break; }; done
[ -n "$STG_ENV" ] || fail "no .env found under $STAGING_ROOT"
PROD_ENV="$PROD_ROOT/.env"
[ -f "$PROD_ENV" ] || fail "no production .env at $PROD_ENV"
[ -w "$PROD_ENV" ] || fail "the production .env is not writable by $(id -un)."

STG_PUBLIC=""; for c in "$STAGING_ROOT/current/public" "$STAGING_ROOT/public"; do [ -d "$c" ] && { STG_PUBLIC="$c"; break; }; done
[ -n "$STG_PUBLIC" ] || fail "no public/ directory found under $STAGING_ROOT"
PROD_APP="$PROD_ROOT/current"
[ -d "$PROD_APP/public" ] || fail "no deployed release at $PROD_APP (deploy production first)."
SHARED="$PROD_ROOT/storage/app/shared-public"

KEYS=(APP_LOGO APP_COMPACT_LOGO APP_DARK_LOGO APP_FAVICON APP_AUTH_ILLUSTRATION APP_INSTALLER_ILLUSTRATION)

# Value of the LAST exact-key line, quotes stripped. Never sources the file; prints only the requested key.
env_get() { # file key
    local line
    line="$(grep -E "^$2=" "$1" | tail -n 1 || true)"
    line="${line#*=}"
    line="${line%$'\r'}"
    if [[ "$line" == \"*\" ]]; then line="${line:1:${#line}-2}"; elif [[ "$line" == \'*\' ]]; then line="${line:1:${#line}-2}"; fi
    printf '%s' "$line"
}

valid_path() { # empty, a bundled default, or a branding asset path; nothing else (no traversal, no URLs)
    [[ -z "$1" || "$1" =~ ^images/branding/[a-z_]+/[A-Za-z0-9._-]+$ || "$1" =~ ^images/branding/[A-Za-z0-9._-]+$ ]]
}

echo "mode               : $([ "$APPLY" = 1 ] && echo APPLY || echo 'DRY RUN (nothing is written)')"
echo "staging .env       : $STG_ENV (read only)"
echo "production .env    : $PROD_ENV"
echo "shared image store : $SHARED"
echo

declare -A STG_VAL PROD_VAL
problems=0
printf '%-30s %-62s %s\n' KEY "STAGING VALUE" "PRODUCTION NOW"
for k in "${KEYS[@]}"; do
    STG_VAL[$k]="$(env_get "$STG_ENV" "$k")"
    PROD_VAL[$k]="$(env_get "$PROD_ENV" "$k")"
    valid_path "${STG_VAL[$k]}" || fail "staging $k holds an unexpected value; refusing (expected an images/branding/... path or empty)."
    printf '%-30s %-62s %s\n' "$k" "${STG_VAL[$k]:-<empty>}" "${PROD_VAL[$k]:-<empty>}"
done
echo

# --- plan the file copies ------------------------------------------------------------------------------------------
echo "image files:"
declare -a COPIES=()
for k in "${KEYS[@]}"; do
    p="${STG_VAL[$k]}"
    [ -n "$p" ] || continue
    src="$STG_PUBLIC/$p"
    if [[ "$p" =~ ^images/branding/[A-Za-z0-9._-]+$ ]]; then
        # A bundled default shipped inside the application itself; nothing to copy, it must exist in production's release.
        if [ -f "$PROD_APP/public/$p" ]; then echo "  [bundled]  $p (ships with the release)"; else echo "  [MISSING]  $p is a bundled default but is not in the production release"; problems=$((problems+1)); fi
        continue
    fi
    if [ ! -f "$src" ]; then echo "  [MISSING]  $p not found on staging ($src)"; problems=$((problems+1)); continue; fi
    dir="$(dirname "$p")"
    if [ ! -L "$PROD_APP/public/$dir" ]; then echo "  [BLOCKED]  $PROD_APP/public/$dir is not the shared-storage link; run a production deploy first or the file would be lost on the next release"; problems=$((problems+1)); continue; fi
    dst="$SHARED/$p"
    if [ -f "$dst" ]; then
        if cmp -s "$src" "$dst"; then echo "  [present]  $p (identical, left alone)"; else echo "  [CONFLICT] $p exists on production with different content; refusing to overwrite"; problems=$((problems+1)); fi
    else
        echo "  [copy]     $p  ($(stat -c %s "$src") bytes)"
        COPIES+=("$p")
    fi
done
echo

if [ "$problems" -gt 0 ]; then fail "$problems problem(s) above. Nothing was changed."; fi

if [ "$APPLY" != 1 ]; then
    echo "Dry run complete: ${#COPIES[@]} file(s) would be copied and the six keys above set. Re-run with --apply to write."
    exit 0
fi

# --- 1. copy the image files into shared storage ------------------------------------------------------------------------
for p in "${COPIES[@]}"; do
    install -D -m 0644 "$STG_PUBLIC/$p" "$SHARED/$p"
    cmp -s "$STG_PUBLIC/$p" "$SHARED/$p" || fail "copy of $p failed verification; the production .env was NOT changed."
done
echo "copied ${#COPIES[@]} file(s) into shared storage."

# --- 2. set exactly the six keys in the production .env (atomic, mode preserved) ----------------------------------------
tmp="$(mktemp "$PROD_ENV.XXXXXX")"
trap 'rm -f "$tmp"' EXIT
cp -p "$PROD_ENV" "$tmp"
for k in "${KEYS[@]}"; do
    v="${STG_VAL[$k]}"
    awk -v key="$k" -v val="$v" '
        BEGIN { done = 0 }
        index($0, key "=") == 1 { if (!done) { print key "=\"" val "\""; done = 1 } ; next }
        { print }
        END { if (!done) print key "=\"" val "\"" }
    ' "$tmp" > "$tmp.new"
    cat "$tmp.new" > "$tmp"; rm -f "$tmp.new"
done
mv -f "$tmp" "$(readlink -f "$PROD_ENV")"
trap - EXIT
echo "production .env: six branding keys set (nothing else changed)."

# --- 3. rebuild config cache, invalidate the branding cache ---------------------------------------------------------------
( cd "$PROD_APP" && "$PHP_BIN" artisan config:cache && "$PHP_BIN" artisan cache:forget platform_branding:resolved ) || fail "artisan failed; the .env and files ARE in place, re-run config:cache manually."

# --- 4. verify --------------------------------------------------------------------------------------------------------------
echo
echo "verification:"
bad=0
for k in "${KEYS[@]}"; do
    p="${STG_VAL[$k]}"
    [ -n "$p" ] || { printf '  %-30s empty (same as staging)\n' "$k"; continue; }
    now="$(env_get "$PROD_ENV" "$k")"
    [ "$now" = "$p" ] || { echo "  [FAIL] $k in production .env is '$now'"; bad=$((bad+1)); continue; }
    if [ ! -f "$PROD_APP/public/$p" ]; then echo "  [FAIL] $k: $p is not served from the production release (public/)"; bad=$((bad+1)); continue; fi
    if [ "$SKIP_HTTP" = 1 ]; then echo "  [ok]   $k -> $p (on disk; HTTP skipped)"; continue; fi
    head="$(curl -sS -m 20 -o /dev/null -D - "$BASE_URL/$p" 2>/dev/null || true)"
    code="$(printf '%s' "$head" | head -n 1 | awk '{print $2}')"
    ctype="$(printf '%s' "$head" | grep -i '^content-type:' | head -n 1 | tr -d '\r')"
    if [ "$code" = "200" ] && [[ "${ctype,,}" == *image/* ]]; then echo "  [ok]   $k -> $BASE_URL/$p  (200, ${ctype#*: })"; else echo "  [FAIL] $k -> $BASE_URL/$p  (HTTP ${code:-none}; ${ctype:-no content-type})"; bad=$((bad+1)); fi
done
echo
if [ "$bad" -gt 0 ]; then echo "$bad check(s) failed."; exit 1; fi
echo "All checks passed. Open $BASE_URL/login and the Platform Owner dashboard in a private window to see the logo, favicon and illustrations."
