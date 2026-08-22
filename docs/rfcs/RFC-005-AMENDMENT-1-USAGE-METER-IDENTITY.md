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
unchanged except as explicitly modified above.

---

## C. `usage_meter_transitions` schema

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
§D's rename step already creates. **A plain `meter_key → usage_meters.meter_key`
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

## D. `business_usage_rates` — exact correction, revised for currency integrity and column width

**Width inconsistency, closed this pass:** the live migration
(`database/migrations/2026_08_16_120002_create_business_usage_rates_table.php`)
declares `$table->string('feature_key', 64);` and
`$table->unique(['feature_key', 'version']);` — the latter with
Laravel's default auto-generated index name
`business_usage_rates_feature_key_version_unique`. A plain
`renameColumn()` renames the column only; it does **not** widen a
`varchar(64)` into a `varchar(128)`, and it does not rename the
existing index. Since `usage_meters.meter_key` and every newly-created
`meter_key` column in this design are `string(128)`, an un-widened
`business_usage_rates.meter_key` would silently truncate or reject any
meter key longer than 64 characters — a real semantic defect, not a
cosmetic one, independent of whether MySQL's FK mechanics tolerate
differing `varchar` lengths on either side of a foreign key (they do,
but that is irrelevant to whether the *data itself* fits). **Locked
rule: every `meter_key` column in this amendment is `VARCHAR(128)`,
same charset/collation as the table's other string columns.** Four
steps, in this exact order, none combinable with the next without
breaking a downstream dependency:

```php
// D-1: rename only — do not combine with a type change in the same
// Blueprint call; renaming and altering type together is unsafe across
// Laravel/doctrine-dbal versions.
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->renameColumn('feature_key', 'meter_key');
});

// D-2: widen, now that the column is named meter_key. MySQL permits
// MODIFY COLUMN to widen an indexed varchar without dropping the index
// first; charset/collation are preserved implicitly since only the
// length changes.
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->string('meter_key', 128)->change();
});

// D-3: rebuild the old feature-key-named indexes under the meter-key
// identity. The pre-rename auto-generated name is
// business_usage_rates_feature_key_version_unique (audited directly
// from the live migration, not invented) — Laravel computed that name
// from the column list at creation time, and renaming the column does
// not rename the index, so this exact string is still the index's real
// name and must be referenced explicitly, not regenerated from the new
// column name.
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->dropUnique('business_usage_rates_feature_key_version_unique');
    $table->unique(['meter_key', 'version'], 'business_usage_rates_meter_key_version_unique');
    $table->unique(['meter_key', 'id'], 'business_usage_rates_meter_key_id_unique'); // FK target for §C's and §E/F's same-meter constraints
});

// D-4: composite currency FK — added only once usage_meters exists with
// its own unique(meter_key, currency_id) target index (§O):
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->foreign(['meter_key', 'currency_id'], 'rates_meter_currency_foreign')
        ->references(['meter_key', 'currency_id'])->on('usage_meters')
        ->restrictOnDelete();
});
```

**No plain `meter_key → usage_meters.meter_key` FK is added on this
table.** The new composite `(meter_key, currency_id) → usage_meters(meter_key, currency_id)`
FK **subsumes** it entirely — any row satisfying the composite
constraint necessarily has a `meter_key` that exists in `usage_meters`
(the composite reference cannot resolve otherwise), so a separate plain
FK on `meter_key` alone would be strictly redundant. This is a
deliberate simplification, not an oversight: adding both would be two
overlapping constraints enforcing the same underlying fact via
different paths.

**The exact currency invariant, locked (resolves independent review
§1):**

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

## E. `business_usage_rate_activations` — corresponding change, revised for same-meter rate integrity and column width

The live migration
(`database/migrations/2026_08_16_120003_create_business_usage_rate_activations_table.php`)
declares `$table->string('feature_key', 64);` and
`$table->index('feature_key');` — the latter with Laravel's
auto-generated name `business_usage_rate_activations_feature_key_index`.
The identical width defect as §D applies here, and is closed the same
way:

