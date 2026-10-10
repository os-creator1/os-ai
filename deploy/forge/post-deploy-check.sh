#!/usr/bin/env bash
# Read-only HTTP readiness checks for a deployed MotionGrove host. Safe to run any time:
# it sends only GETs and deliberately UNSIGNED webhook POSTs (which must be refused with 400
# and store nothing). It uses no credentials and changes no data.
#
#   bash deploy/forge/post-deploy-check.sh https://staging.getmotiongrove.com
#
# Exit status is the number of failed checks. Passing this is READINESS, not acceptance: the
# end-to-end flows in docs/product/FORGE-STAGING.md section 8 must still be exercised by hand.

BASE="${1:-}"
if [ -z "$BASE" ]; then echo "usage: $0 https://host" >&2; exit 2; fi
BASE="${BASE%/}"
fail=0

code() { curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$@" || echo 000; }
check() { # name expected actual
    if [ "$2" = "$3" ]; then echo "ok    $1 ($3)"; else echo "FAIL  $1 (expected $2, got $3)"; fail=$((fail + 1)); fi
}

check "GET /login (readiness probe)"          200 "$(code "$BASE/login")"
check "GET /register"                          200 "$(code "$BASE/register")"
check "GET / redirects to /login"              302 "$(code "$BASE/")"
check "unknown site uuid is a 404, not a 500" 404 "$(code "$BASE/sites/00000000-0000-4000-8000-000000000000")"

# robots.txt must come from Laravel (allow-all), never a static file or a server rule.
robots="$(curl -sS --max-time 20 "$BASE/robots.txt" || true)"
if printf '%s' "$robots" | grep -q '^User-agent: \*' && printf '%s' "$robots" | grep -q '^Disallow:[[:space:]]*$'; then
    echo "ok    /robots.txt is the allow-all body"
else
    echo "FAIL  /robots.txt is not the app's allow-all body (check the Nginx robots.txt block)"; fail=$((fail + 1))
fi

# Webhook endpoints exist and refuse an unsigned payload (400), never 404/500.
# (usage-billing answers 500 until STRIPE_SECRET is set: its gateway cannot be built without it.)
for ep in platform-subscriptions business-payments agency-subscriptions usage-billing; do
    check "unsigned POST /stripe/webhook/$ep refused" 400 "$(code -X POST -H 'Content-Type: application/json' -d '{}' "$BASE/stripe/webhook/$ep")"
done

# Hardening: dotfiles and the env file are not served.
check "GET /.env is not served" 404 "$(code "$BASE/.env")"

if [ "$fail" -eq 0 ]; then echo "All readiness checks passed."; else echo "$fail check(s) failed."; fi
exit "$fail"
