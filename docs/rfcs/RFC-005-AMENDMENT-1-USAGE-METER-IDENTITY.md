# RFC-005 Amendment 1 — Usage Meter Identity

**Status: DESIGN — NOT AUTHORIZED FOR IMPLEMENTATION UNTIL HUMAN MERGE AND A SEPARATE IMPLEMENTATION CONTRACT.**

Authorized for drafting by the merged
`docs/automation/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY-CONTRACT.md`
(PR #108, human-merged; corrected by **RFC-005 Amendment 1 Governance
Contract Correction Round 1**, PR #110, merge
`2bf3bc5e1ab31a9c95495f113d5dde3748b4218f`, human-merged). This document
is the one file that contract authorizes. Merging this document does
**not** itself change any `app/`, `database/`, `routes/`, `config/`, or
`resources/` file; does not create any migration, model, repository,
manager, gateway, controller, route, view, or test; does not resume
RFC-005 Milestone 5; and does not select a first metered feature. A
separate, later, explicitly bounded implementation contract is required
before any of the schema or code changes this document specifies may be
written.

This document is the authoritative superseding text for the specific
RFC-005 provisions it names (§14 primarily, §11 by reference, §36's
Milestone 5 entry, §39 item 11) once it is human-merged. It does not
edit `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` directly.

**Revision note (prior pass):** a final architecture review found one
long-deferred financial invariant this amendment was the correct place
to resolve (RFC-005 M1 contract §5.5's own explicit deferral of
rate/wallet currency reconciliation "to M5" — this amendment is that
architecture prerequisite), two remaining audit tables whose
independently-valid columns could still contradict each other, and a
governance-level question that design pass could not resolve
unilaterally: whether the merged contract's "public method signatures
remain unchanged" lock extends to `UsageWalletManager`'s constructor.
The first three were fully resolved in that pass; the fourth was
resolved honestly as a **stated blocker** — the contradiction was real,
and that pass declined to silently assume either reading.

**Revision note (this pass):** the constructor-governance contradiction
identified above has since been resolved by a separate, human-merged
governance correction — **RFC-005 Amendment 1 Governance Contract
Correction Round 1**, PR #110, merge
`2bf3bc5e1ab31a9c95495f113d5dde3748b4218f`. That correction amended the
merged contract's own §5 item 6 to explicitly exempt
`UsageWalletManager::__construct()` from the "public method signatures
unchanged" freeze, solely because it is Laravel dependency-injection
wiring rather than part of the stable domain API, within the exact
bounded delta this document already specified (§H.3). This pass
reconciles every part of the design that referenced the former blocker,
and additionally closes one independent schema-consistency gap: the
`meter_key` column width was not uniformly normalized across every
renamed legacy table (§D, §E).

---

## Post-Merge Design Correction 1 — Safe Expand/Cutover/Contract Sequencing

**This is a design correction to this already-merged document — not a
governance-contract correction, not an implementation correction round,
and not M5 work.** It does not consume the one remaining correction
round belonging to the separate governance contract corrected by PR
#110, and it does not touch that contract at all.

**The defect, found by direct inspection of the current runtime code
while preparing the Slice 1 implementation contract this document
authorizes:** the prior pass's §O locked a single 13-step migration
sequence that renamed `business_usage_rates.feature_key` →
`meter_key`, renamed `business_usage_rate_activations.feature_key` →
`meter_key`, and added `business_usage_reservations.meter_key` as
`NOT NULL` — all inside "Slice 1," under the stated requirement that
Slice 1 makes zero change to `UsageWalletManager`. Direct inspection of
`UsageWalletManager::setActiveRate()` and `UsageWalletManager::reserve()`
shows this is not deployable as sequenced:

- `setActiveRate()` (unchanged) writes the literal array key
  `'feature_key' => $featureKey` into both
  `$this->rateRepository->create([...])` and
  `$this->rateActivationRepository->create([...])`. Once those two
  tables' columns are renamed away, this unchanged code either silently
  drops the key during Eloquent mass assignment (no strict-attribute
  mode is enabled anywhere in this application) or fails outright on an
  unknown column, and the resulting insert then fails the renamed
  column's `NOT NULL` constraint. This is exercised today by
  `UsageWalletManagerConcurrencyTest`.
- `reserve()` (unchanged) writes reservations via
  `$this->reservationRepository->create([...'feature_key' => $featureKey...])`
  and never sets a `meter_key` key at all. Adding
  `business_usage_reservations.meter_key` as `NOT NULL` — as the merged
  design's final architecture correctly requires — makes every such
  unchanged call fail immediately. This is exercised today by at least
  four passing test files
  (`UsageWalletManagerReservationLifecycleTest`,
  `UsageWalletManagerCommittedSpendFormulaTest`,
  `UsageCalendarMonthRolloverTest`, `NoAutoRechargeDispatchAtM1Test`).

**The prior 13-step plan was correct as a description of the FINAL
schema graph, but incorrect as a deployable two-slice sequence** —it
tightened and renamed columns before the code capable of populating the
new identity existed. The final architecture named throughout this
document is not wrong and is not reopened by this correction:
`PlatformFeature` as entitlement identity, `UsageMeter` as economic
identity, every meter/rate/currency and same-meter/audit/ledger
integrity rule, `VARCHAR(128)` meter keys everywhere, every immutability
rule, `UsageWalletManager`'s final re-pointed semantics, the structural
entitlement decoupling, no FX, and no real meter/rate/M5 candidate are
all unchanged. **Only the migration/deployment sequencing and the
Slice 1/Slice 2 responsibility split change**, replacing the single
13-step plan with an **expand → cutover → contract** sequence:

