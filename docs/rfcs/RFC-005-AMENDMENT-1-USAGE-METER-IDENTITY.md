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
schema graph, but incorrect as a deployable sequence** — it tightened
and renamed columns before the code capable of populating the new
identity existed. The final architecture named throughout this document
is not wrong and is not reopened by this correction: `PlatformFeature`
as entitlement identity, `UsageMeter` as economic identity, every
meter/rate/currency and same-meter/audit/ledger integrity rule,
`VARCHAR(128)` meter keys everywhere, every immutability rule,
`UsageWalletManager`'s final re-pointed semantics, the structural
entitlement decoupling, no FX, and no real meter/rate/M5 candidate are
all unchanged. **Only the migration/deployment sequencing and the slice
responsibility split change.**

### Design Correction 2 — three independently deployable slices

**A second-pass review found that bundling Phase B (cutover) and Phase C
(contract) into a single Slice 2 release, as this correction first
proposed, reintroduced an equivalent ordering hazard one level up: the
transitional schema Slice 1 leaves behind still has `business_usage_rates.feature_key`
and `business_usage_rate_activations.feature_key` as `NOT NULL`.** A
cutover that stops writing `feature_key` cannot deploy safely before a
contract migration that relaxes that constraint, and a contract
migration that drops `feature_key` cannot deploy safely before the
cutover code that stops reading it — ordinary rolling/staged deployment
cannot guarantee code and migrations land atomically, so bundling them
into "one release" silently assumed a stop-the-world deployment this
document never stated as a requirement. **This is corrected by splitting
into three independently mergeable slices**, each individually safe to
run for an arbitrary period before the next:

- **Slice 1 — EXPAND:** exactly as below — additive, nullable shadow
  `meter_key` columns; `usage_meters`/`usage_meter_transitions` at
  final shape; `UsageWalletManager` untouched; the legacy
  `feature_key` columns on `business_usage_rates`/
  `business_usage_rate_activations` remain **`NOT NULL`**, unchanged.
- **Slice 2 — CUTOVER:** first, a **backward-compatible schema
  relaxation** — `business_usage_rates.feature_key` and
  `business_usage_rate_activations.feature_key` go from `NOT NULL` to
  nullable, with the columns and their legacy indexes left physically
  in place. This alone is safe to deploy before any code change: the
  unchanged Slice 1 manager keeps writing `feature_key` exactly as
  before, into a column that merely became more permissive. Only after
  that migration is live does the cutover code — `UsageWalletManager`'s
  constructor and its `reserve()`/`setActiveRate()`/`activateMetering()`
  bodies — become active, reading and writing meter identity and no
  longer relying on `feature_key`'s presence. The legacy columns stay
  physically present, nullable, and ignored — not dropped, not
  tightened.
- **Slice 3 — CONTRACT:** a separate, later, separately human-reviewed
  implementation contract and PR, starting only after Slice 2 is merged
  and its cutover code has been running (so real `meter_key` values
  exist on every row). Preflights for any remaining `NULL` `meter_key`
  row, fails closed if found, then tightens `meter_key` to `NOT NULL`
  and drops the legacy `feature_key` columns/indexes on
  `business_usage_rates`/`business_usage_rate_activations` — reaching
  the exact final schema this document has always specified.

No live `UsageMeter` or rate is activated by any of the three slices,
and **M5 remains blocked until all three slices merge** — unchanged in
spirit from every prior pass, now naming three gates instead of two.

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
column** in Slice 1, populated by nothing until Slice 2's cutover code
lands, then promoted to the sole identity column in Slice 3.

**Slice 1 — EXPAND:**

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

**Slice 2 — CUTOVER, deployed as two ordered steps within the one
Slice 2 PR:**

```php
// D-4 (Slice 2, deployed BEFORE the cutover code): backward-compatible
// relaxation. Column and index stay physically present.
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->string('feature_key', 64)->nullable()->change();
});
```