```php
// E-1: rename only.
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->renameColumn('feature_key', 'meter_key');
});

// E-2: widen to varchar(128), matching every other meter_key column.
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->string('meter_key', 128)->change();
});

// E-3: rebuild the old feature-key-named index under the meter-key
// identity — business_usage_rate_activations_feature_key_index is the
// exact pre-rename auto-generated name, audited directly from the live
// migration; renaming the column does not rename this index.
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->dropIndex('business_usage_rate_activations_feature_key_index');
    $table->index('meter_key', 'business_usage_rate_activations_meter_key_index');
});

// E-4: FKs, added once both target tables carry their required indexes
// (§O):
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
    $table->foreign(['meter_key', 'rate_id'], 'activations_meter_rate_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')
        ->restrictOnDelete();
});
```

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

## F. Reservations — exact treatment, revised for currency and full same-meter integrity

```php
Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->string('meter_key', 128)->after('feature_key');
    $table->index('meter_key');
});

// Added once target indexes exist — §O:
Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
    $table->foreign(['meter_key', 'rate_id'], 'reservations_meter_rate_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')
        ->restrictOnDelete();
});
```

**A plain `meter_key → usage_meters.meter_key` FK is added this pass**
(absent from the prior pass) — the same direct-verification discipline
now applied uniformly to every table carrying a `meter_key` column,
alongside the composite same-meter-rate FK the prior pass already
specified.

`feature_key` is retained unchanged (immutable owning-feature snapshot,
prior pass, unchanged reasoning).

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

**The `reserve()` check sequence, revised and finalized — resolves
independent review §1 in full, superseding the prior pass's sequence:**

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
pass (unchanged, restated in §L).

---

## G. Ledger — exact treatment, revised for meter/rate integrity with an honest nullable-case analysis

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

