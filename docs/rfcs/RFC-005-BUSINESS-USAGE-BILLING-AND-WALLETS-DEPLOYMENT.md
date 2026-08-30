# RFC-005 Business Usage Billing and Wallets — Deployment Guide

Grounded in the actual repository implementation of RFC-005 Milestones 1–5, Amendment 1 (Usage Meter Identity), and all nine post-M5 remediations, verified by direct inspection during Milestone 6. Not copied from any prior RFC's deployment guide.

---

## 1. Scope

This guide covers RFC-005 as it actually exists in this repository today:

- **Milestone 1** — Business-scoped wallet/ledger foundation, calendar-month period accounting, reservation/commit/release/expire lifecycle, rate catalog.
- **Milestone 2** — Business monthly spend cap, per-feature limits, platform safety limits, payer selection, billing contact.
- **Milestone 3** — Stripe provider-customer/payment-instrument model, webhook processing, auto-recharge. **Live production Stripe charging remains explicitly blocked** (`READY_FOR_TEST_MODE_IMPLEMENTATION — BLOCKED_FOR_LIVE_CHARGING`) — this posture is distinct from, and not resolved by, test-mode readiness.
- **Milestone 4** — Additional-Business-slot agreements and paid add-ons, including cancellation/proration/dunning.
- **Amendment 1** — Usage meter identity (`usage_meters`, immutable `meter_key`), replacing the earlier feature-key-only rate model.
- **Milestone 5** — The first real metered feature classification (Conversations pilot mechanism — real activation is a separate, unexecuted human operator step).
- **Nine post-M5 remediations** — reservation admission wiring, funding provider-flow correction, receipt boundary, job/event dispatch completion, reconciliation-race correction, funding-confirmation concurrency correction, admin usage-billing surface, provider refund/dispute-outcome handling, and §35 test-coverage completion.

It does **not** cover Milestone 6's own conformance/tag process — see `docs/automation/RFC-005-M6-CONFORMANCE.md` and `docs/automation/RFC-005-M6-CONTRACT.md` for that governance record.

---

## 2. Prerequisites

- Laravel 12 / PHP 8.2+ (this repository), already running RFC-001 (Business Core), RFC-002 (Opportunity Engine), RFC-003 (Workspace and Business Account Core, tagged `rfc-003-workspace-and-business-account-core`), and RFC-004 (Plans and Business Feature Entitlements, tagged `rfc-004-plans-and-business-feature-entitlements`).
- `bcmath` PHP extension enabled — every RFC-005 money computation uses `bcmath` string arithmetic, never float arithmetic.
- A Stripe account and API keys, if you intend to exercise the payment-provider path at all (test mode is fully supported; live mode remains blocked — see §5 below).
- The disposable `ultimatesms_testing` database for running the regression commands in §13; never point them at a production or otherwise non-disposable database.

---

## 3. Environment prerequisites — confirmed present, no invented key

### `config/services.php` — `stripe` block

```php
'stripe' => [
    'model'   => User::class,
    'key'     => env('STRIPE_KEY'),
    'secret'  => env('STRIPE_SECRET'),
    'webhook' => [
        'secret'    => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
    ],
    'mode'         => env('STRIPE_MODE', 'test'),
    'api_version'  => env('STRIPE_API_VERSION'),
],
```

`STRIPE_MODE` and `STRIPE_API_VERSION` have no meaningful default that allows the gateway to construct — `App\Library\Usage\StripePaymentProviderGateway`'s constructor fails closed (throws `RuntimeException`) before constructing a Stripe client if:

- `mode` is not exactly `test` or `live`
- `secret` is blank
- `webhookSecret` is blank
- `apiVersion` is blank
- `secret`'s prefix does not match `sk_{mode}_` (a live secret key with `STRIPE_MODE=test`, or vice versa, is rejected)

This means the application **cannot silently run in an unintended Stripe mode** — a missing or mismatched configuration value prevents the gateway from being constructed at all, rather than defaulting to some assumed mode.

### `config/usage_billing.php`