Only once this migration is live does `setActiveRate()`'s cutover body
(§H) stop writing `feature_key` and start writing `meter_key` instead —
safe precisely because `feature_key` now accepts `NULL`.
`BusinessUsageRateRepository::findByFeatureAndVersion()`/
`latestVersionForFeature()` switch their internal `where('feature_key', ...)`
clause to `where('meter_key', ...)` in this same Slice 2 PR — their
method names and parameters are unchanged, since this repository is not
`UsageWalletManager` and carries no signature freeze.

**Slice 3 — CONTRACT (separate implementation contract and PR, after
Slice 2 has merged and run):**

```php
// D-5 (Slice 3): preflight — fail closed, never fabricate.
$remaining = DB::table('business_usage_rates')->whereNull('meter_key')->count();
if ($remaining > 0) {
    throw new UsageMeterBackfillIncompleteException('business_usage_rates', $remaining);
}

// D-6 (Slice 3): tighten and drop the legacy identity.
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
index already built on that column.

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

**Slice 1 — EXPAND:**

```php
// E-1 (Slice 1): purely additive — feature_key and its index are
// untouched (feature_key remains NOT NULL through all of Slice 1).
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

**Slice 2 — CUTOVER, two ordered steps within the one Slice 2 PR:**

```php
// E-3 (Slice 2, deployed BEFORE the cutover code): backward-compatible
// relaxation, identical rationale to §D-4.
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->string('feature_key', 64)->nullable()->change();
});
```

Only once this migration is live does `setActiveRate()`'s activation
create-payload stop writing `feature_key` and start writing `meter_key`
instead (§H).

**Slice 3 — CONTRACT (separate implementation contract and PR):**

