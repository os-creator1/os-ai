# MotionGrove staging on Laravel Forge (zero-downtime deployments)

First real staging server: `motiongrove-staging` (DigitalOcean NYC1, Ubuntu 24.04, PHP 8.3, MySQL 8.4,
4 GB / 2 vCPU), site `staging.getmotiongrove.com`, deployed from the **`staging`** branch with Forge
**zero-downtime deployments** enabled.

**Status: nothing here has been deployed or exercised on the server.** Section 11 lists the acceptance flows;
every row starts as "not run" and may be marked only after it has been exercised.

Companion docs: [`DEPLOYMENT-READINESS.md`](DEPLOYMENT-READINESS.md) (env audit, scheduler, queue, web server,
storage, checklist), [`production.env.example`](production.env.example) (every variable),
[`FRESH-INSTALL.md`](FRESH-INSTALL.md), [`PAYMENTS-LIVE-ACCEPTANCE.md`](PAYMENTS-LIVE-ACCEPTANCE.md).

## 0. The three settings to get right before the first deploy

1. **Frontend build: OFF.** Do not run `npm run build` (the script does not exist; see section 8). Delete any
   `npm ci` / `npm run build` lines from Forge's pre-filled deployment script and switch off any "build frontend
   assets" option Forge offers for the site. Compiled assets are committed.
2. **Deployment script:** replace the **entire** contents of Forge's deployment editor with the 9 lines of
   [`deploy/forge/deploy.sh`](../../deploy/forge/deploy.sh), exactly as they are. It holds only Forge's three
   macros (each alone on its own line) plus `bash deploy/forge/release-build.sh`; all real logic is in
   [`release-build.sh`](../../deploy/forge/release-build.sh), a normal file that Forge never rewrites. **Never put a
   macro name in a comment or anywhere else in that editor:** Forge expands macros as text, so each extra mention is
   rewritten too (this is what broke the first version, which mentioned each macro three times).
3. **Environment:** set `APP_KEY` (once) and the staging values in section 3 *before* the first deploy; the
   script refuses to deploy without an `APP_KEY`.

## 1. What ships and from where

- Repository `os-creator1/os-ai`. **The site tracks branch `staging` only.** `main` is never a deploy source;
  keep automatic deployment from `main` off.
- Laravel 12, PHP `^8.2` (8.3 on the server). Extensions (present on a Forge PHP 8.3 server; confirm once with
  `php -m`): `ctype curl dom exif fileinfo gd intl json libxml mbstring openssl pcntl pdo pdo_mysql posix
  simplexml zip`.
- Layout under zero-downtime: `/home/forge/staging.getmotiongrove.com/{current,releases/<id>,storage,.env}`;
  nginx root is `.../current/public`. `storage/` and `.env` are shared across releases by Forge.

## 2. One-time Forge setup

1. **Site** (already created): root domain `staging.getmotiongrove.com`, web directory `/public`, PHP 8.3,
   repo `os-creator1/os-ai`, branch **`staging`**.
2. **Database:** a new empty `utf8mb4` / `utf8mb4_unicode_ci` database (e.g. `motiongrove_staging`) with its own
   user. Never a name containing `testing`; the deploy script refuses those.
3. **Environment** (Forge -> Environment): from [`production.env.example`](production.env.example) plus section 3.
   Generate the key **once**, on any machine with the repo: `php artisan key:generate --show`, paste the value
   as `APP_KEY`, and never rotate it (stored webhook payloads are encrypted with it). `.env` must stay writable
   by the site user: Platform Owner Settings screens rewrite it (it is a shared file, so those writes survive
   deploys; they also delete the config cache, so run `php artisan config:cache` in `current` afterwards).
