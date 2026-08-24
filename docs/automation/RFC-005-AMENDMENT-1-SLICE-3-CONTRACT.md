# RFC-005 Amendment 1 — Slice 3 CONTRACT (Schema Contraction) Implementation Contract

<a id="locked-slice-3-contract"></a>

**Status: completed and closed.** Implementation was PR #119 (branch
`agent/rfc-005-amendment-1-slice-3-contract`), human-merged as
`3d512d5c981792f32dbb1fad941e9cb158455c7a`, final head
`11d4c485399feb3b1097c9e08df3ecf44a19cdac`. Three exceptional corrections
(PR #121–#123) and two post-verification test-alignment corrections
(PR #124–#125) were authorized and applied against the locked scope below
before merge. See
[`docs/automation/RFC-005-AMENDMENT-1-CLOSURE.md`](RFC-005-AMENDMENT-1-CLOSURE.md)
for full closure evidence — Slice 3 does not have a separate slice-level
closure document; its completion is recorded in the combined RFC-005
Amendment 1 closure alongside Slices 1 and 2.

Historical context below, prepared after Slice 2 CUTOVER
(PR #114) had already been human-merged, and reconciled after RFC-005
Amendment 1 Design Correction 2 (PR #118) had also already been
human-merged. This document locked the exact scope, schema, code, and proof
requirements for the Slice 3 implementation.

This contract does not redesign RFC-005 Amendment 1. It encodes the Slice 3
CONTRACT behavior already fixed by the merged design document
(`docs/rfcs/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY.md`, merged in PR #111,
and corrected by Design Correction 2, PR #118 — §D "Slice 3 — CONTRACT",
§E "Slice 3 — CONTRACT", §F "Slice 3 — CONTRACT", §O steps 19–23 and its
"Slice 3 rollback" subsection, and proofs 35–41), against the current, real,
merged source of `app/Library/Usage/UsageWalletManager.php` and its
repositories as they exist on `main` after Slice 2 CUTOVER (PR #114, merged
as commit `054ce63f0facc1b0e3077a2a394ae24e2f20c70e`).

## §0 Governance

- No schema migration, code change, or test file for Slice 3 is written by
  this PR. This PR changes only governance documentation and automation
  state.
- No other RFC, amendment slice, or milestone is opened, advanced, or
  implied by this contract. RFC-005 Milestone 5 remains unauthorized. PR
  #107 remains an open, unmerged, Draft blocker and is not touched.
- Codex review is not required for the eventual Slice 3 implementation
  unless a human separately requires it.
- A maximum of 2 ordinary correction rounds applies to the eventual Slice 3
  implementation, per `docs/automation/AI-AUTONOMY-STATE.json`'s
  `maximum_correction_rounds`.
- Human-only merge remains mandatory for both this governance PR and the
  later Slice 3 implementation PR.

### §0.1 Why this contract does not authorize automatic start

**Repository truth, stated precisely (no invented precedent):** Slice 1's
actual governance sequence was contract PR #112 merged first, followed
directly by implementation PR #113 — there was no separate inert
target-marker PR preceding #112. Slice 2's actual sequence was different:
an inert target PR (#114), at a locked head SHA, was established first, and
only then did governance contract PR #115 point
`AI-AUTONOMY-STATE.json` at that specific PR/SHA and set
`start_automatically_after_contract_merge: true`. These are two genuinely
different sequences from the two prior slices, not one repeated pattern —
either is a legitimate precedent, and this contract does not assume either
one is required for Slice 3.

**No Slice 3 target PR exists yet.** Slice 3 is also materially more
destructive than Slice 1 or Slice 2 — it drops columns and tightens `NOT
NULL` constraints on live tables — and its own preflights (§4 below) have
not yet been run against a real deployment.

**The currently selected workflow is manual: Claude Code (interactively) +
ChatGPT + human review and merge — not the automatic Codex/AI Subscription
Loop.** This contract does not assume, plan for, or imply that merging it,
or any later state update, will start or resume that automatic loop. A
future, separate, human-selected task may establish a Slice 3 target PR and
separately authorize implementation against its exact locked head SHA — via
the manual workflow, or via the automatic loop only if a human explicitly
re-selects that policy at that time. Neither decision is made here. This
governance update therefore keeps every automatic-execution flag false
(§9's `AI-AUTONOMY-STATE.json` fields: `implementation_authorized`,
`start_automatically_after_contract_merge`, `advance_automatically`,
`codex_review_required_for_completion`, `automatic_model_handoff_required`),
alongside the exact `allowed_paths`/`required_new_paths`/
`required_test_commands` a later authorization update would need, so that
step is a small, reviewable diff rather than a rewrite.

## §1 Purpose

Contract the schema to its final Amendment 1 shape: tighten `meter_key` to
`NOT NULL` and drop the legacy `feature_key` identity from
`business_usage_rates`/`business_usage_rate_activations` (never from
`business_usage_reservations`, where `feature_key` is the permanent
owning-feature snapshot, or from `business_usage_ledger_entries`, where
`meter_key` stays nullable permanently); retire the Slice 2 transitional
owning-feature-wide version allocator and its duplicate-race retry loop in
favor of the final meter-local allocator and a plain single-transaction row
lock; preflight fail-closed, as one global check spanning all three
tightened tables, against any remaining `meter_key IS NULL` row, with zero
fabrication under any circumstance, both forward and in rollback.

## §2 Mechanical Audit — current repository reality, reconciled against the design's Slice 3 assumptions

Confirmed directly against the current merged worktree at `main`
(`e187aa9623ada4bca21c80750d13d6f07e455f74`, the merge of PR #118, Design
Correction 2) before this reconciliation was written. **No contradiction
between the merged Slice 2 state, the merged Design Correction 2 text, and
this contract's own requirements was found; nothing below required
stopping.**

- `app/Library/Usage/UsageWalletManager.php` remains byte-identical to PR
  #114's own head — Design Correction 2 touched only
  `docs/rfcs/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY.md`, no product file.
- `setActiveRate()`'s current (Slice 2) body: a bounded 3-attempt loop
  around `DB::transaction()`, calling `findForUpdateByMeterKey()`, then
  `latestVersionForFeature($meter->feature_key) + 1`, dual-writing
  `feature_key`/`meter_key` on both the rate and activation inserts, then
  updating the meter's `active_rate_id`; the `catch (QueryException $e)`
  block retries only on `isDuplicateRace($e,
  'business_usage_rates_feature_key_version_unique')` while `$attempt < 3`.
  Exactly what §D of the design says Slice 3 must replace with a single
  `DB::transaction()` call and `latestVersionForMeter()`.
- `findForUpdateByMeterKey()` (Slice 2) is unaffected by Slice 3 — the
  design's own final concurrency guarantee (proof 41) depends on this
  exact existing lock, not a new one.
- `app/Exceptions/Usage/UsageMeterBackfillIncompleteException.php` and
  `app/Exceptions/Usage/UsageMeterRollbackVersionCollisionException.php`
  **do not exist yet.** Confirmed by direct search of `app/Exceptions/`.
  Both are Slice 3's own, per the now-merged design (§D/§E/§F, §O "Slice 3
  rollback").
- `app/Models/BusinessUsageRate.php` and
  `app/Models/BusinessUsageRateActivation.php` both still carry
  `'feature_key'` in `$fillable` alongside `'meter_key'`. Slice 3 must
  remove `'feature_key'` from both once the column is dropped.
  `BusinessUsageReservation` and `BusinessUsageLedgerEntry` correctly keep
  `'feature_key'` — unaffected, since neither table's `feature_key` column
  is dropped by Slice 3.
- Migrations `2026_08_22_120001`–`_120007` (Slice 1) remain the latest
  Usage-related migrations on `main` — no Slice 2 migration exists (zero
  schema mutation, as its own contract required), so Slice 3's own
  migrations are the very next ones in sequence.
- No existing test currently seeds a `meter_key = NULL` row on any of the
  three tightened tables to exercise a fail-closed preflight, forward or
  in rollback — confirmed by search. A genuinely new test file is required.
- `tests/Feature/Usage/UsageWalletManagerSetActiveRateConcurrencyTest.php`
  (Slice 2) currently proves the sibling-meter collision-and-retry
  behavior. This behavior ceases to exist once Slice 3 drops
  `business_usage_rates_feature_key_version_unique`; this file must be
  modified in Slice 3 as described in §9.

## §3 Absolute Runtime Boundary (for the eventual Slice 3 implementation)

- Preflight is fail-closed and non-negotiable, both forward and in
  rollback, and is a single **global** check across every table it
  concerns before the first DDL statement of any kind runs (§4, §6).
- No fabricated, copied, inferred, or backfilled meter identity, under any
  circumstance, in either the forward migration or its rollback. A row
  whose `meter_key` cannot resolve to exactly one `usage_meters` row fails
  closed. A rollback that cannot recreate the legacy uniqueness constraint
  without a value collision fails closed. Neither case is ever resolved by
  renumbering, guessing, or fabricating a value.
- Every existing public domain/API method signature on `UsageWalletManager`
  remains byte-identical to its current merged form. No new public method
  is added. Constructor signature is unaffected — Slice 3 needs no new
  repository dependency.
- No service locator, `app()`, `resolve()`, method injection, setter
  injection, or static/global repository state is introduced.
- `EntitlementManager::decide` and `UsageAuthorizationGateway` are not
  touched. No new entitlement decision key is introduced; the existing
  nine remain unchanged. No live charging authorization is introduced —
  Slice 3 is schema/versioning-allocator work only, exercised solely
  through `UsageWalletManager`'s already-frozen methods and disposable
  test fixtures, exactly as Slice 1 and Slice 2 were.
- No real `UsageMeter`, rate, or pilot value is fabricated anywhere,
  including in migration-verification test fixtures.
- No synthetic/fake privileged actor — every actor id used anywhere in
  Slice 3's tests is a genuine, disposable `User` row, matching the Slice
  2 precedent.
- No RFC-005 Milestone 5 work of any kind. No change to PR #107.
- `business_usage_reservations.feature_key` is never dropped or relaxed —
  it is the permanent owning-feature snapshot in the final architecture.
- `business_usage_ledger_entries.meter_key` is never touched — it remains
  nullable permanently, unaffected by this slice.

## §4 Exact Schema Scope

Three new migrations. Their file-level DDL responsibilities are unchanged
from a naive per-table decomposition, **but two of the three also own a
global preflight that is not about their own table alone** — placed there
specifically because of how Laravel actually orders `up()`/`down()`
execution across a batch (justified in full in §6; do not read this
section without §6).

### 4.1 `business_usage_rates` — migration 1, `2026_08_24_120001` (design §D-4/D-5, step 20)

`up()` performs the **global forward preflight** across all three tables
first, then this table's own DDL:

```php
// Global forward preflight — all three tables, before any Slice 3 DDL.
foreach ([
    'business_usage_rates',
    'business_usage_rate_activations',
    'business_usage_reservations',
] as $table) {
    $remaining = DB::table($table)->whereNull('meter_key')->count();
    if ($remaining > 0) {
        throw new UsageMeterBackfillIncompleteException($table, $remaining);
    }
}

// This migration's own DDL — reached only once the check above passes.
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable(false)->change();
    $table->dropUnique('business_usage_rates_feature_key_version_unique');
    $table->dropColumn('feature_key');
});
```

`down()` performs only this table's own restoration DDL — no preflight of
its own (§6 explains why none is needed here):

```php
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->string('feature_key', 64)->nullable()->after('meter_key');
});
DB::statement(
    'UPDATE business_usage_rates r JOIN usage_meters m ON r.meter_key = m.meter_key SET r.feature_key = m.feature_key'
);
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->unique(['feature_key', 'version'], 'business_usage_rates_feature_key_version_unique');
});
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->string('feature_key', 64)->nullable(false)->change();
    $table->string('meter_key', 128)->nullable()->change();
});
```

No further index change is needed on the `up()` side —
`business_usage_rates_meter_key_version_unique` and
`business_usage_rates_meter_key_id_unique` already exist from Slice 1 and
already fully govern uniqueness once `feature_key`'s index is gone. No
plain `meter_key → usage_meters.meter_key` FK is added — the existing
composite `(meter_key, currency_id) → usage_meters(meter_key, currency_id)`
FK already subsumes it.

### 4.2 `business_usage_rate_activations` — migration 2, `2026_08_24_120002` (design §E-3/E-4, step 21)

`up()` performs only this table's own DDL — no preflight of its own (the
global forward preflight already ran in migration 1's `up()`, which
executes first): preflight (defensive, matching design §E-3's own text,
though structurally redundant given migration 1 already gates the whole
sequence — see §6) then tighten `meter_key` to `NOT NULL`; drop
`business_usage_rate_activations_feature_key_index`; drop `feature_key`.
`down()` performs only this table's own restoration DDL — no preflight of
its own (§6). Both existing FKs (plain `meter_key → usage_meters.meter_key`,
composite `(meter_key, rate_id) → business_usage_rates(meter_key, id)`)
already exist from Slice 1 and are retained unchanged.

### 4.3 `business_usage_reservations` — migration 3, `2026_08_24_120003` (design §F, step 22)

`up()` performs only this table's own DDL — no preflight of its own (§6):

```php
Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable(false)->change();
});
```

`down()` performs the **global rollback Preflights A and B** first
(§6 explains why this file, specifically, is where they must live), then
this table's own (trivial) DDL:

```php
// Preflight A — meter resolution, both business_usage_rates and
// business_usage_rate_activations, read-only, zero DDL.
foreach ([
    'business_usage_rates' => 'business_usage_rates',
    'business_usage_rate_activations' => 'business_usage_rate_activations',
] as $table) {
    $unresolved = DB::table($table)
        ->leftJoin('usage_meters', "{$table}.meter_key", '=', 'usage_meters.meter_key')
        ->whereNull('usage_meters.meter_key')
        ->count();
    if ($unresolved > 0) {
        throw new UsageMeterBackfillIncompleteException($table, $unresolved);
    }
}

// Preflight B — legacy-uniqueness collision, business_usage_rates only,
// read-only, zero DDL. business_usage_rate_activations' own legacy index
// is a plain (non-unique) index and can never collide.
$collision = DB::table('business_usage_rates')
    ->join('usage_meters', 'business_usage_rates.meter_key', '=', 'usage_meters.meter_key')
    ->select('usage_meters.feature_key', 'business_usage_rates.version', DB::raw('COUNT(*) as row_count'))
    ->groupBy('usage_meters.feature_key', 'business_usage_rates.version')
    ->havingRaw('COUNT(*) > 1')
    ->first();
if ($collision !== null) {
    throw new UsageMeterRollbackVersionCollisionException(
        $collision->feature_key,
        (int) $collision->version,
        (int) $collision->row_count,
    );
}

// This table's own (trivial) restoration DDL — reached only once both
// preflights above pass.
Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable()->change();
});
```

`feature_key` is **not** dropped by `business_usage_reservations`' `up()`
— it is the permanent owning-feature snapshot (design §F). Its rollback
needs no join-based restore, since nothing was dropped.

### 4.4 `business_usage_ledger_entries` (step 23)

**Not touched.** `meter_key` remains nullable permanently. No migration
for this table is part of Slice 3.

## §5 Exact Code Scope

### 5.1 `app/Exceptions/Usage/UsageMeterBackfillIncompleteException.php` (new)

```php
<?php

namespace App\Exceptions\Usage;

use RuntimeException;

class UsageMeterBackfillIncompleteException extends RuntimeException
{
    public function __construct(public readonly string $table, public readonly int $remainingCount)
    {
        parent::__construct(
            "UsageMeter backfill incomplete: {$remainingCount} row(s) in {$table} still have no meter_key."
        );
    }
}
```

Thrown by the global forward preflight (migration 1's `up()`) and by
rollback Preflight A (migration 3's `down()`) — never by `reserve()`,
`setActiveRate()`, `activateMetering()`, or any other request-time code
path.

### 5.2 `app/Exceptions/Usage/UsageMeterRollbackVersionCollisionException.php` (new)

```php
<?php

namespace App\Exceptions\Usage;

use RuntimeException;

class UsageMeterRollbackVersionCollisionException extends RuntimeException
{
    public function __construct(
        public readonly string $featureKey,
        public readonly int $version,
        public readonly int $collidingRowCount,
    ) {
        parent::__construct(
            "Slice 3 rollback cannot restore business_usage_rates_feature_key_version_unique: "
            . "{$collidingRowCount} row(s) would collide on feature_key '{$featureKey}', version {$version}."
        );
    }
}
```

Thrown only by rollback Preflight B (migration 3's `down()`) — never by
any request-time path, and never by the forward Slice 3 migrations.

### 5.3 `BusinessUsageRateRepository` / `EloquentBusinessUsageRateRepository`

Remove `latestVersionForFeature(string $featureKey): int`. Add:

```php
public function latestVersionForMeter(string $meterKey): int
{
    return (int) ($this->query()->where('meter_key', $meterKey)->max('version') ?? 0);
}
```

`findByMeterAndVersion()` and `create()` are unchanged in name and
signature. `findById()` is unchanged.

### 5.4 `UsageWalletManager::setActiveRate()` (signature frozen)

Reverts to a single `DB::transaction()` call, no retry loop:

```php
public function setActiveRate(
    string $featureKey,
    string $retailRateMicro,
    string $providerCostMicro,
    string $unitLabel,
    int $currencyId,
    int $actorUserId,
    string $reason,
): \App\Models\BusinessUsageRate {
    return DB::transaction(function () use ($featureKey, $retailRateMicro, $providerCostMicro, $unitLabel, $currencyId, $actorUserId, $reason) {
        $meter = $this->meterRepository->findForUpdateByMeterKey($featureKey);

        if ($meter === null) {
            throw new NoActiveRateForFeatureException($featureKey);
        }

        $nextVersion = $this->rateRepository->latestVersionForMeter($meter->meter_key) + 1;

        $rate = $this->rateRepository->create([
            'meter_key' => $meter->meter_key,
            'version' => $nextVersion,
            'retail_rate_micro' => $retailRateMicro,
            'provider_cost_micro' => $providerCostMicro,
            'unit_label' => $unitLabel,
            'rounding_rule' => RoundingRule::RoundHalfUp->value,
            'currency_id' => $currencyId,
            'created_by_user_id' => $actorUserId,
            'created_at' => Carbon::now(),
        ]);

        $this->rateActivationRepository->create([
            'meter_key' => $meter->meter_key,
            'rate_id' => $rate->id,
            'activated_at' => Carbon::now(),
            'activated_by_user_id' => $actorUserId,
            'reason' => $reason,
            'created_at' => Carbon::now(),
        ]);

        $this->meterRepository->update($meter, [
            'active_rate_id' => $rate->id,
            'updated_by_user_id' => $actorUserId,
        ]);

        return $rate;
    });
}
```

`feature_key` is no longer part of either create payload — the column no
longer exists. `isDuplicateRace()` becomes unused by this method; it is
not deleted by this slice unless a direct audit at implementation time
confirms it has zero remaining callers anywhere on the class (§2's audit
found none besides `initializeWalletForNewBusiness()`, unrelated).
`reserve()`, `commit()`, `release()`, `activateMetering()`, and
`evaluateCoarseCapacity()` are not touched by Slice 3.

### 5.5 `app/Models/BusinessUsageRate.php` / `app/Models/BusinessUsageRateActivation.php`

Remove `'feature_key'` from `$fillable` on both models. No other change.
`BusinessUsageReservation` and `BusinessUsageLedgerEntry` are not touched.

## §6 Migration Ordering — how the global preflights are actually guaranteed

**This is the load-bearing section; nothing above should be read as
authoritative about ordering without it.**

Laravel runs a batch of pending migrations' `up()` methods in ascending
filename/timestamp order, and rolls back that same batch's `down()`
methods in the exact **reverse** order — the migration whose `up()` ran
last is the migration whose `down()` runs first
(`Illuminate\Database\Migrations\Migrator::runPending()` /
`rollbackMigrations()`). With three files timestamped `_120001` (rates),
`_120002` (activations), `_120003` (reservations):

- **Forward (`php artisan migrate`):** `up()` order is rates → activations
  → reservations.
- **Rollback (`php artisan migrate:rollback`, whole batch):** `down()`
  order is reservations → activations → rates — the exact reverse.

**Forward placement.** The global forward preflight must run before any
of the three tables' DDL. Since rates' migration (`_120001`) is the first
to run `up()`, its preflight — checking all three tables' `meter_key IS
NULL` counts — is what actually executes first. Placed anywhere else
(e.g. inside activations' or reservations' own `up()`), it would run
*after* an earlier migration's DDL had already committed, which is exactly
the defect Design Correction 2 required fixing. If the global preflight
throws, Laravel's `migrate` command stops immediately — activations' and
reservations' `up()` methods never run at all, so zero DDL occurs anywhere.

**Rollback placement.** By the identical reasoning, reversed: the global
rollback Preflights A and B must run before any of rates' or activations'
restoration DDL. Because `down()` order is reservations → activations →
rates, **the migration whose `down()` runs first is reservations'
(`_120003`)** — not rates', despite rates being "migration 1." Placing
Preflights A/B inside reservations' `down()`, before its own (trivial,
harmless) `meter_key`-loosening statement, is therefore the only
placement that actually executes before activations' or rates' own
restoration DDL. If either preflight throws, Laravel's `migrate:rollback`
stops immediately — activations' and rates' `down()` methods never run,
so zero DDL occurs on either table (reservations' own trivial DDL also
never runs in the failure case, which is a conservative, safe
side-effect, not a violation of anything this contract requires).

**Why not a fourth, dedicated "gate" migration?** A standalone migration
timestamped after `_120003` would run `up()` last (correct for owning
nothing) but `down()` **first** in a rollback — the same position
reservations' migration already occupies. Adding a fourth file to hold
logic that already has a correct, natural home in an existing file (§4.3)
would be scope the contract does not need; §9's allowlist stays at three
migration files, not four.

**Scope of the guarantee.** This ordering guarantee holds for a normal,
whole-batch invocation — `php artisan migrate` / `php artisan
migrate:rollback` covering all three Slice 3 migrations together, exactly
how Slice 1, Slice 2, and Slice 3 have each been deployed as one coherent
unit throughout this Amendment. It is not a guarantee about an operator
using `--step` to roll back these three migrations individually, across
separate command invocations, out of their intended combined batch — doing
so is outside how this Amendment's slices are deployed and is not
addressed by this contract.

**If a future implementer finds this three-file decomposition cannot
literally satisfy this ordering** (for instance, if Slice 3 is ever split
across multiple `migrate` batches instead of one), **implementation must
stop and report the discrepancy — not silently reorder files, add an
undocumented fourth migration, or weaken either preflight to a per-file
check.**

## §7 Repository Authority Rules

- `BusinessUsageRateRepository::latestVersionForMeter()` is the sole
  version allocator after Slice 3; no owning-feature-wide method remains
  on the interface.
- No new repository interface or implementation is introduced.
- `UsageMeterRepository`/`UsageMeterTransitionRepository` are unaffected —
  Slice 3 adds no new method to either.

## §8 Rollback Discipline

Every rollback in §4 follows the same rule, with zero exceptions: verify
via read-only preflights against the columns Slice 3 already leaves in
place before any DDL runs (§4.3, §6); restore via an authoritative join
against `usage_meters`' own immutable `feature_key`; fail closed — via
`UsageMeterBackfillIncompleteException` (unresolved `meter_key`) or
`UsageMeterRollbackVersionCollisionException` (legacy uniqueness cannot be
restored without a collision) — with zero DDL anywhere on either affected
table if either preflight fails; never guess, renumber, or fabricate a
value under any circumstance.

## §9 Required Proofs

All of the following must be demonstrated by real, passing, non-mocked
`php artisan test` runs against `ultimatesms_testing` MySQL:

1. **Forward global preflight (design proof 35, corrected scope).** Seed a
   `meter_key = NULL` row on exactly one of the three tables at a time
   (three sub-cases); run the full three-migration `up()` batch; assert
   `UsageMeterBackfillIncompleteException` names the correct table and
   count, and that **zero schema change occurred on any of the three
   tables**, not just the one seeded — proving the check is global, not
   per-file. Also assert it happens whether the seeded `NULL` is on the
   first, second, or third table in migration order (the case where the
   failing table is *not* the one migration 1 would naturally check
   first is the one that would have silently regressed to per-table
   partial completion without this contract's correction).
2. **Final schema state (design proof 36).** After migration,
   `business_usage_rates`/`business_usage_rate_activations` no longer
   have a `feature_key` column, and
   `business_usage_rates_feature_key_version_unique`/
   `business_usage_rate_activations_feature_key_index` no longer exist.
3. **Reservations unaffected (design proof 37).**
   `business_usage_reservations.feature_key` remains present and
   populated exactly as before Slice 3.
4. **Final column state (design proof 38).** All six `meter_key` columns
   are `VARCHAR(128)` with compatible collation; `NOT NULL` on
   `usage_meters`, `usage_meter_transitions`, `business_usage_rates`,
   `business_usage_rate_activations`, `business_usage_reservations`;
   nullable permanently on `business_usage_ledger_entries`.
5. **RFC-005 M3/M4 regressions unaffected (design proof 39).** The
   existing funding and add-on/additional-slot suites pass unmodified.
6. **Rollback runs both global preflights before any rollback DDL
   (design proof 40, corrected — Design Correction 2).**
   - **Failure mode A (unresolved meter relation):** seed a row whose
     `meter_key` cannot resolve on either `business_usage_rates` or
     `business_usage_rate_activations` (two sub-cases); run the full
     three-migration `down()` batch; assert
     `UsageMeterBackfillIncompleteException` names the correct table and
     count, and that **zero rollback DDL ran on any of the three
     tables** — `feature_key` was never re-added anywhere, `meter_key`
     was never loosened anywhere, including on
     `business_usage_reservations`, whose own trivial rollback also never
     ran.
   - **Failure mode B (post-Slice-3 sibling-meter version reuse):** seed
     two sibling meters under one `feature_key`, each holding a rate at
     the same `version` (constructed exactly as proof 41 below); run the
     full `down()` batch; assert
     `UsageMeterRollbackVersionCollisionException` is thrown with the
     exact `(featureKey, version, collidingRowCount)`, and that **zero
     rollback DDL ran anywhere** — no `feature_key` column re-added, no
     legacy index recreated, nothing tightened or loosened, on any of
     the three tables.
   - **Success path:** with no unresolved meter and no version collision,
     run the full `down()` batch and assert `business_usage_rates`/
     `business_usage_rate_activations` are restored to Slice 2's exact
     schema — `feature_key` present, `NOT NULL`, correctly repopulated
     for every row from `usage_meters.feature_key`, the legacy
     unique/index structures recreated exactly as they existed before
     Slice 3, and `meter_key` loosened back to nullable.
7. **Meter-local versioning, re-verified (design proof 41, corrected
   fixture — Design Correction 2).** Before Slice 3, under the shared
   feature-wide allocator: call `setActiveRate()` for meter A (→ version
   1), then meter B (→ version 2), then meter A again (→ version 3) — a
   real, achievable history under Slice 2's own
   `UNIQUE(feature_key, version)` constraint, with no repeated version
   number. After Slice 3, confirm neither meter's prior rates were
   renumbered or disturbed by the column drop; a new `setActiveRate()`
   call for meter B allocates via `latestVersionForMeter('B')`, sees only
   B's own prior row (version 2), and correctly becomes B's own version
   3 — identical in number to A's existing version 3, legal only because
   `UNIQUE(meter_key, version)` scopes uniqueness per meter, not per
   feature. Also re-confirm two genuinely overlapping `setActiveRate()`
   calls for the *same* meter still serialize correctly via
   `findForUpdateByMeterKey()`'s row lock alone, using the same real,
   separate-OS-process technique established in Slice 2, with the Slice
   2 sibling-meter retry loop removed from the method under test.

## §10 Exact Implementation Allowlist

`require_exact_scope: true` — the eventual Slice 3 implementation pull
request's cumulative diff against its base must equal this list exactly,
no more and no fewer paths.

1. `database/migrations/2026_08_24_120001_tighten_and_contract_business_usage_rates_table.php` (new — owns the global forward preflight, §4.1/§6)
2. `database/migrations/2026_08_24_120002_tighten_and_contract_business_usage_rate_activations_table.php` (new)
3. `database/migrations/2026_08_24_120003_tighten_meter_key_on_business_usage_reservations_table.php` (new — owns the global rollback Preflights A+B, §4.3/§6)
4. `app/Exceptions/Usage/UsageMeterBackfillIncompleteException.php` (new)
5. `app/Exceptions/Usage/UsageMeterRollbackVersionCollisionException.php` (new)
6. `app/Repositories/Contracts/BusinessUsageRateRepository.php`
7. `app/Repositories/Eloquent/EloquentBusinessUsageRateRepository.php`
8. `app/Library/Usage/UsageWalletManager.php`
9. `app/Models/BusinessUsageRate.php`
10. `app/Models/BusinessUsageRateActivation.php`
11. `tests/Feature/Usage/BusinessUsageRateSchemaTest.php`
12. `tests/Feature/Usage/BusinessUsageReservationLedgerSchemaTest.php`
13. `tests/Feature/Usage/UsageMeterBackfillPreflightTest.php` (new —
    proofs 1 and 6 from §9)
14. `tests/Feature/Usage/UsageWalletManagerSetActiveRateConcurrencyTest.php`
    (modified — the Slice 2 sibling-collision/exhausted-retry test
    methods are removed since that code path no longer exists; proof 7
    from §9 is added)

**Stop threshold:** if real implementation requires touching any path
outside this list, or discovers that any path in this list is
unnecessary, stop with `ai:needs-human` and report the discrepancy rather
than expanding or shrinking scope unilaterally. This includes the
migration-ordering guarantee in §6 — if a three-file decomposition proves
insufficient at implementation time, stop and report; do not add a fourth
migration file or otherwise change this allowlist unilaterally.

Required test command (must report a positive test count):

```
php artisan test tests/Feature/Usage
```

Required regression commands (design proof 39):

```
php artisan test tests/Feature/Entitlement tests/Feature/Workspace
```

## §11 Explicit Prohibitions

- No fabricated, copied, inferred, or backfilled meter identity, forward
  or in rollback, under any circumstance.
- No change to `EntitlementManager`, `UsageAuthorizationGateway`, or any
  of the nine existing entitlement decision keys.
- No new public method on `UsageWalletManager`; no constructor change.
- No service locator / `app()` / `resolve()` / method or setter
  injection.
- No touching `business_usage_reservations.feature_key` or
  `business_usage_ledger_entries.meter_key`.
- No real `UsageMeter`, rate, or pilot value fabricated anywhere,
  including migration-verification fixtures.
- No synthetic/fake privileged actor.
- No touching PR #107.
- No RFC-005 Milestone 5 work of any kind.
- No merge of any pull request by automation.
- No `ai:*` label change and no start/resume of the Codex/AI Subscription
  Loop as a result of this governance PR merging (§0.1).
- No fourth migration file, no per-file redundant preflight that would
  reintroduce partial-restoration risk, and no reordering of the three
  migration files' timestamps (§6).

## §12 RFC-005 Milestone 5 / PR #107

Unaffected. RFC-005 Milestone 5 (PR #107, "docs: define RFC-005 M5
metered feature classification") remains unselected and unauthorized —
blocked until all three Amendment 1 slices merge (design §P). PR #107
remains open, Draft, and unmerged, and continues to serve as blocker
evidence. Nothing in this contract changes that status.

## §13 Verification and Publication

This governance PR itself changes only
`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md` and
`docs/automation/AI-AUTONOMY-STATE.json` — no design document, since
Design Correction 2 (PR #118) already carries the design-level fixes this
contract reconciles against. It authorizes no automatic start (§0.1). A
separate, later, human-selected task must first establish a Slice 3
target PR at a locked head SHA, then a second, separate, lightweight state
update may set `implementation_authorized: true` and, only if a human
explicitly chooses to re-enable the automatic workflow at that time,
`start_automatically_after_contract_merge: true` — against that exact
PR/SHA — before Slice 3 implementation may begin.