Unchanged from the prior pass for `commit()`, `release()`,
`expireStaleReservations()`, `evaluateCoarseCapacity()` (§I, unchanged
body), and every non-metering-related public method. Revised for
`reserve()` (§F's new check sequence, above) and `setActiveRate()`/
`activateMetering()` (unchanged mechanics from the prior pass — no
currency-specific change needed there, since `setActiveRate()` already
receives the rate's `currency_id` as an explicit caller-supplied
argument, and the new database-level composite FK in §D is what
actually prevents a mismatched value from ever being persisted — no new
application-level check is needed inside `setActiveRate()` itself
beyond letting that insert fail if the caller supplied the wrong
currency, which is itself a required future test, §7).

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

## O. Migration/backfill strategy — exact staged order, final, re-derived in full

**No migration is created by this document.** The final constraint
graph (§A) — now including the width-normalization steps §D/§E require
and the no-longer-blocked constructor change — requires **13 steps**,
not the prior pass's 9; the step count changed because §D and §E each
split into a rename/widen/reindex sequence rather than a single rename.
Later steps depend on indexes or column definitions created in earlier
ones; no step may be reordered without breaking a downstream
prerequisite:

1. **Create `usage_meters`** (§B): `business_id` FK to `businesses`
   (not circular — `businesses` already exists) and `currency_id` FK to
   `currencies` (not circular — `currencies` already exists) are both
   added immediately; `active_rate_id` remains a plain, unconstrained
   column. `unique(meter_key)` and `unique(meter_key, currency_id)` are
   both created now, at `string(128)`. Empty at creation.
2. **Rename `business_usage_rates.feature_key` → `meter_key`** (§D-1).
   Empty table, zero data risk.
3. **Widen `business_usage_rates.meter_key` to `string(128)`** (§D-2) —
   valid now: the column exists under its new name from step 2.
4. **Rebuild `business_usage_rates`' indexes under the meter-key
   identity** (§D-3): drop
   `business_usage_rates_feature_key_version_unique` (the exact,
   audited pre-rename name — unaffected by steps 2–3); add
   `business_usage_rates_meter_key_version_unique` and
   `business_usage_rates_meter_key_id_unique`.
5. **Add `business_usage_rates(meter_key, currency_id) → usage_meters(meter_key, currency_id)`**
   (§D-4) — valid now: step 1 created the target index, step 3 widened
   the source column to match it, step 4 created `meter_key`'s own
   post-rename indexes.
6. **Add `usage_meters(meter_key, active_rate_id) → business_usage_rates(meter_key, id)`**
   — valid now: step 4 created the target index.
7. **Rename `business_usage_rate_activations.feature_key` → `meter_key`**
   (§E-1). Empty table, zero data risk.
8. **Widen `business_usage_rate_activations.meter_key` to `string(128)`**
   (§E-2) — valid now.
9. **Rebuild `business_usage_rate_activations`' index under the
   meter-key identity** (§E-3): drop
   `business_usage_rate_activations_feature_key_index` (the exact,
   audited pre-rename name); add
   `business_usage_rate_activations_meter_key_index`.
10. **Add `business_usage_rate_activations`' FKs** (§E-4): the plain
    `meter_key → usage_meters.meter_key` FK and the composite
    `(meter_key, rate_id) → business_usage_rates(meter_key, id)` FK —
    both valid now, since step 6's target index chain and step 4's
    `business_usage_rates_meter_key_id_unique` already exist.
11. **Create `usage_meter_transitions`** (§C), at `string(128)` from
    creation (no rename needed — a brand-new table), with its plain
    `meter_key → usage_meters.meter_key` FK and both composite
    `from`/`to`-`active_rate_id` FKs against
    `business_usage_rates(meter_key, id)` — all valid now.
12. **Add `business_usage_reservations.meter_key`** (`string(128)`,
    `NOT NULL`, table empty — no rename needed) with its plain
    `meter_key → usage_meters.meter_key` FK and composite
    `(meter_key, rate_id) → business_usage_rates(meter_key, id)` FK
    (§F) — valid now.
13. **Add `business_usage_ledger_entries.meter_key`** (`string(128)`,
    nullable — no rename needed), with its plain and composite FKs
    (§G, both nullable-safe per `MATCH SIMPLE`) — valid now.

**Code changes follow strictly after all 13 schema steps**, and — per
§H.3's now-merged governance correction — are no longer split into a
blocked/unblocked portion: new `UsageMeter`/`UsageMeterTransition`
models; new `UsageMeterRepository`/`UsageMeterTransitionRepository`
contracts and Eloquent implementations; the five exception classes
(§F); the `UsageWalletManager` method-body **and constructor** changes
(§H) — fully authorized, no remaining governance gate; the three
read-only classification repository classes are left entirely as-is,
per the prior pass, removed from `UsageWalletManager`'s constructor
only if genuinely unused (§H.3).

**Verification the future implementation must perform before trusting
this order:** every `meter_key` column across all six affected tables
(`usage_meters`, `usage_meter_transitions`, `business_usage_rates`,
`business_usage_rate_activations`, `business_usage_reservations`,
`business_usage_ledger_entries`) must resolve to identical
`VARCHAR(128)` type and collation, matching the table's default
collation — a mismatch would cause a composite FK to fail to attach
even when the logical reference is correct; `currency_id`/`rate_id`/
`active_rate_id`/`id` must all be consistently `unsignedBigInteger`/
`bigint unsigned` across every referencing and referenced column. This
is a required future schema test (§7, proof 29).

**Rollback posture:** exact reverse of the 13 steps above — drop ledger
constraints (13), reservation constraints (12), the transitions table
entirely (11), rate-activation FKs (10) and its rebuilt index (9) and
its widened column back to 64 and its rename back (8, 7), the two
`usage_meters`↔`business_usage_rates` composite FKs (6, 5), the rates
table's rebuilt indexes back to the original `feature_key` unique (4),
its widened column back to 64 and its rename back (3, 2), then drop
`usage_meters` entirely (1). Every step is a standard reversible
Laravel migration `down()`.

**Zero fabricated rows**, unchanged from the prior pass.

---

## P. Amendment implementation decomposition

**Slice 1 — Schema and repository foundation.** §O steps 1–13, plus the
new model/repository/contract/exception files (the non-manager portion
of the code-changes step). Conceptual responsibility: prove every
table, column, and — now including the currency, width-normalization,
and full audit-table constraints — every composite FK correctly rejects
a manually-attempted violating insert at the database level, with zero
change to any existing runtime behavior.

**Slice 2 — `UsageWalletManager` re-pointing, including its constructor.**
§H's method-body and constructor changes. **Governance prerequisite
satisfied by merged PR #110 (§H.3)** — the constructor-signature
question is resolved and no longer conditions this slice. Slice 2 still
requires its own, separately human-merged implementation contract
before any code may be written, exactly like Slice 1 — that requirement
is ordinary implementation-contract discipline, not a residual
governance gate. Its conceptual responsibility is unchanged from the
prior pass, extended per §7's updated proof list.

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

**29 total** (13 carried forward unchanged + 16 added across the two
most recent passes) — every item is a future test, none written here:

1–13. Unchanged from the prior pass (generic entitlement wallet-
independence even with a manually-forced classification row; the five
`reserve()` fail-closed cases; active-rate same-meter integrity;
reservation meter/rate integrity; safe rate rotation; unaffected
`Pending` reservations after rotation; classification rows byte-
identical; M3/M4 regression unaffected; a clean success path).

14. **USD meter/rate + USD wallet → normal reserve.**
15. **USD meter/rate + EUR wallet → `UsageMeterCurrencyMismatchException`,
    zero writes.**
16. **A Business-scoped USD meter, invoked by that same Business but
    with a EUR wallet, still fails** — Business-scope match does not
    substitute for currency match; both are independently required.
17. **A global USD meter, invoked by a different, otherwise-authorized
    USD-wallet Business → succeeds** if every other check passes.
18. **A rate cannot be inserted for a meter using a different currency**
    — attempted directly at the database layer, §D's composite FK
    rejects the write.
19. **`setActiveRate()` cannot rotate a meter into a different currency**
    — the same database-level rejection as 18, exercised via the
    manager method rather than a raw insert.
20. **Existing reservations remain unambiguously denominated by their
    original snapshotted `rate_id`** after any later rotation — no
    `currency_id` column exists on the reservation to become stale
    (§F).
21. **An audit row in `business_usage_rate_activations` claiming
    `meter_key = A, rate_id = <B's rate>` is rejected at the database
    layer** (§E).
22. **An audit row in `usage_meter_transitions` claiming a
    `from_active_rate_id`/`to_active_rate_id` belonging to a different
    meter is rejected at the database layer** (§C).
23. **A `business_usage_ledger_entries` row claiming `meter_key = A`
    while `rate_id` belongs to meter B is rejected at the database
    layer; a `ReservationRelease`-shaped row with `meter_key` populated
    and `rate_id = NULL` is correctly accepted** (§G).
24. **`UsageMeterRepository::create()` rejects an unknown `feature_key`**
    (not a valid `PlatformFeature::tryFrom()` value), and rejects
    creation with no actor/`updated_by_user_id` or empty `description`.
25. **No repository or manager code path can mutate `meter_key`,
    `feature_key`, `business_id`, or `currency_id` after a meter's
    creation** — a direct test asserting the manager's own `update()`
    call sites never include these four keys in their attribute arrays,
    mirroring the existing, unwritten discipline
    `PlatformFeatureUsageClassificationRepository::update()`'s own
    callers already follow for `feature_key`.
26. **`UsageWalletManager` remains fully container-resolvable after the
    authorized constructor dependency swap** (§H.3) — a test that
    resolving it from Laravel's container succeeds with no manual
    binding changes, exercising the exact DI-only resolution pattern
    this design's governance reasoning relies on.
27. **Every existing public domain/API method on `UsageWalletManager`
    retains a byte-for-byte-equivalent signature declaration** (§0's
    enumerated list) after the Slice 2 implementation — a reflection-
    based test comparing each method's parameter types, order,
    defaults, and return type against its pre-Amendment declaration.
28. **No `app()`/`resolve()`/service-locator call, setter injection, or
    method injection into a frozen domain method is introduced anywhere
    in `UsageWalletManager`** (§H.3) — a static-analysis or direct
    source-grep test asserting their absence from the final
    implementation.
29. **All six `meter_key` columns resolve to identical `VARCHAR(128)`
    type and collation** (§D, §E, §O) — a direct
    `INFORMATION_SCHEMA`/`Schema::getColumnType`-based test across
    `usage_meters`, `usage_meter_transitions`, `business_usage_rates`,
    `business_usage_rate_activations`, `business_usage_reservations`,
    and `business_usage_ledger_entries`.

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