4. **DNS + TLS:** A record for `staging`, then Forge -> SSL -> LetsEncrypt.
5. **Nginx:** apply section 4 (robots.txt block, body size). Required.
6. **Deployment script:** section 0 item 2.
7. **Scheduler** (mandatory; the app's queue consumer is a scheduled `queue:work` line): Forge -> Scheduler ->
   `php8.3 /home/forge/staging.getmotiongrove.com/current/artisan schedule:run`, every minute, user `forge`.
   Point it at **`current`**, not at a release directory.
8. **Queue worker** (recommended in addition to the scheduler): Forge -> Queue -> connection `database`, queues
   `automation,default,batch`, tries `1`, timeout `900`, max-time `3600`, one worker, directory `current`.
   `$RESTART_QUEUES()` in the deploy script restarts it after every activation.
9. **First deploy**, then the **first install** (section 7). Install is *not* part of the deploy script.
10. Register the Stripe **test-mode** webhooks and provider callbacks (DEPLOYMENT-READINESS section 8 steps 12-13).

## 3. Staging environment (deltas from `production.env.example`)

Secrets are supplied by the owner in Forge only; never committed, pasted into chat, or logged.

| Variable | Staging value | Why |
|---|---|---|
| `APP_ENV` | `production` | the Google/Meta `fake` drivers throw in production |
| `APP_DEBUG` | `false` | the deploy script refuses `true` |
| `APP_URL` | `https://staging.getmotiongrove.com` | exact origin; `TrustHosts` and OAuth/Stripe return URLs depend on it |
| `APP_KEY` | generated once (section 2.3) | **never** the key in `.env.example` (committed and public); the script refuses an empty key |
| `APP_NAME`, `APP_TITLE` | `MotionGrove` | platform name; `APP_TITLE` is the page-title suffix |
| `DB_*` | the staging database/user | |
| `QUEUE_CONNECTION` | `database` | never `sync` |
| `CACHE_DRIVER`, `SESSION_DRIVER` | `file` | one server; shared `storage/` |
| `SESSION_SECURE_COOKIE` | `true` | |
| `LOG_CHANNEL` / `LOG_LEVEL` | `daily` / `warning` | |
| `MAIL_*` | a **sandbox** SMTP, never real customers | `MAIL_MAILER=smtp` (the app reads `MAIL_MAILER`, not `MAIL_DRIVER`) |
| `ACCOUNT_VERIFICATION` | `true` for the real signup flow (needs working mail); `false` only to skip the email step | the local preview has `false`, which fails two signup tests there |
| `ACCOUNT_CAN_REGISTER` | `true` | sellable plans are still required (section 5.1) |
| `STRIPE_MODE` | `test` | **the deploy script refuses `live` mode and any `sk_live_/pk_live_/rk_live_` key** |
| `STRIPE_KEY`, `STRIPE_SECRET` | owner-supplied **test** keys | needed to sell a plan or build a webhook gateway |
| `STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET`, `STRIPE_CONNECT_WEBHOOK_SECRET`, `STRIPE_AGENCY_SUBSCRIPTION_WEBHOOK_SECRET`, `STRIPE_WEBHOOK_SECRET` | the four test-mode signing secrets | four lanes, four endpoints, four different secrets |
| `GOOGLE_ADS_DRIVER`, `META_ADS_DRIVER` | `http` (or unset) | `fake` is refused in production |
| `EXTERNAL_SITE_AUDIT_DRIVER` | unset (`http`) | `fake` is a fixture driver |
| `SEO_RANK_TRACKING_ENABLED` | `false` | paid provider, fail-closed |
| `OPENAI_ACTIVE` / `OPENAI_API_KEY` | `false` / empty until section 5.4 | AI kill switch |
| `BUSINESS_ONBOARDING_ENABLED`, `BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS` | `true` for the onboarding acceptance | default off |
| `OPPORTUNITY_ENGINE_ENABLED`, `DOCUMENTS_ENABLED` | optional; gate scheduled sweeps/reminders, not the pages | default off |
| `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` | `90` | unset means webhook payloads are never purged |
| `FORGE_*` | blank for now | custom-domain TLS automation is unproven (DEPLOYMENT-READINESS 1.2 C) |

## 4. Nginx (Forge -> Site -> Files -> Edit Nginx Configuration)

The root is `/home/forge/staging.getmotiongrove.com/current/public` (Forge manages this line; check it ends in
`current/public`). Two edits are required:

```
# 1. REPLACE Forge's default robots block:
#      location = /robots.txt  { access_log off; log_not_found off; }
#    which answers from disk and returns 404 (there is deliberately no public/robots.txt), silently removing
#    the app's dynamic robots and every customer's Sitemap line. Use:
location = /robots.txt { try_files /dev/null /index.php?$query_string; }

# 2. Website images are capped at 8 MB:
client_max_body_size 10m;
```

Keep `upload_max_filesize >= 8M` and `post_max_size >= 10M` in the server's PHP settings. Never add a static
robots file or a CDN/server robots rule. Verify with `deploy/forge/post-deploy-check.sh`.

## 5. Feature gates: authoritative configuration path (all configuration; no code change needed)

### 5.1 Registration and sellable plans
- `ACCOUNT_CAN_REGISTER` (env; also Platform Owner -> Settings -> Security) turns the registration route on. A plan
  must also be **sellable** (`WorkspacePlanCatalog::isSellable()`): `is_active`, `available_for_signup`, a `price`,
  a `currency_id` **and** a `provider_price_id` (Stripe Price ID). Nothing sellable shows the closed signup state.
- The authoritative UI is **Platform Owner -> Platform plans** (`admin/platform-plans`): display name, active,
  *available for signup*, price, currency, cycle, trial, **Stripe Price ID**, with audited price history and a
  per-plan **blockers** list. Saving a Price ID verifies price/currency/cycle against Stripe, so **real test prices
  and Stripe test credentials are both required** first. No price or Price ID is hardcoded or invented.

### 5.2 Stripe (test mode only)
- `STRIPE_MODE=test`, test `STRIPE_KEY`/`STRIPE_SECRET`, and one signing secret per lane (section 3).
- Webhooks (test-mode dashboard): `/stripe/webhook/platform-subscriptions` (Core/Growth checkout needs this one),
  `/stripe/webhook/business-payments` and `/stripe/webhook/agency-subscriptions` (Connect),
  `/stripe/webhook/usage-billing`. Events: DEPLOYMENT-READINESS section 8 step 12.
- An **unsigned** POST must return `400`. With no `STRIPE_SECRET`, `usage-billing` answers 500 ("services.stripe.secret
  must not be empty"); with the secret set it refuses with 400.
- **Committed demonstration credential, fixed:** `database/seeders/PaymentMethodsSeeder.php` seeded an upstream
  demo Stripe test key pair into the legacy `payment_methods` table. Those literals are now blank, a regression test
  (`NoHardcodedGatewayCredentialsTest::test_no_stripe_key_literal_is_committed_in_application_code`) fails if any
  `sk_/pk_/rk_(test|live)_...` literal returns to `app/`, `config/`, `database/`, `routes/` or `resources/views/`, and the
  deploy script refuses live Stripe mode or live keys. V1 platform billing never reads that table (it uses `STRIPE_*`).
  Other legacy gateway rows (PayPal, Braintree, Razorpay, PayHere, EasyPay, Selcom) still seed upstream demo values;
  they are disabled and not Stripe, so they were left alone. After first install confirm none is enabled:
  `SELECT name, status FROM payment_methods WHERE status = 1;` must return no rows, and do not enable legacy gateways.

### 5.3 Branding
Login and registration render through `AuthBrandPresenter` (authorised Agency brand for the host, else the
Platform Owner name/illustration, else the neutral fallback); navigation uses `x-branding-logo` / `x-branding-favicon`;
Agency white-label resolves first. Set `APP_NAME` and `APP_TITLE`. Remaining hard-coded product copy ("Setting up
your Business OS...", "Business OS leads / outcomes", "AI Business Advisor recommendations", the neutral "AI
Business OS" fallback) is a copy decision, unchanged.

### 5.4 AI generation
- Kill switch `OPENAI_ACTIVE` (default `false`; also Platform Owner -> Settings), key `OPENAI_API_KEY`, model
  `OPENAI_MODEL` (default `gpt-4o`). Per-plan monthly workspace caps in `config/ai.php` (Core $5, Growth $10, Agency
  $25, trial $1.50) plus a platform cap `AI_PLATFORM_MONTHLY_CAP_MICROUSD` (default $20); a workspace without a valid
  plan has a zero cap.
- **Smallest safe real-AI smoke test** (needs owner approval and a key; none was used): set
  `AI_BUDGET_CORE_WORKSPACE_CAP_MICROUSD=500000` and `AI_PLATFORM_MONTHLY_CAP_MICROUSD=1000000`, then
  `OPENAI_API_KEY`, `OPENAI_ACTIVE=true`, `config:cache`; run **one** generation as a test Core account; read
  Platform Owner -> AI usage; set `OPENAI_ACTIVE=false` again. The owner pastes the key into Forge; never copy the
  developer machine's key.

## 6. Deployment lifecycle and safety (what `deploy.sh` + `release-build.sh` do, in order)

Forge variables (from Forge's docs): `FORGE_SITE_ROOT` = `/home/forge/<site>` (holds `current/`, `releases/`, the shared
`storage/` and `.env`); `FORGE_SITE_PATH` = `<site root>/current` (does **not** exist on a first deploy, so it is never
used); `FORGE_RELEASE_DIRECTORY` = the new release. Forge itself adds `cd $FORGE_RELEASE_DIRECTORY` after the create-release
macro; `deploy.sh` repeats it explicitly.

1. `$CREATE_RELEASE()`: Forge clones branch `staging` into a **new** release directory and links the shared `.env`
   and `storage/`. There is no `git pull` and no assumption about a mutable checkout; the live release is untouched.
2. Guards (refuse before building anything): not the `staging` branch; `.env` missing; a `*_testing` database;
   `APP_DEBUG=true`; empty `APP_KEY`; Stripe live mode or live keys; a static `public/robots.txt`.
3. `composer install --no-dev --prefer-dist --optimize-autoloader` (runs package discovery inside the release).
4. Shared state: creates the `storage/` skeleton and **links customer upload directories into shared storage**
   (below), then `storage:link --relative`.
5. `config:cache`, `route:cache`, `view:cache`, `event:cache`, built inside the new release.
6. `migrate --force`: additive migrations only, against the live DB **while the previous release still serves**
   (so every migration must be backward compatible; drop/rename in a later release). That is also what makes a Forge
   rollback safe.
7. `$ACTIVATE_RELEASE()`: Forge atomically repoints `current` and reloads PHP-FPM. Only now does traffic move.
8. `$RESTART_QUEUES()`: restarts the Forge queue daemons onto the new release. Scheduler-driven workers pick up new
   code on their next minutely run.

**Never in the script:** `platform:install`, any seeder, `migrate:fresh/refresh`, `db:wipe`, `key:generate`,
`git clean`, `rsync --delete`, npm/mix.

**Persistence across releases and rollbacks.** `public/` belongs to one release, but the app writes uploads there:
`images/websites` (including responsive `v/` derivatives), `images/business`, `images/branding/{logo,logo_compact,
logo_dark,favicon,auth_illustration,installer_illustration,agency}`, `images/logo`, `mms`, `voice`, `senderid_docs`.
`deploy.sh` replaces each with a symlink to `storage/app/shared-public/<same path>` (inside the shared `storage/`),
copying files a release ships (demo logos, sample voice XML) in once without overwriting. A deploy or Forge
rollback therefore never loses an upload. `storage/` (sessions, cache, logs, `storage/app`) and `.env` are shared by
Forge; the app writes `.env` through the symlink with `file_put_contents`, so Settings saves persist.
Uploads exist **only on disk**: include `storage/app/shared-public` in backups.
**Verify on the server after the second deploy:** `bash /home/forge/staging.getmotiongrove.com/current/deploy/forge/verify-uploads.sh /home/forge/staging.getmotiongrove.com`
(it checks every link, writes a probe through `current`, and reads it from the previous release).

**Confirm in the first deployment log:** that the build prints "Release <id> built; ready to activate." before
activation, and (on the server) that `releases/<id>/.env` and `releases/<id>/storage` are links into the site root. The
build script links them itself if Forge has not, so persistence does not depend on Forge's Shared Paths setting. If a
deployment fails with an error naming a variable, send the first 20 lines of the deployment output.

**If Forge reports a syntax error in the generated script:** it is raised by Forge's expansion of the deployment
editor, not by `release-build.sh` (which is plain bash and can be tested alone). The editor must contain only the 9
lines of `deploy.sh`: macros alone on their own lines, no comments that name a macro, no `set` options.

Other: no dedicated health route; `GET /login` -> 200 and `GET /` -> 302 are the readiness signals.
`bootstrap/cache/packages.php`/`services.php` are no longer tracked (stale; they fought package discovery).

## 7. First installation (separate, once, never from a deploy)

After the first successful deploy, over SSH as `forge`:

```
cd /home/forge/staging.getmotiongrove.com/current
php8.3 artisan platform:install --owner-email=<you@getmotiongrove.com>   # needs a real SSH terminal: the owner password is a concealed prompt
```
**Why this is required:** a deploy only runs migrations. The `app_config` settings rows (about 76, including
`custom_script` and `license`) are seeded by `platform:install` (its `db:seed` step). Before it has run, the login page
crashes with `Attempt to read property "value" on null` (the helper is now tolerant, but the platform is still not
configured). On a fresh, empty database this is safe: the only truncating seeders (`AppConfigSeeder`,
`PaymentMethodsSeeder`) have nothing of value to lose, and every other step is idempotent.

**Then, before the first owner login, the licence gate:** the seeded `app_config.license` is an empty string, and the
`ValidProduct` middleware on every admin and customer route sends a signed-in user to the legacy `/verify-purchase-code`
screen (a vendored CodeCanyon licence checker) while it is empty. That is a decision for the owner: either enter a valid
purchase code on that screen, or (the way the local RC environment was set up) store a non-empty value once:

```
php8.3 artisan tinker --execute="App\Models\AppConfig::where('setting','license')->update(['value'=>'staging-placeholder']); echo App\Models\AppConfig::where('setting','license')->value('value');"
```
Then check `SELECT count(*) FROM currencies;` = 12, `workspace_plan_catalog` has `core`, `growth`, `agency`, one
`is_admin` user, and the legacy-gateway query in section 5.2 returns no rows; sign in at `/login` as the owner.

**Do not re-run `platform:install` on a live instance.** It is idempotent for catalogs, but `db:seed` includes
`PaymentMethodsSeeder`, which **truncates `payment_methods`** and re-seeds it. Upgrades are deploys only.

## 8. Frontend assets and npm (the Forge "Missing script: build" error)

- `package.json` defines Laravel Mix scripts only: `development`, `watch`, `watch-poll`, `hot`, `production`
  (`mix --production`). **There is no `build` script**, and none should be invented. `webpack.mix.js` is the whole asset
  pipeline; there is no Vite.
- Compiled CSS/JS/vendors/fonts and `public/mix-manifest.json` are **committed**. Verified: all 95 `mix('...')`
  references in views resolve to a manifest entry and a file on disk. **No frontend build is needed or wanted on the
  server**; a build there would rewrite committed assets inside the release.
- **Forge setting:** frontend build **off**; no `npm` line in the deployment script. (If assets ever change:
  `npm ci && npm run production` locally, commit the output, deploy.)
- **`npm audit` (49 findings: 2 critical, 22 high, 18 moderate, 7 low)** was read from the lockfile only
  (`npm audit --package-lock-only`); nothing was installed, upgraded or fixed. Node is **never run in production**
  (no Node server), so findings in build tooling are not exposed to users:
  - the 2 criticals are `proxy-addr` and `shell-quote`, transitive packages of the dev server / `browser-sync`;
  - 48 of the 49 arise only from `devDependencies` (laravel-mix/webpack/sass/browser-sync/imagemin/...);
  - the one finding in the production-dependency tree (`brace-expansion`, high) comes via `glob`, which only
    `webpack.mix.js` uses at build time;
  - `axios` (high) is a devDependency and is **not** bundled into the shipped JS (`echo.js` only checks for a global).
  No dependency changes were made. Not covered by `npm audit`: third-party libraries copied into the committed
  `public/vendors/**`; review those separately if desired.

## 9. Validation performed in the repository (isolated test databases only)

Passing: `NoHardcodedGatewayCredentialsTest` (3, including the new Stripe-literal guard, shown to fail when a key is
reintroduced), `WebsiteInstallationSeedingTest` (2), `QuestionnaireV2ProvisioningTest` (6), `V1SignupTest` (11),
`PlatformOwnerControlsTest` (17), `WebhookActivationTest` (14), AI gateway/budget tests (44), brand presenter/fallback (16).
`release-build.sh` was exercised with stubbed composer/php on a model of a brand-new site (no `current/`, no shared
storage): it does not create `current/`, builds the shared storage at the site root, and every guard refuses its case
(debug, test DB, live mode, live key, empty key, wrong branch, static robots.txt, missing Forge variable). `deploy.sh`
contains each macro exactly once, alone on its line. Forge's real expansion is not available outside Forge, so the
generated-script syntax is **not** verified here. **Not exercised:** the symlink and
persistence logic (the build machine is Windows without symlink rights or WSL), Forge's real macros, `composer --no-dev`
(no Composer here), FPM/Nginx, cron, MySQL 8.4 on Linux, any live provider. `verify-uploads.sh` exists for that.

Known failures unrelated to this work: `V1SignupHttpTest` x2 (the local preview `.env` has `ACCOUNT_VERIFICATION=false`);
`AuthBrandTenantIsolationTest` x1 (stale Contract 13 fixture); `PaymentsContractsAcceptanceTest` (email-send token step;
identical on the unchanged preview).

## 10. Readiness check (after each deploy)

`bash deploy/forge/post-deploy-check.sh https://staging.getmotiongrove.com` (read-only GETs and unsigned webhook POSTs).
Readiness only; it is not acceptance.

## 11. End-to-end staging acceptance (all rows start "not run")

Use throwaway accounts and Stripe **test** cards (`4242 4242 4242 4242`).

| # | Flow | How | Needs from the owner | Status |
|---|---|---|---|---|
| 1 | Public registration | `/register` shows the closed state with no sellable plan; opens after step 2 | none | not run |
| 2 | Core/Growth selectable | Platform plans: price, currency, cycle, Stripe Price ID, *available for signup*; blockers empty | real test prices, Stripe test keys, Price IDs | not run |
| 3 | Sandbox checkout + webhook | sign up on Growth with a test card; `checkout.session.completed` delivered 2xx; account activates; `jobs` drains; `failed_jobs` = 0 | the four webhook secrets | not run |
| 4 | Onboarding + Location | finish onboarding; one Business and one Location exist | `BUSINESS_ONBOARDING_ENABLED=true` | not run |
| 5 | Hosted website setup | "Build with MotionGrove" wizard | none | not run |
| 6 | AI generation | one generation under the tiny cap (5.4) | approved OpenAI key | not run |
| 7 | Edit + staging publish | Studio edit, publish, open the URL; robots carries the `Sitemap:` line | none | not run |
| 8 | Inquiry form | submit the published site's form | none | not run |
| 9 | Contact + CRM opportunity | submission creates a Contact and an Opportunity in the right pipeline/Location | none | not run |
| 10 | Queue + scheduler | `schedule:list` shows 53 entries; `storage/cronJobAvailable` exists; a queued job drains in ~1 min | none | not run |
| 11 | Isolation | two Businesses/Locations each see only their own data | two test accounts | not run |
| 12 | Uploads survive deploy and rollback | upload a logo and a site image; deploy again; roll back once; both still load; `verify-uploads.sh` passes | none | not run |

## 12. Blockers and approvals needed from the owner

1. First deployment approval, and confirmation of the section 6 assumptions from its log.
2. Stripe **test** keys, the four webhook secrets, real test Price IDs and prices for Core and Growth.
3. Sandbox SMTP, or `ACCOUNT_VERIFICATION=false` for the first pass.
4. Approval and a key for the single OpenAI smoke test.
5. Decision on the hard-coded "Business OS" copy (5.3) and on the other legacy gateway demo values (5.2).
