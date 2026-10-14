# MotionGrove production on Laravel Forge — getmotiongrove.com

Production runbook. Sibling of [`FORGE-STAGING.md`](FORGE-STAGING.md) (staging is unchanged and remains test-only).
Companion docs: [`DEPLOYMENT-READINESS.md`](DEPLOYMENT-READINESS.md) (env audit, scheduler, queue, web server, storage),
[`production-getmotiongrove.env.example`](production-getmotiongrove.env.example) (the variable template for this site),
[`FRESH-INSTALL.md`](FRESH-INSTALL.md), [`PAYMENTS-LIVE-ACCEPTANCE.md`](PAYMENTS-LIVE-ACCEPTANCE.md).

**Status: nothing here has been deployed, provisioned or run against a server.** The scripts were validated offline only
(section 10). Every Forge/DNS step below is still to be done by a human.

## 0. Architecture

| | Staging | Production |
|---|---|---|
| Host | `staging.getmotiongrove.com` | `getmotiongrove.com` |
| Branch | `staging` (integration / testing) | `main` (approved release branch) |
| Site directory | `/home/forge/staging.getmotiongrove.com` | `/home/forge/getmotiongrove.com` |
| Database / `.env` | its own | its own, **never** copied from staging |
| Deploy script | `deploy/forge/deploy.sh` | `deploy/forge/production/deploy.sh` |
| Stripe | test only (script refuses live) | live or test, but mode and keys must agree |
| Deploys | manual | **manual only** |

Production deploys an exact, approved commit of `main`, by hand, from Forge. Nothing deploys production automatically:
quick-deploy and any push-to-deploy webhook stay **off**, and no GitHub Action or staging job calls Forge for this site.

## 1. What the production scripts are (all under `deploy/forge/production/`)

| File | Purpose |
|---|---|
| `deploy.sh` | The **entire** content of Forge's deployment editor (9 lines; Forge macros alone on their own lines, each once). |
| `release-build.sh` | Same build as staging (guards, `composer --no-dev`, shared uploads, caches, `migrate --force`), with production guards. |
| `preflight.sh` | Read-only guard set. Runs inside every deploy and can be run by hand against `.env` **before** deploying. |
| `install-production.sh` | The one-time, guarded first installation (section 7). Never run by a deploy. |
| `post-deploy-check.sh` | Read-only HTTP readiness check; understands deliberately **closed** registration. |
| `validate-scripts.sh` | Offline test harness with negative safety cases (section 10). No network, no database. |

