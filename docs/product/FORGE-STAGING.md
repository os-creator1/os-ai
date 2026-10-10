# MotionGrove staging on Laravel Forge

First real staging server: `motiongrove-staging` (DigitalOcean NYC1, Ubuntu 24.04, PHP 8.3,
MySQL 8.4, 4 GB / 2 vCPU), site `staging.getmotiongrove.com`, deployed from the `staging` branch.

**Status: nothing here has been deployed or exercised on the server.** This document is the
repository-side preparation and the exact procedure. Section 8 lists the acceptance flows; every
row there starts as "not run" and must be marked only after it has actually been exercised.

Companion docs (read these, they are the measured detail): [`DEPLOYMENT-READINESS.md`](DEPLOYMENT-READINESS.md)
(env audit, scheduler, queue, web server, storage, checklist), [`production.env.example`](production.env.example)
(every variable), [`FRESH-INSTALL.md`](FRESH-INSTALL.md), [`PAYMENTS-LIVE-ACCEPTANCE.md`](PAYMENTS-LIVE-ACCEPTANCE.md).

## 1. What ships and from where

- Repository `os-creator1/os-ai`. **The Forge site tracks branch `staging` only.** `main` is not a deploy
  source and automatic deploys from it must stay off. Quick-deploy on `staging` is the user's call; the
  script below is safe to run on every push, but is also fine to trigger by hand.
- Compiled CSS/JS are committed (Laravel Mix output); **no Node build runs on the server.**
- Laravel 12, PHP `^8.2` (8.3 on the server). Required extensions (all present on a Forge PHP 8.3 server;
  confirm with `php -m` once): `ctype curl dom exif fileinfo gd intl json libxml mbstring openssl pcntl pdo
  pdo_mysql posix simplexml zip`.

## 2. One-time Forge site setup

1. **New site**: root domain `staging.getmotiongrove.com`, project type *General PHP / Laravel*, web directory
   `/public`, PHP 8.3. Install the repo `os-creator1/os-ai`, branch **`staging`**, no composer install at creation
   (the deploy script does it), "Create database" off (we create it below).
2. **Database**: create a new empty `utf8mb4` / `utf8mb4_unicode_ci` database (e.g. `motiongrove_staging`) and a
   dedicated user. Never a name containing `testing`: the deploy script refuses those.
3. **Environment** (Forge -> Environment): start from [`production.env.example`](production.env.example) and apply
   section 3 below. `.env` must stay writable by the site user: the Platform Owner Settings screens write to it.
4. **DNS + TLS**: point `staging` A record at the server, then Forge -> SSL -> LetsEncrypt.
5. **Nginx**: apply section 4 (the `robots.txt` block). This is required, not optional.
6. **Deployment script**: paste [`deploy/forge/deploy.sh`](../../deploy/forge/deploy.sh) into Forge -> Deployments.
7. **Scheduler**: Forge -> Scheduler -> command `php8.3 /home/forge/staging.getmotiongrove.com/artisan schedule:run`,
   frequency every minute, user `forge`. The app's queue consumer *is* a scheduled `queue:work` line
   (DEPLOYMENT-READINESS section 5.1), so the scheduler is mandatory.
8. **Queue worker** (recommended, in addition to the scheduler, never instead of it): Forge -> Queue -> connection
   `database`, queue `automation,default,batch`, tries `1`, timeout `900`, max-time `3600`, one worker.
9. **First install**, once, over SSH as `forge`, from the site directory, after the first deploy has run:
   ```
   php artisan platform:install --owner-email=<you@getmotiongrove.com>
   ```
   (enter the owner password at the concealed prompt; it is never an argument). It is idempotent but must not be
   part of the deploy script. Then `php artisan config:cache`.
10. Register the Stripe webhooks and provider callbacks (DEPLOYMENT-READINESS section 8 steps 12-13) using the
    **test-mode** dashboard.

## 3. Staging environment (deltas from `production.env.example`)

Secrets are supplied by the owner in Forge only. They are never committed, pasted into chat or logs.

