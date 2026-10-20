#!/usr/bin/env bash
# Read-only HTTP readiness checks for the PRODUCTION host. Sends only GETs and deliberately UNSIGNED
# webhook POSTs (which must be refused with 400 and store nothing). No credentials, no data changed.
#
#   bash deploy/forge/production/post-deploy-check.sh https://getmotiongrove.com closed   # registration closed (default)
#   bash deploy/forge/production/post-deploy-check.sh https://getmotiongrove.com open     # after launch approval
#
# The second argument states what the environment is SUPPOSED to be (ACCOUNT_CAN_REGISTER=false -> closed),
# and the check fails if reality differs in either direction: closed must really show the closed screen,
# open must really show the signup start. Unlike the staging check, /register is not required to be "open".
# Exit status is the number of failed checks. This is readiness, not acceptance.

BASE="${1:-}"
EXPECT="${2:-closed}"
if [ -z "$BASE" ]; then echo "usage: $0 https://host [closed|open]" >&2; exit 2; fi
case "$EXPECT" in closed|open) : ;; *) echo "second argument must be 'closed' or 'open'" >&2; exit 2 ;; esac
BASE="${BASE%/}"
fail=0

code() { curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$@" || echo 000; }
check() { # name expected actual
    if [ "$2" = "$3" ]; then echo "ok    $1 ($3)"; else echo "FAIL  $1 (expected $2, got $3)"; fail=$((fail + 1)); fi
}

case "$BASE" in
    https://getmotiongrove.com) : ;;
    *) echo "FAIL  this script is for https://getmotiongrove.com only (staging has its own check)"; exit 1 ;;
esac

check "GET /login (readiness probe)"           200 "$(code "$BASE/login")"
# On the platform host a guest gets the public marketing homepage (200); only a signed-in user is redirected into the app.
check "GET / serves the marketing homepage"     200 "$(code "$BASE/")"
home="$(curl -sS --max-time 20 "$BASE/" || true)"
if printf '%s' "$home" | grep -q '<h1' && printf '%s' "$home" | grep -q 'href="[^"]*/pricing"'; then echo "ok    GET / is the marketing homepage (h1 and pricing link present)"; else echo "FAIL  GET / does not look like the marketing homepage"; fail=$((fail + 1)); fi
check "http:// redirects to https://"           301 "$(code "http://${BASE#https://}/login")"
check "unknown site uuid is a 404, not a 500"   404 "$(code "$BASE/sites/00000000-0000-4000-8000-000000000000")"

# Registration state. The closed screen is HTTP 200 with data-role="signup-closed" (V1SignupController::closedView).
reg_body="$(curl -sS --max-time 20 "$BASE/register" || true)"
reg_code="$(code "$BASE/register")"
check "GET /register answers" 200 "$reg_code"
if printf '%s' "$reg_body" | grep -q 'data-role="signup-closed"'; then actual=closed; else actual=open; fi
check "registration is $EXPECT" "$EXPECT" "$actual"

# Closed means the write endpoints must not create anything either: an unauthenticated, tokenless POST is
# refused (419 CSRF or a redirect), never accepted. A 2xx here would mean signup is processing.
if [ "$EXPECT" = "closed" ]; then
    post_code="$(code -X POST -d 'email=probe@invalid.example' "$BASE/register/account")"
    case "$post_code" in 419|302|403|404|405) echo "ok    POST /register/account is not accepted ($post_code)" ;;
        *) echo "FAIL  POST /register/account answered $post_code while registration should be closed"; fail=$((fail + 1)) ;;
    esac
fi

# robots.txt must come from Laravel (allow-all), never a static file or a server rule.
robots="$(curl -sS --max-time 20 "$BASE/robots.txt" || true)"
if printf '%s' "$robots" | grep -q '^User-agent: \*' && printf '%s' "$robots" | grep -q '^Disallow:[[:space:]]*$'; then
    echo "ok    /robots.txt is the allow-all body"
else
    echo "FAIL  /robots.txt is not the app's allow-all body (check the Nginx robots.txt block)"; fail=$((fail + 1))
fi

# Webhook endpoints exist and refuse an unsigned payload (400), never 404/500.
for ep in platform-subscriptions business-payments agency-subscriptions usage-billing; do
    check "unsigned POST /stripe/webhook/$ep refused" 400 "$(code -X POST -H 'Content-Type: application/json' -d '{}' "$BASE/stripe/webhook/$ep")"
done

# Hardening: dotfiles and the env file are not served; debug pages are off.
check "GET /.env is not served" 404 "$(code "$BASE/.env")"
dbg="$(curl -sS --max-time 20 "$BASE/this-route-does-not-exist-$$" || true)"
if printf '%s' "$dbg" | grep -qiE 'Whoops|Stack trace|Illuminate\\|APP_KEY'; then echo "FAIL  a 404 page leaks debug output (APP_DEBUG?)"; fail=$((fail + 1)); else echo "ok    404 page shows no debug output"; fi

if [ "$fail" -eq 0 ]; then echo "All readiness checks passed (registration: $EXPECT)."; else echo "$fail check(s) failed."; fi
exit "$fail"
