# RFC-005 Amendment 1 — Slice 1 EXPAND Implementation Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This contract authorizes drafting a future, separate, bounded
implementation PR only.** Merging this contract does **not** itself
create, modify, or delete any migration, model, repository, provider
binding, exception, test, or any other `app/`, `database/`, `routes/`,
`config/`, or `resources/` file. It does not resume RFC-005 Milestone
5. It does not select a real `UsageMeter`, a real rate, or a real M5
candidate feature. It does not modify PR #107 in any way. This
contract's own scope is this one document, drafted on this one branch,
and nothing else.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-amendment-1-slice-1-expand-contract`,
  in an isolated linked worktree, based on `origin/main` at
  `1a4bb6a1302fbc4bdeb3f4e2892a83703392c94e` (merge of PR #111 —
  "RFC-005 Amendment 1 design correction: expand/cutover/contract
  sequencing, dual-write, and concurrency-safe versioning" — the
  authoritative, fully-corrected merged design).
- **Authorized directly by the merged design document**
  `docs/rfcs/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY.md`, itself
  authorized by the merged
  `docs/automation/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY-CONTRACT.md`
  (PR #108) as corrected by **RFC-005 Amendment 1 Governance Contract
  Correction Round 1** (PR #110,
  merge `2bf3bc5e1ab31a9c95495f113d5dde3748b4218f`). This contract
  drafts the exact bounded scope for the design's own **Slice 1 —
  EXPAND** (§O/§P of the merged design), and nothing else.
- **The abandoned local branch `chore/rfc-005-amendment-1-slice-1-contract`
  carries no authority and is not used as a source for this contract.**
  It predates three subsequent design corrections (PR #111) and does
  not reflect the current merged design.
- `maximum_correction_rounds: 2` applies to the **future implementation
  PR this contract authorizes** — unconsumed. Drafting this contract
  itself consumes **zero** implementation correction rounds.
- **This contract does not touch, and does not consume any round of,
  the separate Amendment governance-contract accounting.** Governance
  Contract Correction Round 1 of 2 was consumed by PR #110; **one
  governance-contract correction round remains unused**, entirely
  unaffected by this document.
- Drafting this contract makes **zero** application changes. No `app/`,
  `database/`, `routes/`, `config/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by this
  branch — only this one new document.
- **Audit discipline:** every path, filename, and behavioral claim
  below was directly verified against the actual merged source at
  `1a4bb6a1302fbc4bdeb3f4e2892a83703392c94e` — `app/Library/Usage/UsageWalletManager.php`,
  `app/Repositories/Contracts/BusinessUsageRateRepository.php` and its
  Eloquent implementation, every rate/activation/reservation/ledger
  model, every relevant migration, `app/Providers/AppServiceProvider.php`'s
  binding array, and the existing `tests/Feature/Usage/` suite — not
  assumed from the design document's own prose alone.

---

## 1. Purpose

This contract authorizes drafting a future, separate implementation PR
for **RFC-005 Amendment 1 — Slice 1 (EXPAND)** exactly as specified by
the merged design's §O "Slice 1 — EXPAND" (11 steps) and §P "Slice 1 —
Additive `UsageMeter` Foundation / Expand." Its purpose is to establish
the additive `UsageMeter`/`UsageMeterTransition` persistence foundation
and the nullable shadow `meter_key` columns on every legacy table,
while leaving `UsageWalletManager`'s runtime behavior **byte-identical**
to its current, pre-Amendment state. Slice 1 does not perform any
`UsageMeter` runtime cutover — that is Slice 2's own, later, separately
authorized scope.

---

## 2. Mechanical audit — Slice 1 is deployable as designed

Direct inspection of the merged design against the actual current
source confirms Slice 1 is mechanically achievable with zero
`UsageWalletManager.php` change:

- `UsageWalletManager::setActiveRate()` (line ~702) and `reserve()`
  (line ~233) read/write only `feature_key` on
  `business_usage_rates`/`business_usage_rate_activations`/
  `business_usage_reservations` today — confirmed no code path in the
  current source reads or writes any column named `meter_key` (it does
  not exist yet), so adding it as a new, nullable, additive column
  cannot change either method's behavior.
- `BusinessUsageRateRepository::findByFeatureAndVersion()`/
  `latestVersionForFeature()` query only `feature_key` in their current
  Eloquent implementation — untouched by an additive `meter_key`
  column on the same table.
