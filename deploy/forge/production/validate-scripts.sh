#!/usr/bin/env bash
# Offline, non-destructive validation of the production deploy scripts. Touches nothing outside a temp
# directory: no database, no network, no Forge, no real composer/php (stubs are used). Safe anywhere.
#
#   bash deploy/forge/production/validate-scripts.sh
#
# Exit status = number of failed assertions.

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd -P)"
PRE="$HERE/deploy/forge/production/preflight.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
pass=0; failn=0

ok()   { pass=$((pass + 1)); echo "ok    $1"; }
nok()  { failn=$((failn + 1)); echo "FAIL  $1"; }
expect_pass() { # name, cmd...
    local n="$1"; shift
    if "$@" >"$TMP/out" 2>&1; then ok "$n"; else nok "$n (expected success)"; sed 's/^/        /' "$TMP/out" | head -8; fi
}
expect_refuse() { # name, expected-substring, cmd...
    local n="$1" sub="$2"; shift 2
    if "$@" >"$TMP/out" 2>&1; then nok "$n (expected refusal, command succeeded)"; return; fi
    if grep -qF -- "$sub" "$TMP/out"; then ok "$n"; else nok "$n (refused, but not for '$sub')"; sed 's/^/        /' "$TMP/out" | head -6; fi
}

SITE="/home/forge/getmotiongrove.com"

# A known-good LIVE environment (all values are syntactically valid fakes, no real secret).
good_env() {
    cat <<EOF
APP_NAME=MotionGrove
APP_ENV=production
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
APP_DEBUG=false
APP_URL=https://getmotiongrove.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=motiongrove_production
DB_USERNAME=mg_prod
DB_PASSWORD=not-a-real-password
QUEUE_CONNECTION=database
CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=hello@getmotiongrove.com
ACCOUNT_CAN_REGISTER=false
STRIPE_MODE=live
STRIPE_KEY=pk_live_FAKEFAKEFAKE
STRIPE_SECRET=sk_live_FAKEFAKEFAKE
STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET=whsec_FAKE1
STRIPE_WEBHOOK_SECRET=whsec_FAKE2
OPENAI_ACTIVE=false
EOF
}
# mutate NAME 'sed-expression' -> writes $TMP/NAME.env
mutate() { good_env | sed -E "$2" > "$TMP/$1.env"; }
drop()   { good_env | grep -vE "^$2=" > "$TMP/$1.env"; }
run_pre() { bash "$PRE" "$1" "${2:-$SITE}" "${3:-main}"; }

echo "== syntax"
for f in preflight release-build install-production post-deploy-check validate-scripts; do
    if bash -n "$HERE/deploy/forge/production/$f.sh" 2>"$TMP/syn"; then ok "bash -n $f.sh"; else nok "bash -n $f.sh"; cat "$TMP/syn"; fi
done

echo "== deploy.sh (Forge editor contents)"
D="$HERE/deploy/forge/production/deploy.sh"
for m in CREATE_RELEASE ACTIVATE_RELEASE RESTART_QUEUES; do
    n="$(grep -c "\$$m()" "$D")"; [ "$n" = "1" ] && ok "macro $m appears exactly once" || nok "macro $m appears $n times"
    grep -E "^\\\$$m\(\)$" "$D" >/dev/null && ok "macro $m alone on its line" || nok "macro $m not alone on its line"
done
[ "$(grep -c . "$D")" -le 9 ] && ok "deploy.sh has <= 9 non-empty lines" || nok "deploy.sh is longer than 9 lines"
grep -q 'production/release-build.sh' "$D" && ok "calls the production build script" || nok "does not call the production build script"
! grep -q 'deploy/forge/release-build.sh' "$D" && ok "does not call the staging build script" || nok "calls the staging build script"

echo "== staging scripts untouched"
for f in deploy.sh release-build.sh post-deploy-check.sh verify-uploads.sh; do
    if git -C "$HERE" diff --quiet origin/staging -- "deploy/forge/$f" 2>/dev/null; then ok "deploy/forge/$f identical to origin/staging"; else nok "deploy/forge/$f differs from origin/staging"; fi
done