```php
'webhook_event' => [
    'lease_minutes'  => env('USAGE_BILLING_WEBHOOK_LEASE_MINUTES', 5),
    'max_attempts'   => env('USAGE_BILLING_WEBHOOK_MAX_ATTEMPTS', 5),
    'retention_days' => env('USAGE_BILLING_WEBHOOK_RETENTION_DAYS'),
],
'conversations_metering' => [
    'pilot_business_id'       => env('USAGE_BILLING_CONVERSATIONS_PILOT_BUSINESS_ID'),
    'pilot_country_id'        => env('USAGE_BILLING_CONVERSATIONS_PILOT_COUNTRY_ID'),
    'pilot_sending_server_id' => env('USAGE_BILLING_CONVERSATIONS_PILOT_SENDING_SERVER_ID'),
],
```

- `USAGE_BILLING_WEBHOOK_MAX_ATTEMPTS` is honored only up to `App\Library\Usage\PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING = 20` — a configured value above 20 is silently clamped down to 20, never allowed higher.
- `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` has **no default**. Leaving it unset means the purge job (`PurgeExpiredWebhookPayloads`) never purges any encrypted webhook payload — this fails closed (indefinite retention), never the opposite (immediate purge). Set an explicit value before relying on payload purging for storage/compliance reasons.
- The three `conversations_metering` pilot keys are all `null` by default. They are **not required for deployment** — leaving them unset simply means the Conversations pilot rate can never be activated (`usage:activate-conversations-rate` requires all three to resolve to real, matching values). Do not set placeholder or invented values for these keys.

### Installed SDK

`stripe/stripe-php` `v7.128.0`, confirmed in `composer.lock` at deployment-guide-drafting time. Re-confirm the exact locked version at your own deployment time via `composer show stripe/stripe-php` — do not assume this version number remains current indefinitely.

---

## 4. Upgrade posture

RFC-005 is additive at the schema level relative to RFC-001 through RFC-004: 49 migrations introduce new tables and, in a small number of cases, new columns on RFC-005's own previously-created tables (never on an RFC-001/002/003/004 table). No RFC-001/002/003/004 table, column, route, controller, or view is dropped, renamed, or altered.