| Variable | Staging value | Why |
|---|---|---|
| `APP_ENV` | `production` | the Google/Meta `fake` drivers throw in production; staging is a real server |
| `APP_DEBUG` | `false` | the deploy script refuses `true` |
| `APP_URL` | `https://staging.getmotiongrove.com` | exact origin; `TrustHosts` and OAuth/Stripe return URLs depend on it |
| `APP_KEY` | generated once on the server (`php artisan key:generate --force`, first install only) | **never** the key in `.env.example` (it is committed and public) |
| `APP_NAME` | `MotionGrove` | platform name shown on login/register/navigation |
| `APP_TITLE` | `MotionGrove` | page-title suffix (the local preview still says "Test Title") |
| `DB_*` | the staging database/user | |
| `QUEUE_CONNECTION` | `database` | never `sync` |
| `CACHE_DRIVER`, `SESSION_DRIVER` | `file` | one server |
| `SESSION_SECURE_COOKIE` | `true` | |
| `LOG_CHANNEL` / `LOG_LEVEL` | `daily` / `warning` | |
| `MAIL_*` | a **sandbox** SMTP (Mailtrap/Mailpit-style or a verified sender), never real customers | `MAIL_MAILER=smtp`; the app reads `MAIL_MAILER`, not `MAIL_DRIVER` |
| `ACCOUNT_VERIFICATION` | `true` for the real signup flow (needs working mail); `false` only to skip the email step | the local preview has `false`, which is why two signup tests fail there |
| `ACCOUNT_CAN_REGISTER` | `true` | sellable plans are still required (section 5.1) |
| `STRIPE_MODE` | `test` | no live charges |
| `STRIPE_KEY`, `STRIPE_SECRET` | **owner supplies test keys** | needed before a plan can be sold or a webhook endpoint can be built |
| `STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET`, `STRIPE_CONNECT_WEBHOOK_SECRET`, `STRIPE_AGENCY_SUBSCRIPTION_WEBHOOK_SECRET`, `STRIPE_WEBHOOK_SECRET` | the four test-mode signing secrets | four lanes, four endpoints, four different secrets |
| `GOOGLE_ADS_DRIVER`, `META_ADS_DRIVER` | `http` (or unset) | `fake` is refused in production |
| `EXTERNAL_SITE_AUDIT_DRIVER` | unset (`http`) | `fake` is a fixture driver |
| `SEO_RANK_TRACKING_ENABLED` | `false` | paid provider, fail-closed |
| `OPENAI_ACTIVE` / `OPENAI_API_KEY` | `false` / empty until section 5.4 | AI kill switch |
| `BUSINESS_ONBOARDING_ENABLED`, `BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS` | decide deliberately: `true` for the onboarding acceptance | default off; the scheduled sweeps no-op while off |
| `OPPORTUNITY_ENGINE_ENABLED`, `DOCUMENTS_ENABLED` | optional; they gate scheduled sweeps/reminders, not the pages | default off |
| `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` | `90` | unset means webhook payloads are never purged |
| `FORGE_*` | leave blank for now | custom-domain TLS automation is unproven (DEPLOYMENT-READINESS 1.2 C) |

## 4. Nginx: `/robots.txt` must reach Laravel (required)

Forge's default site template contains

```
location = /robots.txt  { access_log off; log_not_found off; }
```

With no `try_files`, nginx answers that from disk and returns **404** (there is deliberately no
`public/robots.txt`), which silently removes the app's dynamic robots and every customer's `Sitemap:` line.
In Forge -> Site -> Edit Files -> Edit Nginx Configuration, replace that block with:

```
location = /robots.txt { try_files /dev/null /index.php?$query_string; }
```

Also set `client_max_body_size 10m;` (website images are capped at 8 MB) and keep `upload_max_filesize >= 8M`,
`post_max_size >= 10M` in the server's PHP settings. Verify with `deploy/forge/post-deploy-check.sh` after deploy.

## 5. Feature gates: authoritative configuration path and what is code vs configuration

Verified by reading the code and by focused tests (section 7). **No code change was needed for any gate**; each
is configuration or an owner-supplied credential.

### 5.1 Registration and sellable plans: configuration only

- `ACCOUNT_CAN_REGISTER` (env, also writable from Platform Owner -> Settings -> Security) turns the registration
  *route* on. A plan must additionally be **sellable**: `WorkspacePlanCatalog::isSellable()` requires
  `is_active`, `available_for_signup`, a `price`, a `currency_id` **and** a `provider_price_id` (Stripe Price ID).
  If nothing is sellable the signup controller shows the closed state.
- The authoritative UI is **Platform Owner -> Platform plans** (`admin/platform-plans`, `PlatformPlansController`
  -> `PlatformPlanAdministrator`). It edits display name, active, *available for signup*, price, currency, billing
  cycle, trial, **Stripe Price ID** and the slot-price ratio, with audited price history. Each plan card lists its
  **blockers** (`PlatformPlanPresenter::blockers`), so the owner sees exactly why a tier is not sellable. Nothing
  is hardcoded; no seeder supplies prices.
- When a Stripe Price ID is saved the administrator verifies it against price, currency and billing cycle through
  `PlatformPriceVerifier` using the configured Stripe secret (`provider_price_id` validation error otherwise). So
  **real test prices and test Stripe credentials are both required before Core or Growth can be switched on.**
  This task did not and must not invent prices, Price IDs or keys.

### 5.2 Stripe checkout and webhooks: configuration only