echo "== preflight: accepted"
good_env > "$TMP/good.env"
expect_pass "good live env" run_pre "$TMP/good.env"
mutate testmode 's/^STRIPE_MODE=.*/STRIPE_MODE=test/; s/pk_live_/pk_test_/; s/sk_live_/sk_test_/'
expect_pass "consistent test-mode dry run is allowed" run_pre "$TMP/testmode.env"
mutate crlf 's/$/\r/'
expect_pass "CRLF line endings" run_pre "$TMP/crlf.env"
mutate quoted 's/^APP_URL=.*/APP_URL="https:\/\/getmotiongrove.com"/'
expect_pass "quoted values" run_pre "$TMP/quoted.env"
mutate open 's/^ACCOUNT_CAN_REGISTER=.*/ACCOUNT_CAN_REGISTER=true/'
expect_pass "registration explicitly open is accepted (the launch state)" run_pre "$TMP/open.env"

echo "== preflight: refused"
expect_refuse "wrong branch (staging)" "'main' branch" run_pre "$TMP/good.env" "$SITE" staging
expect_refuse "wrong branch (feature)" "'main' branch" run_pre "$TMP/good.env" "$SITE" agent/some-feature
expect_refuse "staging site root" "site root must be exactly" run_pre "$TMP/good.env" /home/forge/staging.getmotiongrove.com main
expect_refuse "empty site root" "site root must be exactly" bash "$PRE" "$TMP/good.env" "" main
mutate e1 's/^APP_ENV=.*/APP_ENV=local/';                   expect_refuse "APP_ENV=local" "APP_ENV" run_pre "$TMP/e1.env"
mutate e2 's/^APP_DEBUG=.*/APP_DEBUG=true/';                expect_refuse "APP_DEBUG=true" "APP_DEBUG" run_pre "$TMP/e2.env"
drop   e3 APP_DEBUG;                                         expect_refuse "APP_DEBUG unset" "APP_DEBUG" run_pre "$TMP/e3.env"
mutate e4 's#^APP_URL=.*#APP_URL=https://staging.getmotiongrove.com#'; expect_refuse "APP_URL staging" "APP_URL" run_pre "$TMP/e4.env"
mutate e5 's#^APP_URL=.*#APP_URL=https://getmotiongrove.com/#';        expect_refuse "APP_URL trailing slash" "APP_URL" run_pre "$TMP/e5.env"
mutate e6 's#^APP_URL=.*#APP_URL=http://getmotiongrove.com#';          expect_refuse "APP_URL http" "APP_URL" run_pre "$TMP/e6.env"
mutate e7 's/^APP_KEY=.*/APP_KEY=/';                        expect_refuse "APP_KEY empty" "APP_KEY" run_pre "$TMP/e7.env"
mutate e8 's/^DB_DATABASE=.*/DB_DATABASE=ultimatesms_testing/';    expect_refuse "DB name testing" "DB_DATABASE" run_pre "$TMP/e8.env"
mutate e9 's/^DB_DATABASE=.*/DB_DATABASE=motiongrove_staging/';    expect_refuse "DB name staging" "DB_DATABASE" run_pre "$TMP/e9.env"
mutate f1 's/^DB_USERNAME=.*/DB_USERNAME=root/';            expect_refuse "DB user root" "least-privilege" run_pre "$TMP/f1.env"
mutate f2 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/';  expect_refuse "queue sync" "QUEUE_CONNECTION" run_pre "$TMP/f2.env"
mutate f3 's/^CACHE_DRIVER=.*/CACHE_DRIVER=array/';         expect_refuse "cache array" "CACHE_DRIVER" run_pre "$TMP/f3.env"
mutate f4 's/^MAIL_MAILER=.*/MAIL_MAILER=log/';             expect_refuse "mail log" "MAIL_MAILER" run_pre "$TMP/f4.env"
mutate f5 's/^SESSION_SECURE_COOKIE=.*/SESSION_SECURE_COOKIE=false/'; expect_refuse "insecure cookie" "SESSION_SECURE_COOKIE" run_pre "$TMP/f5.env"
printf 'GOOGLE_ADS_DRIVER=fake\n' | cat "$TMP/good.env" - > "$TMP/f6.env"; expect_refuse "fake ads driver" "GOOGLE_ADS_DRIVER" run_pre "$TMP/f6.env"
drop   g1 ACCOUNT_CAN_REGISTER;                              expect_refuse "registration flag unset (app default is OPEN)" "ACCOUNT_CAN_REGISTER" run_pre "$TMP/g1.env"
mutate g2 's/^ACCOUNT_CAN_REGISTER=.*/ACCOUNT_CAN_REGISTER=maybe/'; expect_refuse "registration flag not boolean" "ACCOUNT_CAN_REGISTER" run_pre "$TMP/g2.env"
drop   h1 STRIPE_MODE;                                       expect_refuse "stripe mode unset" "STRIPE_MODE" run_pre "$TMP/h1.env"
mutate h2 's/^STRIPE_MODE=.*/STRIPE_MODE=live/; s/^STRIPE_SECRET=.*/STRIPE_SECRET=sk_test_FAKE/'; expect_refuse "live mode + test secret" "STRIPE_SECRET" run_pre "$TMP/h2.env"
mutate h3 's/^STRIPE_KEY=.*/STRIPE_KEY=pk_test_FAKE/';       expect_refuse "live mode + test publishable" "STRIPE_KEY" run_pre "$TMP/h3.env"
mutate h4 's/^STRIPE_MODE=.*/STRIPE_MODE=test/';             expect_refuse "test mode + live keys" "test" run_pre "$TMP/h4.env"
mutate h5 's/^STRIPE_SECRET=.*/STRIPE_SECRET=/';             expect_refuse "live mode + empty secret" "STRIPE_SECRET" run_pre "$TMP/h5.env"
drop   h6 STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET;       expect_refuse "live mode, no platform webhook secret" "WEBHOOK_SECRET" run_pre "$TMP/h6.env"
printf 'STRIPE_AGENCY_SUBSCRIPTION_WEBHOOK_SECRET=x\nSTRIPE_FOO=sk_test_LEAK\n' | cat "$TMP/good.env" - > "$TMP/h7.env"; expect_refuse "stray test key under a live mode" "test-mode Stripe key" run_pre "$TMP/h7.env"
mutate h8 's/^STRIPE_MODE=.*/STRIPE_MODE=Live /';             expect_pass "STRIPE_MODE is case/space tolerant" run_pre "$TMP/h8.env"
expect_refuse "missing env file" "not found" run_pre "$TMP/nope.env"