- **Phase A — Slice 1 EXPAND:** add every new table and every new
  `meter_key` column as **additive and nullable**, alongside the
  existing legacy columns, with every FK and index that nullable
  semantics make safe to attach immediately. `UsageWalletManager` is
  not modified at all, and its current behavior — including every
  currently-passing test that exercises `reserve()` or
  `setActiveRate()` — is unaffected, because the legacy columns those
  methods read and write are untouched and the new columns they never
  populate are exempted from enforcement by MySQL/InnoDB's `MATCH
  SIMPLE` semantics (already established and relied upon for the
  ledger's own `meter_key` in §G).
- **Phase B — Slice 2 CUTOVER:** re-point `UsageWalletManager`'s
  constructor and the method bodies of `reserve()`, `setActiveRate()`,
  and `activateMetering()` to read and write meter identity instead of
  feature identity, exactly as this document's §H already specifies.
- **Phase C — Slice 2 CONTRACT:** in the same bounded Slice 2 release,
  once the cutover code is actually populating `meter_key` on every
  write path, tighten the schema to its final, already-approved shape —
  drop the legacy `feature_key` columns and their indexes on
  `business_usage_rates`/`business_usage_rate_activations`, and tighten
  `meter_key` to `NOT NULL` on those two tables and on
  `business_usage_reservations`.

No live `UsageMeter` or rate is activated by either slice, and M5
remains blocked until Slice 2 merges — unchanged from every prior pass.

---

## 0. Repository facts this design is built on

Unchanged from the prior pass (§0), with one addition confirmed by
direct re-read of `docs/automation/RFC-005-M1-CONTRACT.md`:

- `business_usage_rates.currency_id`'s own schema table entry reads,
  verbatim: *"one currency per `(feature_key, version)`; reconciling
  this against a per-Business wallet currency (§5.5) is deferred to
  M5 — no rate is ever activated at M1."* **This amendment is the
  architecture prerequisite M5 depends on — this deferred reconciliation
  must be resolved here, not left open again.**
- `business_usage_wallets.currency_id`'s own schema table entry reads,
  verbatim: *"resolved per §5.5, from that Business's own
  `currency_code`; an immutable accounting snapshot once set — never
  rewritten by any code path."* Two Businesses can therefore have
  wallets in different currencies, permanently, by design.
- The merged governance contract's **current, corrected** text (§5 item
  6, as amended by Correction Round 1 / PR #110, re-quoted verbatim,
  load-bearing for §H.3):
  > *"6. `UsageWalletManager`'s public domain/API method signatures
  > remain unchanged; its constructor is separately, narrowly exempted,
  > per Correction Round 1 (§0.1)."* — followed by (a) the frozen
  > enumerated domain/API method list and (b) the explicit
  > `__construct()` exemption, bounded to adding `UsageMeterRepository`/
  > `UsageMeterTransitionRepository` and removing the two old
  > classification repositories only if genuinely unused, with no
  > service-locator, setter-injection, or method-injection workaround
  > authorized. The original, superseded wording — which locked "every
  > other existing public method" without qualification, and which this
  > document's prior pass correctly identified as literally including
  > `__construct()` — no longer governs.

---

## A. Domain model

Unchanged in kind from the prior pass, extended to name currency as
part of a meter's own immutable identity, not a separate concern:

**`UsageMeter`** — a specific, real, variable-cost operation,
optionally scoped to one specific Business, **denominated in exactly
one currency**, that is either currently metered (with exactly one
active, verifiably-its-own, verifiably-same-currency rate) or not
metered at all.

**Ownership relationships, revised to show every enforcement point this
pass adds:**

```
PlatformFeature (1) ──owns (0..N, label only, unenforced)───────> UsageMeter
Business        (0..1) ──scopes (0..N, FK-enforced)─────────────> UsageMeter
Currency        (1) ──denominates (0..N, FK-enforced, immutable)─> UsageMeter
UsageMeter      (1) ──owns (1..N, FK-enforced, same-currency-enforced)─> business_usage_rate
UsageMeter      (1) ──points to (0..1, composite-FK-enforced, same-meter)─> its active business_usage_rate
UsageMeter      (1) ──owns (0..N, FK-enforced, same-meter-enforced)─> business_usage_rate_activation
UsageMeter      (1) ──audits (0..N, FK-enforced, same-meter-enforced)─> usage_meter_transition
UsageMeter      (1) ──owns (0..N, FK-enforced, same-meter-enforced)─> business_usage_reservation
business_usage_reservation ──snapshots (composite-FK-enforced, same meter)─> business_usage_rate
business_usage_reservation (1) ──produces (1..N, meter-FK-enforced where populated)─> business_usage_ledger_entry
```

**Currency is now a first-class, immutable dimension of `UsageMeter`'s
own identity** (§H, §J) — not something checked only at the wallet
boundary. A `meter_key`, a `business_id` scope, and a `currency_id` are
all decided once, at meter creation, and never revisited.

---

## B. `usage_meters` schema

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
    $table->unique(['meter_key', 'currency_id']); // FK target for §D's composite constraint
    $table->index('feature_key');
    $table->index('business_id');
});
```

**`unique(meter_key, currency_id)` is declared even though `meter_key`
alone is already unique** — logically redundant as a uniqueness
guarantee, but mechanically required: MySQL/InnoDB requires an explicit
index whose leading columns match a composite foreign key's referenced
columns exactly; `unique(meter_key)` alone does not satisfy a
`(meter_key, currency_id)`-shaped reference target, regardless of
`meter_key` already being unique on its own. This index exists purely
to serve as that FK target (§D).

**`active_rate_id` remains a plain, unconstrained nullable column at
creation** — its real constraint (composite, same-meter) is added in a
later migration step (§O), for the identical staging reason as the
prior pass.

Column-by-column (unchanged rows from the prior pass omitted for
brevity; new/changed rows only):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `currency_id` | `unsignedBigInteger`, FK → `currencies.id`, `restrictOnDelete()` | **No** | — | **New this pass.** The meter's own immutable settlement currency. Every rate this meter ever activates must share this exact `currency_id` (§D, DB-enforced). **Immutable after creation (§H.4)** — a meter that must be re-denominated is retired and replaced by a new meter under a new key, identical discipline to `meter_key`/`feature_key`/`business_id`. |

Every other column, index, and constraint from the prior pass's §B is
unchanged except as explicitly modified above. **Unaffected by Design
Correction 1** — `usage_meters` is a brand-new table with no legacy
writer of any kind; it is created in Slice 1 EXPAND at this exact final
shape, with no Phase C step of its own.

---

## C. `usage_meter_transitions` schema

**Unaffected by Design Correction 1, and audited directly for it:** no
current code path writes to this table at all — `activateMetering()`
is, per its own docblock, "never called by any M1 production code
path," and no test exercises it either. There is no legacy writer to
collide with, so — unlike §D/§E/§F — there is nothing to expand around.
This table is created in Slice 1 EXPAND at its exact final shape below,
using the `business_usage_rates(meter_key, id)` target index that §D's
Slice 1 phase already creates (that index's existence does not depend
on §D's Phase C tightening). No Phase C step applies to this table.

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

**Two integrity gaps closed this pass, per the independent review's
§2.B finding:** the prior pass's `from_active_rate_id`/`to_active_rate_id`
were plain nullable FKs to `business_usage_rates.id` alone — proving
each references *some* real rate, but never that it is *this meter's
own* rate. **Both are now composite FKs against
`business_usage_rates(meter_key, id)`**, using the same target index
§D's Slice 1 EXPAND phase already creates. **A plain `meter_key → usage_meters.meter_key`
FK is added** (absent from the prior pass) — `meter_key` on this table
now provably references a real meter, not merely an indexed string.
Standard SQL multi-column FK semantics exempt a row from either
composite constraint whenever the corresponding `*_active_rate_id`
column is `NULL` (the case for a fresh meter's first `activateMetering()`
call, where `from_active_rate_id`/`to_active_rate_id` are equal and
non-null in practice today — but the schema must not assume that always
holds).

**Scope confirmed unchanged from the prior pass:** this table audits
metering-state changes only (`false → true`, and `true → false` if a
future `deactivateMetering()` is added) — never rate rotations, which
`business_usage_rate_activations` alone audits (§4 of the prior pass,
unchanged, restated in §H below).

---

## D. `business_usage_rates` — expand/cutover/contract, revised for currency integrity and column width

**Width rule, unchanged from the prior pass:** every `meter_key` column
in this amendment is `VARCHAR(128)`, same charset/collation as its
table's other string columns. **Sequencing, corrected this pass (Design
Correction 1):** the legacy `feature_key` column
(`$table->string('feature_key', 64)`, live in
`database/migrations/2026_08_16_120002_create_business_usage_rates_table.php`,
with its default auto-generated unique index
`business_usage_rates_feature_key_version_unique`) is **not renamed or
widened in Slice 1**. It is left completely untouched through Slice 1,
because `UsageWalletManager::setActiveRate()` — unmodified in Slice 1 —
still writes to it directly (`$this->rateRepository->create(['feature_key' => $featureKey, ...])`).
`meter_key` is instead added as a **new, additive, nullable shadow
column** in Slice 1, populated by nothing until Slice 2, then promoted
to the sole identity column in Slice 2's contract phase.