```php
// E-4 (Slice 3): preflight — fail closed, never fabricate.
$remaining = DB::table('business_usage_rate_activations')->whereNull('meter_key')->count();
if ($remaining > 0) {
    throw new UsageMeterBackfillIncompleteException('business_usage_rate_activations', $remaining);
}

// E-5 (Slice 3): tighten and drop the legacy identity.
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
tightening is deferred to Slice 3, once `reserve()` itself is
populating it (from Slice 2 onward). Unlike §D/§E, `feature_key` is
never relaxed or dropped here in any slice — it needs no Slice 2
schema step at all.

**Slice 1 — EXPAND:**

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

**Slice 3 — CONTRACT (separate implementation contract and PR, once
`reserve()` has been populating `meter_key` on every new reservation
since Slice 2):**

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
so Slice 3 only tightens `meter_key`; it never touches `feature_key`,
and this table needs no Slice 2 schema step at all (only Slice 2's
*code* — `reserve()` itself — changes for this table). The plain and
composite FKs were already created in Slice 1 and require no further
change.

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

**The `reserve()` check sequence — this is Slice 2 CUTOVER content, not
Slice 1.** `reserve()` is not modified at all in Slice 1 (above); the
sequence below is what Slice 2's bounded implementation PR re-points
`reserve()`'s body to, adding the `meter_key` write to the reservation
create-payload. The `NOT NULL` tightening itself is a separate, later
Slice 3 schema step (§F's Slice 3 block above), not part of this same
PR. Revised and finalized — resolves
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

**A sixth exception exists in this design, owned entirely by Slice 3, in
a completely separate vocabulary and separate moment:
`UsageMeterBackfillIncompleteException`** (§D, §E, §F), thrown only by
Slice 3's own preflight checks — never by `reserve()`, never at request
time, never in Slice 1 or Slice 2, and never confused with the five
request-time exceptions above (which are Slice 1's own request-time
vocabulary, created in Slice 1 even though `reserve()` does not call
them until Slice 2). It mirrors the existing
`PlatformFeatureUsageClassificationBackfillIncompleteException`'s own
exact-count discipline
(`app/Exceptions/Usage/PlatformFeatureUsageClassificationBackfillIncompleteException.php`,
audited directly): `__construct(string $table, int $remainingCount)`,
thrown when Slice 3's own tightening migration finds any row with
`meter_key` still `NULL` immediately before dropping the legacy column
or tightening `NOT NULL` — the fail-closed guarantee, never a
fabricated backfill.

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

**Everything in this section is Slice 2 CUTOVER.** `UsageWalletManager`
is not modified at all in Slice 1; every method body and constructor
change described below lands in Slice 2's own bounded implementation
PR, preceded by Slice 2's own backward-compatible `feature_key`
relaxation on `business_usage_rates`/`business_usage_rate_activations`
(§D-4, §E-3). The final `NOT NULL` tightening and legacy-column drop
this section's changes eventually make possible is Slice 3's own,
later, separate schema work (§D, §E, §F, §O) — not part of this PR.

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
currency, which is itself a required future test (proof 18, Slice 2).

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

## O. Migration/backfill strategy — expand/cutover/contract, three independently deployable slices

**No migration is created by this document.** **Design Correction 2
supersedes Design Correction 1's own two-phase-in-one-PR sequence** —
that sequence correctly separated schema expansion from manager
re-pointing, but bundled the manager cutover and the final `NOT NULL`
tightening into "one bounded Slice 2 release," which silently assumed
code and migrations for that release land atomically. They do not, in
an ordinary rolling/staged deployment, so this is now three separately
mergeable slices, each individually safe to run indefinitely before the
next. The final constraint graph (§A) is unchanged.

### Slice 1 — EXPAND (11 steps)

**Column-by-column nullability, precisely, since this is exactly what
Design Correction 2 tightens up:**

| Column | Nullability in Slice 1 | Why |
|---|---|---|
| `usage_meters.meter_key` | **`NOT NULL` from creation** | Brand-new table, no legacy writer — created at final shape. |
| `usage_meter_transitions.meter_key` | **`NOT NULL` from creation** | Same — brand-new table, no legacy writer. |
| `business_usage_rates.meter_key` | **Transitionally nullable** | Legacy `setActiveRate()` never populates it until Slice 2. |
| `business_usage_rate_activations.meter_key` | **Transitionally nullable** | Same reason. |
| `business_usage_reservations.meter_key` | **Transitionally nullable** | Legacy `reserve()` never populates it until Slice 2. |
| `business_usage_ledger_entries.meter_key` | **Permanently nullable** | Never scheduled for tightening at all — non-metered entry types legitimately never carry one, forever (§G). |

1. **Create `usage_meters`** (§B), at its exact final shape: `business_id`
   FK to `businesses`, `currency_id` FK to `currencies`, `active_rate_id`
   a plain unconstrained nullable column, `unique(meter_key)` and
   `unique(meter_key, currency_id)`, all at `string(128)`, `meter_key`
   `NOT NULL`. Empty at creation. No legacy writer exists for this
   table, so it needs no later tightening.
2. **Add `business_usage_rates.meter_key`** (§D-1) — `string(128)`,
   **nullable**, additive; `feature_key` and its existing unique index
   are untouched (**`feature_key` remains `NOT NULL` through all of
   Slice 1** — this is not relaxed until Slice 2).
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
   index are untouched (**remains `NOT NULL` through all of Slice 1**).
7. **Add `business_usage_rate_activations`' FKs** (§E-2): the plain
   `meter_key → usage_meters.meter_key` FK and the composite
   `(meter_key, rate_id) → business_usage_rates(meter_key, id)` FK —
   both valid now, both nullable-safe.
8. **Create `usage_meter_transitions`** (§C), at its exact final shape
   — no legacy writer exists for this table either (confirmed:
   `activateMetering()` has no production caller and no test exercises
   it), so it is created complete in this phase.
9. **Add `business_usage_reservations.meter_key`** (§F) — `string(128)`,
   **nullable** (the pre-Design-Correction `NOT NULL` was the original
   defect), with its plain and composite FKs, both nullable-safe;
   `feature_key` is untouched (it is permanent, not legacy).
10. **Add `business_usage_ledger_entries.meter_key`** (§G) —
    `string(128)`, nullable **permanently**, with its plain and
    composite FKs, both nullable-safe per `MATCH SIMPLE`.
11. **Code**: new `UsageMeter`/`UsageMeterTransition` models; new
    `UsageMeterRepository`/`UsageMeterTransitionRepository` contracts
    and Eloquent implementations; the five request-time exception
    classes (§F) — `NoActiveRateForFeatureException` (reused),
    `UsageMeterBusinessScopeMismatchException`,
    `UsageMeterCurrencyMismatchException`, `UsageMeterNotMeteredException`,
    `UsageMeterRateIntegrityException`. **`UsageMeterBackfillIncompleteException`
    is not created in Slice 1** — nothing in Slice 1 preflights a
    backfill; it belongs to Slice 3, where that preflight actually runs
    (§F above). Container bindings for the two new repository contracts
    in `AppServiceProvider`; `'meter_key'` added to `$fillable` on `BusinessUsageRate`,
    `BusinessUsageRateActivation`, and `BusinessUsageReservation`,
    alongside their existing `'feature_key'`. **Zero change to
    `UsageWalletManager.php`, and zero change to
    `BusinessUsageRateRepository`'s query targets** —
    `findByFeatureAndVersion()`/`latestVersionForFeature()` keep
    querying `feature_key`. No new `UsageMeter` row is created; no
    `meter_key` value is ever populated by any Slice 1 code path.

**Slice 1 is fully deployable with `UsageWalletManager` byte-identical
to its pre-Amendment state**, and is safe to run indefinitely on its
own — every currently-passing test that exercises `reserve()`,
`setActiveRate()`, `commit()`, or `release()` continues to pass
unchanged, because every legacy column those methods read and write is
untouched, and every new column they never populate is nullable and
therefore exempt from FK enforcement.

### Slice 2 — CUTOVER (schema relaxation, then code; one bounded PR, safe to run indefinitely before Slice 3)

12. **Schema relaxation, deployed first, before any cutover code is
    active:** `business_usage_rates.feature_key` and
    `business_usage_rate_activations.feature_key` go from `NOT NULL` to
    nullable. The columns and their legacy indexes
    (`business_usage_rates_feature_key_version_unique`,
    `business_usage_rate_activations_feature_key_index`) remain
    physically present, untouched otherwise. **This step alone is
    backward-compatible**: the unchanged Slice 1 manager keeps writing
    `feature_key` exactly as before, into a column that merely accepts
    `NULL` now — no existing row or existing code path is affected.
13. `UsageWalletManager::__construct()` adds `UsageMeterRepository`/
    `UsageMeterTransitionRepository`; removes the two old classification
    repositories only if genuinely unused (§H.3).
14. `reserve()` re-pointed to the §F check sequence; its reservation
    create-payload gains `'meter_key' => $meter->meter_key`, alongside
    its existing `feature_key` key (unchanged — reservations' `feature_key`
    is permanent, §F).
15. `setActiveRate()`'s two create-payloads (rate, activation) **stop
    writing `feature_key` entirely** and write `'meter_key' => $featureKey`
    instead (§H); parameter names are unchanged. This is safe only
    because step 12 already made `feature_key` nullable on both tables
    — the column silently receives `NULL` for every new row from this
    point on, exactly as designed, and no `NOT NULL` violation is
    possible.
16. `activateMetering()` re-pointed through `UsageMeterRepository`/
    `UsageMeterTransitionRepository`.
17. `evaluateCoarseCapacity()` becomes the unconditional
    `return new UsageCapacityDecision(true);` pass-through (§I).
18. `commit()`/`release()` each gain `'meter_key' => $reservation->meter_key`
    in their existing ledger create-payloads (§G).
19. `BusinessUsageRateRepository::findByFeatureAndVersion()`/
    `latestVersionForFeature()` switch their internal `where()` target
    from `feature_key` to `meter_key` — method names and parameters
    unchanged, since this repository is not `UsageWalletManager` and
    carries no signature freeze.

**After Slice 2, the legacy `feature_key` columns on
`business_usage_rates`/`business_usage_rate_activations` are physically
present, nullable, no longer authoritative, and ignored by the cutover
code — not dropped, not tightened.** This schema/code combination is
safe to run for an arbitrary period before Slice 3.

**Slice 2 rollback, made real, not merely reversible-in-theory:**
rolling back to Slice 1 code means `setActiveRate()` once again requires
`feature_key`, so a rollback cannot simply drop the column-relaxation
migration — it must **deterministically restore `feature_key`** for
every row Slice 2's cutover code created without it:

```php
DB::table('business_usage_rates as r')
    ->join('usage_meters as m', 'r.meter_key', '=', 'm.meter_key')
    ->whereNull('r.feature_key')
    ->update(['r.feature_key' => DB::raw('m.feature_key')]);