- Needs `STRIPE_KEY` + `STRIPE_SECRET` (test), `STRIPE_MODE=test`, and one signing secret per lane (section 3).
- Webhook endpoints on the staging host (create them in the **test-mode** dashboard):
  `/stripe/webhook/platform-subscriptions` (signup/plan subscriptions, the one the Core/Growth checkout needs),
  `/stripe/webhook/business-payments` (Connect), `/stripe/webhook/agency-subscriptions` (Connect),
  `/stripe/webhook/usage-billing`. Events per DEPLOYMENT-READINESS section 8 step 12.
- Readiness check: an **unsigned** POST to each must return `400`. On a host with no `STRIPE_SECRET` the
  `usage-billing` endpoint currently answers `500` ("services.stripe.secret must not be empty") instead of a 4xx
  because its gateway cannot be built; with the secret set it refuses with 400. Not a blocker; noted so the
  readiness script's expectation is understood.
- Local Stripe CLI exists on the owner's machine (`stripe listen`), but staging uses real dashboard webhooks.

### 5.3 Branding: wired; one setting and some copy to know about

- Login and registration render through `AuthBrandPresenter` (an authorised Agency brand for the request host,
  else the Platform Owner's configured name/illustration, else the neutral fallback). The sidebar and favicon use
  the `x-branding-logo` / `x-branding-favicon` components. Agency white-label resolves first
  (`AgencyBrandResolver`), so an Agency override takes precedence over the platform brand.
- Verified on the running preview: `/login` and `/register` titles and body say **MotionGrove**; the navigation
  logo `alt` is MotionGrove. The platform name comes from `APP_NAME`/Platform Settings.
- **Not wired to the platform name:** `APP_TITLE` is the page-title suffix on application pages (the preview
  shows "Test Title"): set it in Platform Settings or env. Several customer-facing strings are hard-coded product
  copy rather than the platform name: "Setting up your Business OS..." (signup provisioning screen), "Business OS
  leads / outcomes" (Ads leads pages), "AI Business Advisor recommendations" (Analytics), and the neutral
  "AI Business OS" fallback panel. These are copy decisions, left unchanged (tests may pin them); decide whether
  they should read as the platform name.

### 5.4 AI generation: the kill switch, key and budget

- Gate 1 is `OPENAI_ACTIVE` (default `false`): `AiGateway` returns `AiDisabled` and spends nothing. It is also
  switchable from Platform Owner -> Settings (written to `.env` by `PlatformSettingsEnvWriter`). The key is
  `OPENAI_API_KEY`; model `OPENAI_MODEL` (default `gpt-4o`).
- Gate 2 is the per-plan monthly **workspace cap** (config/ai.php, micro-USD): Core `5_000_000` ($5), Growth
  `10_000_000` ($10), Agency `25_000_000`, trial `1_500_000`; plus a platform cap `AI_PLATFORM_MONTHLY_CAP_MICROUSD`
  (default `20_000_000`) and per-category Business ceilings. A workspace with no valid plan resolves to a **zero cap**
  and is refused (`BudgetExhausted`).
- **Smallest safe real-AI smoke test** (needs the owner's approval and key; none was used here):
  1. In Forge env set a deliberately tiny cap first, e.g. `AI_BUDGET_CORE_WORKSPACE_CAP_MICROUSD=500000`
     (50 cents) and `AI_PLATFORM_MONTHLY_CAP_MICROUSD=1000000` ($1), then `OPENAI_API_KEY=<owner's key>`.
  2. `OPENAI_ACTIVE=true`, `php artisan config:cache`.
  3. As a *test* Core account, run **one** website generation (or one service description) and read
     Platform Owner -> AI usage for the recorded cost.
  4. Set `OPENAI_ACTIVE=false` again unless continuing acceptance.
  Do not copy the key that exists on the developer machine; the owner pastes a key into Forge directly.

## 6. Deployment safety

- The deploy script is the standard in-place Forge `git pull`. It never runs `platform:install`, a seeder,
  `migrate:fresh/refresh`, `db:wipe`, `git clean`, `key:generate`, `rsync --delete` or a Node build, so a deploy
  cannot reset customer data or delete uploads. Migrations are additive (`migrate --force`).
- **Uploads** (`public/images/websites/**`, `public/images/business/**`, `public/images/branding/**` except the
  three tracked `default-*.svg`, `public/mms`) and `storage/app` are untracked on disk. An in-place `git pull`
  leaves them alone. They are now git-ignored so they cannot be committed by accident. They are **not** in the
  database or repository: include them in backups. Never switch this site to zero-downtime/release-directory
  deployment without first symlinking those paths to shared storage (DEPLOYMENT-READINESS section 7).
- `bootstrap/cache/packages.php` and `services.php` were committed (stale, without Blade Icons) and fought every
  `composer install`/`package:discover`. They are untracked and the directory is kept with a `.gitignore`.
- 30 test-residue images under `public/images/websites/<uuid>/` (about 5 MB, from one earlier commit) are removed
  from the tree and ignored.
- Readiness probe: there is no dedicated health route; `GET /login` -> `200` and `GET /` -> `302` are the safe
  existing signals. `APP_STAGE=new` would make `/` answer 503.
- Sessions, cache and queue use `file`/`file`/`database` on this single server.

## 7. Verification performed in the repository (isolated test database only)

Passing: `V1SignupTest` (11), `PlatformOwnerControlsTest` (17), `WebhookActivationTest` (14), `AiGatewayTest` (20),
`AiGatewayPlatformScopeTest` (16), `AiBudgetPolicyResolverPlatformTest` (8), `AuthBrandPresenterTest` (7),
`BrandingPresenterFallbackTest` (9), and the document/invoice UI set.

Failing, none caused by the deployment changes:
- `V1SignupHttpTest`: 2 failures (`...sends_the_email_verification_notification`,
  `...success_endpoint_activates_from_provider_truth_and_hands_off_to_verification`) because the local
  preview `.env` has `ACCOUNT_VERIFICATION=false`; they expect it on (default `true`).
- `AuthBrandTenantIsolationTest`: 1 error, a stale fixture (adds a second Business to one Workspace, which
  Contract 13 forbids).
- `PaymentsContractsAcceptanceTest`: fails at the email-send token step, identically on the unchanged preview.

Not verifiable without a server: `composer install --no-dev` (no Composer on the build machine; nothing in
`app/`, `config/`, `routes/` references the dev-only packages), PHP-FPM/Nginx behaviour, real cron/queue, MySQL
8.4 on Linux, any live provider.

## 8. End-to-end staging acceptance (procedure; all rows start "not run")

Run after sections 2-3 are done, using throwaway accounts and Stripe **test** cards (`4242 4242 4242 4242`).
First: `bash deploy/forge/post-deploy-check.sh https://staging.getmotiongrove.com` (readiness only).

| # | Flow | How | Needs from the owner | Status |
|---|---|---|---|---|
| 1 | Public registration | open `/register`; with no sellable plan it must show the closed state; after step 2 it must open | none | not run |
| 2 | Core/Growth selectable | Platform Owner -> Platform plans: set price, currency, cycle, Stripe Price ID, *available for signup*; blockers list must be empty | real test prices, Stripe test keys and Price IDs | not run |
| 3 | Sandbox checkout + webhook | sign up on Growth with a test card; confirm `checkout.session.completed` reaches `/stripe/webhook/platform-subscriptions` (Stripe dashboard: delivered 2xx), the account activates, `jobs` drains, `failed_jobs` stays 0 | the four webhook secrets | not run |
| 4 | Onboarding + Location | finish onboarding; confirm one Business and one Location exist | `BUSINESS_ONBOARDING_ENABLED=true` | not run |
| 5 | Hosted website setup | choose "Build with MotionGrove", complete the wizard | none | not run |
| 6 | AI generation | one generation under the tiny cap (section 5.4) | owner-approved OpenAI key | not run |
| 7 | Edit + staging publish | edit in Studio, publish, open the published URL; `robots.txt` carries the `Sitemap:` line | none | not run |
| 8 | Inquiry form | submit the published site's form | none | not run |
| 9 | Contact + CRM opportunity | the submission creates a Contact and an Opportunity in the right pipeline/Location | none | not run |
| 10 | Queue + scheduler | `schedule:list` shows 53 entries; `storage/cronJobAvailable` exists; a queued job drains in ~1 min | none | not run |
| 11 | Isolation | with two Businesses/Locations, confirm each sees only its own contacts, opportunities, forms and website | two test accounts | not run |
| 12 | Uploads survive a deploy | upload a logo and a website image, redeploy once, confirm both still load | none | not run |

## 9. Blockers and approvals needed from the owner

1. Forge access or a server shell to run sections 2 and 8 (not available to this task).
2. Stripe **test** keys, the four webhook signing secrets, and test **Price IDs** for Core and Growth with the
   chosen prices/currency (nothing was invented).
3. A sandbox SMTP, or `ACCOUNT_VERIFICATION=false` for the first pass.
4. Approval and a key for the one real OpenAI smoke test, and the tiny caps.
5. A decision on the hard-coded "Business OS" copy (section 5.3), and awareness of one tracked key-like string:
   `database/seeders/PaymentMethodsSeeder.php` contains an upstream demo Stripe **test** secret/publishable pair.
   `platform:install` seeds it into `payment_methods` as a **disabled, sandbox** legacy row (`status=false`). It is not
   read by the V1 platform billing (which uses `STRIPE_*` env). Leave that legacy row disabled, and consider
   removing the literal from the seeder in a later change; the string is already in the repository history.
6. Pushing the `staging` branch: see the delivery report; the remote branch needs the owner's decision.