**Phase A — Slice 1 EXPAND:**

```php
// D-1 (Slice 1): purely additive — feature_key is untouched.
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable()->after('feature_key');
});

// D-2 (Slice 1): new indexes under the meter-key identity, coexisting
// with the untouched legacy business_usage_rates_feature_key_version_unique.
// A composite UNIQUE index tolerates any number of rows with meter_key
// NULL — SQL unique-index semantics never treat two NULLs as equal, so
// this is safe while meter_key is universally NULL, and correctly
// enforces once real values appear.
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->unique(['meter_key', 'version'], 'business_usage_rates_meter_key_version_unique');
    $table->unique(['meter_key', 'id'], 'business_usage_rates_meter_key_id_unique'); // FK target for §C's and §E/F's same-meter constraints
});

// D-3 (Slice 1): composite currency FK, added once usage_meters exists
// with its own unique(meter_key, currency_id) target index (§O). Safe
// while meter_key is universally NULL: MySQL/InnoDB's MATCH SIMPLE
// semantics exempt any row where any FK column is NULL, so every
// current row is exempted and no legacy write is affected; a row where
// meter_key is populated (e.g. by a Slice 1 test exercising the
// constraint directly via DB::table()->insert(), not through
// UsageWalletManager) is fully enforced.
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->foreign(['meter_key', 'currency_id'], 'rates_meter_currency_foreign')
        ->references(['meter_key', 'currency_id'])->on('usage_meters')
        ->restrictOnDelete();
});
```

`BusinessUsageRate::$fillable` gains `'meter_key'` alongside the
existing `'feature_key'` — harmless, since no Slice 1 code path ever
populates it. `BusinessUsageRateRepository::findByFeatureAndVersion()`
and `latestVersionForFeature()` are **not modified in Slice 1** — they
keep querying `feature_key`, exactly as `UsageWalletManager::setActiveRate()`
still calls them.

**Phase C — Slice 2 CONTRACT (same bounded Slice 2 PR as the §H
cutover, after the cutover code lands):**

```php
// D-4 (Slice 2, after cutover code is writing meter_key on every
// insert): preflight — fail closed, never fabricate.
$remaining = DB::table('business_usage_rates')->whereNull('meter_key')->count();
if ($remaining > 0) {
    throw new UsageMeterBackfillIncompleteException('business_usage_rates', $remaining);
}

// D-5 (Slice 2): tighten and drop the legacy identity.
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable(false)->change();
    $table->dropUnique('business_usage_rates_feature_key_version_unique');
    $table->dropColumn('feature_key');
});
```

The final unique indexes (`business_usage_rates_meter_key_version_unique`,
`business_usage_rates_meter_key_id_unique`) and the composite currency
FK were already created in Slice 1 (D-2, D-3) and require no further
change — tightening `meter_key` to `NOT NULL` does not invalidate an
index already built on that column. `BusinessUsageRateRepository::findByFeatureAndVersion()`/
`latestVersionForFeature()` switch their internal `where('feature_key', ...)`
clause to `where('meter_key', ...)` in this same Slice 2 PR — their
method names and parameter lists are unchanged (they are not
`UsageWalletManager` methods and carry no signature freeze), only the
column they query changes, in lockstep with `setActiveRate()`'s own
cutover.

**No plain `meter_key → usage_meters.meter_key` FK is added on this
table, in either slice.** The composite `(meter_key, currency_id) → usage_meters(meter_key, currency_id)`
FK **subsumes** it entirely — any row satisfying the composite
constraint necessarily has a `meter_key` that exists in `usage_meters`
(the composite reference cannot resolve otherwise), so a separate plain
FK on `meter_key` alone would be strictly redundant. This is a
deliberate simplification, not an oversight: adding both would be two
overlapping constraints enforcing the same underlying fact via
different paths.

**The exact currency invariant, locked (resolves independent review
§1), reaching its final enforced state once Slice 2's contract phase
completes:**

> Every `business_usage_rates` row's `currency_id` must equal its
> owning meter's own `currency_id` — enforced by the database, not
> merely asserted in a docblock. **A rate for a EUR meter can never be
> inserted with a USD `currency_id`, or vice versa; the insert itself
> fails at the database layer.**

`retail_rate_micro` and `provider_cost_micro` remain plain integer
columns, denominated implicitly in whatever `currency_id` that row (and
therefore its owning meter) carries — **no FX arithmetic, no automatic
conversion, and no platform fallback currency exist anywhere in this
design** (§J).

---

## E. `business_usage_rate_activations` — expand/cutover/contract, revised for same-meter rate integrity and column width

The live migration
(`database/migrations/2026_08_16_120003_create_business_usage_rate_activations_table.php`)
declares `$table->string('feature_key', 64);` and
`$table->index('feature_key');` — the latter with Laravel's
auto-generated name `business_usage_rate_activations_feature_key_index`.
**Sequencing, corrected this pass:** identical reasoning to §D —
`UsageWalletManager::setActiveRate()` (unmodified in Slice 1) still
writes `'feature_key' => $featureKey` into
`$this->rateActivationRepository->create([...])`, so `feature_key` is
left untouched through Slice 1 and `meter_key` is added as an additive,
nullable shadow column instead.

**Phase A — Slice 1 EXPAND:**

```php
// E-1 (Slice 1): purely additive — feature_key and its index are
// untouched.
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable()->after('feature_key');
    $table->index('meter_key', 'business_usage_rate_activations_meter_key_index');
});

// E-2 (Slice 1): FKs — nullable-safe per MATCH SIMPLE, exactly as §D-3.
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
    $table->foreign(['meter_key', 'rate_id'], 'activations_meter_rate_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')
        ->restrictOnDelete();
});
```

`BusinessUsageRateActivation::$fillable` gains `'meter_key'` alongside
`'feature_key'` — harmless, unpopulated until Slice 2.

**Phase C — Slice 2 CONTRACT (same bounded PR as the §E cutover
above):**

```php
// E-3 (Slice 2, after cutover code is writing meter_key on every
// insert): preflight — fail closed, never fabricate.
$remaining = DB::table('business_usage_rate_activations')->whereNull('meter_key')->count();
if ($remaining > 0) {
    throw new UsageMeterBackfillIncompleteException('business_usage_rate_activations', $remaining);
}

// E-4 (Slice 2): tighten and drop the legacy identity.
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable(false)->change();
    $table->dropIndex('business_usage_rate_activations_feature_key_index');
    $table->dropColumn('feature_key');
});
```

The `meter_key` index and both FKs were already created in Slice 1
(E-1, E-2) and require no further change.

**Both FKs are retained, not redundant with each other** — they target
**different tables** for **different guarantees**: the plain
`meter_key → usage_meters.meter_key` FK proves the meter itself is
real; the new composite `(meter_key, rate_id) → business_usage_rates(meter_key, id)`
FK proves the specific rate this audit row records was actually issued
under *this same* meter (closing the exact gap the independent review
named: `meter_key = A, rate_id = B's rate` previously passed both
individual FKs while being a genuinely invalid audit row). `rate_id`,
`activated_at`, `activated_by_user_id`, `reason`, `created_at` unchanged.

