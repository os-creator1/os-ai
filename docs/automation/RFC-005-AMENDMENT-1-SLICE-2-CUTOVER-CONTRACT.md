# RFC-005 Amendment 1 — Slice 2 CUTOVER Implementation Contract

<a id="locked-slice-2-cutover-contract"></a>

**Status:** Human-reviewed governance contract. Authorizes implementation on
pull request #114 (`agent/rfc-005-amendment-1-slice-2-cutover`) only after
this document and `docs/automation/AI-AUTONOMY-STATE.json` are merged to
`main` by a human.

This contract does not redesign RFC-005 Amendment 1. It encodes the Slice 2
CUTOVER behavior already fixed by the merged design document
(`docs/rfcs/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY.md`, merged in PR #111)
and the merged governance contract
(`docs/automation/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY-CONTRACT.md`,
merged in PR #108/#110), against the current, real, merged source of
`app/Library/Usage/UsageWalletManager.php` and its repositories as they
exist on `main` after Slice 1 EXPAND (PR #113).

## §0 Governance

- This contract binds exactly one implementation pull request: #114.
- No schema migration of any kind may be part of this slice.
- No other RFC, amendment slice, or milestone is opened, advanced, or
  implied by this contract. M5 remains unauthorized. PR #107 remains an
  open, unmerged, Draft blocker and is not touched by this slice.
- Codex review is not required for this slice unless a human separately
  requires it; the merged design does not condition Slice 2 on Codex.
- A maximum of 2 correction rounds applies, per
  `docs/automation/AI-AUTONOMY-STATE.json`'s `maximum_correction_rounds`.

## §1 Purpose

Re-point `UsageWalletManager`'s runtime charging authority from
`PlatformFeatureUsageClassification`/`PlatformFeatureUsageClassificationTransition`
to `UsageMeter`/`UsageMeterTransition`, while:

- making zero schema change of any kind;
- keeping every public domain/API method signature on `UsageWalletManager`
  byte-identical to its current merged form;
- keeping `EntitlementManager::decide` and `UsageAuthorizationGateway`
  signatures and their nine existing entitlement decision keys byte-identical;
- dual-writing `feature_key` (from the resolved meter's own immutable
  `feature_key`) and `meter_key` on every rate, activation, reservation, and
  ledger write touched by this slice's methods;
- leaving the legacy classification tables/repositories present, unmodified,
  and runtime-inert for Amendment charging logic.

## §2 Mechanical Audit (source of truth for every fact below)

Confirmed directly against the current merged worktree at `main`
(`0ba7f6362b56d5e6fae9a95df93decdbee8606c2`) before this contract was written:

- `app/Library/Usage/UsageWalletManager.php` (1190 lines) — constructor at
  lines 62–75 injects exactly 11 repositories, two of which
  (`PlatformFeatureUsageClassificationRepository`,
  `PlatformFeatureUsageClassificationTransitionRepository`) are referenced
  **only** inside `reserve()`, `setActiveRate()`, `activateMetering()`, and
  `evaluateCoarseCapacity()` — no other method on the class (including every
  M2/M3 method: `setSpendCap`, `setFeatureLimit`, `setSafetyLimit`,
  `setBillingStatus`, `configureAutoRecharge`, `recordAutoRechargeFailure`,
  `commit`, `release`, `creditFromFunding`, `expireStaleReservations`,
  `initializeWalletForNewBusiness`, and all private helpers including
  `isDuplicateRace()`) touches either classification repository. This
  satisfies the merged design's constructor-removal condition: once the four
  methods above are re-pointed, no genuine dependency on either classification
  repository remains.
- `app/Exceptions/Usage/UsageMeterBusinessScopeMismatchException.php`,
  `UsageMeterCurrencyMismatchException.php`, `UsageMeterNotMeteredException.php`,
  and `UsageMeterRateIntegrityException.php` already exist, were created in
  Slice 1 as additive scaffolding, and each carries a docblock stating it is
  "thrown by `UsageWalletManager::reserve()` (Slice 2, not yet wired in Slice
  1)". Slice 2 wires these four existing exceptions into `reserve()`; it does
  not invent new exception types.
- `app/Repositories/Contracts/BusinessUsageRateRepository.php` /
  `EloquentBusinessUsageRateRepository.php`: `findByFeatureAndVersion(string
  $featureKey, int $version)` has **zero real callers** anywhere in `app/` or
  `tests/` (confirmed by direct search of the full worktree; only its own
  interface and implementation definitions match). The zero-real-caller
  condition required by the merged design to rename this method still holds.
  `latestVersionForFeature(string $featureKey)` has exactly one real caller:
  `UsageWalletManager::setActiveRate()`.
- `app/Repositories/Contracts/UsageMeterRepository.php` /
  `EloquentUsageMeterRepository.php` (Slice 1): currently expose
  `findByMeterKey()`, `create()`, and a narrowly whitelisted `update()`
  (`active_rate_id`, `is_metered`, `description`, `updated_by_user_id`
  only — `meter_key`/`feature_key`/`business_id`/`currency_id` can never be
  mutated through it). No row-locking finder exists yet.
- `app/Repositories/Contracts/UsageMeterTransitionRepository.php` /
  `EloquentUsageMeterTransitionRepository.php` (Slice 1): append-only,
  expose only `create()`.
- Models `BusinessUsageRate`, `BusinessUsageRateActivation`,
  `BusinessUsageReservation`, `BusinessUsageLedgerEntry` already carry
  `meter_key` in `$fillable` alongside `feature_key` (Slice 1).
- Migrations `2026_08_22_120001`–`_120007` already establish, at final
  shape: `usage_meters` (unique `meter_key`; composite unique
  `[meter_key, currency_id]`); `usage_meter_transitions`; nullable
  `meter_key` shadow columns plus composite same-meter foreign keys on
  `business_usage_rates`, `business_usage_rate_activations`,
  `business_usage_reservations`, `business_usage_ledger_entries`; and the
  composite FK `rates_meter_currency_foreign` binding a rate's
  `(meter_key, currency_id)` to its owning meter's own
  `(meter_key, currency_id)`. The legacy
  `business_usage_rates_feature_key_version_unique` index is untouched and
  still enforced. No migration is added, altered, or reverted by this slice.
- **Existing-test blast radius (the decisive new finding of this audit):**
  five existing test files call `UsageWalletManager::setActiveRate('crm',
  ...)` and/or `->reserve($business, 'crm', ...)` against the current
  classification-based implementation, with no corresponding `UsageMeter`
  fixture row and no `activateMetering()` call anywhere in their setup:
  `NoAutoRechargeDispatchAtM1Test.php`, `UsageCalendarMonthRolloverTest.php`,
  `UsageWalletManagerCommittedSpendFormulaTest.php`,
  `UsageWalletManagerConcurrencyTest.php`,
  `UsageWalletManagerReservationLifecycleTest.php`. After cutover,
  `setActiveRate('crm', ...)` requires a genuine, pre-existing `UsageMeter`
  row with `meter_key = 'crm'`, and `reserve()` newly requires that meter's
  `is_metered` to be `true` (via the already-scaffolded
  `UsageMeterNotMeteredException` path). Every one of these five files'
  fixture setup must therefore be extended, in place, to create a real
  `UsageMeter` row (via `UsageMeterRepository::create()`) and call
  `activateMetering('crm', ...)` before calling `setActiveRate()`/`reserve()`,
  or all five files regress to failure on the very next real test run. This
  is not new design — it is the mechanical consequence of wiring the
  already-scaffolded exceptions and is required for the implementation to be
  real rather than merely additive.
- No existing test currently exercises `UsageWalletManager::activateMetering()`
  or `setActiveRate()`'s concurrency behavior; the only textual match for
  "activateMetering" outside `UsageWalletManager.php` itself is a docblock
  comment in `UsageMeterTransitionSchemaTest.php`, not a real call.
- The concurrency-proof technique already established in the codebase
  (`UsageWalletManagerConcurrencyTest.php`) uses genuine, separate OS
  processes (Symfony `Process` + `PhpExecutableFinder`) that each boot the
  full Laravel application and hold a real row lock via
  `DB::transaction()` + `lockForUpdate()`, synchronized over STDOUT lines
  and/or a filesystem signal flag — not simulated or mocked concurrency.
  Slice 2's new concurrency proofs must reuse this exact technique.

## §3 Absolute Runtime Boundary

- Zero schema mutation. No migration file is added, edited, or reverted.
- Every public method signature on `UsageWalletManager` is byte-identical to
  its current merged signature. The constructor is the only exception, and
  only for repository dependency wiring (remove
  `PlatformFeatureUsageClassificationRepository` and
  `PlatformFeatureUsageClassificationTransitionRepository`; add
  `UsageMeterRepository` and `UsageMeterTransitionRepository`).
- No service locator, `app()`, `resolve()`, method injection, setter
  injection, or static/global repository state is introduced anywhere in
  this slice.
- No new public method is added to `UsageWalletManager`.
- `EntitlementManager::decide` and `UsageAuthorizationGateway` are not
  touched. No new entitlement decision key is introduced; the existing nine
  remain unchanged.
- No Planned feature becomes executable because billing now exists.
- No synthetic/fake privileged actor. Every `updated_by_user_id`/
  `actor_user_id`/`created_by_user_id`/`activated_by_user_id` used anywhere
  in this slice's product code or tests is a genuine `User` id, consistent
  with `EloquentUsageMeterRepository::create()`'s existing actor-existence
  check.
- No real `UsageMeter`, rate, or pilot value is fabricated. Test fixtures may
  create their own disposable rows (as every existing Usage test already
  does for classifications/rates) — this is ordinary test setup, not
  production backfill.
- Slice1-era rows with `meter_key IS NULL` are not fabricated, backfilled, or
  otherwise mutated by this slice. Any unexpected legacy NULL row remains a
  Slice 3 stop/remediation condition, not a Slice 2 concern.
- The legacy classification tables and their two repositories remain present
  and are removed from `UsageWalletManager`'s constructor only — they are
  neither modified nor deleted as files, and no other code path's use of
  them (if any exists elsewhere in the codebase, outside `UsageWalletManager`)
  is touched.

## §4 Exact Schema Scope

None. Zero migrations. Zero schema mutation of any table, column, index, or
foreign key.

## §5 Exact Code Scope — Required Behavior Per Method

### 5.1 Constructor

Remove `PlatformFeatureUsageClassificationRepository` and
`PlatformFeatureUsageClassificationTransitionRepository`. Add
`UsageMeterRepository $meterRepository` and
`UsageMeterTransitionRepository $meterTransitionRepository`. All other
constructor parameters are unchanged, in place, in their existing order.

### 5.2 `UsageMeterRepository` — add exactly one method

Add `findForUpdateByMeterKey(string $meterKey): ?UsageMeter` to the contract
and its Eloquent implementation, using
`$this->query()->where('meter_key', $meterKey)->lockForUpdate()->first()`.
No other method is added, removed, or changed on this repository.

### 5.3 `BusinessUsageRateRepository` — rename exactly one method

Rename `findByFeatureAndVersion(string $featureKey, int $version)` to
`findByMeterAndVersion(string $meterKey, int $version)`, querying
`meter_key` instead of `feature_key`. This is safe only because §2 confirms
zero real callers exist; if implementation discovers a real caller that this
audit missed, stop and report — do not rename. `latestVersionForFeature()`
and `create()` are unchanged in name and signature.

### 5.4 `reserve(Business $business, string $featureKey, string $idempotencyKey, ?string $quantity = null)` (signature frozen)

The `$featureKey` parameter is semantically the meter key. After the
existing wallet resolution/lock and rollover steps (unchanged), replace the
classification lookup with, in order:

1. `$meter = $this->meterRepository->findByMeterKey($featureKey);` — if
   `null`, throw `NoActiveRateForFeatureException($featureKey)`.
2. If `$meter->business_id !== null` and does not match `$business->id`,
   throw `UsageMeterBusinessScopeMismatchException($featureKey,
   (int) $business->id)`.
3. If the wallet's `currency_id` does not match `$meter->currency_id`, throw
   `UsageMeterCurrencyMismatchException($featureKey, (int)
   $wallet->currency_id, (int) $meter->currency_id)`.
4. If `$meter->active_rate_id === null`, throw
   `NoActiveRateForFeatureException($featureKey)`.
5. If `! $meter->is_metered`, throw
   `UsageMeterNotMeteredException($featureKey)`.
6. `$rate = $this->rateRepository->findById((int) $meter->active_rate_id);`
   — if `null`, throw `NoActiveRateForFeatureException($featureKey)`.
7. If `$rate->meter_key !== $meter->meter_key` or `(int) $rate->currency_id
   !== (int) $meter->currency_id`, throw
   `UsageMeterRateIntegrityException($featureKey, $rate->id)` (defensive
   only — structurally unreachable given the composite database
   constraints).

The reservation `create()` payload dual-writes `'feature_key' =>
$meter->feature_key` and `'meter_key' => $meter->meter_key` — never the raw
`$featureKey` parameter. The reservation's own ledger entry (the
"Reservation" type, created immediately after the reservation row) copies
`'feature_key' => $reservation->feature_key` and `'meter_key' =>
$reservation->meter_key` from the just-created reservation, not by
re-deriving from `$meter` independently.

### 5.5 `commit()` and `release()` (signatures frozen)

Every ledger `create()` call inside `commit()` (UsageCharge,
UsageOverageCharge, and ReservationRelease shapes) and inside `release()`
(ReservationRelease shape) that currently copies `'feature_key' =>
$reservation->feature_key` gains `'meter_key' => $reservation->meter_key`
alongside it. No other change to either method.

### 5.6 `setActiveRate(string $featureKey, string $retailRateMicro, string $providerCostMicro, string $unitLabel, int $currencyId, int $actorUserId, string $reason): BusinessUsageRate` (signature frozen)

Bounded 3-attempt retry, each attempt a fresh transaction:

```
for attempt in 1..=3:
    try:
        return DB::transaction(function () {
            meter = meterRepository.findForUpdateByMeterKey(featureKey)
            if meter is null: throw NoActiveRateForFeatureException(featureKey)
            nextVersion = rateRepository.latestVersionForFeature(meter.feature_key) + 1
            rate = rateRepository.create([
                feature_key: meter.feature_key, meter_key: meter.meter_key,
                version: nextVersion, retail_rate_micro, provider_cost_micro,
                unit_label, rounding_rule: RoundHalfUp, currency_id,
                created_by_user_id: actorUserId, created_at: now(),
            ])
            rateActivationRepository.create([
                feature_key: meter.feature_key, meter_key: meter.meter_key,
                rate_id: rate.id, activated_at: now(),
                activated_by_user_id: actorUserId, reason, created_at: now(),
            ])
            meterRepository.update(meter, [
                active_rate_id: rate.id, updated_by_user_id: actorUserId,
            ])
            return rate
        })
    catch QueryException e:
        if isDuplicateRace(e, 'business_usage_rates_feature_key_version_unique')
           and attempt < 3:
            continue
        throw e
```

`isDuplicateRace()` is the existing private method — reused verbatim,
unmodified. No new mutex, no locking of the classification row. `currencyId`
is not separately validated in code; a mismatched currency fails at insert
time via the existing `rates_meter_currency_foreign` composite foreign key,
exactly as it does today for any other foreign-key-backed create.

### 5.7 `activateMetering(string $featureKey, int $actorUserId, string $reason): void` (signature frozen)

Inside a single `DB::transaction()`:

1. `$meter = $this->meterRepository->findForUpdateByMeterKey($featureKey);`
   — if `null` or `$meter->active_rate_id === null`, throw
   `NoActiveRateForFeatureException($featureKey)`.
2. `$this->meterTransitionRepository->create([...])` recording
   `meter_key`, `from_is_metered => $meter->is_metered`, `to_is_metered =>
   true`, `from_active_rate_id`/`to_active_rate_id => $meter->active_rate_id`
   (unchanged by activation), `actor_user_id`, `reason`, `created_at`.
3. `$this->meterRepository->update($meter, ['is_metered' => true,
   'updated_by_user_id' => $actorUserId]);`

### 5.8 `evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision` (signature frozen)

Replace the entire body with `return new UsageCapacityDecision(true);`.
Feature entitlement must not depend on wallet health. Parameters remain
present (frozen signature) even though the body no longer reads them.

## §6 Repository Authority Rules

- `UsageMeterRepository::update()` keeps its existing narrow whitelist
  (`active_rate_id`, `is_metered`, `description`, `updated_by_user_id`).
  Nothing in this slice needs, and nothing in this slice may add, a wider
  mutation surface.
- `UsageMeterTransitionRepository` remains append-only; no update/delete
  method is added.
- The two classification repositories are not modified as files.

## §7 Data / Backfill Rule

No production data is created, altered, or backfilled. Slice1-era rows with
`meter_key IS NULL` are left exactly as they are. Test fixtures creating
their own disposable `UsageMeter`/rate/activation rows (see §5.4's five
existing files and the two new test files in §9) are ordinary test setup,
not backfill, and do not violate this rule.

## §8 Required Proofs

All of the following must be demonstrated by real, passing, non-mocked
`php artisan test` runs against `ultimatesms_testing` MySQL, using the
codebase's existing genuine-OS-process concurrency technique (§2) for the
four concurrency proofs:

1. **Same-meter concurrent rotations serialize correctly** — two real OS
   processes both call `setActiveRate()` for the same `meter_key`; the
   second genuinely blocks on `findForUpdateByMeterKey()`'s row lock until
   the first's transaction commits, then proceeds; both succeed with
   strictly increasing versions and no lost update.
2. **Sibling meters sharing one feature can collide, and the loser retries
   and still succeeds** — two `UsageMeter` rows sharing one `feature_key`
   but different `meter_key`s (and different currencies, since
   `usage_meters` is unique on `[meter_key, currency_id]` and rates are
   feature-key-version-unique only) race `setActiveRate()`; construct the
   scenario so both attempt to insert the same `(feature_key, version)`
   pair, forcing exactly one `QueryException` against
   `business_usage_rates_feature_key_version_unique`; the losing process's
   retry recomputes `latestVersionForFeature()` and succeeds on its next
   attempt.
3. **Successful overlap yields two durable rates/activations and correct
   active IDs** — after proof 2, both meters' `active_rate_id` point at
   their own genuinely persisted rate, and both rates/activations carry the
   correct dual-written `feature_key`/`meter_key` pair.
4. **Exact collision on all 3 attempts propagates the original
   `QueryException` with no partial state** — a fixture that forces the
   unique-constraint collision on every attempt results in the original
   `QueryException` propagating uncaught after the third attempt, and no
   rate, activation, or meter update from any attempt is left committed.

Additionally, real (non-concurrency) coverage must demonstrate:

5. `reserve()`'s new meter-authority checks: business-scope mismatch,
   currency mismatch, not-metered, and rate-integrity paths each throw their
   already-scaffolded exception, and the happy path dual-writes
   `feature_key`/`meter_key` correctly on the reservation and its ledger
   entry.
6. `commit()`/`release()` propagate `meter_key` from the reservation onto
   every ledger entry they create.
7. `activateMetering()` writes a `UsageMeterTransition` row and flips
   `is_metered` to `true` via `UsageMeterRepository::update()`'s existing
   whitelist — no classification table is touched.
8. `evaluateCoarseCapacity()` unconditionally returns an allowed
   `UsageCapacityDecision` regardless of wallet or meter state.
9. `findByMeterAndVersion()` (renamed) resolves correctly by `meter_key`,
   and `findForUpdateByMeterKey()` genuinely locks its row (provable via the
   same OS-process technique as proof 1, or by reuse of proof 1's fixture).
10. All five pre-existing test files identified in §2 pass unmodified in
    their assertions after their fixture setup is extended with a real
    `UsageMeter` row plus `activateMetering()` call — i.e., cutover causes
    no regression in existing reservation/rollover/spend-formula/
    auto-recharge-gating/concurrency coverage.

## §9 Exact Implementation Allowlist

`require_exact_scope: true` — pull request #114's cumulative diff against
`main` must equal this list exactly, no more and no fewer paths. It
necessarily includes the inert target marker already present on #114 from
its prior commit.

1. `docs/automation/RFC-005-AMENDMENT-1-SLICE-2-CUTOVER-TARGET.md` (already
   present; no further change expected to its content)
2. `app/Library/Usage/UsageWalletManager.php`
3. `app/Repositories/Contracts/UsageMeterRepository.php`
4. `app/Repositories/Eloquent/EloquentUsageMeterRepository.php`
5. `app/Repositories/Contracts/BusinessUsageRateRepository.php`
6. `app/Repositories/Eloquent/EloquentBusinessUsageRateRepository.php`
7. `tests/Feature/Usage/NoAutoRechargeDispatchAtM1Test.php`
8. `tests/Feature/Usage/UsageCalendarMonthRolloverTest.php`
9. `tests/Feature/Usage/UsageWalletManagerCommittedSpendFormulaTest.php`
10. `tests/Feature/Usage/UsageWalletManagerConcurrencyTest.php`
11. `tests/Feature/Usage/UsageWalletManagerReservationLifecycleTest.php`
12. `tests/Feature/Usage/UsageMeterSchemaTest.php`
13. `tests/Feature/Usage/BusinessUsageRateSchemaTest.php`
14. `tests/Feature/Usage/BusinessUsageReservationLedgerSchemaTest.php`
15. `tests/Feature/Usage/UsageWalletManagerSetActiveRateConcurrencyTest.php`
    (new — proofs 1–4 and 9 from §8)
16. `tests/Feature/Usage/UsageWalletManagerMeterAuthorityTest.php` (new —
    proofs 5–8 and 10 from §8)

**Stop threshold:** if real implementation requires touching any path
outside this list, or discovers that any path in this list is unnecessary,
stop with `ai:needs-human` and report the discrepancy rather than expanding
or shrinking scope unilaterally.

Required test command (must report a positive test count):

```
php artisan test tests/Feature/Usage
```

## §10 Explicit Prohibitions

- No migration file of any kind.
- No change to `EntitlementManager`, `UsageAuthorizationGateway`, or any of
  the nine existing entitlement decision keys.
- No new public method on `UsageWalletManager`.
- No service locator / `app()` / `resolve()` / method or setter injection.
- No reintroduction of the classification row as a concurrency mutex.
- No fabricated `UsageMeter`, rate, or pilot data outside disposable test
  fixtures.
- No backfill or mutation of Slice1-era `meter_key IS NULL` rows.
- No touching PR #107.
- No Slice 3 or M5 work of any kind.
- No merge of any pull request by automation.
- No `ai:*` label applied to PR #114 by this contract or its authoring
  process — labels are applied by the existing automation only, per the
  merged `AI-AUTONOMY-STATE.json` policy.

## §11 M5 / PR #107

Unaffected. RFC-003 Milestone 5 remains unselected and unauthorized. PR #107
remains open, Draft, and unmerged, and continues to serve as blocker
evidence. Nothing in this slice changes that status.

## §12 Verification and Publication

Before implementation on PR #114 begins, this contract and
`docs/automation/AI-AUTONOMY-STATE.json` must be merged to `main` by a human,
via a separate, non-Draft pull request that changes exactly those two files.
After implementation, the exact-scope gate (`ai_subscription_gate.js
check-scope`) and the required test command above are the authoritative
completion evidence; this document is not amended to reflect implementation
output — a correction round is opened instead if reality diverges from §5–§9.