`deploy/forge/verify-uploads.sh` (staging's, unmodified) is generic and is reused: pass the production site root.

### Fail-closed guards (a deploy refuses, before building or activating anything, unless all hold)
- `FORGE_SITE_ROOT` is exactly `/home/forge/getmotiongrove.com` and the branch is exactly `main`. These two are checked **first**,
  before a single directory or link is created.
- The shared `/home/forge/getmotiongrove.com/.env` exists, and the release does not ship its own `.env`.
- `APP_ENV=production`; `APP_DEBUG=false` **explicitly** (unset is refused); `APP_URL=https://getmotiongrove.com` exactly (no slash, no path);
  `APP_KEY` present and `base64:`-shaped.
- `DB_CONNECTION=mysql`; `DB_DATABASE` set and not containing `test`, `staging`, `stage`, `local`, `dev`, `demo` or `sandbox`;
  `DB_USERNAME` set and not `root`; `DB_PASSWORD` set; `DB_HOST` not a staging host.
- `QUEUE_CONNECTION=database`; `CACHE_DRIVER` set and not `array`/`null`; `SESSION_SECURE_COOKIE=true`;
  `MAIL_MAILER` a real mailer (not `log`/`array`/unset) and `MAIL_FROM_ADDRESS` set.
- No `*_DRIVER=fake` for Google Ads, Meta Ads or the external site audit.
- `ACCOUNT_CAN_REGISTER` is present and exactly `true` or `false`. **The application default is open**, so an unset value would open
  public signup on the first deploy; the script therefore forces a deliberate choice.
- **Stripe, mode and keys must agree** (`STRIPE_MODE` must be explicitly `test` or `live`):
  - `live`: `STRIPE_KEY` = `pk_live_…`, `STRIPE_SECRET` = `sk_live_…` or `rk_live_…`, `STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET` and
    `STRIPE_WEBHOOK_SECRET` = `whsec_…`; any `*_test_` key in any `STRIPE_*` variable is refused.
  - `test` (a dry run on the production host): keys empty or `pk_test_`/`sk_test_`/`rk_test_`; any `*_live_` key in any `STRIPE_*` variable is refused.
- No `public/robots.txt` in the release.

## 2. One-time setup (human, in this order)

### 2.1 Prerequisites you must decide first
- **Server:** a separate Forge server, or the existing `motiongrove-staging` server with a second site. Both work with these scripts.
  A separate server isolates production load and failure from staging and is the safer default; the second-site route saves cost but
  shares CPU, disk and PHP-FPM with staging. This is your call; do not provision anything until you have made it.
- The production site user must be `forge` (the scripts hard-code `/home/forge/getmotiongrove.com`).

### 2.2 Forge: create the site
1. Forge -> server -> **New site**: root domain `getmotiongrove.com`, project type **Laravel**, web directory `/public`, PHP **8.3**,
   **no** database creation wizard defaults you do not recognise, **no** "Install Composer dependencies" / "Build frontend assets" options.
2. Git: provider GitHub, repository `os-creator1/os-ai`, branch **`main`**. Enable **Zero downtime deployments** (this creates
   `releases/` and `current/`).
3. **Quick deploy: OFF.** Do not add any deploy webhook anywhere. Do not enable "Deploy on push".
4. Forge -> site -> **Deployments -> Deployment script**: replace the **entire** contents with the 9 lines of
   [`deploy/forge/production/deploy.sh`](../../deploy/forge/production/deploy.sh), exactly. Do not add comments, `set` options, `npm`
   or any extra macro mention (Forge expands macro names wherever they appear, which broke the first staging attempt).
5. Forge -> site -> **Deployments**: disable any "run migrations"/"build" toggle Forge offers. The script already migrates.

### 2.3 Database
Forge -> server -> **Database**: create a **new, empty** `utf8mb4` / `utf8mb4_unicode_ci` database named e.g. `motiongrove_production`, and a
**new** dedicated user with a generated password limited to that database. Do not reuse the staging database or user. The name must not
contain `test`, `staging`, `stage`, `local`, `dev`, `demo` or `sandbox` (the guard refuses it).

### 2.4 Environment (Forge -> site -> Environment)
Start from [`production-getmotiongrove.env.example`](production-getmotiongrove.env.example). Rules:
- Generate a **new** `APP_KEY` once: `php artisan key:generate --show` (any machine with the repo), paste it. **Never reuse the staging key;
  never rotate it afterwards** (stored webhook payloads are encrypted with it). Record it in your password manager.
- Do **not** copy staging's `.env`, its Stripe test keys, webhook secrets, SMTP sandbox, database credentials or any test customer data.
- Keep `ACCOUNT_CAN_REGISTER=false` until launch is approved (section 9).
- Secrets are entered in Forge only: never in Git, a ticket or a chat.
- You may run the guards against the file before the first deploy, from any checkout of this branch:
  `bash deploy/forge/production/preflight.sh <path-to-saved-env> /home/forge/getmotiongrove.com main` (prints problems, changes nothing).

### 2.5 DNS
At the DNS provider for `getmotiongrove.com`:
- `A  getmotiongrove.com -> <production server IPv4>` (Forge shows it on the server page). Add `AAAA` only if the server has IPv6 set up.
- Decide `www`: either an `A`/`CNAME` for `www` plus a Forge **redirect** site/rule to the apex, or no `www` record. `www` does not exist today.
- Currently (checked 2026-10-14) the apex has **no** A record and `staging` resolves to `162.243.175.79`. Do not touch the staging record.
- Wait for propagation (`nslookup getmotiongrove.com`) before requesting the certificate.

### 2.6 SSL
Forge -> site -> **SSL -> Let's Encrypt** for `getmotiongrove.com` (add `www` too if used). Then enable HTTPS redirect (Forge does this by default
for LetsEncrypt sites) and set **Force HTTPS** if offered. The post-deploy check verifies `http://` -> `https://` returns 301.