---

## F. Reservations — expand/cutover/contract, revised for currency and full same-meter integrity

`feature_key` is retained **permanently** — it is the immutable
owning-feature snapshot in the final architecture, not a legacy column
being phased out, so it requires no rename/drop treatment in either
slice. **Sequencing, corrected this pass:** `UsageWalletManager::reserve()`
(unmodified in Slice 1) creates every reservation via
`$this->reservationRepository->create([...'feature_key' => $featureKey...])`
and never sets `meter_key`. Adding `meter_key` as `NOT NULL` in Slice 1,
as the prior pass specified, would make every such call fail
immediately — exercised today by
`UsageWalletManagerReservationLifecycleTest`,
`UsageWalletManagerCommittedSpendFormulaTest`,
`UsageCalendarMonthRolloverTest`, and `NoAutoRechargeDispatchAtM1Test`.
`meter_key` is instead added nullable in Slice 1, and its `NOT NULL`
tightening is deferred to Slice 2's contract phase, once `reserve()`
itself is populating it.

**Phase A — Slice 1 EXPAND:**

```php
Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable()->after('feature_key');
    $table->index('meter_key');
});

// FKs — nullable-safe per MATCH SIMPLE, exactly as §D/§E:
Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
    $table->foreign(['meter_key', 'rate_id'], 'reservations_meter_rate_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')
        ->restrictOnDelete();
});
```

`BusinessUsageReservation::$fillable` gains `'meter_key'` alongside the
existing `'feature_key'` — harmless, unpopulated until Slice 2.
`reserve()` itself is untouched in Slice 1: its existing create-payload
simply never sets `meter_key`, which defaults to `NULL` on this
nullable column — no error, no behavior change, identical rows to
today plus one always-`NULL` column.

**Phase C — Slice 2 CONTRACT (same bounded PR as the §H cutover, once
`reserve()` is populating `meter_key` on every new reservation):**

```php
$remaining = DB::table('business_usage_reservations')->whereNull('meter_key')->count();
if ($remaining > 0) {
    throw new UsageMeterBackfillIncompleteException('business_usage_reservations', $remaining);
}

Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable(false)->change();
});
```

`feature_key` is **not dropped** here — unlike §D/§E, this table's
legacy column is the final architecture's own permanent snapshot field,
so Phase C only tightens `meter_key`; it never touches `feature_key`.
The plain and composite FKs were already created in Slice 1 and require
no further change.

**A plain `meter_key → usage_meters.meter_key` FK is added this pass**
(absent from the pass before Design Correction 1) — the same
direct-verification discipline now applied uniformly to every table
carrying a `meter_key` column, alongside the composite same-meter-rate
FK already specified.

**No `currency_id` column is added to this table — a deliberate
decision, not an omission.** A reservation's `rate_id` is immutable
(`business_usage_rates` rows are never updated after creation, §0), and
that rate's own `currency_id` is now, by the new composite FK in §D,
permanently guaranteed to equal its owning meter's `currency_id`.
Historical denomination of any reservation is therefore already
permanently and unambiguously determinable by joining through the
existing, immutable `rate_id` — a redundant `currency_id` snapshot on
the reservation itself would duplicate a fact the schema already makes
structurally unambiguous, which is exactly the "do not add one merely
for symmetry" instruction this pass follows.

**The `reserve()` check sequence — this is Phase B/Slice 2 CUTOVER
content, not Slice 1.** `reserve()` is not modified at all in Slice 1
(above); the sequence below is what Slice 2's bounded implementation PR
re-points `reserve()`'s body to, in the same release that adds the
`meter_key` write to the reservation create-payload and performs §F's
Phase C `NOT NULL` tightening. Revised and finalized — resolves
independent review §1 in full, superseding every prior pass's sequence:

```php
$meter = $this->meterRepository->findByMeterKey($featureKey);

if ($meter === null) {
    throw new NoActiveRateForFeatureException($featureKey);
}

if ($meter->business_id !== null && (int) $meter->business_id !== (int) $business->id) {
    throw new UsageMeterBusinessScopeMismatchException($featureKey, (int) $business->id);
}

if ((int) $wallet->currency_id !== (int) $meter->currency_id) {
    throw new UsageMeterCurrencyMismatchException($featureKey, (int) $wallet->currency_id, (int) $meter->currency_id);
}

if ($meter->active_rate_id === null) {
    throw new NoActiveRateForFeatureException($featureKey);
}

if (! $meter->is_metered) {
    throw new UsageMeterNotMeteredException($featureKey);
}

$rate = $this->rateRepository->findById((int) $meter->active_rate_id);

if ($rate === null) {
    throw new NoActiveRateForFeatureException($featureKey);
}

if ($rate->meter_key !== $meter->meter_key || (int) $rate->currency_id !== (int) $meter->currency_id) {
    throw new UsageMeterRateIntegrityException($featureKey, $rate->id);
}
```

**Ordering rationale:** identity/scope checks (meter exists, Business
scope, currency) come first, since they answer "is this even a valid
pairing for this caller" independent of the meter's current economic
state; economic-state checks (`active_rate_id` set, `is_metered`) come
next; the rate's own existence and same-meter/same-currency integrity
are checked last, as a final defensive verification of data the schema
should already make impossible to violate.

**Five exception classes total in this failure surface: one pre-existing,
reused where truthful, plus four `UsageMeter`-specific classes this
amendment introduces, the newest being `UsageMeterCurrencyMismatchException`:**

- `NoActiveRateForFeatureException` (existing, reused where truthful —
  unchanged from the prior pass).
- `UsageMeterBusinessScopeMismatchException` (prior pass, unchanged).
- **`UsageMeterCurrencyMismatchException` (new this pass)** — thrown
  exclusively for the wallet/meter currency mismatch, which is
  inherently per-execution and cannot be database-enforced (a
  Business's wallet currency is fixed independently of any specific
  meter it might attempt to use). This is never conflated with
  `UsageMeterRateIntegrityException`, whose own currency check (the
  rate/meter pairing) *is* database-enforced and this application-level
  check only defends against defensively.