// identical join/update for business_usage_rate_activations
```

This is **authoritative reverse derivation, not fabrication**:
`usage_meters.feature_key` is immutable (§B, §H.4); the `meter_key` FK
already proves the owning meter exists; `PlatformFeature` ownership is
explicitly part of a `UsageMeter`'s own identity (§A). If any row's
`meter_key` fails to resolve to exactly one `usage_meters.feature_key`
(orphaned `meter_key`, or a `meter_key` that is itself still `NULL` on
a row created before the cutover activated), **the rollback fails
closed** — it does not guess, does not fabricate, and does not proceed
to the final `feature_key → NOT NULL` step until every row resolves.
Only once every row is restored does rollback re-tighten `feature_key`
to `NOT NULL`, matching Slice 1's original schema exactly.

### Slice 3 — CONTRACT (separate implementation contract and PR, after Slice 2 is merged and has run)

A completely separate, later, separately human-reviewed implementation
contract — not authorized by this document, and not part of Slice 2's
own PR. Starts only once Slice 2's cutover code has been live long
enough that every current row genuinely carries a real `meter_key`.

20. **Preflight** (§D, §E, §F): count `NULL`-`meter_key` rows in
    `business_usage_rates`, `business_usage_rate_activations`, and
    `business_usage_reservations`; throw
    `UsageMeterBackfillIncompleteException($table, $remaining)` and stop
    — no schema change of any kind — if any count is non-zero. No
    fabricated backfill, no `feature_key`-to-`meter_key` copy, under any
    circumstance.
21. **Tighten `business_usage_rates`**: `meter_key` → `NOT NULL`; drop
    `business_usage_rates_feature_key_version_unique`; drop `feature_key`.
22. **Tighten `business_usage_rate_activations`**: `meter_key` →
    `NOT NULL`; drop `business_usage_rate_activations_feature_key_index`;
    drop `feature_key`.
23. **Tighten `business_usage_reservations`**: `meter_key` → `NOT NULL`.
    `feature_key` is **not** dropped — it is the permanent
    owning-feature snapshot.
24. `business_usage_ledger_entries.meter_key` is **not** touched — it
    remains nullable permanently (§G), unaffected by this slice.

After Slice 3, the schema is exactly the final Amendment 1 target this
document has always specified.

**Slice 3 rollback, restoring Slice 2's schema, not Slice 1's:**
because Slice 2's own schema intentionally left `feature_key` nullable
(step 12), Slice 3's rollback does **not** need to make `feature_key`
`NOT NULL` again — it only needs to restore the column and its data:

1. Re-add `feature_key` as `VARCHAR(64) NULL` on
   `business_usage_rates`/`business_usage_rate_activations`.
2. Populate it deterministically via the identical join used by Slice
   2's own rollback: `row.meter_key → usage_meters.meter_key → usage_meters.feature_key`.
3. Recreate `business_usage_rates_feature_key_version_unique`/
   `business_usage_rate_activations_feature_key_index` exactly as
   Slice 2's schema requires them.
4. Loosen `meter_key` back to nullable.

If the meter-to-owning-feature mapping cannot be resolved for any row,
**the rollback fails closed** — the same authoritative-reconstruction,
never-fabricate discipline as Slice 2's own rollback, not a relaxed
version of it.

**Verification the future implementation must perform before trusting
this order:** every `meter_key` column across all six affected tables
must resolve to identical `VARCHAR(128)` type and collation, matching
each table's default collation, at every slice boundary — a mismatch
would cause a composite FK to fail to attach even when the logical
reference is correct; `currency_id`/`rate_id`/`active_rate_id`/`id`
must all be consistently `unsignedBigInteger`/`bigint unsigned` across
every referencing and referenced column. This is a required future
schema test (proof 11 for Slice 1, proof 29 for Slice 3's final
tightened state, below).

**Zero fabricated rows, in any slice — every restoration in both
rollback paths is a join against `usage_meters`' own immutable
`feature_key`, never a guess.**

---

## P. Amendment implementation decomposition

**Replaces the prior pass's decomposition entirely — Design Correction
2 corrects it from two slices (expand, then cutover-bundled-with-
contract) to three independently deployable slices**, since bundling
cutover and contract into one release silently assumed an atomic
code+migration deployment this document never required.

**Slice 1 — Additive `UsageMeter` Foundation / Expand.** §O Slice 1,
steps 1–11: the new `usage_meters`/`usage_meter_transitions` tables at
their final shape (`meter_key` `NOT NULL` from creation — no legacy
writer, nothing to expand around); nullable, additive shadow
`meter_key` columns and every nullable-safe FK/index on
`business_usage_rates`, `business_usage_rate_activations`
(`feature_key` untouched, still `NOT NULL`), `business_usage_reservations`
(`feature_key` permanent, untouched), and `business_usage_ledger_entries`
(`meter_key` permanently nullable); the new
`UsageMeterRepository`/`UsageMeterTransitionRepository` contracts,
Eloquent implementations, container bindings, models, and the five
request-time exception classes. **`UsageWalletManager.php` is not
modified — at all.** Current manager and repository execution
(`reserve()`, `setActiveRate()`, `commit()`, `release()`,
`findByFeatureAndVersion()`, `latestVersionForFeature()`) remains
behaviorally unchanged, still reading and writing the legacy
`feature_key` identity. Conceptual responsibility: prove every new
table and every new nullable constraint correctly rejects a
manually-attempted violating insert at the database level, while every
existing test and code path is unaffected. Safe to run indefinitely on
its own.

**Slice 2 — Meter Authority Cutover.** §O Slice 2, steps 12–19, one
bounded implementation PR: first, the backward-compatible
`feature_key → nullable` relaxation on `business_usage_rates`/
`business_usage_rate_activations` (deployed before any code change);
then the `UsageWalletManager` constructor swap;
`reserve()`/`setActiveRate()`/`activateMetering()` re-pointing to meter
identity; the `evaluateCoarseCapacity()` entitlement coarse-gate
correction; `meter_key` writes added to reservation and ledger
create-payloads; `BusinessUsageRateRepository`'s query-target switch.
The legacy `feature_key` columns and indexes on
`business_usage_rates`/`business_usage_rate_activations` remain
physically present, nullable, and ignored — **not dropped, not
tightened, in this slice.** Every existing public domain/API method
signature on `UsageWalletManager` stays frozen exactly as §H.3
approves — no service locator, no method injection, no setter
injection. Rollback must deterministically restore `feature_key` via
an authoritative join against `usage_meters`, failing closed if any row
cannot resolve (§O). Slice 2 requires its own, separately human-merged
implementation contract, drafted only after Slice 1 merges. Safe to run
indefinitely on its own, before Slice 3.

**Slice 3 — Schema Contract.** §O Slice 3, steps 20–24: a completely
separate, later implementation contract and PR, starting only after
Slice 2 has merged and run long enough that every row genuinely carries
a real `meter_key`. The fail-closed `UsageMeterBackfillIncompleteException`
preflight; final `NOT NULL` tightening on `business_usage_rates`/
`business_usage_rate_activations`/`business_usage_reservations`; the
final drop of the legacy `feature_key` columns and indexes on the first
two tables only (`business_usage_reservations.feature_key` and
`business_usage_ledger_entries.meter_key`'s nullability are both
permanent and untouched). Rollback restores Slice 2's schema (not
Slice 1's) via the identical authoritative-join, fail-closed discipline.
After Slice 3, the schema is exactly the final Amendment 1 target this
document has always specified. **M5 remains blocked until all three
slices merge** — unchanged in spirit from every prior pass, now naming
three gates.

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

**Recounted and re-split across three slices this pass — the prior
pass's 26-item, two-slice list is superseded, not preserved for
continuity, since Design Correction 2 moves most of the manager-cutover
proofs to Slice 2 and reserves final-schema proofs for a genuinely
separate Slice 3.** Every item below is a future test, none written
here.

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
   logic invoked. (`usage_meters.meter_key` and
   `usage_meter_transitions.meter_key` are excluded from this proof —
   both are `NOT NULL` from creation and have no legacy writer to
   accommodate.)
5. No `UsageMeter` row or backfill of any kind is required for proofs 2–4
   to hold.
6. Each new meter-scoped FK — `business_usage_rates(meter_key,currency_id)→usage_meters`
   (§D-3), `usage_meters(meter_key,active_rate_id)→business_usage_rates`
   (§B/§O step 5), `business_usage_rate_activations`'s plain+composite
   pair (§E-2), `business_usage_reservations`'s plain+composite pair
   (§F), `business_usage_ledger_entries`'s plain+composite pair (§G),
   and `usage_meter_transitions`'s three FKs (§C) — rejects a
   manually-inserted mismatched row whenever `meter_key` **is**
   populated.
7. A manually-inserted row with `meter_key = NULL` on each of the four
   transitionally/permanently nullable legacy tables is correctly
   exempted from FK enforcement (`MATCH SIMPLE`) regardless of any
   other column's value; **this is a distinct claim from proof 7a
   below, and both must hold independently.**
7a. A `business_usage_ledger_entries` row with `meter_key` **populated**
    and `rate_id = NULL` — the legitimate `ReservationRelease` shape
    (§G) — is correctly **accepted**, since `MATCH SIMPLE` exempts a
    row when **any** FK column is `NULL`, not only when all are; this
    must not be confused with proof 7's all-`NULL` case.
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
    compatible collation at their Slice 1 state — `NOT NULL` on
    `usage_meters`/`usage_meter_transitions`, nullable on the other
    four — a direct `INFORMATION_SCHEMA`/`Schema::getColumnType`-based
    test.

### Slice 2 proofs (14) — cutover code and its own schema relaxation, before Slice 3's tightening

12. The `feature_key → nullable` relaxation migration (§D-4, §E-3) is,
    by itself, a no-op for existing behavior — deployed alone, before
    any code change, every currently-passing Slice 1 test still passes
    unchanged.
13. Once the cutover code is active, every new `business_usage_rates`/
    `business_usage_rate_activations` row has `feature_key = NULL` and
    a real `meter_key` — the legacy column remains physically present
    and passes its own (now-nullable) constraint, but is no longer
    populated or read by any code path.
14. USD meter/rate + USD wallet → normal `reserve()`, exercised through
    the re-pointed manager.
15. USD meter/rate + EUR wallet → `UsageMeterCurrencyMismatchException`,
    zero writes.
16. A Business-scoped USD meter, invoked by that same Business but with
    a EUR wallet, still fails — Business-scope match does not
    substitute for currency match.
17. A global USD meter, invoked by a different, otherwise-authorized
    USD-wallet Business, succeeds if every other check passes.
18. `setActiveRate()` rejects a caller-supplied rate whose currency
    does not match its meter's own — exercised via the manager method
    itself, now that `feature_key`'s relaxation makes the underlying
    write mechanically possible.
19. Existing reservations remain unambiguously denominated by their
    original snapshotted `rate_id` after any later rotation.
20. An audit row in `business_usage_rate_activations` or
    `usage_meter_transitions` claiming a rate belonging to a different
    meter is rejected, exercised through `setActiveRate()`/
    `activateMetering()`.
21. A `business_usage_ledger_entries` row claiming `meter_key = A` while
    `rate_id` belongs to meter B is rejected, exercised through
    `commit()`/`release()`.
22. `UsageWalletManager` remains fully container-resolvable after the
    authorized constructor dependency swap.
23. Every existing public domain/API method on `UsageWalletManager`
    retains a byte-for-byte-equivalent signature declaration (§0's
    enumerated list) after Slice 2 — a reflection-based comparison
    against the pre-Amendment declaration.
24. No `app()`/`resolve()`/service-locator call, setter injection, or
    method injection into a frozen domain method is introduced anywhere
    in `UsageWalletManager` — a static-analysis or source-grep test.
25. **Slice 2 rollback is exercised, not merely asserted:** deliberately
    seed `business_usage_rates`/`business_usage_rate_activations` rows
    created by the cutover code (`feature_key = NULL`, real
    `meter_key`), run the rollback's authoritative join-restore, and
    confirm every row's `feature_key` is correctly repopulated from
    `usage_meters.feature_key` and the column is tightened back to
    `NOT NULL`; separately, seed one row whose `meter_key` cannot
    resolve to any `usage_meters` row and confirm the rollback fails
    closed, performing zero schema change, rather than leaving a
    partially-restored table.

### Slice 3 proofs (6) — final schema state, a separate later contract

26. The preflight correctly throws
    `UsageMeterBackfillIncompleteException($table, $remainingCount)`
    and performs zero schema change when a `meter_key = NULL` row is
    deliberately seeded on any of the three tightened tables inside a
    test transaction — the fail-closed guarantee, exercised, not just
    asserted.
27. After Slice 3, `business_usage_rates`/`business_usage_rate_activations`
    no longer have a `feature_key` column, and their legacy indexes
    (`business_usage_rates_feature_key_version_unique`,
    `business_usage_rate_activations_feature_key_index`) are gone.
28. `business_usage_reservations.feature_key` remains present and
    populated exactly as before, permanently — unaffected by Slice 3.
29. All six `meter_key` columns reach their final designed state —
    `NOT NULL` on `usage_meters`, `usage_meter_transitions`,
    `business_usage_rates`, `business_usage_rate_activations`, and
    `business_usage_reservations`; nullable **permanently** on
    `business_usage_ledger_entries` — still `VARCHAR(128)` with
    compatible collation.
30. M3 funding and M4 add-on/additional-slot regression suites remain
    unaffected after all three slices have landed.
31. **Slice 3 rollback restores Slice 2's schema, not Slice 1's:**
    `feature_key` is re-added nullable (never `NOT NULL` — Slice 2's
    own schema never required that), repopulated via the identical
    authoritative join against `usage_meters.feature_key`, and the
    legacy index is recreated exactly as Slice 2 left it; a row whose
    `meter_key` cannot resolve fails the rollback closed, identically
    to proof 25.

Total: **31** (11 Slice 1 + 14 Slice 2 + 6 Slice 3).

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