echo "== staging safeguards still present (staging script unchanged and still refuses live Stripe)"
grep -q "staging must stay in Stripe test mode" "$HERE/deploy/forge/release-build.sh" && ok "staging build still refuses live Stripe" || nok "staging live-Stripe refusal missing"
grep -q '"staging" \]' "$HERE/deploy/forge/release-build.sh" && ok "staging build still requires branch staging" || nok "staging branch guard missing"

echo "== release-build.sh end to end against a fake site (stub composer/php, no network)"
FAKE="$TMP/forge"; mkdir -p "$FAKE/bin"
cat > "$FAKE/bin/php" <<'EOS'
#!/usr/bin/env bash
echo "php $*" >> "$STUB_LOG"
EOS
cat > "$FAKE/bin/composer" <<'EOS'
#!/usr/bin/env bash
echo "composer $*" >> "$STUB_LOG"
EOS
chmod +x "$FAKE/bin/php" "$FAKE/bin/composer"
make_release() { # dir
    rm -rf "$1"; mkdir -p "$1/deploy/forge/production" "$1/public/images/websites" "$1/storage"
    cp "$HERE"/deploy/forge/production/*.sh "$1/deploy/forge/production/"
    : > "$1/public/images/websites/ships-with-release.txt"
}
run_build() { # release-dir site-root branch
    ( cd "$1" && STUB_LOG="$TMP/stub.log" FORGE_PHP="$FAKE/bin/php" FORGE_COMPOSER="$FAKE/bin/composer" \
      FORGE_SITE_ROOT="$2" FORGE_RELEASE_DIRECTORY="$1" FORGE_SITE_BRANCH="$3" bash deploy/forge/production/release-build.sh )
}
# The real production root is hard-coded; the fake cannot satisfy it, so these cases prove the guard fires
# BEFORE any filesystem change. (A full happy path needs /home/forge and runs only on the server.)
REL="$TMP/rel1"; make_release "$REL"
expect_refuse "build refuses a non-production site root" "expected /home/forge/getmotiongrove.com" run_build "$REL" "$TMP/site-staging" main
[ ! -e "$TMP/site-staging" ] && ok "refused build created no directories" || nok "refused build created $TMP/site-staging"
expect_refuse "build refuses branch staging" "'main' branch" run_build "$REL" /home/forge/getmotiongrove.com staging
if [ -e /home/forge ]; then echo "skip  shared-.env case (/home/forge exists: never run the build case on a real server)"; else expect_refuse "build refuses without a shared .env" "no /home/forge/getmotiongrove.com/.env" run_build "$REL" /home/forge/getmotiongrove.com main; fi
[ ! -s "$TMP/stub.log" ] && ok "no composer/php command ran in any refused case" || nok "a command ran in a refused case: $(cat "$TMP/stub.log")"


echo "== install-production.sh refusals (before any database access; fake php would fail the test if called)"
INS="$HERE/deploy/forge/production/install-production.sh"
FS="$TMP/fakesite"; mkdir -p "$FS/current/deploy/forge/production" "$FS/storage/app"
cp "$HERE"/deploy/forge/production/*.sh "$FS/current/deploy/forge/production/"
cp "$TMP/good.env" "$FS/.env"
run_ins() { ( cd "$FS/current" && MG_SITE_ROOT_OVERRIDE="$FS" FORGE_PHP="$FAKE/bin/php" STUB_LOG="$TMP/stub2.log" bash deploy/forge/production/install-production.sh "$@" ); }
expect_refuse "install: unknown argument" "usage" run_ins --bogus
expect_refuse "install: owner email required" "--owner-email" run_ins
expect_refuse "install: bad owner email" "does not look like an email" run_ins --owner-email=nope
expect_refuse "install: refuses outside the production site" "run this from" bash "$INS" --check-only
: > "$FS/storage/app/.platform-installed"
expect_refuse "install: refuses when already installed (marker)" "already installed" run_ins --check-only
rm -f "$FS/storage/app/.platform-installed"
expect_refuse "install: environment guards run (fake site root is not production)" "environment guards failed" run_ins --check-only
[ ! -s "$TMP/stub2.log" ] && ok "install refusals never invoked php/artisan" || nok "install invoked php: $(cat "$TMP/stub2.log")"

echo "== post-deploy-check.sh (fake curl; no network)"
PDC="$HERE/deploy/forge/production/post-deploy-check.sh"
mkdir -p "$TMP/fakecurl"
cat > "$TMP/fakecurl/curl" <<'STUB'
#!/usr/bin/env bash
# Answers like the production host, steered by FAKE_* variables. Prints the status for -w calls, else the body.
url="${@: -1}"; path="/${url#*://*/}"; [ "$path" = "/${url}" ] && path="/"
method=GET; want_code=0
for a in "$@"; do [ "$a" = "POST" ] && method=POST; [ "$a" = "-w" ] && want_code=1; done
code=404; body="Not Found"
case "$url" in http://*) code=301 ;; *)
  case "$method:$path" in
    GET:/login) code=200 ;;
    GET:/) code="${FAKE_HOME_CODE:-200}"; body='<html><body><h1>More bookings.</h1><a href="https://getmotiongrove.com/pricing">Pricing</a></body></html>' ;;
    GET:/register) code=200; body='<main data-role="signup-closed">Closed</main>' ;;
    POST:/register/account) code=419 ;;
    GET:/robots.txt) code=200; body=$'User-agent: *\nDisallow:' ;;
    POST:/stripe/webhook/*) code="${FAKE_WEBHOOK_CODE:-400}" ;;
    GET:/this-route-does-not-exist-*) body="${FAKE_404_BODY:-Not Found}" ;;
  esac ;;
esac
if [ "$want_code" = 1 ]; then printf '%s' "$code"; else printf '%s' "$body"; fi
STUB
chmod +x "$TMP/fakecurl/curl"
run_pdc() { PATH="$TMP/fakecurl:$PATH" bash "$PDC" https://getmotiongrove.com closed; }
expect_pass   "readiness: marketing homepage answering 200 passes every check" run_pdc
expect_refuse "readiness: a redirect on GET / is no longer accepted" "GET / serves the marketing homepage" env FAKE_HOME_CODE=302 PATH="$TMP/fakecurl:$PATH" bash "$PDC" https://getmotiongrove.com closed
expect_refuse "readiness: an accepted unsigned webhook still fails" "unsigned POST /stripe/webhook" env FAKE_WEBHOOK_CODE=200 PATH="$TMP/fakecurl:$PATH" bash "$PDC" https://getmotiongrove.com closed
expect_refuse "readiness: debug output on a 404 still fails" "leaks debug output" env FAKE_404_BODY="Whoops! Stack trace" PATH="$TMP/fakecurl:$PATH" bash "$PDC" https://getmotiongrove.com closed
echo
echo "validated: $pass passed, $failn failed"
exit "$failn"
