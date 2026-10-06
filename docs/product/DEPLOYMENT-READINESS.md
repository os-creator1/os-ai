# V1 deployment readiness

Fresh-install verification, production environment audit, scheduler/queue audit, web-server
requirements, storage requirements and the one exact deployment checklist.

Nothing here was deployed. Everything below was measured against branch
`agent/v1-deployment-readiness` (base `origin/agent/v1-completion-integration` @ `6c42beb3`)
except where a row says **not verifiable here**.

Companion docs: [`FRESH-INSTALL.md`](FRESH-INSTALL.md) (the install command),
[`PAYMENTS-LIVE-ACCEPTANCE.md`](PAYMENTS-LIVE-ACCEPTANCE.md) (live Stripe acceptance),
[`production.env.example`](production.env.example) (the variable template).

---

## 1. Findings

### 1.1 Fixed on this branch

| # | Finding | Fix |
|---|---|---|
| 1 | **`platform:install` was not idempotent.** `CurrenciesSeeder` used `Currency::create()`, so every run inserted another 12 currencies (12 → 24 → 36 over three runs, still 12 distinct codes). `FRESH-INSTALL.md` promised "every step is idempotent". | `database/seeders/CurrenciesSeeder.php` now uses `firstOrCreate(['code' => …])`. Pinned by `tests/Feature/Console/CurrenciesSeederIdempotencyTest.php`. |
| 2 | **`usage:spending-threshold-alerts` was never scheduled.** The command (Slice 5 §12.2, "alerts before thresholds") was built and tested, but nothing ran it, so no billing contact was ever warned before a spending limit. Its own docblock says it is safe on any schedule. | Registered hourly + `withoutOverlapping()` in `app/Console/Kernel.php`. Pinned by `tests/Feature/Console/ProductionSchedulerRegistrationTest.php`. |

### 1.2 Open — needs a human decision or an operator action

| # | Finding | Why it matters |
|---|---|---|
| A | **`/robots.txt` must be routed through Laravel by the web server (operator action).** Website V1 final (`94bc4c1c`) made robots dynamic: the platform host answers from `Public\RobotsController` (route `public.robots`, allow-all, byte-identical to the old static file) and every published Website's primary custom domain answers from `ResolveCustomDomainWebsite::renderRobots()` with that site's own `Sitemap: https://<domain>/sitemap` line. The tracked static `public/robots.txt` **no longer exists** and must never be reintroduced. (The earlier version of this runbook, written against an older base, described the static file; that no longer applies.) | If the web server answers `/robots.txt` itself (a static file, an `Alias`, a `return 200` rule, a CDN robots rule) or never hands the request to Laravel for a customer domain, no Website domain gets its `Sitemap:` line. Use the Nginx block in §6.2 (enabled) and the Apache note in §6.3; verify with the §6.4 smoke check. |
| B | `.env.example` is a Laravel-5-era dev template: `QUEUE_CONNECTION=sync`, `MAIL_DRIVER` (the app reads `MAIL_MAILER`), and a **committed real-looking `APP_KEY`**. Copying it to production gives a shared, public encryption key and an inline queue. | Do not use it for production. Use `production.env.example`; always run `php artisan key:generate`. Not changed on this branch (dev flows depend on it). |
| C | **Custom-domain TLS automation is Forge-only and unproven.** `ForgeDomainProvisioner` states "NOT WIRED UP FOR A REAL DEPLOYMENT YET"; no test calls the real Forge API. | On any non-Forge host, certificates for customer domains are a manual/other-tooling job (§6.5). |
| D | `public/mix-manifest.json` lists 2 entries with no file on disk: `/js/scripts/customizer.js`, `/images/backgrounds/plugin.svg` (1189 entries, 1187 present). | Pre-existing and harmless unless a view calls `mix()` on one of them. No front-end build is needed to deploy (compiled assets are committed). |
| E | Legacy upstream SMS commands are intentionally unscheduled (§4.2). | Listed so nobody mistakes them for gaps. |

---

## 2. Fresh install — what was run and what was proven

**Environment:** PHP 8.3.30, MySQL 8.4.3 (Laragon, Windows). **Databases (both disposable
`TestDatabaseSafety`-approved siblings, never the canonical one):**
`ultimatesms_testing_deploy_readiness` (install) and `ultimatesms_testing_deploy_tests`
(PHPUnit). Configuration was passed as process environment variables only — no `.env` file
was written and no tracked file was modified by the runs.

| Step | Result |
|---|---|
| Start from an empty database | `CREATE DATABASE … utf8mb4_unicode_ci`, 0 tables. |
| `platform:install --skip-owner` | Exit 0 in 2m40s on this machine. Migrations ran from empty to head (the run ended at `2026_11_04_090002_create_appointment_notifications_table`) with no error. `administrator` role seeded; stops and says to create the owner. |
| `platform:create-owner` | Created user id 1, `is_admin = 1`. Password is read only from the concealed prompt (piped here); never an argument. |
| `platform:install` (full) | Exit 0 in 23s. All seven documented steps ran. |
| Re-run `platform:install` (×3 more) | Exit 0 each time. Output: "Nothing to migrate", "An administrator already exists; skipping owner creation", every Blueprint/template step reports "already … nothing to do". |
| Row counts before vs after re-runs | **Identical for all 270 tables** after fixing finding 1.1 #1 (before the fix `currencies` grew 12 per run). Verified a second time after the fix with two further full runs. |

Seeded state after install (all counts stable across re-runs):