RFC-005's own integration point into RFC-004 is a single, additive dependency-injection seam: `EntitlementManager::decide()`'s final step calls `UsageAuthorizationGateway::check()`, bound in `AppServiceProvider.php:168` to `RealUsageAuthorizationGateway` (replacing RFC-004's own `NullUsageAuthorizationGateway`). `RealUsageAuthorizationGateway::check()` delegates to `UsageWalletManager::evaluateCoarseCapacity()`, which remains frozen to unconditionally return an "authorized" decision for every feature not yet classified `is_metered = true` — so every non-metered feature's `decide()` outcome is completely unaffected by this integration. This is confirmed directly by `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`.

Business creation gains two new listener calls (`App\Listeners\Usage\InitializeBusinessUsageProfile`, subscribed to `BusinessCreated` and `BusinessAssignedToWorkspace`, both `ShouldDispatchAfterCommit`): `UsageWalletManager::initializeWalletForNewBusiness()` and `BillingProfileManager::initializePayerAssignmentForBusiness()`. Both run after the Business row is already committed, so an initialization failure here can never roll back the Business itself. A wallet-initialization failure (`BusinessCurrencyUnresolvableException`, when a Business's currency cannot be resolved) is caught and logged non-sensitively rather than propagated — the affected Business remains wallet-uninitialized until an operator corrects its currency data and re-runs the wallet backfill. Payer-assignment initialization has no equivalent failure mode and always runs independently of the wallet outcome.

---

## 5. Test-mode versus live-mode posture

**Live production Stripe charging is explicitly blocked in this repository's current state.** The status header `READY_FOR_TEST_MODE_IMPLEMENTATION — BLOCKED_FOR_LIVE_CHARGING`, first recorded at M3, is preserved unresolved by every subsequent milestone and remediation, confirmed directly by this Milestone 6 pass. This is a deliberate, currently-unaddressed readiness question, distinct from and not implied by test-mode functioning correctly.

Before considering live-mode charging, the following must additionally be resolved (none of which this deployment guide or M6 resolves):

- **Production tax/VAT legal sufficiency (§39 item 6)** — explicitly scoped by RFC-005 itself as a legal/compliance gate, not something this or any prior RFC-005 document provides legal advice on. **Unresolved.**
- Every other open §39 commercial/product decision relevant to real charging (exact retail rates, default spend caps/limits, auto-recharge defaults, add-on roster/pricing, safety-limit ceilings, currency scope) — see `docs/automation/RFC-005-M6-CONFORMANCE.md` for the full, honestly-recorded list of what remains open.

For test-mode deployment (the only mode this repository currently supports end-to-end): set `STRIPE_MODE=test`, a `sk_test_...`-prefixed `STRIPE_SECRET`, a matching `STRIPE_WEBHOOK_SECRET` from your Stripe test-mode webhook endpoint configuration, and a real `STRIPE_API_VERSION` matching what your Stripe test account expects.

---

## 6. Migration sequence

All 49 RFC-005 migrations, confirmed present by direct inspection at Milestone 6 drafting time, in real timestamp order:

| Group | Files | Purpose |
|---|---|---|
| M1 (7 tables + 2 backfills) | `2026_08_16_120001`–`120009` | Wallet/ledger/reservation/rate schema; classification and wallet backfills |
| M2 (7 tables + 1 backfill) | `2026_08_16_130001`–`130008` | Limits, billing status, billing contact, payer assignment schema and backfill |
| M3 (5 tables) | `2026_08_16_140001`–`140005` | Provider customer, payment instrument, funding attempt, webhook event schema |
| M4 (7 tables) | `2026_08_20_150001`–`150007` | Slot agreement, renewal charge, add-on catalog/purchase schema |
| Amendment 1 (2 new tables + 8 column-shape changes, 3 slices) | `2026_08_22_120001`–`120007`, `2026_08_24_120001`–`120003` | `usage_meters`/`usage_meter_transitions`; `meter_key` introduction (EXPAND), then `feature_key` retirement (CONTRACT) |
| R3 Receipt Boundary (1 table) | `2026_08_27_120001` | `business_billing_receipts` — first real receipt implementation |
| R7 Admin Usage Billing Surface (1 column) | `2026_08_28_120001` | Nullable `reason` on `business_funding_attempt_transitions` |
| R8 Provider Refund/Dispute Outcome Handling (8 migrations) | `2026_08_29_120001`–`120008` | Provider reference columns + backfill, refund/dispute counters, retry/lease indexing |

**49 files total**, independently reconfirmed against `origin/main` during this Milestone 6 pass — see `docs/automation/RFC-005-M6-CONFORMANCE.md` for the exact breakdown and cross-check against RFC-004's own, textually adjacent but unrelated, `2026_08_13_*`/`2026_08_20_120000`/`2026_08_20_130000` migrations.

Run in the normal way:

```bash
php artisan migrate
```

DDL and data-mutating migrations (seeds, backfills) are ordered by timestamp exactly as Laravel's migration runner expects; no manual reordering or separate backfill step is required beyond `php artisan migrate` itself.

---

## 7. Wallet, payer, and usage-profile initialization and backfill behavior

- **New Businesses** (created after this deployment): `App\Listeners\Usage\InitializeBusinessUsageProfile` initializes exactly one wallet and exactly one payer assignment per Business, idempotently, on both `BusinessCreated` and `BusinessAssignedToWorkspace` events.
- **Pre-existing Businesses** (existing before this deployment): covered by dedicated backfill migrations —
  - `2026_08_16_120009_backfill_business_usage_wallets.php` (M1) — wallet initialization for every pre-existing Business.
  - `2026_08_16_130008_backfill_business_payer_assignments.php` (M2) — payer assignment for every Business created between the M1 and M2 deploys, and every earlier pre-existing Business.
  - `2026_08_16_120008_backfill_platform_feature_usage_classifications.php` (M1) — classification rows for every existing platform feature.
  - `2026_08_29_120002_backfill_provider_payment_intent_reference_for_auto_recharge_attempts.php` (R8) — backfills the new independently-unique provider reference columns for pre-existing auto-recharge funding attempts.
- **Amendment 1's Slice 3 (CONTRACT) migration** (`2026_08_24_120001_tighten_and_contract_business_usage_rates_table.php`) runs a forward preflight before any destructive DDL: it throws `UsageMeterBackfillIncompleteException` if any `meter_key` is still null anywhere in the rate/activation/reservation tables it is about to tighten. If you see this exception, do not force the migration through — resolve the underlying missing `meter_key` data first (this should not occur in a fresh deployment of the full, already-completed migration sequence; it is a safeguard against a partial or out-of-order migration run).
- A wallet-initialization failure for a specific Business (`BusinessCurrencyUnresolvableException`, when that Business's currency cannot be resolved) leaves that Business without a wallet; every wallet operation for it then fails closed (`UsageWalletNotFoundException`) until an operator corrects its currency data and re-runs the wallet backfill.

---

## 8. Queue/worker and scheduler requirements

**No separate, dedicated Usage-billing worker process is required.** This application's existing scheduled command already processes every RFC-005 queued job:

```php
$schedule->command('queue:work --queue=automation,default,batch --timeout=120 --tries=1 --max-time=180 --stop-when-empty')->everyMinute();
```

The following RFC-005 jobs are registered in `app/Console/Kernel.php`, unconditionally whenever `config('app.stage') !== 'demo'`, alongside this application's other existing scheduled work — none is gated by a separate RFC-005-specific feature flag:

| Job | Cadence |
|---|---|
| `PurgeExpiredWebhookPayloads` | hourly |
| `ReconcileProviderPendingState` | every 5 minutes |
| `RetryStuckPaymentProviderEvents` | every 5 minutes |
| `InitiateSlotAgreementRenewal` | every 5 minutes |
| `FinalizeSlotAgreementCancellation` | every 5 minutes |
| `ReconcileSlotAgreementAllocation` | hourly |
| `ExpireStaleUsageReservations` | every 5 minutes |

Confirmed directly in `app/Console/Kernel.php` (lines 26–32 for the `use` imports, lines 112–127 for the `$schedule->job(...)` registrations), inside the same `else` branch (line 76) that runs whenever the application is not in `demo` stage — i.e. these run in every real deployment, including local/staging/production, without a separate opt-in.

If your deployment environment does not already run Laravel's scheduler (`php artisan schedule:run` via cron, typically every minute), start doing so — none of the above jobs run without it, and none of them have a separate manual invocation path intended for routine production use.

---

## 9. Webhook endpoint

```
POST /stripe/webhook/usage-billing
```

- Defined in `routes/public.php`: `Route::post('stripe/webhook/usage-billing', 'StripeWebhookController@handle')->name('webhooks.stripe.usage-billing');`
- **Unauthenticated** (Stripe cannot authenticate as an application user) and **CSRF-exempted**, via the existing `VerifyCsrfToken` middleware's `$except` array (`app/Http/Middleware/VerifyCsrfToken.php`), which already carries several other unauthenticated provider-callback exemptions (`callback/sslcommerz/*`, `callback/aamarpay/*`, etc.) — `stripe/webhook/usage-billing` is one entry among these, not a uniquely-implemented exemption mechanism.
- Signature verification is performed inside `StripeWebhookController@handle` / `ProcessPaymentProviderEvent`, using `STRIPE_WEBHOOK_SECRET` and `STRIPE_WEBHOOK_TOLERANCE` (default 300 seconds) from `config/services.php`.
- Configure this exact path as your Stripe webhook endpoint URL (e.g. `https://your-domain.example/stripe/webhook/usage-billing`) in the Stripe Dashboard, for whichever mode (test/live) you are deploying for, and copy the resulting signing secret into `STRIPE_WEBHOOK_SECRET`.

---

## 10. Webhook retry / reclaim / exhaustion / disposition / purge lifecycle

- **Claim/lease**: `ProcessPaymentProviderEvent` claims a `payment_provider_events` row under a lease (`USAGE_BILLING_WEBHOOK_LEASE_MINUTES`, default 5 minutes). A stale (expired) lease is eligible for reclaim by a fair, round-robin reclaim query (corrected by the Reconciliation-Race Correction remediation) rather than a naive oldest-first scan that could starve later events.
- **Retry/exhaustion**: `RetryStuckPaymentProviderEvents` (scheduled every 5 minutes) reclaims stuck/failed events, bounded by `PaymentProviderEventRetryPolicy::resolvedMaxAttempts()`, which clamps the configured `USAGE_BILLING_WEBHOOK_MAX_ATTEMPTS` to a hard ceiling of `MAX_ATTEMPTS_CEILING = 20` regardless of configuration. Once an event's attempt count is exhausted, it becomes permanently unreclaimed by the ordinary retry path and surfaces in the administrator's exhausted-events queue.
- **Disposition**: an exhausted event can be dispositioned to `disposed` by an administrator, via `POST /admin/.../provider-events/{event}/dispose` (`routes/admin.php`, `PaymentProviderEventController@dispose`), recording `disposed_at`/`disposed_by_user_id`/`disposition_note`. Once disposed, the event never again matches the claim query's `WHERE` clause under any circumstance.
- **Purge**: `PurgeExpiredWebhookPayloads` (scheduled hourly) purges the encrypted payload (`payload_encrypted`) of `processed`/`ignored`/`disposed` events once past `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` — but never their `id`/`provider_event_id`/`payload_hash`/`state`/`attempts`/timestamps/disposition fields, which remain permanently intact so the `UNIQUE (provider, provider_event_id)` idempotency constraint continues to reject a genuine replay even after payload purge. **This job never purges anything if `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` is left unset** (§3 above).
- **Reconciliation**: `ReconcileProviderPendingState` (every 5 minutes) reconciles locally-pending funding attempts/slot agreements against the provider's own authoritative state, for cases where a webhook was missed or delayed rather than explicitly failed.

---

## 11. Encryption / secret handling

`payment_provider_events.payload_encrypted` uses Laravel's built-in `encrypted` Eloquent cast — the first real production use of that cast in this codebase, confirmed directly in the model. This means the raw webhook payload is encrypted at rest using the application's `APP_KEY`; **rotating `APP_KEY` without a corresponding re-encryption migration will make previously-stored encrypted payloads unreadable.** Treat `APP_KEY` rotation as a breaking operation for any still-retained (non-purged) webhook payload, and coordinate it with your `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` policy accordingly.

No raw card number or other raw payment-instrument secret is ever stored — confirmed directly (`tests/Feature/Usage/BusinessPaymentInstrumentSchemaTest.php::test_no_raw_card_number_column_exists`); only Stripe-issued tokens/references are persisted.

---

## 12. Provider customer / payment-instrument considerations

- `payment_provider_customers` enforces `UNIQUE(id, provider)` plus unique generated columns `active_business_id`/`active_workspace_id` per provider — at most one active Stripe customer object per Business and per Workspace.
- `business_payment_instruments` enforces a composite foreign key `(provider_customer_id, provider) → payment_provider_customers(id, provider)` — a provider-mismatched instrument row is rejected at the schema level, not merely by application-code convention. This was independently verified this pass by a direct `DB::table()` insert attempt against a mismatched pair, which raised a `QueryException`.
- Exactly one default instrument per provider customer is enforced by locking the parent `payment_provider_customers` row before clearing/setting `is_default`.

---

## 13. Wallet / meter verification steps

After deployment, verify:

```sql
-- Every Business should have exactly one wallet.
SELECT COUNT(*) FROM businesses b
LEFT JOIN business_usage_wallets w ON w.business_id = b.id
WHERE w.id IS NULL;
-- Expected: 0 (excluding any Business whose currency genuinely could not be
-- resolved at backfill time — investigate any non-zero result before assuming
-- it is this known, narrow exception).

-- Every Business should have exactly one payer assignment.
SELECT COUNT(*) FROM businesses b
LEFT JOIN business_payer_assignments p ON p.business_id = b.id
WHERE p.id IS NULL;
-- Expected: 0.
```

And run the final regression commands in §14 below, which exercise the wallet/meter/reservation/rate machinery end to end via the automated test suite rather than manual production queries alone.

---

## 14. Final regression commands

Verified present and non-empty in this repository before locking these commands: `tests/Unit/Usage` (4 files), `tests/Feature/Usage` (128 files), `tests/Unit/Entitlement` (1 file), `tests/Feature/Entitlement` (30 files), `tests/Unit/Workspace` (4 files), `tests/Feature/Workspace` (37 files), `tests/Unit/Business` (3 files), `tests/Feature/Business` (11 files), `tests/Unit/Opportunity` (10 files), `tests/Feature/Opportunity` (37 files).

```bash
php artisan test tests/Unit/Usage tests/Feature/Usage
php artisan test tests/Unit/Entitlement tests/Feature/Entitlement
php artisan test tests/Unit/Workspace tests/Feature/Workspace
php artisan test tests/Unit/Business tests/Feature/Business
php artisan test tests/Unit/Opportunity tests/Feature/Opportunity
php artisan test --stop-on-failure
```

Every command must exit successfully and discover a positive test count — a zero/"no tests found" result is a failure. Run only against the disposable `ultimatesms_testing` database. See `docs/automation/RFC-005-M6-CONFORMANCE.md` for this Milestone's actual recorded results.

---

## 15. Rollback / recovery posture — what must never be destructively rolled back

**Do not run a routine `php artisan migrate:rollback` covering the RFC-005 migration batch(es) after any live RFC-005 use.** Every RFC-005 table uses `restrictOnDelete()` foreign keys (confirmed across 23+ migration files this pass) — this protects row-level referenced-row deletion (e.g. you cannot delete a `businesses` row while a wallet still references it), but it does **not** protect against a `Schema::dropIfExists()` batch rollback, which drops each RFC-005 table outright regardless of its foreign keys, following RFC-004's own already-documented rollback-safety finding (`RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md` §17) for exactly the same reason.

Data that must **never** be destructively rolled back once real usage has occurred:

- `business_usage_wallets` / `business_usage_ledger_entries` / `business_usage_reservations` — the append-only financial ledger and its current-balance/committed-spend counters.
- `business_payer_assignments` / `business_billing_contacts` — payer/contact configuration and their historical snapshots on funding attempts.
- `business_funding_attempts` / `business_funding_attempt_transitions` / `payment_provider_events` — the full funding and webhook-processing history, including refund/dispute-outcome data (R8).
- `business_billing_receipts` — issued receipts (R3).
- `additional_business_slot_agreements` / `additional_business_slot_renewal_charges` and their transition tables — real, potentially still-active recurring payment agreements.
- `business_usage_addon_purchases` / `business_usage_addon_purchase_transitions`.

Preferred recovery paths, in order of preference, mirroring RFC-004's own established posture:

1. **Forward repair/redeploy.** Fix the underlying issue and deploy a new migration or code fix on top of the existing, additive RFC-005 schema.
2. **Roll back application code only, while retaining the RFC-005 database schema and data.** Review compatibility with whatever code version you are rolling back to.
3. **Any actual physical schema/data removal** requires a separately reviewed backup-and-data-migration plan, executed deliberately with a verified backup in hand — never an automatic or routine migration rollback.

---

## 16. Separation from the legacy subscription/quota stack

RFC-005 introduces no change to the legacy `Plan`/`Subscription`/`PaymentMethods` classes or their tables (`app/Models/Plan.php`, `app/Models/Subscription.php`) — confirmed unmodified by any RFC-005 milestone or remediation's authorized scope. The legacy stack and RFC-005's own wallet/usage-billing stack are fully independent; a Business's legacy subscription/quota state has no bearing on its RFC-005 wallet, and vice versa. `EnsureUserIsAdministrator` (the platform-administrator gate every RFC-005 admin surface sits behind) remains unmodified and checks only `users.is_admin`, independent of any RFC-005 permission or state.

---

## 17. Optional Conversations pilot activation procedure

**Optional, human-operator-only. Not required for deployment, and not performed by this guide or by any RFC-005 milestone/remediation to date.**

`app/Console/Commands/ActivateConversationsUsageRate.php` (`usage:activate-conversations-rate`) exists and requires, as prerequisites, that all three of `USAGE_BILLING_CONVERSATIONS_PILOT_BUSINESS_ID` / `_PILOT_COUNTRY_ID` / `_PILOT_SENDING_SERVER_ID` be set to real, matching values (§3 above) — none of which this guide invents or recommends a value for. An operator choosing to activate the pilot must independently decide and set those three values, and a real per-unit rate, through channels outside this deployment guide's scope. Until an operator does so, Conversations remains classified non-metered like every other feature, and no metering-related billing occurs for it.

---

## 18. Explicit production-launch limitations

This deployment guide covers **technical deployment of RFC-005's existing, tested architecture**. It does not, and cannot, resolve:

- **Production tax/VAT legal sufficiency (§39 item 6)** — an explicit legal/compliance gate. No legal conclusion is offered here or anywhere in RFC-005's own governance documents.
- **Live Stripe charging readiness** — explicitly blocked (`BLOCKED_FOR_LIVE_CHARGING`), independent of the tax/VAT item.
- Every other open §39 commercial/product decision (exact retail rates, default spend cap, default per-feature limits, auto-recharge default threshold/cap, Agency subsidy policy, Agency rebilling timing, v1 add-on roster/pricing, per-feature safety-limit ceilings, currency/multi-currency scope).
- The additional-slot agreement `payment_lapsed` grandfathered-capacity revocation policy (§39 item 13) — M4 implements every RFC-defined lapse/recovery mechanism but performs zero automatic Business-slot revocation; this remains an open human product-policy decision.
- The deferred, non-§39, low-balance-notification-after-successful-auto-recharge timing observation, first disclosed by the Job/Event Dispatch Completion Correction remediation and still unresolved.

See `docs/automation/RFC-005-M6-CONFORMANCE.md` for the complete, currently-accurate list of every open item, honestly recorded and never silently defaulted.

---

## 19. Release/tag verification

No `rfc-005-*` tag exists yet, locally or on `origin`, as of this Milestone 6 pass (confirmed via `git tag -l` and `git ls-remote --tags origin`). The eventual `rfc-005-business-usage-billing-and-wallets` annotated tag is created only after the M6 release-readiness PR is human-merged and the post-merge exact-tag-candidate regression (`php artisan test --stop-on-failure`, run again against the exact `main` commit about to be tagged) passes — both gated by explicit human authorization, never automatic, per `docs/automation/RFC-005-M6-CONTRACT.md` §§8–9. Once created, verify:

```bash
git ls-remote --tags origin | grep rfc-005-business-usage-billing-and-wallets
git cat-file -t rfc-005-business-usage-billing-and-wallets   # must print "tag"
git cat-file -p rfc-005-business-usage-billing-and-wallets   # must show a tagger line and the exact message
```

Expected annotation message: `RFC-005 Business Usage Billing and Wallets · Milestone 6 complete`.

---

## 20. Out of scope

- Live Stripe charging of any kind (§5).
- Setting real, non-null commercial parameter values for any open §39 item (§18).
- Activating the Conversations pilot (§17) — an optional, separate human operator action.
- Resolving the additional-slot grandfathered-capacity revocation policy (§39 item 13).
- Any legal or tax/VAT determination (§39 item 6).
- Milestone 6's own conformance/tag governance process beyond §19 above — see `docs/automation/RFC-005-M6-CONTRACT.md` and `docs/automation/RFC-005-M6-CONFORMANCE.md` for that governance record.