### 2.7 Nginx (Forge -> site -> Files -> Edit Nginx Configuration)
Two edits, same as staging (section 4 of `FORGE-STAGING.md`):
```
# 1. REPLACE Forge's default robots block (it answers from disk and returns 404; the app's robots is dynamic):
#      location = /robots.txt  { access_log off; log_not_found off; }
location = /robots.txt { try_files /dev/null /index.php?$query_string; }

# 2. Website images are capped at 8 MB:
client_max_body_size 10m;
```
Confirm the `root` line ends in `/home/forge/getmotiongrove.com/current/public`. Keep PHP `upload_max_filesize >= 8M`, `post_max_size >= 10M`
(Forge -> server -> PHP). Never add a static robots file or a CDN/server robots rule.

### 2.8 Scheduler (mandatory)
Forge -> server -> **Scheduler** -> New job: command `php8.3 /home/forge/getmotiongrove.com/current/artisan schedule:run`, user `forge`, frequency
every minute. Point it at `current`, **not** a release directory. The app's queue consumer is a `queue:work` line inside this schedule, so without
the scheduler nothing queued ever runs. (If production shares the staging server, this is a second, separate job; do not edit staging's.)

### 2.9 Queue worker (recommended, in addition to the scheduler)
Forge -> site -> **Queue** -> New worker: connection `database`, queue `automation,default,batch`, tries `1`, timeout `900`, max time `3600`,
processes `1`, directory `current` (the site's own). `$RESTART_QUEUES()` in the deploy script restarts it after every activation. Never use a
different queue list: nothing enqueues elsewhere.

### 2.10 Email
Create a real production SMTP/API mail account (not staging's sandbox) with SPF, DKIM and DMARC for `getmotiongrove.com` published in DNS, then set
`MAIL_*` in the environment. Verification, booking and document emails depend on it; the guard refuses `log`/`array`.

### 2.11 Stripe (live mode; no real charge is made by any step here)
See section 8 for the exact, charge-free procedure.

## 3. Deploying an approved `main` (every release, manual)

Preconditions: the commit you want is on `main`, it contains `deploy/forge/production/`, and you have its SHA. **Forge deploys the branch head**, so
`main` must not move between your approval and your click. If it is unclear what head is, stop and check GitHub first.

1. Forge -> site `getmotiongrove.com` -> **Deploy Now**.
2. Read the deployment log. It must show, in order: `preflight: all production guards passed.`, composer install, caches, migrations,
   `Release <id> built; ready to activate.`, then activation. A line starting `Refusing to deploy to production:` or `REFUSED:` means nothing was
   activated and the previous release is still live; fix the named cause and deploy again.
3. Run the readiness check from any machine:
   `bash deploy/forge/production/post-deploy-check.sh https://getmotiongrove.com closed`
4. After the **second** deploy, on the server: `bash /home/forge/getmotiongrove.com/current/deploy/forge/verify-uploads.sh /home/forge/getmotiongrove.com`.
5. Rollback: Forge -> **Deployments -> Rollback** to the previous release. Safe because migrations are additive and backward compatible (drop or
   rename columns only in a later release), and uploads/storage/`.env` are shared across releases.

Migrations run inside step 2, against the live database, *before* activation, while the previous release is still serving. They run automatically as
part of the deploy: do not run `migrate` by hand, and never `migrate:fresh`, `migrate:refresh` or `db:wipe`.

## 4. What a deploy never does
`platform:install` or any seeder, `migrate:fresh/refresh`, `db:wipe`, `key:generate`, `git clean`, `rsync --delete`, npm/mix builds, touching staging.

## 5. Isolation from staging (checklist)
- [ ] Different directory (`/home/forge/getmotiongrove.com`), enforced by the script.
- [ ] Different database and DB user; name guard refuses staging/test names.
- [ ] Different `APP_KEY`, `.env`, Stripe credentials and webhook secrets, SMTP account.
- [ ] Different scheduler job and queue worker, each pointing at its own `current`.
- [ ] Different Forge site with its own branch (`main`) and no deploy webhook shared with staging.
- [ ] No staging test customers, workspaces or Stripe test objects in production (the production database is created empty and populated only by migrate + the install step).

## 6. Cache, sessions, logs
`CACHE_DRIVER=file`, `SESSION_DRIVER=file` are fine on one server (shared `storage/`). `LOG_CHANNEL=daily`, `LOG_LEVEL=warning`. Caches
(`config`, `route`, `view`, `event`) are rebuilt in every release before activation. Saving Platform Owner Settings rewrites `.env` and clears the
config cache: run `php8.3 artisan config:cache` in `current` afterwards.

## 7. First installation — safe, one-time, on a verified empty database

### 7.1 What the installer does, and why it needs a guard
`php artisan platform:install` runs, in order: `migrate --force`; `db:seed --class=UserSeeder` (the `administrator` role); `platform:create-owner`
(interactive; password at a concealed prompt, never an argument); a full `db:seed`; and the Photo Booth Blueprint, document templates and V2 configuration.
Every step is idempotent **except one hazard**: the full `db:seed` includes `PaymentMethodsSeeder` (truncates `payment_methods`) and `AppConfigSeeder`
(truncates `app_config`). On a pristine database that loses nothing; on a live one it destroys settings. So it must run exactly once, on an empty database, and never again.

### 7.2 Procedure (after the first successful deploy)
The first deploy has already migrated the empty database (schema, plan catalog), so "empty" now means *migrated but with no users, businesses or payment methods*.

0. **Before the first deploy**, in Forge's database panel, confirm the new database has **0 tables**. (If it does not, stop: it is not the new database.)
1. Deploy `main` once (section 3). Confirm the log and the readiness check.
2. SSH as `forge` and verify, changing nothing:
   ```
   cd /home/forge/getmotiongrove.com/current
   bash deploy/forge/production/install-production.sh --check-only
   ```
   It re-runs every environment guard, prints the target database name, and inspects row counts read-only. It refuses unless `migrations > 0`,
   `users = 0`, `businesses = 0` and `payment_methods = 0`, and unless no install marker exists. Read the printed database name and confirm it is the production one.
3. Install, from the same real SSH terminal (it needs a TTY):
   ```
   bash deploy/forge/production/install-production.sh --owner-email=<your real owner email>
   ```
   It repeats the guards, asks you to type `INSTALL getmotiongrove.com`, runs `platform:install`, and you type the owner password **only at the concealed prompt**.
   On success it writes `/home/forge/getmotiongrove.com/storage/app/.platform-installed`, after which the wrapper refuses to run again.
4. Verify: sign in at `https://getmotiongrove.com/login` as the owner; then in the database `SELECT count(*) FROM currencies` = 12, `workspace_plan_catalog`
   has `core`, `growth`, `agency`, exactly one `is_admin` user, and `SELECT name, status FROM payment_methods WHERE status = 1;` returns **no** rows (do not enable legacy gateways).
5. Registration is still closed. Run `post-deploy-check.sh ... closed` again.

If the install stops part-way: do **not** re-run blindly and do not delete the marker by hand. The marker exists only after success, and the underlying command is
idempotent, but the truncating seeders mean you should first read the error, check what exists, and decide. An unattended retry is exactly what the guard is there to prevent.

Upgrades are deploys only. Never run the installer again on production.

## 8. Stripe verification without a charge

All of this is read-only, or happens in Stripe **test mode**; nothing here can create a real charge.

1. **Prices.** In the Stripe dashboard (live), create the recurring prices for Core and Growth (product, amount, currency, interval). The trial length is a
   per-plan setting in the app (Platform Owner -> Platform plans -> trial days), sent to Stripe as `trial_period_days` at Checkout; it is **not** part of the Price.
   Record the `price_…` IDs. Saving a Price ID in Platform plans verifies amount, currency and interval against Stripe, so that screen is itself the price check.
2. **Webhooks (live mode).** Register four endpoints and put each signing secret in its own variable:

   | Endpoint | Variable |
   |---|---|
   | `https://getmotiongrove.com/stripe/webhook/platform-subscriptions` | `STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET` |
   | `https://getmotiongrove.com/stripe/webhook/business-payments` (Connect) | `STRIPE_CONNECT_WEBHOOK_SECRET` |
   | `https://getmotiongrove.com/stripe/webhook/agency-subscriptions` (Connect) | `STRIPE_AGENCY_SUBSCRIPTION_WEBHOOK_SECRET` |
   | `https://getmotiongrove.com/stripe/webhook/usage-billing` | `STRIPE_WEBHOOK_SECRET` |

   Events: [`DEPLOYMENT-READINESS.md`](DEPLOYMENT-READINESS.md) section 8 step 12.
3. **Charge-free endpoint check.** `post-deploy-check.sh` sends an **unsigned** POST to each endpoint and requires `400`. That proves the route exists and
   rejects unsigned payloads; it cannot create anything. (`usage-billing` returns 500 until `STRIPE_SECRET` is set.)
4. **Signed delivery without a charge.** In the live dashboard use "Send test webhook" on the endpoint, or `stripe trigger` against **test mode** on a
   separate dry run; confirm 2xx and that `failed_jobs` stays empty. Never complete a live Checkout with a real card to "test".
5. **Trial behaviour, rehearsed in test mode.** To rehearse the exact trial flow before going live, temporarily run production in `STRIPE_MODE=test` with
   `sk_test_/pk_test_` keys (the guard allows exactly that combination and rejects any mixed one), set test prices, and sign up a throwaway account with
   `4242 4242 4242 4242`: it must start a trial (no charge today) and activate on `checkout.session.completed`. Then switch the environment to live
   keys and live prices, deploy, and confirm `post-deploy-check.sh`. Do this only while registration is otherwise closed (it needs a controlled, short opening; see section 9).
   Delete any test customer afterwards.

## 9. Keeping registration closed, and opening it

`ACCOUNT_CAN_REGISTER=false` in the production environment. The closed state is a normal `200` page marked `data-role="signup-closed"`; the guest write
endpoints answer from the same gate. Registration is also closed whenever no plan is *sellable* (active, available for signup, price, currency, Stripe Price ID).

`post-deploy-check.sh https://getmotiongrove.com closed` asserts **both** that `/register` shows the closed screen and that an untokened POST to the signup
endpoint is not accepted. It fails if registration is unexpectedly open, and `... open` fails if it is unexpectedly closed, so a wrong flag cannot pass silently.

**To open (only after final release approval):** set `ACCOUNT_CAN_REGISTER=true` in Forge -> Environment, `php8.3 artisan config:cache` in `current`, mark at least
one plan sellable, and run `post-deploy-check.sh https://getmotiongrove.com open`. To close again, reverse it.

## 10. Validation of these scripts (what was and was not proven)
`bash deploy/forge/production/validate-scripts.sh` runs offline against temp files only (no database, network, Forge, composer or php). It checks syntax of
every script; that `deploy.sh` contains each Forge macro exactly once, alone on its line; that the staging scripts are byte-identical to `origin/staging`; that
a good live and a good test-mode environment pass; and that each unsafe case is **refused for the right reason**: wrong branch, staging/empty/wrong site root,
`APP_ENV`, `APP_DEBUG` true or unset, `APP_URL` (staging/trailing slash/http), empty key, test/staging DB names, root DB user, `sync` queue, `array` cache, `log` mailer,
insecure cookie, fake drivers, registration flag unset or non-boolean, Stripe mode unset, live mode with test/empty keys, test mode with live keys, missing live webhook
secrets, and a stray test key under live mode. It also proves a refused build creates no directories and runs no composer/php command.

**Not proven (needs the server):** Forge's real macro expansion, the symlink/persistence logic (identical to staging's, proven there only after staging's second deploy),
`composer --no-dev`, nginx, cron, MySQL 8.4 on Linux, `install-production.sh`'s database inspection (it needs `php artisan tinker` against a real database; only its
refusals before that point are exercised offline), and any live provider.

## 11. Configuration to change when onboarding, the website builder and Ask MotionGrove reach `main`

Review at the time of merge; these were read from the branches/config as of 2026-10-14 and the list should be re-derived from the final diff of `main`
(`git diff <old main>..<new main> -- config .env.example docs/product/production.env.example`).

| Area | Setting | Production action |
|---|---|---|
| Onboarding | `BUSINESS_ONBOARDING_ENABLED`, `BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS` | Both default **off**. Set `true` for the customer flow only once onboarding is on `main` and accepted. |
| Account email | `ACCOUNT_VERIFICATION` | Default `true`: keep `true` in production; it needs the working production mail from 2.10. |
| Website builder | `SEO_RANK_TRACKING_ENABLED` | Keep `false` (paid provider) unless separately approved. `FORGE_*` stays blank until custom-domain TLS is proven. |
| AI master switch | `OPENAI_ACTIVE`, `OPENAI_API_KEY`, `OPENAI_MODEL` | Off and empty until Ask MotionGrove/AI is approved. Use a **production-only** key created for this site; never the developer or staging key. Flip `OPENAI_ACTIVE=true` last. |
| AI budgets | `AI_PLATFORM_MONTHLY_CAP_MICROUSD` (default $20), `AI_BUDGET_{TRIAL,CORE,GROWTH,AGENCY}_WORKSPACE_CAP_MICROUSD`, `AI_BUDGET_*_INTERACTIVE_SHARE_BPS` | Decide real production caps. Ask MotionGrove's branch changes defaults (e.g. trial cap $1.50 -> $3.00, interactive share 30% -> 80%); set explicit values so a default change cannot silently raise spend. |
| Ask MotionGrove | `AI_ROUTE_ASSISTANT_MODEL` (default `gpt-4o-mini`), `AI_ROUTE_ASSISTANT_*_PRICE_MICROUSD_PER_MTOK`, `_MAX_INPUT_TOKENS`, `_MAX_OUTPUT_TOKENS`, `_MAX_REQUEST_COST_MICROUSD`, `_TIMEOUT_SECONDS`, `AI_ENFORCE_BUDGETS_FOR_EXISTING_CATEGORIES` | Defaults exist; verify the price variables against the provider's current pricing before enabling, since they drive the budget ledger. |
| Ask MotionGrove | `ASSISTANT_MAX_MODEL_ITERATIONS`, `ASSISTANT_TURN_BUDGET_SECONDS`, `ASSISTANT_RETENTION_DAYS` (90), `ASSISTANT_DELETE_WAIT_SECONDS` | Defaults ok; confirm the 90-day conversation retention suits your privacy policy. |
| Scheduler / queue | `assistant` purge command added to `app/Console/Kernel.php` | No Forge change: it rides the existing `schedule:run`. After deploy, `php8.3 artisan schedule:list` should show the entry. |
| Preflight | `ACCOUNT_CAN_REGISTER`, Stripe mode | Unchanged. If a new variable makes production unsafe when unset, add it to `preflight.sh` in the same release. |
| Launch | `ACCOUNT_CAN_REGISTER=true`, sellable plans, `post-deploy-check.sh ... open` | Only after final approval (section 9). |

## 12. What still needs your action (nothing below has been done)
1. Decide: separate production server vs second site on the existing server.
2. Forge: create the site, database, environment, scheduler, queue worker, nginx edits, SSL (sections 2.2-2.9, 2.6).
3. DNS: apex A record (and `www` decision), SPF/DKIM/DMARC for mail.
4. Stripe: live prices and the four webhooks; production-only secrets in Forge.
5. Merge this branch's scripts and the finished product into `main`; deploy that exact `main` by hand (section 3); then the one-time install (section 7).
6. Leave registration closed until final approval.

## 13. AI (Ask MotionGrove V1 and website generation) — production requirements

Read from PR #460 (`integration/ask-motiongrove-v1`, head `78b7c821`) and the staging AI gateway. **No value below is a secret; the key itself is entered in Forge only.**
Both website generation and Ask MotionGrove go through one gateway (`AiGateway`). It **fails closed**: when anything below is not satisfied, the feature refuses
(website generation shows its "unavailable" message), it never calls the provider.

| # | Requirement | Production setting |
|---|---|---|
| 1 | Master switch | `OPENAI_ACTIVE=true` (default **false**). Flip last, after 2-6. Platform Owner -> Settings can also set it and rewrites `.env`; run `config:cache` afterwards. |
| 2 | Provider credentials | `OPENAI_API_KEY` = a key created **for this site only** (never the developer's or staging's). Optional: `OPENAI_ORGANIZATION`, `OPENAI_PROJECT`; `OPENAI_MODEL` defaults to `gpt-4o`. |
| 3 | Budget enforcement | Leave `AI_ENFORCE_BUDGETS_FOR_EXISTING_CATEGORIES=true` (default). Set explicit caps: `AI_PLATFORM_MONTHLY_CAP_MICROUSD` (default 20,000,000 = $20) and `AI_BUDGET_{TRIAL,CORE,GROWTH}_WORKSPACE_CAP_MICROUSD`. **A Workspace with no valid plan has a zero cap**, so generation is refused until the account has a trial or paid plan. |
| 4 | Shared cache locks | Ask MotionGrove serialises turns and deletions with `Cache::lock`. On the single production server `CACHE_DRIVER=file` is sufficient (web, scheduler and workers share one `storage/`). Move to `redis`/`database` before adding a second server. Never `array` (the preflight refuses it). |
| 5 | Queue worker | The Forge worker from section 2.9 (`database`, `automation,default,batch`) and the cron scheduler. Nothing else to add. |
| 6 | Scheduler | `assistant:purge-expired` (daily, no-overlap) rides the existing `schedule:run`. After deploy, `php8.3 artisan schedule:list` must show it. `ASSISTANT_RETENTION_DAYS=90` by default. |
| 7 | Timeouts | Defaults (`ASSISTANT_TURN_BUDGET_SECONDS=75`, `AI_ROUTE_ASSISTANT_TIMEOUT_SECONDS=25`) are consistent; keep the route timeout well below the turn budget. |
| 8 | Privacy / legal (not a code item) | The first-use AI-data notice, data-processing agreement, provider subprocessor disclosure, provider-side retention and cross-border transfer have **not** been legally reviewed (PR #460 doc, prerequisite 4). Keep `OPENAI_ACTIVE=false` until you have accepted that risk or completed the review. |

Smallest safe production check once enabled: with the owner account, run **one** website generation and one Ask MotionGrove question under a trial plan, then
read Platform Owner -> AI usage and confirm the ledger shows an *ok* entry and the cost is within the cap. If generation reports an error, the cause is recorded in
`ai_usage_ledger.refusal_reason` (`ai_disabled`, `plan_unavailable`, `entitlement_missing`, `dormant`, `budget_exhausted`, ...) or, for a provider failure, in `storage/logs`.