| Requirement | Evidence |
|---|---|
| Migrations | 270 tables created. |
| Currencies | `currencies` = 12 rows, 12 distinct codes. |
| Core / Growth / Agency | `workspace_plan_catalog` = `core`, `growth`, `agency`, all `is_active`; `workspace_plan_features` = 49 rows. The legacy SMS `plans` table (Free, Standard, DLT Enabled, Enterprise) is separate and also seeded. |
| Platform Owner | `users` = 1 (`is_admin`), `roles` = `administrator`, `role_user` = 1. |
| Photo Booth Blueprint | `niche_blueprints` = 1, `niche_blueprint_versions` = 3 (v1 published, v2 templates, v3 full configuration), `niche_blueprint_components` = 28 (3 CRM pipelines, 4 custom fields, 1 tag set, 1 form, 1 booking type, 4 package templates, 3 automation workflows, 8 document templates, website config, SEO strategy, citation recommendations). |
| Website templates | `website_templates` = 4. `question_packs` = 2; Photo Booth website questionnaire `questionnaire_versions` = 2 (v1 → v2). |
| Document templates | `document_templates` = 4 (the Photo Booth proposal/contract set). |
| Default recipes | **Not database rows.** Platform Automation recipes (`PlatformAutomationRecipes`/`PlatformAutomationCatalog`) and CRM business templates are defined in code, so there is nothing to seed and nothing to be missing. |
| Other catalogs | `countries` 232, `languages` 14, `email_templates` 11, `payment_methods` 29, `permissions` 106, `app_config` 76, `senderid_plans` 7, `platform_theme_presets` 4, `seo_citation_directories` 15. |
| Scheduler prerequisites | `schedule:list` boots and registers **53** entries (52 before finding 1.1 #2). `storage/cronJobAvailable` is created by the first scheduler boot. A real `schedule:run` cron is still required (§4). |
| Queue | The `jobs` and `failed_jobs` tables exist after migrate; the default connection is `database` (§5). |
| Production caches | `route:cache`, `config:cache`, `view:cache` and `event:cache` all succeed (no closure routes, no unserialisable config). They were cleared again afterwards. |
| `storage:link` | Succeeded (`public/storage` → `storage/app/public`, git-ignored). It is only needed for the `public` disk (§7). |

**Not verifiable here:** a real MySQL 8 on Linux, `php-fpm`, Nginx/Apache, a real cron, a real
supervisor, any live provider (Stripe, Telnyx, Google, Meta, DataForSEO). The PHP CLI used here
does not load `pcntl`/`posix` (Windows); `composer.json` requires both — a Linux PHP has them.

---

## 3. Environment audit

Source of truth: every `env('…')` in `config/`, `app/`, `routes/`, `bootstrap/`, `database/`.
Values are never printed here. Template: [`production.env.example`](production.env.example).

**Classes**

- **required** — the app does not boot, is insecure, or silently misbehaves without it.
- **optional** — has a safe default; set only to tune.
- **external-provider-only** — needed only if you switch that provider's feature on. Left blank,
  the feature shows its readiness state (nothing is sent to the provider).

Several settings are also written back to `.env` by the Platform Owner's Settings screens
(mail, sign-in/security, social login, AI, app name/locale/timezone) through
`PlatformSettingsEnvWriter`. **`.env` must therefore be writable by the PHP user**, and each save
clears the config cache — re-run `php artisan config:cache` after changing settings in the UI.

### APP

| Variable | Class | Notes |
|---|---|---|
| `APP_ENV` | required | `production`. The Google/Meta `fake` drivers throw only when this is `production`. |
| `APP_KEY` | required | `php artisan key:generate`. **Never rotate casually**: stored webhook payloads are encrypted with it (RFC-005 §11). |
| `APP_DEBUG` | required | `false`. |
| `APP_URL` | required | Exact public `https://` origin of the platform host. Used to tell the platform host from a customer custom domain (`ResolveCustomDomainWebsite`, `TrustHosts`), for OAuth/Stripe return URLs and the `public` disk URL. |
| `APP_NAME`, `APP_TITLE` | optional | UI-writable. |
| `APP_STAGE` | optional | Default `Live`. `new` makes `/` answer 503 "run platform:install". Leave unset after install. |
| `URL_FORCE_HTTPS` | optional | Set `true` when TLS terminates at a proxy/CDN: `TrustProxies::$proxies` is null, so forwarded-proto is not trusted by default. |
| `ASSET_URL` | optional | Only for a CDN. |
| `SESSION_DRIVER` | optional | `file` default is fine on one host; use `database`/`redis` for more than one. |
| `SESSION_SECURE_COOKIE` | optional | Set `true` in production. |
| `SESSION_DOMAIN`, `SESSION_LIFETIME` | optional | |
| `LOG_CHANNEL`, `LOG_LEVEL` | optional | `daily` recommended (§9). |
| `SUPER_ADMIN_EMAIL`, `ADMIN_PATH`, `PURCHASE_CODE`, `LEGACY_MESSAGING_MENU`, `ULANDING_ENABLED`, `USUPPORT_ENABLED` | optional | Inherited upstream switches. |
| `BUSINESS_ONBOARDING_ENABLED`, `BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS`, `OPPORTUNITY_ENGINE_ENABLED`, `DOCUMENTS_ENABLED` | optional | All **default off**. Decide deliberately; the scheduled sweeps no-op while off. |
| `APP_LOCALE`, `APP_TIMEZONE`, `APP_COUNTRY`, `APP_DATE_FORMAT`, `APP_TIME_FORMAT`, `APP_KEYWORD`, `APP_FOOTER_*`, `APP_*_LOGO`, `THEME_*` | optional | UI-writable branding/locale. |

### DB

| Variable | Class | Notes |
|---|---|---|
| `DB_CONNECTION` | required | `mysql`. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | required | A least-privilege user (needs DDL for `migrate`). **Never** a database whose name `TestDatabaseSafety` accepts (`ultimatesms_testing*`). |
| `DB_SOCKET`, `DB_PREFIX`, `DB_FOREIGN_KEYS`, `DATABASE_URL`, `MYSQL_ATTR_SSL_CA` | optional | |

### CACHE

| Variable | Class | Notes |
|---|---|---|
| `CACHE_DRIVER` | required (explicit) | Default `file`. Must be shared by web, scheduler and workers (`file` on one host; `redis`/`database` on several) and must support atomic locks — `withoutOverlapping()` and the custom-domain host trust list use the cache. **Never `array`.** |
| `CACHE_PREFIX` | optional | |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_CLIENT`, `REDIS_DB`, `REDIS_CACHE_DB`, `REDIS_PREFIX`, `REDIS_URL` | external-provider-only | Only if a Redis driver is chosen. |

### QUEUE

| Variable | Class | Notes |
|---|---|---|
| `QUEUE_CONNECTION` | required | **`database`.** Not `sync` (§5.3). `.env.example` ships `sync`. |
| `QUEUE_FAILED_DRIVER` | optional | Default `database-uuids` → `failed_jobs`. |
| `REDIS_QUEUE` | external-provider-only | Redis queue only. (`config/horizon.php` exists but Horizon is not part of this deployment.) |
| `BUSINESS_ONBOARDING_ANALYSIS_QUEUE`, `OPPORTUNITY_ENGINE_QUEUE`, `DOCUMENTS_QUEUE`, `COO_INSIGHT_QUEUE` | optional | All default `default`. **Do not change**: the scheduled worker only drains `automation,default,batch`; a job on any other queue name is never run. |

### MAIL

| Variable | Class | Notes |
|---|---|---|
| `MAIL_MAILER` | required | Not `MAIL_DRIVER` (legacy; `config/mail.php` no longer reads it). |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | required | Document links, booking notifications, announcements and account mail all depend on it. UI-writable. |
| `MAILGUN_*`, `POSTMARK_TOKEN`, `SPARKPOST_SECRET`, `AWS_*` (SES) | external-provider-only | Only for those mailers. |

### STRIPE

Four lanes, four endpoints, four **different** signing secrets. All secrets stay in the environment.

| Variable | Class | Notes |
|---|---|---|
| `STRIPE_KEY`, `STRIPE_SECRET` | external-provider-only | Required to take any payment or run paid plans. |
| `STRIPE_MODE` | required once Stripe is on | Default `test`. Set `live` deliberately. |
| `STRIPE_API_VERSION` | optional | |
| `STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET` | external-provider-only | Lane A — platform plan subscriptions → `/stripe/webhook/platform-subscriptions`. |
| `STRIPE_CONNECT_WEBHOOK_SECRET` | external-provider-only | Lane B — Business document payments (Connect) → `/stripe/webhook/business-payments`. |
| `STRIPE_AGENCY_SUBSCRIPTION_WEBHOOK_SECRET` | external-provider-only | Lane C — Agency SaaS client subscriptions (Connect) → `/stripe/webhook/agency-subscriptions`. |
| `STRIPE_WEBHOOK_SECRET` | external-provider-only | Lane D — wallet/usage billing → `/stripe/webhook/usage-billing`. |
| `STRIPE_AGENCY_CONNECT_CLIENT_ID` | external-provider-only | Only for "Connect existing Stripe account" (Agency). A public identifier, not a secret. |
| `STRIPE_*_WEBHOOK_TOLERANCE` | optional | Default 300 s. |
| `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` | optional but **recommended** | **Unset ⇒ webhook payloads are never purged** (RFC-005 §10). |
| `USAGE_BILLING_WEBHOOK_LEASE_MINUTES`, `USAGE_BILLING_WEBHOOK_MAX_ATTEMPTS`, `USAGE_BILLING_CONVERSATIONS_PILOT_*` | optional | |

### TELNYX

| Variable | Class | Notes |
|---|---|---|
| `TELNYX_API_KEY` | external-provider-only | The single platform credential (never stored per Business). |
| `TELNYX_WEBHOOK_PUBLIC_KEY` | external-provider-only | Verifies inbound webhooks. |
| `TELNYX_MODE` | required once Telnyx is on | Default `sandbox`. |
| `MANAGED_MESSAGING_ENABLED`, `MANAGED_MESSAGING_PROVISIONING_ENABLED` | optional | Both default **false**; key presence alone activates nothing. |
| `MESSAGING_NUMBER_*`, `MESSAGING_WEBHOOK_REJECTION_RETENTION_DAYS` | optional | Renewal/retention tuning. |

### GOOGLE (and Microsoft OAuth, which shares the same callback pattern)

| Variable | Class | Notes |
|---|---|---|
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT`, `SOCIALITE_GOOGLE` | external-provider-only | Platform **sign-in** only. UI-writable. |
| `GOOGLE_BUSINESS_PROFILE_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT` | external-provider-only | Dedicated client. Redirect = `https://<host>/gbp/oauth/callback`. |
| `GOOGLE_ADS_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT` | external-provider-only | Dedicated `adwords` client. Redirect = `https://<host>/ads/oauth/callback`. |
| `GOOGLE_ADS_DRIVER` | optional | `http` (default). `fake` **throws** in production. |
| `GOOGLE_ADS_DEVELOPER_TOKEN` | optional | Header is sent only when non-empty. |
| `GOOGLE_CALENDAR_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT` | external-provider-only | Redirect = `https://<host>/calendar-connection/oauth/google/callback`. |
| `MICROSOFT_CALENDAR_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT`, `_TENANT` | external-provider-only | Redirect = `https://<host>/calendar-connection/oauth/outlook/callback`. |
| `BUSINESS_EMAIL_GOOGLE_*`, `BUSINESS_EMAIL_MICROSOFT_*` | external-provider-only | Business inbox connection. Redirect = `https://<host>/email/oauth/{provider}/callback`. |
| `GOOGLE_ADS_*`, `GOOGLE_BUSINESS_PROFILE_*`, `CALENDAR_EXTERNAL_*` tuning keys | optional | Budgets, breakers, timeouts, lookbacks. |

### META

| Variable | Class | Notes |
|---|---|---|
| `META_ADS_APP_ID`, `META_ADS_APP_SECRET`, `META_ADS_REDIRECT_URI` | external-provider-only | Redirect = `https://<host>/ads/meta/oauth/callback`. |
| `META_ADS_DRIVER` | optional | `http` default; `fake` throws in production. |
| `FACEBOOK_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT`, `SOCIALITE_FACEBOOK` | external-provider-only | Sign-in only. |
| `META_ADS_*` tuning keys | optional | |

### DATAFORSEO

| Variable | Class | Notes |
|---|---|---|
| `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | external-provider-only | **Paid** provider. |
| `SEO_RANK_TRACKING_ENABLED` | optional | Default **false** (fail-closed master switch). Spend caps (`SEO_RANK_*_CAP_MICROS`) can only be lowered from the documented defaults. |
| `DATAFORSEO_BASE_URL`, `SEO_*` | optional | |

### FILES / STORAGE

| Variable | Class | Notes |
|---|---|---|
| `FILESYSTEM_DISK` | optional | `local` default. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_URL`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` | external-provider-only | S3 only. **Website, Business and branding images do not use it** — they are written under `public/` (§7). |

### WEBHOOKS / OTHER PROVIDERS

| Variable | Class | Notes |
|---|---|---|
| `PLATFORM_AUTOMATION_WEBHOOK_SECRET` | external-provider-only | Signs Platform Automation "Call a webhook" steps (`X-Platform-Signature`). Required only if such a step is used. |
| `FORGE_API_TOKEN`, `FORGE_ORGANIZATION_SLUG`, `FORGE_SERVER_ID`, `FORGE_SITE_ID` | external-provider-only | Custom-domain certificate provisioning (finding 1.2 C). |
| `FORGE_CNAME_TARGET`, `FORGE_A_RECORD_IP` | required to offer custom domains | The DNS instructions shown to the owner. Not secrets. |
| `OPENAI_ACTIVE`, `OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_ORGANIZATION`, `OPENAI_PROJECT`, `OPENAI_ROLE` | external-provider-only | Master switch default **false**. UI-writable. |
| `AI_*`, `COO_*`, `OPPORTUNITY_*`, `GROWTH_*` | optional | Budgets/prices/models with documented defaults. |
| `NOCAPTCHA_SITEKEY`, `NOCAPTCHA_SECRET`, `NOCAPTCHA_IN_LOGIN`, `NOCAPTCHA_IN_REGISTRATION` | external-provider-only | reCAPTCHA; UI-writable. |
| `PUSHER_*`, `ABLY_KEY`, `BROADCAST_DRIVER` | external-provider-only | Realtime; default `null`. |
| `IPINFO_TOKEN`, `IPDATA_TOKEN`, `IP2LOCATIONIO_TOKEN`, `IP_API_TOKEN`, `KLOUDEND_TOKEN`, `MAXMIND_*` | external-provider-only | IP geolocation. |
| `FLARE_KEY`, `LOG_SLACK_WEBHOOK_URL`, `PAPERTRAIL_*` | external-provider-only | Error/log shipping. |

---

## 4. Scheduler audit

Laravel's scheduler **must** be driven by cron — nothing below runs without it:

```
* * * * * cd /var/www/os-ai/current && php artisan schedule:run >> /dev/null 2>&1
```

Run it as the same user as the web/queue processes (it writes `storage/` files).

### 4.1 Registered (`app/Console/Kernel.php`, verified with `php artisan schedule:list`: 53 entries)

| Area | Entries |
|---|---|
| **Queue worker** | `queue:work --queue=automation,default,batch --timeout=120 --tries=1 --max-time=180 --stop-when-empty` every minute |
| **Automations** | `automation:run` (5m), `automation:workflows-recover-stalled` (5m), `automation:workflows-resume-due` (1m), `automation:workflows-date-sweep` (5m), `outreach:dispatch-due-followups` (5m) |
| **Platform Automations** | `platform-automation:sweep` (15m, no-overlap) |
| **Announcements** | `platform-announcements:sweep` (1m, no-overlap) |
| **Booking reminders** | `calendar:dispatch-due-reminders` (1m); `calendar:sync-external-connections` (15m, no-overlap) |
| **Rank sync (SEO)** | `ScheduleSeoRankChecks` (hourly), `ProcessSeoRankChecks` (5m), `PruneSeoRankObservations` (03:40), `seo:rank-sync-locations` (Mon 04:20) |
| **Ads sync** | `SweepGoogleAdsSyncs` (02:40), `SweepMetaAdsSyncs` (03:10) |
| **Google Business Profile** | `PurgeExpiredGoogleBusinessProfileMirrors` (hourly), `SweepGoogleBusinessProfileRefreshes` (daily) |
| **Messaging / carrier** | `RefreshPendingMessagingRegistrations` (15m), `RefreshPendingCampaignAssignments` (15m), `messaging:sweep-number-renewals` (daily), `messaging:purge-webhook-rejections` (daily) |
| **Documents / payments** | `documents:expire-due` (15m), `documents:dispatch-due-reminders` (hourly), `documents:dispatch-balance-requests` (hourly), `documents:reconcile-stale-payments` (5m), `PurgeExpiredWebhookPayloads` (hourly), `ReconcileProviderPendingState` (5m), `RetryStuckPaymentProviderEvents` (5m) |
| **Billing / entitlement** | `platform-subscriptions:apply-due-plan-changes` (hourly), `agency-subscriptions:apply-due-plan-changes` (hourly), `workspaces:advance-account-lifecycle` (hourly), `subscription:check` (hourly), `InitiateSlotAgreementRenewal` / `FinalizeSlotAgreementCancellation` (5m), `ReconcileSlotAgreementAllocation` (hourly), `ExpireStaleUsageReservations` (5m), **`usage:spending-threshold-alerts` (hourly — added)** |
| **AI / insight** | `ExpireStaleAiReservations` (5m), `coo:dispatch-insight-reviews` (04:10 daily + 1st of month 05:10), `opportunity:sweep-expired-snoozes`, `opportunity:dispatch-business-advisor` (03:10), `growth:evaluate` (03:40) |
| **Inherited SMS** | `campaign:recurring`, `campaign:scheduled`, `sms:schedule-api-message` (1m), `dashboard:warm`, `keywords:check`, `numbers:check`, `senderid:check`, `user:preferences`, `app:clean-database` (monthly) |

Sweeps for disabled modules (Documents, Opportunity, SEO rank tracking, AI, Ads, managed
messaging) are registered **unconditionally** and each owns its own fail-closed no-op; the
scheduler never needs to know whether a module is on.

`tests/Feature/Console/ProductionSchedulerRegistrationTest.php` now fails if any of the commands
and jobs above (the production-critical subset) is dropped from the Kernel.

### 4.2 Commands deliberately not scheduled

| Command | Why |
|---|---|
| `imartgroup:dlr` (commented out), `diafaan:dlr`, `gatewayapi:dlr`, `smpp:dlr`, `visionup:inbound`, `session:whatsender`, `custom:run` | Provider-specific delivery-receipt / inbound pollers inherited from Ultimate SMS. Webhook-driven (`dlr/*`, `inbound/*`) for the supported gateways. Schedule one only if you run that specific gateway. |
| `app:set-done-pending-campaign`, `campaign:clear`, `app:clear-chatbox`, `app:clear-tickets-data`, `jobs:cleanup-monitors` (commented out) | Manual maintenance. |
| `blueprint:install-missing`, `blueprint:seed-photo-booth`, `blueprint:seed-photo-booth-v2`, `documents:seed-photo-booth-templates`, `platform:create-owner`, `platform:install` | Install/ops, run by hand. |
| `workspaces:backfill`, `workspaces:backfill-entitlements`, `usage:backfill-wallets`, `usage:additional-business-slot-retirement-preflight`, `agency:migrate-client-businesses`, `workspaces:migrate-nonagency-multibusiness`, `workspaces:report-nonagency-multibusiness`, `usage:resolve-reservation`, `messaging:legacy-provider-usage-report`, `usage:activate-conversations-rate` | One-time migration / operator tools. |
| `EvaluateBusinessAutoRecharge` | Event-dispatched, not periodic (its own docblock). |

### 4.3 Overlap and the cron-driven worker

The Kernel's `queue:work` runs **every minute with no `withoutOverlapping()`**. Overlap is safe
(database-queue rows are reserved atomically), and it is deliberate: a worker that is mid-way
through a long job must not block the next minute's drain. Capacity consequence: N long jobs ⇒
N concurrent worker processes; size PHP/DB connections accordingly. Workers are short-lived, so
they pick up new code each minute and **`queue:restart` is not needed for them**.

---

## 5. Queue / jobs

### 5.1 Process expectations

| Process | Command | Required |
|---|---|---|
| Scheduler | cron `* * * * * php artisan schedule:run` | **Yes** — the Kernel's `queue:work` line is the app's designed queue consumer (AUTOMATIONS-V2 contract §1.1: "cron-driven, not a supervisor"). Effective resolution is one minute. |
| Persistent worker (optional) | `php artisan queue:work --queue=automation,default,batch --sleep=3 --tries=1 --timeout=900 --max-time=3600` under supervisor/systemd | Recommended if sub-minute latency matters (webhook-only payment completion, booking emails). Run **in addition**, never instead of cron. Use the **same queue list** and run `php artisan queue:restart` on every deploy. |

Never run workers against a queue name other than those three — nothing enqueues elsewhere
(`onQueue` appears only for `automation` and `batch`; everything else is `default`).

### 5.2 Failed-job and retry behaviour

- Failed jobs go to `failed_jobs` (`database-uuids`). Inspect: `php artisan queue:failed`.
  Retry: `php artisan queue:retry <uuid>` / `queue:retry all`. Discard: `queue:forget <uuid>`,
  `queue:flush`.
- **Default attempts = 1.** `App\Jobs\Base` sets `tries = 1`, `maxExceptions = 1`,
  `failOnTimeout = true`, and the scheduled worker passes `--tries=1`. A job without its own
  `$tries` is **not** auto-retried; a failure lands in `failed_jobs`. This is deliberate for
  anything that may already have called a provider (a retried send is a duplicate send).
- **Exceptions that retry:** `ProcessPlatformSubscriptionEvent`, `ProcessAgencyClientSubscriptionEvent`
  (3 tries + backoff), `BuildInitialBusinessSnapshot`, `ExecuteOpportunityAction`,
  `RunBusinessAdvisorOpportunityProducer`, `RunSeoAuditForRevision`,
  `DeliverPlatformAnnouncementChunk` (3 tries); `SendMessage`/`SendFileMessage`/`Delay` use
  `retryUntil`.
- **Recovery is by sweep, not by blind retry.** Lost or stuck work is re-driven by the scheduled
  sweeps: `automation:workflows-recover-stalled`, `RetryStuckPaymentProviderEvents` (bounded by
  `USAGE_BILLING_WEBHOOK_MAX_ATTEMPTS`, hard ceiling 20, then an administrator disposes it),
  `ReconcileProviderPendingState`, `documents:reconcile-stale-payments`, the Platform Automation
  sweep. So a flushed queue or a worker outage self-heals once workers and cron are back.
- **`retry_after` is 9000 s** on the `database` connection. A job whose worker was killed hard
  stays reserved for up to 2.5 h before becoming available again. Prefer `queue:restart` /
  graceful stops; per-job `$timeout` (e.g. 900 s for `SendMessage`, 7200 s for imports) overrides
  the worker's `--timeout`.
- **Monitor** `select count(*) from failed_jobs` and `jobs` age; both should be ~0 at rest.

### 5.3 `QUEUE_CONNECTION=sync` is not a production configuration

`phpunit.xml` and `.env.example` use `sync`; production must not.

- Stripe/Telnyx/calendar webhooks `::dispatch()` their processors — under `sync` they run **inside
  the provider's HTTP request**, so a slow handler causes provider retries and timeouts.
- `AdvanceWorkflowEnrollment` (`self::dispatch()` to continue a long journey in "a fresh job"),
  `RedispatchHeldEnrollments` (`self::dispatch()` per page) and `ProcessPlatformSubscriptionEvent`
  re-dispatch themselves: under `sync` they **recurse on the call stack** instead of yielding to
  the worker.
- `->delay()` is ignored by `sync`, so delayed jobs (GBP stagger, follow-ups, Ads sync stagger)
  fire immediately.
- Only `PlatformRunExecutor::park()` is explicitly guarded (`config('queue.default') !== 'sync'`).
- The Kernel's `queue:work` line is a no-op under `sync`.

Use `database` (default in `config/queue.php`), or Redis if you also stand up Redis.

---

## 6. Web server

### 6.1 Requirements

- PHP **8.2+** (verified on 8.3.30) with `ctype curl dom exif fileinfo gd intl json libxml
  mbstring openssl pcntl pdo pdo_mysql posix simplexml zip` (all in `composer.json`).
- **Document root = `public/`.** Not the repository root. (The repo-root `.htaccess` and
  `server.php` are the upstream shared-hosting layout; §6.4 explains why the root layout is a
  hazard here.)
- `upload_max_filesize` ≥ **8M** and `post_max_size` ≥ 10M (Website images are capped at 8 MB and
  4000×4000 px, `ValidWebsiteImageRule`); Nginx `client_max_body_size 10m`. Raise further for
  contact/campaign imports if used.
- HTTPS everywhere; HTTP → HTTPS redirect.

### 6.2 Nginx (reference)

```nginx
server {
    listen 80 default_server;
    server_name _;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2 default_server;
    # Platform host AND every active customer custom domain terminate here.
    # The app decides by Host header (TrustHosts + ResolveCustomDomainWebsite);
    # unknown hosts fall through to normal routing and are refused by TrustHosts.
    server_name platform.example.com;               # + customer domains, see §6.5

    root /var/www/os-ai/current/public;
    index index.php;
    charset utf-8;
    client_max_body_size 10m;

    ssl_certificate     /etc/ssl/platform.example.com/fullchain.pem;
    ssl_certificate_key /etc/ssl/platform.example.com/privkey.pem;

    # Never serve dotfiles (.env lives one level up, but belt and braces).
    location ~ /\.(?!well-known) { deny all; }

    # robots.txt is owned by Laravel (platform host: Public\RobotsController; customer domains:
    # ResolveCustomDomainWebsite, which adds that site's Sitemap line). Hand it to PHP explicitly so no
    # file, cache or default rule can answer it first. See §6.4.
    location = /robots.txt { try_files /dev/null /index.php?$query_string; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    # Uploaded assets are static and content-hashed (sha256 filenames) — cache hard.
    location ~* ^/images/(websites|business|branding)/.+\.(png|jpe?g|webp|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }
}
```

`/stripe/webhook/*`, `/inbound/*`, `/dlr/*`, `/webhooks/*` are plain `POST`s to Laravel: do not
put them behind HTTP auth, a WAF challenge, or a body-rewriting proxy (signature verification
needs the raw body).

### 6.3 Apache

`public/.htaccess` is the standard Laravel front controller and is correct when
`DocumentRoot` is `…/public`. Required: `mod_rewrite`, `AllowOverride All`, `Options -Indexes`
(already in the file). Because `public/robots.txt` no longer exists, `/robots.txt` falls through the
standard rewrite to `index.php` — Laravel answers it. Do **not** add an `Alias`, `RewriteRule` or
`<Files robots.txt>` rule that serves a file or a fixed body (§6.4).

### 6.4 `/robots.txt`, `/sitemap`, and the static-file-ahead-of-Laravel hazard

**Current state of the code (Website V1 final, `94bc4c1c`):** `/robots.txt` is **dynamic and owned by
Laravel**; there is no tracked `public/robots.txt` (a test asserts the file does not exist).

- **Platform host:** `Public\RobotsController` (route `public.robots`) returns
  `User-agent: *` / `Disallow:` (allow all — byte for byte what the old static file said).
- **A published Website's primary custom domain:** `ResolveCustomDomainWebsite::renderRobots()`
  returns the same allow-all rules **plus** `Sitemap: https://<that-domain>/sitemap`. Aliases
  redirect (301) to the primary domain's `robots.txt`; unpublished or gated sites, the preview and
  the platform path (`/sites/{publicId}/…`) never serve one.
- **Sitemaps** are the extensionless `/sitemap` (custom domain) and `/sites/{publicId}/sitemap`
  (platform host), and follow publish and indexability (a noindex page is not listed).

Because that logic only runs when the request reaches PHP, the web server must **route `/robots.txt`
through Laravel for every host, Website custom domains included**:

1. **Docroot must be `public/`.** With the legacy repo-root layout, the root `.htaccess` rewrites
   any URI ending `.\w+` straight into `public/…` as a static lookup *before* Laravel — which would
   also pre-empt the `/robots.txt` route (and is why the sitemap route has no `.xml`).
2. **Nginx:** keep `location = /robots.txt { try_files /dev/null /index.php?$query_string; }` (§6.2)
   so no file, cache or default rule can answer it. **Apache:** no rule needed — with no file in
   `public/`, the front-controller rewrite hands it to Laravel.
3. **Never reintroduce a static robots file and never add a server/CDN robots rule** (no
   `return 200 …`, no `Alias`, no CDN-level robots injection, no `public/robots.txt` in a deploy
   artifact, no stale copy left in a release directory). Any of them silently removes every
   customer's `Sitemap:` line. Check a release artifact with `test ! -e public/robots.txt`.
4. **Smoke check (§8 step 14):** `curl -sS https://<custom-domain>/robots.txt` must contain the
   `Sitemap: https://<custom-domain>/sitemap` line for a published site, `curl -sS
   https://<platform-host>/robots.txt` must be the two-line allow-all body, and `curl -sSI
   https://<custom-domain>/sitemap` must be `200 application/xml` — **not** an HTML 404 or a
   static file from the web server.

### 6.5 Custom domains and HTTPS

- Flow: owner adds a domain → DNS check against `FORGE_CNAME_TARGET` / `FORGE_A_RECORD_IP` →
  `ForgeDomainProvisioner` attaches the domain to the single Forge site and Forge issues the
  certificate → domain becomes `Active` → `TrustHosts` trusts it (60 s cache, invalidated on
  change) and `ResolveCustomDomainWebsite` serves it.
- Every Active domain must resolve to **this** server and present a valid certificate for its own
  name. Only `GET`/`HEAD` page views, `/sitemap`, and the Website form `POST` are served on a custom
  domain; everything else is 404 by design.
- **Off Forge** (finding 1.2 C): the Forge calls will fail. Provide certificates another way
  (certbot per domain, or on-demand TLS such as Caddy) and a catch-all `server_name _` vhost, and
  accept that domain activation will not complete automatically until a provisioner exists.
- Because `TrustProxies::$proxies` is empty, set `URL_FORCE_HTTPS=true` if a proxy/CDN terminates
  TLS in front of Nginx.
- `CACHE_DRIVER` must be shared across PHP workers/hosts (the active-domain list and Website
  snapshots are cached); with several app servers use Redis, not `file`.

---

## 7. Storage

| Path | Written by | Requirement |
|---|---|---|
| `public/images/websites/{website_uid}/` | `WebsiteAssetUploadService` | Writable by PHP user; **persistent across releases**; served statically. Original upload only — **no resized derivatives are generated** (content-hashed `{sha256}.{png\|jpg\|webp}`; 8 MB / 4000 px cap). |
| `public/images/business/{business_uid}/` | `BusinessImageStore` (logos, heroes, gallery) | Same. |
| `public/images/branding/{logo,favicon,…}/` and `public/images/branding/agency/{agency_uid}/` | `BrandingUploadService`, `AgencyWhiteLabelManager` | Same. The three committed `default-*.svg` files in `public/images/branding/` must still ship. |
| `public/mms/` | inbound MMS media | Same. |
| `storage/app/public` ← `public/storage` | `Storage::disk('public')` (keyword MMS files) | Run `php artisan storage:link` once per host; **persistent**. |
| `storage/app`, `storage/app/quota`, `storage/tmp`, `storage/framework/{cache,sessions,views}`, `storage/logs` | framework, quota counters, imports/exports | Writable; `storage/app/quota` and `storage/app` are state, not cache — persistent and backed up. |
| `bootstrap/cache` | `config:cache`, `route:cache`, packages | Writable at deploy time. |
| `.env` | deployed secret + Settings screens | Readable and **writable** by the PHP user (§3), mode `0640`, never in the web root. |

Directories are created on demand with `mkdir(…, 0755)`, so the **PHP-FPM user must own (or
be able to create under) `public/images/`**. In a release-directory deployment (Forge zero-downtime,
Capistrano, Deployer) the upload paths **must be symlinks into a shared directory** that survives
releases, otherwise every deploy deletes every customer image; a `git checkout`/`rsync --delete`
deployment into a single directory would do the same. Uploads are not in the database or the
repository — **they are only on disk**, so they belong in the backup set (§8 step 15).

---

## 8. Production deployment checklist

Do each step in order; every step lists its verification. Replace `<…>`. **Do not run any of
this against the canonical or any `ultimatesms_testing*` database.**

**1. Checkout**
   - [ ] `git fetch origin && git checkout <release-ref>` into the release directory; `git status --short` is empty.
   - [ ] Confirm the release artifact has **no** `public/robots.txt` (`test ! -e public/robots.txt`) — finding 1.2 A.

**2. Composer**
   - [ ] `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`
   - [ ] `php -m` shows `pcntl`, `posix`, `gd`, `intl`, `exif`, `zip`, `pdo_mysql`.

**3. Front-end build**
   - [ ] **Not required.** Compiled CSS/JS/vendors/fonts and `public/mix-manifest.json` are committed. Only run `npm ci && npm run production` if `resources/` changed in this release — then commit the result; do not build on the server.

**4. Environment**
   - [ ] Copy `docs/product/production.env.example` to the shared `.env`; fill every **required** value (§3); mode `0640`, owned by the PHP user.
   - [ ] `php artisan key:generate --force` on a **first** install only. Never on an existing instance.
   - [ ] `APP_ENV=production`, `APP_DEBUG=false`, `QUEUE_CONNECTION=database`, `MAIL_MAILER` set, `CACHE_DRIVER` not `array`, `STRIPE_MODE`/`TELNYX_MODE` deliberately set.

**5. Database + migrate**
   - [ ] Create a **new empty** `utf8mb4` database and a dedicated user; a verified backup exists first on an existing instance.
   - [ ] `php artisan migrate --force` (also performed by step 6 on a first install; run it separately on upgrades). Expect a 2–3 minute first run.

**6. Install / bootstrap**
   - [ ] First install: `php artisan platform:install --owner-email=<you>` (enter the owner password at the concealed prompt). Upgrades: skip — do **not** re-seed on a live instance unless you intend to; it is idempotent but not needed.
   - [ ] `select count(*) from currencies` = 12; `workspace_plan_catalog` shows `core`, `growth`, `agency`; one `is_admin` user.

**7. Storage**
   - [ ] Shared dirs symlinked into each release: `.env`, `storage/app`, `storage/logs`, `public/images/websites`, `public/images/business`, `public/images/branding/*` (not the three `default-*.svg`), `public/mms`.
   - [ ] `php artisan storage:link`
   - [ ] Ownership/permissions: PHP user owns `storage`, `bootstrap/cache`, the shared upload dirs; `public/images` is writable by it.
   - [ ] Optimise: `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache` (all four verified to build).

**8. Queue**
   - [ ] `select count(*) from jobs` and `failed_jobs` both ≈ 0.
   - [ ] (Recommended) supervisor program per §5.1 and `php artisan queue:restart` after each deploy.

**9. Scheduler**
   - [ ] Cron entry per §4 as the PHP user.
   - [ ] `php artisan schedule:list` shows **53** entries including `usage:spending-threshold-alerts` and the `queue:work` line.
   - [ ] After 2 minutes `storage/cronJobAvailable` exists and `queue:work` has drained.

**10. Web server**
   - [ ] Config per §6: docroot `…/current/public`, PHP-FPM, `client_max_body_size` ≥ 10m, `upload_max_filesize` ≥ 8M, **`/robots.txt` routed through Laravel (Nginx `location = /robots.txt` block) and no static robots file or server/CDN robots rule** (§6.4), dotfiles denied.
   - [ ] `nginx -t` / `apachectl configtest`, then reload.

**11. SSL / domain**
   - [ ] Valid certificate for the platform host; HTTP → HTTPS redirect; `APP_URL` exactly matches.
   - [ ] Behind a proxy/CDN: `URL_FORCE_HTTPS=true`.
   - [ ] Custom domains: `FORGE_*` set, or the alternative certificate process from §6.5 in place.

**12. Stripe webhooks** (Dashboard → Developers → Webhooks; live mode for a live deploy; each secret → its own variable)
   - [ ] `https://<host>/stripe/webhook/platform-subscriptions` — events `checkout.session.completed`, `customer.subscription.created|updated|deleted`, `invoice.paid`, `invoice.payment_failed` → `STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET`.
   - [ ] `https://<host>/stripe/webhook/business-payments` — **Connect** endpoint; `payment_intent.succeeded|processing|payment_failed|requires_action|canceled`, `refund.created|updated|failed`, `charge.refund.updated` → `STRIPE_CONNECT_WEBHOOK_SECRET`.
   - [ ] `https://<host>/stripe/webhook/agency-subscriptions` — **Connect** endpoint; same six subscription events as the platform lane → `STRIPE_AGENCY_SUBSCRIPTION_WEBHOOK_SECRET`.
   - [ ] `https://<host>/stripe/webhook/usage-billing` — events as listed on the Stripe panel in Platform Settings (RFC-005 §9) → `STRIPE_WEBHOOK_SECRET`.
   - [ ] Agency "connect existing account": register `https://<host>/agency/stripe/connect-existing/callback` as the OAuth redirect (test and live separately).
   - [ ] A deliberately bad-signature `POST` to each returns **400** and stores nothing.
   - [ ] Then run the live scripts in `PAYMENTS-LIVE-ACCEPTANCE.md`.

**13. Provider OAuth callbacks and webhooks** (register exactly these)
   - [ ] Google sign-in: value of `GOOGLE_REDIRECT` (route `login/google/callback`).
   - [ ] Google Business Profile: `https://<host>/gbp/oauth/callback`.
   - [ ] Google Ads: `https://<host>/ads/oauth/callback`.
   - [ ] Meta Ads: `https://<host>/ads/meta/oauth/callback`.
   - [ ] Calendar: `https://<host>/calendar-connection/oauth/google/callback` and `…/outlook/callback`.
   - [ ] Business email: `https://<host>/email/oauth/google/callback` and `…/microsoft/callback`.
   - [ ] Telnyx managed messaging: inbound webhook `https://<host>/inbound/telnyx-managed`; `TELNYX_WEBHOOK_PUBLIC_KEY` set.
   - [ ] Calendar push webhooks (`/webhooks/calendar/{uid}/{token}/google|outlook`) are generated per connection; they need a public HTTPS `APP_URL`.

**14. Smoke tests** (from outside the server)
   - [ ] `curl -sSI https://<host>/` → `302` to `/login` (a `503` means `APP_STAGE=new`).
   - [ ] `/login` → `200`; sign in as the Platform Owner → Platform Owner Home.
   - [ ] `curl -sS https://<platform-host>/robots.txt` (allow-all), `curl -sS https://<custom-domain>/robots.txt` (must carry `Sitemap: https://<custom-domain>/sitemap`) and `curl -sSI https://<custom-domain>/sitemap` (`200 application/xml`) per §6.4.
   - [ ] `curl -sSI https://<host>/sites/00000000-0000-4000-8000-000000000000` → `404` (not 500).
   - [ ] Upload a logo and a Website image → the file appears under `public/images/…` and loads over HTTPS; redeploy once and confirm it is still there.
   - [ ] Trigger an action that queues a job; `jobs` drains within ~1 minute; `failed_jobs` stays empty.
   - [ ] Send a test email from Platform Settings.
   - [ ] `storage/logs` contains no new `ERROR`/`CRITICAL` entries from the steps above.

**15. Backup and logging**
   - [ ] Nightly `mysqldump --single-transaction` of the production database, retained and restore-tested once.
   - [ ] Back up `.env` (separately, encrypted), `public/images/{websites,business,branding}`, `public/mms`, `storage/app`. Uploads exist **only** on disk.
   - [ ] `LOG_CHANNEL=daily`, retention set; `storage/logs` writable and rotated; alert on `failed_jobs > 0` and on `storage/logs` error volume.
   - [ ] Record `APP_KEY` in the secret store — losing it makes retained encrypted webhook payloads unreadable.

---

## 9. Verification run on this branch

| Check | Result |
|---|---|
| `tests/Feature/Console/ProductionSchedulerRegistrationTest.php` + `CurrenciesSeederIdempotencyTest.php` | **4 tests, 43 assertions, OK** (one PHPUnit deprecation notice, pre-existing config). Against `ultimatesms_testing_deploy_tests`. |
| `platform:install` on an empty DB, then 3 more runs | all exit 0; row counts identical across re-runs after the fix. |
| `schedule:list` | 53 entries. |
| `route:cache`, `config:cache`, `view:cache`, `event:cache` | all succeed. |

| `AdvanceWorkspaceAccountLifecycleTest` (schedules the Kernel) + `WebsiteIndexingTest` | 30 tests, 158 assertions on the deployment lane's own (older) base, where `WebsiteIndexingTest::test_robots_txt_is_untouched_by_this_feature_branch` failed only because of Windows CRLF on the then-tracked static file. **Superseded by integration:** that file is gone (Website V1 final), the test now asserts `public/robots.txt` does not exist and that `/robots.txt` is served by Laravel. See the integration report for the re-run. |

The full repository suite was **not** run for this documentation/readiness pass; only the files
above were executed.