- `UsageMeterNotMeteredException` (prior pass, unchanged).
- `UsageMeterRateIntegrityException` (prior pass, extended this pass to
  also cover the rate/meter currency mismatch — both conditions are the
  identical class of defect: a rate that does not truly belong, in
  every respect, to the meter pointing at it — expected structurally
  unreachable given §D's composite FK).

**Every failure above fails closed: zero reservation, zero ledger, zero
wallet mutation**, identical placement (before any write) and identical
"no legacy fallback after a meter has been selected" rule as the prior
pass (unchanged, restated in §L). Once every check passes, Slice 2's
`reserve()` adds exactly one new key to its existing reservation
create-payload — `'meter_key' => $meter->meter_key` — alongside the
`feature_key` key it already writes; no other key changes.

**A sixth exception exists in this design, in a completely separate
vocabulary and separate moment: `UsageMeterBackfillIncompleteException`**
(§D, §E, §F), thrown only by Slice 2's Phase C migration preflight
checks — never by `reserve()`, never at request time, and never
confused with the five request-time exceptions above. It mirrors the
existing `PlatformFeatureUsageClassificationBackfillIncompleteException`'s
own exact-count discipline (`app/Exceptions/Usage/PlatformFeatureUsageClassificationBackfillIncompleteException.php`,
audited directly): `__construct(string $table, int $remainingCount)`,
thrown when a Phase C tightening migration finds any row with
`meter_key` still `NULL` immediately before dropping the legacy column
or tightening `NOT NULL` — the fail-closed guarantee named in §7's
preconditions, never a fabricated backfill.

---

## G. Ledger — exact treatment, revised for meter/rate integrity with an honest nullable-case analysis

**Unaffected by Design Correction 1.** Unlike §D/§E/§F, this table's
`meter_key` was never scheduled for a `NOT NULL` tightening or a legacy
column drop — it is nullable **permanently**, in the already-approved
final architecture, exactly because non-metered entry types (top-up,
auto-recharge, add-on credit, and the `ReservationRelease` case
documented below) legitimately never carry one. It is created in Slice
1 at its final shape, with no Phase C step, and `commit()`/`release()`
begin populating it only once Slice 2's cutover lands — added to their
existing ledger create-payloads the same way §F adds `meter_key` to
`reserve()`'s reservation payload.

```php
Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable()->after('feature_key');
    $table->index('meter_key');
});

// Added once target indexes exist — §O:
Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
    $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
    $table->foreign(['meter_key', 'rate_id'], 'ledger_meter_rate_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')
        ->restrictOnDelete();
});
```

**Re-audited directly, per the independent review's explicit
instruction not to assume copying from a valid reservation is
sufficient.** Direct re-read of `UsageWalletManager::release()`'s own
ledger `create()` call (and `commit()`'s own unused-portion
`ReservationRelease` sub-case) confirms **neither populates `rate_id`
at all** — both write `feature_key`/`period_key`/`reservation_id` but
omit `rate_id`/`rate_version`/`retail_rate_micro`/etc entirely. This
means a real, legitimate ledger-entry shape exists where `meter_key`
(once added, mirroring `feature_key`) is populated while `rate_id`
remains `NULL`.

**This is confirmed safe for the composite FK above, not merely
assumed:** MySQL/InnoDB foreign keys use `MATCH SIMPLE` semantics
exclusively (InnoDB supports no other match type) — if **any** column
in a multi-column foreign key is `NULL`, the constraint is not enforced
for that row at all. A `ReservationRelease` row with
`meter_key = 'X', rate_id = NULL` is therefore automatically exempted
from `(meter_key, rate_id) → business_usage_rates(meter_key, id)`'s
enforcement, while a `Reservation`/`UsageCharge`/`UsageOverageCharge`
row (where both are always populated together, exactly as `commit()`
already writes them today) remains fully enforced. **No weakening of
the constraint is required** — MySQL's own standard null-exemption
behavior already handles the one legitimate mixed case correctly. The
plain `meter_key → usage_meters.meter_key` FK is likewise nullable-safe
by the identical single-column NULL-exemption rule, and is added for
the same direct-verification consistency as every other `meter_key`-
carrying table.

Every non-metered entry type (top-up, auto-recharge, add-on credit)
continues to leave `meter_key`/`feature_key`/`rate_id` all `NULL`,
exactly as today — completely unaffected by either new FK.

---

## H. `UsageWalletManager` — per-method treatment, exact, revised

**Everything in this section is Phase B — Slice 2 CUTOVER.** `UsageWalletManager`
is not modified at all in Slice 1 (Design Correction 1); every method
body and constructor change described below lands in Slice 2's single
bounded implementation PR, together with the Phase C schema tightening
in §D/§E/§F.

Unchanged in their core logic from the prior pass for `commit()` and
`release()` — each gains exactly one additive key in its existing
ledger create-payload (`'meter_key' => $reservation->meter_key`, §G),
nothing else. `expireStaleReservations()`, `evaluateCoarseCapacity()`
(§I, unchanged body), and every non-metering-related public method are
untouched even in Slice 2. Revised for `reserve()` (§F's new check
sequence, above) and `setActiveRate()`/`activateMetering()`.
`setActiveRate()`'s two create-payloads (§D, §E) each swap their
literal `'feature_key' => $featureKey` key for `'meter_key' => $featureKey`
in this same Slice 2 PR — the parameter itself keeps its current name
(`$featureKey`, unchanged, exactly like `reserve()`'s own parameter,
§F) since it is part of the frozen signature; only the array key it is
written under, and the column it lands in, change. Otherwise unchanged
mechanics from the prior pass — no currency-specific change needed
there, since `setActiveRate()` already receives the rate's `currency_id`
as an explicit caller-supplied argument, and the new database-level
composite FK in §D is what actually prevents a mismatched value from
ever being persisted — no new
application-level check is needed inside `setActiveRate()` itself
beyond letting that insert fail if the caller supplied the wrong
currency, which is itself a required future test (proof 20, Slice 2).

### H.1–H.2 (unchanged from the prior pass)

`evaluateCoarseCapacity()`'s body remains the unconditional
`return new UsageCapacityDecision(true);` pass-through (§I).
`RealUsageAuthorizationGateway::check()` remains completely unchanged.

### H.3 Constructor governance — resolved by merged Correction Round 1, no longer a blocker

**Historical record:** the design's prior pass found a genuine
contradiction in the then-current merged contract's §5 item 6 —
its first sentence locked "every other existing public method" without
qualification (and a PHP constructor is itself a public method), while
its second sentence explicitly authorized re-pointing
`reserve()`/`setActiveRate()`/`activateMetering()` at a *new* repository
that could not reach those methods without either a constructor change,
a method-signature change (also locked), or a service-locator call
(explicitly forbidden). That pass declined to resolve the contradiction
unilaterally and reported it as **Verdict B — a contract-level
blocker**, requiring a separate, human-reviewed governance correction.

**Resolution:** that correction has since been drafted, reviewed, and
human-merged — **RFC-005 Amendment 1 Governance Contract Correction
Round 1**, PR #110, merge `2bf3bc5e1ab31a9c95495f113d5dde3748b4218f`.
It rewrote §5 item 6 into the exact two-part rule quoted in §0: every
existing public **domain/API** method keeps its exact signature, and
`__construct()` is separately, narrowly exempted solely because it is
dependency-injection wiring, not domain API. **This is now a normal,
fully authorized implementation rule — not an open question, and not a
blocker.**

**Final, locked constructor design:**

- Every frozen public domain/API method (§0's enumerated list —
  `reserve()`, `commit()`, `release()`, `setActiveRate()`,
  `activateMetering()`, `evaluateCoarseCapacity()`,
  `initializeWalletForNewBusiness()`, `creditFromFunding()`,
  `expireStaleReservations()`, `setSpendCap()`, `setFeatureLimit()`,
  `setSafetyLimit()`, `setBillingStatus()`, `configureAutoRecharge()`,
  `recordAutoRechargeFailure()`) keeps its exact current signature —
  unaffected by this section.
- `UsageWalletManager::__construct()` **adds** `UsageMeterRepository`
  and `UsageMeterTransitionRepository`.
- `__construct()` **removes** `PlatformFeatureUsageClassificationRepository`
  and `PlatformFeatureUsageClassificationTransitionRepository` **only if**
  the final method-body design (§H, §F) leaves them genuinely unused. If
  any unchanged public method still genuinely depends on either, it is
  **retained** — removal is never required merely for aesthetic
  cleanup, exactly as Correction Round 1 states.
- **No service locator, `app()`/`resolve()` lookup, setter injection, or
  method injection into any existing public domain method is introduced
  anywhere in this design** — the constructor is the sole, exclusive
  mechanism by which `reserve()`, `setActiveRate()`, and
  `activateMetering()` obtain `UsageMeterRepository`/
  `UsageMeterTransitionRepository`, exactly as Laravel's container-only
  resolution pattern already used for this class requires.

Every other part of this design (§B–§G, §I–§L, the schema and migration
work) was already independent of this question and remains unaffected.

---

## I. Entitlement / usage authorization

Unchanged from the prior pass in every respect — `evaluateCoarseCapacity()`'s
unconditional pass-through, `RealUsageAuthorizationGateway::check()`
unchanged, no tenth `EntitlementManager` denial key, the structural (not
data-invariant) decoupling. None of this pass's currency or audit-
integrity work touches this section.

---

## J. Meter identity rules

Unchanged rules from the prior pass, with currency now locked as a
required fourth dimension of every meter naming decision, not an
optional consideration:

> **Currency is a real economic dimension, exactly like channel,
> country, or provider.** If otherwise-identical usage must be sold in
> both USD and EUR, those are two separate `UsageMeter` identities,
> full stop — never one meter with an implicit "current" currency,
> and never resolved by silent conversion. **This amendment introduces
> no FX conversion mechanism of any kind, for any future meter.** A
> meter's `currency_id`, like its `meter_key`/`feature_key`/`business_id`,
> is decided once at creation and never revisited (§H.4).

---

## K. Quantity

Unchanged from the prior pass in every respect.

---

## L. Unsupported meter behavior — fail-closed, exact, revised

| Condition | Where it surfaces | Behavior |
|---|---|---|
| Unknown `meter_key` | `reserve()` | `NoActiveRateForFeatureException` |
| Meter resolved, `business_id` set, mismatched caller | `reserve()` | `UsageMeterBusinessScopeMismatchException` |
| Meter resolved, wallet currency ≠ meter currency | `reserve()` | **New this pass:** `UsageMeterCurrencyMismatchException` |
| Meter exists, no `active_rate_id` | `reserve()` | `NoActiveRateForFeatureException` |
| Meter exists, has an `active_rate_id`, `is_metered = false` | `reserve()` | `UsageMeterNotMeteredException` |
| Referenced rate row does not exist (defensive, expected unreachable) | `reserve()` | `NoActiveRateForFeatureException` |
| Referenced rate's `meter_key` or `currency_id` does not match the meter's own (defensive, expected structurally unreachable given §D/§F's composite FKs) | `reserve()` | `UsageMeterRateIntegrityException` |
| A rate insert with a `currency_id` not matching its meter's own | `setActiveRate()`, at the database layer | Insert rejected by §D's composite FK — no application-level exception needed; the write simply fails |
| Missing wallet / suspended wallet / outstanding debt / insufficient balance | `reserve()`, after every check above passes | Unchanged |

Every condition above fails closed: zero reservation, zero ledger, zero
wallet mutation, all inside the existing transaction before the first
write. No execution boundary may reinterpret any of these as
permission to charge the same operation through a legacy path (§F,
unchanged). No entitlement denial key is affected by any of these —
all are Usage-layer, execution-boundary exceptions.

---

## M. Business attribution

Unchanged from the prior pass in every respect — still explicitly not
solved by this amendment; the interim `primaryBusiness()` +
single-Business-Workspace compatibility rule remains the only mechanism
available; a meter's own `business_id` scope remains a safety rail
against consuming the wrong meter once a Business has already been
correctly resolved, never a substitute for that resolution.

---

## N. M1–M4 compatibility

Unchanged from the prior pass in every row, with the currency-related
schema additions (§B, §D) noted as additional, equally risk-free
changes for the identical reason as every other change in this table:
every table this amendment touches is either brand new or confirmed
empty in every real deployment (§0), except
`business_usage_ledger_entries`, whose real existing rows are
completely unaffected (every new/changed column and constraint is
nullable-safe for them, §G).

---

## O. Migration/backfill strategy — expand/cutover/contract, re-derived in full

**No migration is created by this document.** **Design Correction 1
supersedes the prior pass's single 13-step plan** — that plan correctly
described the final constraint graph (§A) but incorrectly sequenced it
as one continuous run of renames/tightenings landing entirely inside
Slice 1, before any code existed that could populate the new identity
(see the Post-Merge Design Correction 1 record above for the exact
contradiction this caused). The final graph itself is unchanged; only
the sequence and slice boundaries change, into two phases landing in
two separate, independently mergeable PRs.

### Phase A — Slice 1 EXPAND (11 steps, additive/nullable only)

1. **Create `usage_meters`** (§B), at its exact final shape: `business_id`
   FK to `businesses`, `currency_id` FK to `currencies`, `active_rate_id`
   a plain unconstrained nullable column, `unique(meter_key)` and
   `unique(meter_key, currency_id)`, all at `string(128)`. Empty at
   creation. No legacy writer exists for this table, so it needs no
   Phase C step.
2. **Add `business_usage_rates.meter_key`** (§D-1) — `string(128)`,
   **nullable**, additive; `feature_key` and its existing unique index
   are untouched.
3. **Add `business_usage_rates`' new indexes** (§D-2):
   `business_usage_rates_meter_key_version_unique` and
   `business_usage_rates_meter_key_id_unique` — both valid immediately;
   a composite unique index containing an all-`NULL` `meter_key` column
   never triggers a violation (SQL `NULL ≠ NULL` for uniqueness
   purposes).
4. **Add `business_usage_rates(meter_key, currency_id) → usage_meters(meter_key, currency_id)`**
   (§D-3) — valid now: step 1 created the target index, step 2 created
   the matching source column; nullable-safe per `MATCH SIMPLE` since
   every current row has `meter_key = NULL`.
5. **Add `usage_meters(meter_key, active_rate_id) → business_usage_rates(meter_key, id)`**
   — valid now: step 3 created the target index; nullable-safe the same
   way (every fresh meter's `active_rate_id` starts `NULL`).
6. **Add `business_usage_rate_activations.meter_key`** (§E-1) —
   `string(128)`, nullable, additive; `feature_key` and its existing
   index are untouched.
7. **Add `business_usage_rate_activations`' FKs** (§E-2): the plain
   `meter_key → usage_meters.meter_key` FK and the composite
   `(meter_key, rate_id) → business_usage_rates(meter_key, id)` FK —
   both valid now, both nullable-safe.
8. **Create `usage_meter_transitions`** (§C), at its exact final shape
   — no legacy writer exists for this table either (confirmed:
   `activateMetering()` has no production caller and no test exercises
   it), so it is created complete in this phase with no Phase C step.
9. **Add `business_usage_reservations.meter_key`** (§F) — `string(128)`,
   **nullable** (the prior pass's `NOT NULL` is the exact defect this
   correction fixes), with its plain and composite FKs, both
   nullable-safe; `feature_key` is untouched (it is permanent, not
   legacy).
10. **Add `business_usage_ledger_entries.meter_key`** (§G) —
    `string(128)`, nullable **permanently** (unaffected by this
    correction — this was never scheduled for tightening), with its
    plain and composite FKs, both nullable-safe per `MATCH SIMPLE`.
11. **Code**: new `UsageMeter`/`UsageMeterTransition` models; new
    `UsageMeterRepository`/`UsageMeterTransitionRepository` contracts
    and Eloquent implementations; the six exception classes (§F, §D)
    including `UsageMeterBackfillIncompleteException`; container
    bindings for the two new repository contracts (§9); `'meter_key'`
    added to `$fillable` on `BusinessUsageRate`, `BusinessUsageRateActivation`,
    and `BusinessUsageReservation`, alongside their existing
    `'feature_key'`. **Zero change to `UsageWalletManager.php`, and zero
    change to `BusinessUsageRateRepository`'s query targets** —
    `findByFeatureAndVersion()`/`latestVersionForFeature()` keep
    querying `feature_key`. No new `UsageMeter` row is created; no
    `meter_key` value is ever populated by any Slice 1 code path.

**Slice 1 is fully deployable with `UsageWalletManager` byte-identical
to its pre-Amendment state** — every currently-passing test that
exercises `reserve()`, `setActiveRate()`, `commit()`, or `release()`
continues to pass unchanged, because every legacy column those methods
read and write is untouched, and every new column they never populate
is nullable and therefore exempt from FK enforcement.

### Phase B — Slice 2 CUTOVER (code, one bounded PR together with Phase C)

12. `UsageWalletManager::__construct()` adds `UsageMeterRepository`/
    `UsageMeterTransitionRepository`; removes the two old classification
    repositories only if genuinely unused (§H.3).
13. `reserve()` re-pointed to the §F check sequence; its reservation
    create-payload gains `'meter_key' => $meter->meter_key`.
14. `setActiveRate()`'s two create-payloads (rate, activation) swap
    `'feature_key'` for `'meter_key'` as the array key (§H); parameter
    names are unchanged.
15. `activateMetering()` re-pointed through `UsageMeterRepository`/
    `UsageMeterTransitionRepository`.
16. `evaluateCoarseCapacity()` becomes the unconditional
    `return new UsageCapacityDecision(true);` pass-through (§I).
17. `commit()`/`release()` each gain `'meter_key' => $reservation->meter_key`
    in their existing ledger create-payloads (§G).
18. `BusinessUsageRateRepository::findByFeatureAndVersion()`/
    `latestVersionForFeature()` switch their internal `where()` target
    from `feature_key` to `meter_key` — method names and parameters
    unchanged, since this repository is not `UsageWalletManager` and
    carries no signature freeze.

### Phase C — Slice 2 CONTRACT (schema, same PR, strictly after Phase B code lands and is exercised)

19. **Preflight** (§D, §E, §F): count `NULL`-`meter_key` rows in
    `business_usage_rates`, `business_usage_rate_activations`, and
    `business_usage_reservations`; throw
    `UsageMeterBackfillIncompleteException($table, $remaining)` and stop
    if any count is non-zero. No fabricated backfill, no
    `feature_key`-to-`meter_key` copy, under any circumstance.
20. **Tighten `business_usage_rates`**: `meter_key` → `NOT NULL`; drop
    `business_usage_rates_feature_key_version_unique`; drop `feature_key`.
21. **Tighten `business_usage_rate_activations`**: `meter_key` →
    `NOT NULL`; drop `business_usage_rate_activations_feature_key_index`;
    drop `feature_key`.
22. **Tighten `business_usage_reservations`**: `meter_key` → `NOT NULL`.
    `feature_key` is **not** dropped — it is the permanent
    owning-feature snapshot.
23. `business_usage_ledger_entries.meter_key` is **not** touched — it
    remains nullable permanently (§G), unaffected by this phase.

**Verification the future implementation must perform before trusting
this order:** every `meter_key` column across all six affected tables
must resolve to identical `VARCHAR(128)` type and collation, matching
each table's default collation, both before and after Phase C's
tightening — a mismatch would cause a composite FK to fail to attach
even when the logical reference is correct; `currency_id`/`rate_id`/
`active_rate_id`/`id` must all be consistently `unsignedBigInteger`/
`bigint unsigned` across every referencing and referenced column. This
is a required future schema test (proof 11 for Slice 1's partially
nullable state, proof 15 for Slice 2's final tightened state, below).

**Rollback posture:** Phase C's `down()` re-adds `feature_key` as
nullable (a rolled-back Phase C cannot un-fabricate which row belonged
to which legacy feature, so a Phase C rollback is only safe
immediately after Phase C's own deploy, before any new row relies on
`feature_key`'s absence — standard reversible-migration caveat, not
unique to this design) and loosens `meter_key` back to nullable; Phase
A's `down()` is the exact reverse of steps 1–10 — drop ledger FKs (10),
reservation FKs and column (9), the transitions table entirely (8),
activation FKs and column (7, 6), the two `usage_meters`↔`business_usage_rates`
composite FKs (5, 4), the rates' new indexes and column (3, 2), then
drop `usage_meters` entirely (1). Every step is a standard reversible
Laravel migration `down()`.

**Zero fabricated rows, in either phase.**

---

## P. Amendment implementation decomposition

**Replaces the prior pass's decomposition entirely — corrected by
Design Correction 1 to an expand/cutover/contract split, not the
schema-only-vs-manager-only split used before.**

**Slice 1 — Additive `UsageMeter` Foundation / Expand.** §O Phase A,
steps 1–11: the new `usage_meters`/`usage_meter_transitions` tables at
their final shape; nullable, additive shadow `meter_key` columns and
every nullable-safe FK/index on `business_usage_rates`,
`business_usage_rate_activations`, `business_usage_reservations`, and
`business_usage_ledger_entries`; the new
`UsageMeterRepository`/`UsageMeterTransitionRepository` contracts,
Eloquent implementations, container bindings, models, and exception
classes. **`UsageWalletManager.php` is not modified — at all.** Current
manager and repository execution (`reserve()`, `setActiveRate()`,
`commit()`, `release()`, `findByFeatureAndVersion()`,
`latestVersionForFeature()`) remains behaviorally unchanged, still
reading and writing the legacy `feature_key` identity. Conceptual
responsibility: prove every new table and every new nullable
constraint correctly rejects a manually-attempted violating insert at
the database level, while every existing test and code path is
unaffected.

**Slice 2 — Meter Authority Cutover + Schema Contract.** §O Phase B +
Phase C, steps 12–23, landed together in one bounded implementation PR:
the `UsageWalletManager` constructor swap; `reserve()`/`setActiveRate()`/
`activateMetering()` re-pointing to meter identity; the
`evaluateCoarseCapacity()` entitlement coarse-gate correction; the
final `meter_key` writes to reservations and ledger entries;
`BusinessUsageRateRepository`'s query-target switch; the Phase C
preflight and `NOT NULL` tightening; the final drop of the legacy
`feature_key` columns and indexes on `business_usage_rates`/
`business_usage_rate_activations`. Every existing public domain/API
method signature on `UsageWalletManager` stays frozen exactly as §H.3
approves — no service locator, no method injection, no setter
injection. Slice 2 requires its own, separately human-merged
implementation contract, drafted only after Slice 1 merges. **M5
remains blocked until Slice 2 merges** — unchanged from every prior
pass.

---

## Q. M5 resumption

Unchanged from the prior pass. The future M5 contract must additionally
name the chosen meter's exact `currency_id`, consistent with its
Business's own wallet currency, as part of naming "the exact
`UsageMeter` identity" already required.

---

## Governance — exact relationship to the master RFC-005 document

Unchanged from the prior pass, with one addition: this amendment is now
stated explicitly, per §0, to be the architecture prerequisite RFC-005
M1 §5.5 named and deferred — resolving RFC-005 §39 item 10's own
wallet/rate currency-reconciliation question for the metering
architecture specifically (not the whole-RFC multi-currency-scope
question §39 item 10 otherwise leaves open, which this amendment does
not resolve or need to).

---

## Required future implementation proofs

**Recounted and re-split by slice this pass — the prior pass's flat
29-item list is superseded, not preserved for continuity, since several
of those items genuinely belong to Slice 2 now that `reserve()`'s check
sequence and `setActiveRate()`'s re-pointing no longer land in Slice 1.**
Every item below is a future test, none written here.

### Slice 1 proofs (11) — provable with `UsageWalletManager` untouched

1. `UsageWalletManager.php`'s source is byte-identical to its
   pre-Design-Correction-1 state — a diff-based gate (§11), not a unit
   test.
2. `UsageWalletManagerConcurrencyTest`'s existing `setActiveRate()` call
   still passes, unchanged in behavior and in the rows it produces —
   legacy `feature_key`-backed rate versioning and creation still work.
3. `UsageWalletManagerReservationLifecycleTest`,
   `UsageWalletManagerCommittedSpendFormulaTest`,
   `UsageCalendarMonthRolloverTest`, and `NoAutoRechargeDispatchAtM1Test`'s
   existing `reserve()` calls still pass, unchanged in behavior.
4. Every nullable shadow `meter_key` column (`business_usage_rates`,
   `business_usage_rate_activations`, `business_usage_reservations`,
   `business_usage_ledger_entries`) accepts the current, unchanged
   manager's writes, which leave it `NULL` — no error, no fallback
   logic invoked.
5. No `UsageMeter` row or backfill of any kind is required for proofs 2–4
   to hold.
6. Each new meter-scoped FK (§D-3/§D-4's rate↔meter pair, §E-2's
   activation pair, §F's reservation pair, §G's ledger pair, and §C's
   three `usage_meter_transitions` FKs) rejects a manually-inserted
   mismatched row whenever `meter_key` **is** populated.
7. A manually-inserted row with `meter_key = NULL` on each of the same
   tables is correctly exempted from FK enforcement (`MATCH SIMPLE`)
   regardless of any other column's value — including the
   `ReservationRelease`-shaped ledger case (`meter_key` populated,
   `rate_id = NULL`) from §G.
8. Historical `business_usage_ledger_entries` rows (real M3/M4
   funding/add-on rows, `feature_key = NULL`, `rate_id = NULL`) remain
   untouched and valid after `meter_key` is added.
9. M3 funding and M4 add-on/additional-slot regression suites are
   unaffected.
10. `usage_meters`/`usage_meter_transitions` complete schema tests:
    Business/currency FKs and `restrictOnDelete()`; immutable-field
    enforcement (`meter_key`/`feature_key`/`business_id`/`currency_id`
    never mutable via any repository `update()` call); append-only
    `UsageMeterTransitionRepository` (no `update()`/`delete()`);
    `UsageMeterRepository::create()` rejects an unknown `feature_key`
    (not a valid `PlatformFeature::tryFrom()` value) and rejects
    creation with no actor/`updated_by_user_id` or empty `description`.
11. All six `meter_key` columns resolve to `VARCHAR(128)` with
    compatible collation at their Slice 1 (partially nullable) state —
    a direct `INFORMATION_SCHEMA`/`Schema::getColumnType`-based test.

### Slice 2 proofs (15) — require the cutover code

12. The Phase C preflight correctly throws
    `UsageMeterBackfillIncompleteException($table, $remainingCount)`
    and performs zero schema change when a `meter_key = NULL` row is
    deliberately seeded on any of the three tightened tables inside a
    test transaction — the fail-closed guarantee, exercised, not just
    asserted.
13. After Phase C, `business_usage_rates`/`business_usage_rate_activations`
    no longer have a `feature_key` column, and their legacy indexes
    (`business_usage_rates_feature_key_version_unique`,
    `business_usage_rate_activations_feature_key_index`) are gone.
14. `business_usage_reservations.feature_key` remains present and
    populated exactly as before, permanently — unaffected by Phase C.
15. All six `meter_key` columns are `NOT NULL` where designed
    (`usage_meters`, `usage_meter_transitions`, `business_usage_rates`,
    `business_usage_rate_activations`, `business_usage_reservations`)
    and nullable **permanently** on `business_usage_ledger_entries`,
    still `VARCHAR(128)` with compatible collation after Phase C.
16. USD meter/rate + USD wallet → normal `reserve()`, exercised through
    the re-pointed manager.
17. USD meter/rate + EUR wallet → `UsageMeterCurrencyMismatchException`,
    zero writes.
18. A Business-scoped USD meter, invoked by that same Business but with
    a EUR wallet, still fails — Business-scope match does not
    substitute for currency match.
19. A global USD meter, invoked by a different, otherwise-authorized
    USD-wallet Business, succeeds if every other check passes.
20. A rate cannot be inserted for a meter using a different currency —
    exercised via `setActiveRate()` itself, not just a raw insert.
21. Existing reservations remain unambiguously denominated by their
    original snapshotted `rate_id` after any later rotation.
22. An audit row in `business_usage_rate_activations` or
    `usage_meter_transitions` claiming a rate belonging to a different
    meter is rejected, exercised through `setActiveRate()`/
    `activateMetering()`.
23. A `business_usage_ledger_entries` row claiming `meter_key = A` while
    `rate_id` belongs to meter B is rejected, exercised through
    `commit()`/`release()`.
24. `UsageWalletManager` remains fully container-resolvable after the
    authorized constructor dependency swap.
25. Every existing public domain/API method on `UsageWalletManager`
    retains a byte-for-byte-equivalent signature declaration (§0's
    enumerated list) after Slice 2 — a reflection-based comparison
    against the pre-Amendment declaration.
26. No `app()`/`resolve()`/service-locator call, setter injection, or
    method injection into a frozen domain method is introduced anywhere
    in `UsageWalletManager` — a static-analysis or source-grep test.

Total: **26** (11 Slice 1 + 15 Slice 2 — table above numbers 12–26,
fifteen items).

---

## Unresolved human decisions (explicitly listed, separated from structural design)

1. No real `meter_key`, Business scope, currency, or rate value is
   chosen anywhere in this document.
2. No `PlatformFeature` is selected for M5.
3. The general, non-interim Business-attribution solution (§M) remains
   undesigned.
4. Whether `business_feature_usage_limits`/`platform_feature_usage_safety_limits`
   should ever gain meter-level or currency-aware granularity remains
   undecided.
5. Whether a future `deactivateMetering()` method is ever needed
   remains open; the schema is forward-compatible without further
   change.

**Removed this pass:** the item asking whether the governance contract
would be corrected to exempt `UsageWalletManager`'s constructor — that
decision has been made and merged (PR #110, §H.3) and is no longer
open.

---

*End of RFC-005 Amendment 1 design document. Implementation of any kind
— including the `UsageWalletManager` constructor change specified in
§H.3, now fully authorized by merged governance Correction Round 1
(PR #110) — requires a separate, later, explicitly bounded
implementation contract before any code may be written.*