- No current migration, model, or test references `usage_meters` or
  `usage_meter_transitions` — both are genuinely new tables with no
  legacy writer, confirmed by repository-wide search.
- `platform_feature_usage_classifications`/its transition table are
  read/written only inside `reserve()`, `setActiveRate()`,
  `activateMetering()`, and `evaluateCoarseCapacity()` — none of which
  are modified by this contract's scope.

**No mechanical contradiction was found between the merged design and
the actual code for Slice 1 specifically.** (The contradictions found
and resolved in earlier passes — the Slice-2/Slice-3 renaming,
dual-write, and concurrency corrections — are already fully reconciled
in the merged design this contract reads from; none of them bear on
Slice 1's own scope, which touches no manager code at all.)

---

## 3. Absolute runtime boundary — locked, non-negotiable

- **`app/Library/Usage/UsageWalletManager.php` MUST NOT be modified in
  any way** — not a byte, not whitespace, not an import, not a
  comment, not the constructor, not any method body. The future
  implementation PR must include a `git diff` proof that this file is
  absent from its changed-file list, and/or an explicit byte-for-byte
  comparison against the pre-implementation commit.
- **`BusinessUsageRateRepository::findByFeatureAndVersion()` and
  `latestVersionForFeature()` keep their current `feature_key`-based
  query bodies, unchanged.** Slice 1 does not introduce
  `findByMeterAndVersion()` or `latestVersionForMeter()` — those are
  Slice 2/Slice 3 concerns (merged design §D), not authorized here.
- **No classification repository is removed, added to, or otherwise
  touched.** `PlatformFeatureUsageClassificationRepository`/
  `PlatformFeatureUsageClassificationTransitionRepository` remain in
  `UsageWalletManager`'s constructor, completely unchanged.
- **No change to `evaluateCoarseCapacity()`, `reserve()`,
  `setActiveRate()`, or `activateMetering()`'s behavior, of any kind.**
- **No dual-write behavior of any kind.** No code path populates
  `meter_key` on any table in Slice 1.
- **No real `UsageMeter`, rate, activation, transition, reservation, or
  ledger row is ever created, backfilled, or fabricated by this
  contract's authorized implementation, its tests, or any seeder.**

---

## 4. Exact Slice 1 schema scope

Every migration below is additive/nullable-safe only, per the merged
design's own Slice 1 nullability table (design doc, "Column-by-column
nullability" table, §O). **No rename, no drop, no tightening, no
backfill of any kind exists anywhere in this scope.**

### 4.A `usage_meters` — new table, final shape

```php
Schema::create('usage_meters', function (Blueprint $table) {
    $table->id();
    $table->string('meter_key', 128)->unique();
    $table->string('feature_key', 64);
    $table->unsignedBigInteger('business_id')->nullable();
    $table->unsignedBigInteger('currency_id');
    $table->boolean('is_metered')->default(false);
    $table->unsignedBigInteger('active_rate_id')->nullable();
    $table->text('description');
    $table->unsignedBigInteger('updated_by_user_id');
    $table->timestamps();

    $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
    $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
    $table->unique(['meter_key', 'currency_id']);
    $table->index('feature_key');
    $table->index('business_id');
});
```

`meter_key` is `NOT NULL` from creation (no legacy writer exists for
this table). `active_rate_id` is a plain, unconstrained nullable column
at this stage — its composite same-meter FK is added in 4.C below,
once this table's own `unique(meter_key, currency_id)` target index
exists. No row is seeded.

### 4.B `business_usage_rates` — additive `meter_key` only

```php
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable()->after('feature_key');
});

Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->unique(['meter_key', 'version'], 'business_usage_rates_meter_key_version_unique');
    $table->unique(['meter_key', 'id'], 'business_usage_rates_meter_key_id_unique');
});

Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->foreign(['meter_key', 'currency_id'], 'rates_meter_currency_foreign')
        ->references(['meter_key', 'currency_id'])->on('usage_meters')
        ->restrictOnDelete();
});
```

`feature_key` (`VARCHAR(64) NOT NULL`) and its existing
`business_usage_rates_feature_key_version_unique` index are **not
touched in any way** — confirmed present exactly as declared in the
live migration
`database/migrations/2026_08_16_120002_create_business_usage_rates_table.php`.
The new composite currency FK is nullable-safe (`MATCH SIMPLE`): every
current row has `meter_key = NULL`, so no existing row is affected.

### 4.C `usage_meters` — same-meter active-rate FK

```php
Schema::table('usage_meters', function (Blueprint $table) {
    $table->foreign(['meter_key', 'active_rate_id'], 'meters_active_rate_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')
        ->restrictOnDelete();
});
```

Valid only after 4.A and 4.B both exist (4.B's `unique(meter_key, id)`
is this FK's target index) — this migration must run strictly after
both.

### 4.D `business_usage_rate_activations` — additive `meter_key` only

```php
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable()->after('feature_key');
    $table->index('meter_key', 'business_usage_rate_activations_meter_key_index');
});

Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
    $table->foreign(['meter_key', 'rate_id'], 'activations_meter_rate_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')
        ->restrictOnDelete();
});
```

`feature_key` (`VARCHAR(64) NOT NULL`) and its existing
`business_usage_rate_activations_feature_key_index` — confirmed present
exactly as declared in the live migration
`database/migrations/2026_08_16_120003_create_business_usage_rate_activations_table.php`
— are **not touched in any way**.

### 4.E `usage_meter_transitions` — new table, final shape

```php
Schema::create('usage_meter_transitions', function (Blueprint $table) {
    $table->id();
    $table->string('meter_key', 128);
    $table->boolean('from_is_metered');
    $table->boolean('to_is_metered');
    $table->unsignedBigInteger('from_active_rate_id')->nullable();
    $table->unsignedBigInteger('to_active_rate_id')->nullable();
    $table->unsignedBigInteger('actor_user_id');
    $table->text('reason');
    $table->timestamp('created_at');

    $table->foreign('meter_key', 'umt_meter_key_foreign')
        ->references('meter_key')->on('usage_meters')->restrictOnDelete();
    $table->foreign(['meter_key', 'from_active_rate_id'], 'umt_from_rate_same_meter_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')->restrictOnDelete();
    $table->foreign(['meter_key', 'to_active_rate_id'], 'umt_to_rate_same_meter_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')->restrictOnDelete();

    $table->index('meter_key');
});
```

`meter_key` is `NOT NULL` from creation. No legacy writer exists for
this table (confirmed: `activateMetering()` has no production caller
and no test exercises it). No row is seeded.

### 4.F `business_usage_reservations` — additive `meter_key` only

```php
Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable()->after('feature_key');
    $table->index('meter_key');
});

Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
    $table->foreign(['meter_key', 'rate_id'], 'reservations_meter_rate_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')
        ->restrictOnDelete();
});
```

`feature_key` on this table is the design's **permanent** owning-feature
snapshot, not a legacy column — it is never renamed, relaxed, or
dropped in any slice, and is untouched here.

### 4.G `business_usage_ledger_entries` — additive `meter_key`, permanently nullable

```php
Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable()->after('feature_key');
    $table->index('meter_key');
});

Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
    $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
    $table->foreign(['meter_key', 'rate_id'], 'ledger_meter_rate_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')
        ->restrictOnDelete();
});
```

`meter_key` is nullable **permanently** — never scheduled for
tightening in any future slice. The composite FK is confirmed
nullable-safe for the legitimate `ReservationRelease` shape
(`meter_key` populated, `rate_id = NULL`) via MySQL/InnoDB's `MATCH
SIMPLE` semantics (any-`NULL` exempts the row). **No existing historical
row (real M3/M4 funding/add-on rows, `feature_key = NULL`, `rate_id = NULL`)
is modified by this migration** — it is purely additive.

### Exact new migration filenames (staged order; later files depend on earlier ones)

1. `database/migrations/2026_08_22_120001_create_usage_meters_table.php` (§4.A)
2. `database/migrations/2026_08_22_120002_add_meter_key_to_business_usage_rates_table.php` (§4.B)
3. `database/migrations/2026_08_22_120003_add_active_rate_foreign_to_usage_meters_table.php` (§4.C — depends on 1 and 2)
4. `database/migrations/2026_08_22_120004_add_meter_key_to_business_usage_rate_activations_table.php` (§4.D — depends on 1 and 2)
5. `database/migrations/2026_08_22_120005_create_usage_meter_transitions_table.php` (§4.E — depends on 1 and 2)
6. `database/migrations/2026_08_22_120006_add_meter_key_to_business_usage_reservations_table.php` (§4.F — depends on 1 and 2)
7. `database/migrations/2026_08_22_120007_add_meter_key_to_business_usage_ledger_entries_table.php` (§4.G — depends on 1 and 2)

These timestamps continue the repository's actual latest migration
(`2026_08_20_150007_create_business_usage_addon_purchase_transitions_table.php`,
confirmed via direct directory listing) in strict chronological order.
Every `down()` must reverse its own `up()` exactly, in the reverse
dependency order (7→1).

---

## 5. Exact Slice 1 code scope

### New files (all additive; none exist today, confirmed by direct search)

**Models:**
- `app/Models/UsageMeter.php`
- `app/Models/UsageMeterTransition.php`

**Repository contracts:**
- `app/Repositories/Contracts/UsageMeterRepository.php`
- `app/Repositories/Contracts/UsageMeterTransitionRepository.php`

**Eloquent implementations:**
- `app/Repositories/Eloquent/EloquentUsageMeterRepository.php`
- `app/Repositories/Eloquent/EloquentUsageMeterTransitionRepository.php`

**Exceptions (four new; mirrors the existing `app/Exceptions/Usage/*.php`
convention exactly):**
- `app/Exceptions/Usage/UsageMeterBusinessScopeMismatchException.php`
- `app/Exceptions/Usage/UsageMeterCurrencyMismatchException.php`
- `app/Exceptions/Usage/UsageMeterNotMeteredException.php`
- `app/Exceptions/Usage/UsageMeterRateIntegrityException.php`

**`NoActiveRateForFeatureException` is pre-existing and reused — not a
new file.** `UsageMeterBackfillIncompleteException` is **not** part of
Slice 1 — it belongs exclusively to Slice 3, where its preflight
actually runs (merged design §D/§O).

**New tests (mirrors the existing `SchemaTest` convention — one file
per new schema concern, confirmed by direct inspection of
`tests/Feature/Usage/`):**
- `tests/Feature/Usage/UsageMeterSchemaTest.php`
- `tests/Feature/Usage/UsageMeterTransitionSchemaTest.php`

### Modified files (existing; exact change scoped below)

- `app/Providers/AppServiceProvider.php` — add exactly two new entries
  to the existing repository-binding array (confirmed present at line
  ~140–184), adjacent to the existing classification/rate bindings:
  ```php
  \App\Repositories\Contracts\UsageMeterRepository::class => \App\Repositories\Eloquent\EloquentUsageMeterRepository::class,
  \App\Repositories\Contracts\UsageMeterTransitionRepository::class => \App\Repositories\Eloquent\EloquentUsageMeterTransitionRepository::class,
  ```
  No other line in this file may change.
- `app/Models/BusinessUsageRate.php` — add `'meter_key'` to `$fillable`,
  alongside the existing `'feature_key'` (confirmed present at line 20).
- `app/Models/BusinessUsageRateActivation.php` — add `'meter_key'` to
  `$fillable`, alongside the existing `'feature_key'` (confirmed
  present at line 15).
- `app/Models/BusinessUsageReservation.php` — add `'meter_key'` to
  `$fillable`, alongside the existing `'feature_key'` (confirmed
  present at line 19).
- `app/Models/BusinessUsageLedgerEntry.php` — add `'meter_key'` to
  `$fillable`, alongside the existing `'feature_key'` (confirmed
  present at line 30) — mechanically necessary for the same reason as
  the other three models: without it, any future (Slice 2) mass-assignment
  write of `meter_key` would be silently discarded; adding it now is
  harmless since no Slice 1 code path ever populates it.
- `tests/Feature/Usage/BusinessUsageRateSchemaTest.php` — this existing
  file already covers **both** `business_usage_rates` and
  `business_usage_rate_activations` (confirmed by its own docblock);
  add new test methods for both new tables' meter FKs/nullability, no
  other change.
- `tests/Feature/Usage/BusinessUsageReservationLedgerSchemaTest.php` —
  add new test methods for the reservation and ledger meter FKs/
  nullability, including the `ReservationRelease` nullable-`rate_id`
  case, no other change.

No other model, repository, controller, route, view, job, or
configuration file is authorized.

---

## 6. `UsageMeterRepository` — exact authority, locked

`create(array $attributes): UsageMeter` must validate:

- `feature_key` is a valid `App\Enums\Entitlement\PlatformFeature`
  value (`PlatformFeature::tryFrom()`, not silently accepted if
  invalid);
- a genuine actor (`updated_by_user_id`, non-empty/real);
- a non-empty `description`;
- a real `currency_id` (referential integrity enforced at the DB layer
  via the FK; the repository must not silently accept a fabricated
  value the FK would reject, though the FK itself is the final
  authority);
- `business_id`, when supplied, references a real Business (same DB-FK-backed
  discipline).

**Immutable after creation, enforced by construction (no generic
repository method may mutate these): `meter_key`, `feature_key`,
`business_id`, `currency_id`.**

**Mutable, exposed only through the narrow authority the merged design
specifies — `active_rate_id`, `is_metered`, `description`,
`updated_by_user_id`, and timestamps.** No generic, unrestricted
`update(array $attributes)` surface is authorized; the future
implementation must expose narrow, named mutation capability (exact
method shape is an implementation-contract-compliant judgment call
within this constraint, following existing repository conventions —
e.g. `PlatformFeatureUsageClassificationRepository::update()`'s own
attribute-array discipline, where only the manager's own call sites are
trusted never to pass an immutable-field key — is an acceptable
precedent to follow, provided the four immutable fields are never
plausibly passed by any Slice 1 caller).

`UsageMeterTransitionRepository` is **append-only** — `create()` only;
no `update()` or `delete()` method of any kind.

**No real `UsageMeter` row of any kind is created by Slice 1's own
implementation, tests included** — all Slice 1 tests exercise these
repositories with disposable, test-only fixture rows created and torn
down inside the test's own transaction, never seeded into any
persistent or production-reachable state.

---

## 7. Data / backfill rule

Zero fabricated data, without exception:

- no fake `UsageMeter` is created outside a disposable test fixture;
- `feature_key` is never copied into `meter_key`, anywhere, by any
  code this contract authorizes;
- no `NULL` `meter_key` value is ever backfilled;
- no rate, activation, transition, or reservation is seeded;
- no M5 candidate feature is selected;
- metering is never activated for any real feature.

Every legacy writer (`UsageWalletManager`'s unmodified `reserve()`/
`setActiveRate()`/`commit()`/`release()`) is **expected and required**
to continue producing `feature_key` populated, `meter_key = NULL`
throughout Slice 1 — this is the intended, correct transitional state,
not a defect. Every new FK/index this contract authorizes is
nullable-safe specifically so that this expected state never triggers
a constraint violation.

---

## 8. Required Slice 1 proofs — translated to an exact test/gate list

Translates the merged design's 12 Slice-1 proofs
(`docs/rfcs/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY.md`, "Required
future implementation proofs," Slice 1 section) into exact gates. No
proof count beyond these 12 is invented.

1. **`UsageWalletManager.php` byte-identical** — a `git diff`/checksum
   gate against the pre-implementation commit, not a PHPUnit test; the
   implementation PR must show zero diff for this file.
2. **`UsageWalletManagerConcurrencyTest`'s existing `setActiveRate()`
   call still passes, unchanged** — run the existing test file
   unmodified; zero change to its assertions or behavior.
3. **`UsageWalletManagerReservationLifecycleTest`,
   `UsageWalletManagerCommittedSpendFormulaTest`,
   `UsageCalendarMonthRolloverTest`, and `NoAutoRechargeDispatchAtM1Test`'s
   existing `reserve()` calls still pass, unchanged** — same discipline
   as proof 2.
4. **Current manager writes with `meter_key` left `NULL` succeed on
   every transitional table** — covered by proofs 2–3 passing
   unmodified (they exercise exactly this path) plus a direct assertion
   in the new/modified schema tests that a manager-shaped insert
   (`feature_key` populated, `meter_key` absent) succeeds against each
   of the four transitional tables.
5. **No `UsageMeter` row or backfill of any kind is required for proofs
   2–4 to hold** — implicit in 2–4 passing with zero `usage_meters` rows
   present; asserted explicitly in `UsageMeterSchemaTest.php` (table is
   empty before and after the existing suite runs).
6. **Every new FK rejects a manually-inserted mismatched row whenever
   `meter_key` is populated** — direct `DB::table()->insert()` tests in
   `UsageMeterSchemaTest.php` (the currency and active-rate FKs) and the
   modified `BusinessUsageRateSchemaTest.php`/
   `BusinessUsageReservationLedgerSchemaTest.php`/
   `UsageMeterTransitionSchemaTest.php` (their respective composite
   FKs), each expecting a `QueryException`.
7. **A `NULL` `meter_key` row is correctly exempted from FK enforcement
   (`MATCH SIMPLE`), regardless of any other column's value** — the
   direct converse of proof 6, same test files.
8. **The ledger `ReservationRelease` shape (`meter_key` populated,
   `rate_id = NULL`) remains valid and is correctly accepted** — a
   dedicated test in `BusinessUsageReservationLedgerSchemaTest.php`.
9. **Historical `business_usage_ledger_entries` rows (real M3/M4
   funding/add-on rows, `feature_key = NULL`, `rate_id = NULL`) remain
   untouched and valid after `meter_key` is added** — asserted directly
   in `BusinessUsageReservationLedgerSchemaTest.php`.
10. **M3 funding and M4 add-on/additional-slot regression suites remain
    green** — run unmodified, no new file.
11. **`UsageMeter`/`UsageMeterTransition` complete schema, validation,
    immutability, and append-only tests** — `UsageMeterSchemaTest.php`
    (Business/currency FKs and `restrictOnDelete()`; `create()` rejects
    an invalid `feature_key`/missing actor/empty description; no
    repository method can mutate `meter_key`/`feature_key`/`business_id`/
    `currency_id`) and `UsageMeterTransitionSchemaTest.php` (append-only
    — no `update()`/`delete()` method exists on the contract at all).
12. **All six `meter_key` columns resolve to `VARCHAR(128)` with
    compatible collation at their Slice 1 state** — `NOT NULL` on
    `usage_meters`/`usage_meter_transitions`, nullable on the other
    four — a direct `INFORMATION_SCHEMA`/`Schema::getColumnType`-based
    test in `UsageMeterSchemaTest.php`.

**Additionally required, restated for emphasis:** no test seeds a real
meter, rate, or activation outside its own disposable fixture; no test
exercises Slice 2 behavior (no dual-write, no manager re-pointing); no
test selects or activates an M5 candidate; no test modifies any
`platform_feature_usage_classifications` row.

---

## 9. Exact future implementation allowlist

**MODIFY (7 paths):**

1. `app/Providers/AppServiceProvider.php`
2. `app/Models/BusinessUsageRate.php`
3. `app/Models/BusinessUsageRateActivation.php`
4. `app/Models/BusinessUsageReservation.php`
5. `app/Models/BusinessUsageLedgerEntry.php`
6. `tests/Feature/Usage/BusinessUsageRateSchemaTest.php`
7. `tests/Feature/Usage/BusinessUsageReservationLedgerSchemaTest.php`

**NEW (17 paths):**

1. `database/migrations/2026_08_22_120001_create_usage_meters_table.php`
2. `database/migrations/2026_08_22_120002_add_meter_key_to_business_usage_rates_table.php`
3. `database/migrations/2026_08_22_120003_add_active_rate_foreign_to_usage_meters_table.php`
4. `database/migrations/2026_08_22_120004_add_meter_key_to_business_usage_rate_activations_table.php`
5. `database/migrations/2026_08_22_120005_create_usage_meter_transitions_table.php`
6. `database/migrations/2026_08_22_120006_add_meter_key_to_business_usage_reservations_table.php`
7. `database/migrations/2026_08_22_120007_add_meter_key_to_business_usage_ledger_entries_table.php`
8. `app/Models/UsageMeter.php`
9. `app/Models/UsageMeterTransition.php`
10. `app/Repositories/Contracts/UsageMeterRepository.php`
11. `app/Repositories/Contracts/UsageMeterTransitionRepository.php`
12. `app/Repositories/Eloquent/EloquentUsageMeterRepository.php`
13. `app/Repositories/Eloquent/EloquentUsageMeterTransitionRepository.php`
14. `app/Exceptions/Usage/UsageMeterBusinessScopeMismatchException.php`
15. `app/Exceptions/Usage/UsageMeterCurrencyMismatchException.php`
16. `app/Exceptions/Usage/UsageMeterNotMeteredException.php`
17. `app/Exceptions/Usage/UsageMeterRateIntegrityException.php`

Plus two new test files, counted separately from the above since they
are test infrastructure, not product code, but equally bounded:

18. `tests/Feature/Usage/UsageMeterSchemaTest.php`
19. `tests/Feature/Usage/UsageMeterTransitionSchemaTest.php`

**Total authorized paths: 26** (7 modify + 19 new). **Stop threshold:
27.** If the future implementation discovers a genuinely required 27th
path, it must **stop and report for human review** rather than touch
it — this contract does not pre-authorize any path beyond the 26 named
above.

**Explicit DENYLIST — none of the following may be created, modified,
or deleted by the future Slice 1 implementation, under any
circumstance:**

- `app/Library/Usage/UsageWalletManager.php` (absolute — §3)
- `app/Library/Entitlement/EntitlementManager.php`
- `app/Library/Entitlement/RealUsageAuthorizationGateway.php`
- `app/Library/Entitlement/NullUsageAuthorizationGateway.php`
- `app/Models/PlatformFeatureUsageClassification.php`
- `app/Models/PlatformFeatureUsageClassificationTransition.php`
- `app/Repositories/Contracts/PlatformFeatureUsageClassificationRepository.php`
  / `...TransitionRepository.php` and their Eloquent implementations
- `database/migrations/2026_08_16_120004_create_platform_feature_usage_classifications_table.php`,
  `..._120005_create_platform_feature_usage_classification_transitions_table.php`,
  and `..._120008_backfill_platform_feature_usage_classifications.php`
  (existing classification migrations — may be *read* by a test to
  prove no change, never modified)
- any controller, route, or view file
- any M5-related file of any kind
- `docs/automation/RFC-005-M5-CONTRACT.md` or any M5 candidate-audit
  document
- Draft PR #107's own branch, contract, or document, in any form
- any production seeder, factory state, or fixture that creates a real
  `UsageMeter`, rate, activation, or reservation outside a test's own
  disposable transaction
- `docs/automation/AI-AUTONOMY-STATE.json`
- the merged governance contract
  (`docs/automation/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY-CONTRACT.md`)
  and the merged design document itself — both are read-only inputs to
  this contract, never edited by the implementation it authorizes

---

## 10. Explicit prohibitions

- No migration may rename or drop any existing column, table, or
  index.
- No `NOT NULL` tightening of any kind.
- No `UsageWalletManager`, `EntitlementManager`,
  `RealUsageAuthorizationGateway`, or `PlatformFeatureRegistry`
  behavior may change.
- No dual-write of any kind.
- No repository query-target change on `BusinessUsageRateRepository`.
- No new public method may be added to `UsageWalletManager`, and no
  existing one's signature may change.
- No real rate, meter key, currency selection, or pilot value of any
  kind may be fabricated or seeded anywhere, including in tests (tests
  use disposable, transaction-scoped fixtures only).
- No M5 candidate feature may be selected or implied.
- `docs/automation/AI-AUTONOMY-STATE.json` carries no authorization
  weight for any of this and is not touched.

---

## 11. M5 and PR #107

**RFC-005 Milestone 5 remains blocked.** No M5 implementation contract
may be drafted and no M5 candidate feature may be selected until **all
three** Amendment 1 implementation slices (Slice 1, Slice 2, Slice 3)
are independently approved and merged — approval of this contract, or
of the future Slice 1 implementation alone, does not unblock M5.

**Draft PR #107 remains open, in Draft state, unmerged, and completely
untouched** by this contract and by the future implementation it
authorizes. It is not read for any purpose other than the historical
record it already is.

---

## 12. Verification and publication (this document only)

- `git diff origin/main --name-only` from this branch must show exactly
  one path:
  `docs/automation/RFC-005-AMENDMENT-1-SLICE-1-EXPAND-CONTRACT.md`.
- `git diff --check` must be clean.
- Commit message: `docs: define RFC-005 Amendment 1 Slice 1 expand contract`.
- Push branch `chore/rfc-005-amendment-1-slice-1-expand-contract`; open
  a Draft PR against `main`, docs-only, if tooling permits. Keep it
  Draft for human review. Do not merge. Do not begin drafting or
  executing the future Slice 1 implementation until this contract
  itself is merged.
- The future Slice 1 implementation PR, once this contract is merged,
  is bound by: `maximum_correction_rounds: 2`; every correction is a
  new commit, never an amend, never a force-push; human-only merge, no
  auto-merge; a third required correction is a stop-for-human-direction
  condition, exactly as every prior implementation in this engagement
  has been governed.
