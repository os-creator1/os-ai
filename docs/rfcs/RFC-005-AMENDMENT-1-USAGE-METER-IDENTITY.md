# RFC-005 Amendment 1 — Usage Meter Identity

**Status: DESIGN — NOT AUTHORIZED FOR IMPLEMENTATION. SEE §3 GOVERNANCE VERDICT — ONE PROVISION OF THIS DESIGN IS NOT YET AUTHORIZED BY THE MERGED GOVERNANCE CONTRACT AS WRITTEN.**

Authorized for drafting by the merged
`docs/automation/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY-CONTRACT.md`
(PR #108, human-merged). This document is the one file that contract
authorizes. Merging this document does **not** itself change any `app/`,
`database/`, `routes/`, `config/`, or `resources/` file; does not create
any migration, model, repository, manager, gateway, controller, route,
view, or test; does not resume RFC-005 Milestone 5; and does not select
a first metered feature. A separate, later, explicitly bounded
implementation contract is required before any of the schema or code
changes this document specifies may be written — and, per §H.3 below,
one specific part of this design (the `UsageWalletManager` constructor
change) additionally requires a small, separate, human-reviewed
correction to the merged governance contract itself before it is
authorized, regardless of any future implementation contract.

This document is the authoritative superseding text for the specific
RFC-005 provisions it names (§14 primarily, §11 by reference, §36's
Milestone 5 entry, §39 item 11) once it is human-merged. It does not
edit `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` directly.

**Revision note (this pass):** a final architecture review found one
long-deferred financial invariant this amendment is now the correct
place to resolve (RFC-005 M1 contract §5.5's own explicit deferral of
rate/wallet currency reconciliation "to M5" — this amendment is that
architecture prerequisite), two remaining audit tables whose
independently-valid columns could still contradict each other, and a
governance-level question this design cannot resolve unilaterally: does
the merged contract's "public method signatures remain unchanged" lock
extend to `UsageWalletManager`'s constructor. All four are addressed
below — the first three fully resolved in this document; the fourth
resolved as a **stated blocker**, not a silent assumption either way.

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
- The merged governance contract's exact locked text (re-quoted
  verbatim, load-bearing for §H.3):
  > *"6. `UsageWalletManager`'s public method signatures remain
  > unchanged. `reserve()`, `commit()`, `release()`, `setActiveRate()`,
  > `activateMetering()`, and every other existing public method keep
  > their exact current signatures; only their internal resolution
  > target (which repository answers "is this metered, what is the
  > active rate") may change."*

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

## D. `business_usage_rates` — exact correction, revised for currency integrity

```php
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->renameColumn('feature_key', 'meter_key');
});

Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->dropUnique(['feature_key', 'version']); // pre-rename index name
    $table->unique(['meter_key', 'version']);
    $table->unique(['meter_key', 'id']); // FK target for §C's and §E/F's same-meter constraints
});

// Added once usage_meters exists with unique(meter_key, currency_id) — §O:
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

## E. `business_usage_rate_activations` — corresponding change, revised for same-meter rate integrity

```php
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->renameColumn('feature_key', 'meter_key');
});

Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->dropIndex(['feature_key']); // pre-rename index name
    $table->index('meter_key');
});

// Added once both target tables carry their required indexes — §O:
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

**Four exception classes total, one new this pass:**

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

### H.3 Governance verdict on the constructor — resolved as a stated blocker, not a silent assumption

**The independent review is correct to reject the prior pass's own
reasoning here, and this pass withdraws it.** The prior draft argued
that removing `PlatformFeatureUsageClassificationRepository`/
`PlatformFeatureUsageClassificationTransitionRepository` from
`UsageWalletManager`'s constructor (and adding the two new meter
repositories) was "permitted" because the constructor is resolved by
Laravel's container rather than called by application code with
positional arguments. **That reasoning is real and technically true,
but it is not what the merged governance contract's own text says**,
and applying it here would be inconsistent with this same design's own
conservative reading of the identical constraint elsewhere — most
visibly, `reserve()`'s `$featureKey` parameter was deliberately *not*
renamed (§F) specifically because the locked text was read
conservatively to include parameter names, not only arity/types. Using
a more permissive reading for the constructor, purely because it is
more convenient, would be exactly the "silently decide the merged
contract meant an unstated exception" error the independent review
warns against.

**Direct governance-level interpretation audit:** the merged contract's
text reads, verbatim: *"`UsageWalletManager`'s public method signatures
remain unchanged. `reserve()`, `commit()`, `release()`, `setActiveRate()`,
`activateMetering()`, and every other existing public method keep their
exact current signatures."* A PHP constructor is, absent an explicit
access modifier reducing it, a public method. The text names five
methods explicitly and then extends the same rule to "every other
existing public method" **without qualification** — no repository
precedent was found (a direct search of this contract and of RFC-004's
own two amendment contracts) that ever uses "public method" to mean
"public domain/business method, excluding `__construct()`." **No
textual or contextual basis exists to read the constructor out of this
constraint's scope.**

**At the same time, the contract's own second sentence — "only their
internal resolution target ... may change" — explicitly anticipates and
authorizes exactly the kind of change this amendment needs: re-pointing
`reserve()`/`setActiveRate()`/`activateMetering()` at a *new* repository
that does not exist in the current constructor.** In PHP, a method can
only obtain a dependency it does not already have via one of three
mechanisms: constructor injection (locked, per the above), method-
parameter injection (equally locked — a method's own parameter list is
explicitly named as unchangeable), or a service-locator call inside the
method body (explicitly forbidden by the independent review's own
instruction, precisely because it would be a workaround for this exact
lock, not a genuine architectural choice). **All three of the only
mechanically possible ways to give these methods access to a new
repository are foreclosed by the contract's literal text or by explicit
instruction — meaning the contract's own two locked sentences are in
direct tension with each other**, satisfiable simultaneously only under
a reading that exempts dependency-injection wiring from "public method
signatures," which — however technically sound — is not a reading the
contract's own text currently states.

**Verdict: B — CONTRACT-LEVEL BLOCKER.** As written today, the merged
governance contract does not authorize a constructor signature change
to `UsageWalletManager`, and this design document cannot supersede its
own governing contract to grant that authorization itself. **A small,
separate, human-reviewed correction to
`docs/automation/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY-CONTRACT.md`
is required before this specific part of the design (§H's constructor
change, and therefore any `reserve()`/`setActiveRate()`/
`activateMetering()` re-pointing that depends on it) may be
implemented.** The natural, narrowly-scoped correction — stated here as
a recommendation, not enacted by this document — would add one
clarifying sentence to the governance contract's existing item 6,
explicitly exempting `__construct()`'s own dependency-injection
parameter list from the "public method signatures" lock, since that is
the only reading under which the contract's own two sentences can both
be satisfied at all. **This document does not assume that correction is
already granted.** Every other part of this design (§B–§G, §I–§L, the
schema and migration work) is independent of this specific question and
is not blocked by it — only the `UsageWalletManager` code change itself
is. PR #109 is marked not mergeable as a complete, implementation-ready
design until this governance correction occurs, exactly as instructed.

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

## O. Migration/backfill strategy — exact staged order, final

**No migration is created by this document.** The final constraint
graph (§A) requires the following exact order — later steps depend on
indexes created in earlier ones; no step may be reordered without
breaking a downstream FK's own prerequisite:

1. **Create `usage_meters`** (§B): `business_id` FK to `businesses`
   (not circular — `businesses` already exists) and `currency_id` FK to
   `currencies` (not circular — `currencies` already exists) are both
   added immediately; `active_rate_id` remains a plain, unconstrained
   column. `unique(meter_key)` and `unique(meter_key, currency_id)` are
   both created now. Empty at creation.
2. **Rename `business_usage_rates.feature_key` → `meter_key`** (§D);
   drop the old `unique(feature_key, version)`; add
   `unique(meter_key, version)` and `unique(meter_key, id)`. Empty
   table, zero data risk.
3. **Add `business_usage_rates(meter_key, currency_id) → usage_meters(meter_key, currency_id)`**
   (§D) — valid now: step 1 created the target index, step 2 renamed
   the source column.
4. **Add `usage_meters(meter_key, active_rate_id) → business_usage_rates(meter_key, id)`**
   — valid now: step 2 created the target index.
5. **Rename `business_usage_rate_activations.feature_key` → `meter_key`**;
   swap the index; add the plain `meter_key → usage_meters.meter_key`
   FK and the composite `(meter_key, rate_id) → business_usage_rates(meter_key, id)`
   FK (§E) — both valid now.
6. **Create `usage_meter_transitions`** (§C), with its plain
   `meter_key → usage_meters.meter_key` FK and both composite
   `from`/`to`-`active_rate_id` FKs against
   `business_usage_rates(meter_key, id)` — all valid now.
7. **Add `business_usage_reservations.meter_key`** (`NOT NULL`, table
   empty), with its plain `meter_key → usage_meters.meter_key` FK and
   composite `(meter_key, rate_id) → business_usage_rates(meter_key, id)`
   FK (§F) — valid now.
8. **Add `business_usage_ledger_entries.meter_key`** (nullable), with
   its plain and composite FKs (§G, both nullable-safe per `MATCH
   SIMPLE`) — valid now.
9. **Code changes**, strictly after every schema step: new
   `UsageMeter`/`UsageMeterTransition` models; new
   `UsageMeterRepository`/`UsageMeterTransitionRepository` contracts and
   Eloquent implementations; the four new exception classes (§F); the
   `UsageWalletManager` method-body changes (§H) — **the constructor
   portion of this step remains blocked pending §H.3's governance
   correction**; the three read-only classification repository classes
   are left entirely as-is, per the prior pass.

**Verification the future implementation must perform before trusting
this order, named explicitly per the independent review's own
requirement:** every `meter_key` column across all six affected tables
must be declared with identical type and collation (`string(128)`,
matching Laravel's default table collation) — a mismatch would cause a
composite FK to fail to attach even when the logical reference is
correct; `currency_id`/`rate_id`/`active_rate_id`/`id` must all be
consistently `unsignedBigInteger`/`bigint unsigned` across every
referencing and referenced column.

**Rollback posture:** reverse of the above — drop ledger constraints,
then reservation constraints, then the transitions table entirely, then
rate-activation constraints and rename its column back, then the two
`usage_meters`↔`business_usage_rates` composite FKs, then rename
`business_usage_rates`'s column back, then drop `usage_meters` entirely.
Every step is a standard reversible Laravel migration `down()`.

**Zero fabricated rows**, unchanged from the prior pass.

---

## P. Amendment implementation decomposition

**Slice 1 — Schema and repository foundation.** §O steps 1–8, plus the
new model/repository/contract/exception files (step 9's non-manager
portion). Conceptual responsibility: prove every table, column, and —
now including the currency and full audit-table constraints — every
composite FK correctly rejects a manually-attempted violating insert at
the database level, with zero change to any existing runtime behavior.

**Slice 2 — `UsageWalletManager` re-pointing.** §H's method-body
changes. **This slice is explicitly conditioned on §H.3's governance
correction having occurred first** — it cannot proceed under the
current merged contract as written. Once unblocked, its conceptual
responsibility is unchanged from the prior pass, extended per §7's
updated proof list.

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

Restated and expanded per this pass's own findings — every item is a
future test, none written here:

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
26. **§H.3's governance verdict is itself proven, not merely stated:**
    a test (or, more precisely, an implementation-phase check) that the
    `UsageWalletManager` constructor change described in this document
    is only merged after the governance contract's own correction has
    been separately human-merged — this is a process proof, not a code
    proof, and belongs in the future implementation contract's own
    acceptance criteria, not in a PHPUnit test.

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
6. **New this pass, and the most consequential open item:** whether the
   merged governance contract's item 6 will be corrected to exempt
   `UsageWalletManager`'s constructor from the "public method
   signatures unchanged" lock (§H.3). This design cannot proceed to a
   real implementation contract for Slice 2 until a human resolves this
   — either by approving that correction, or by directing an
   alternative this document has not identified (since method-parameter
   injection and service-locator resolution are both independently
   foreclosed, §H.3).

---

*End of RFC-005 Amendment 1 design document. Implementation of any kind
requires a separate, later, explicitly bounded implementation contract.
Additionally, per §H.3, the `UsageWalletManager` constructor change
this design specifies requires a separate, human-reviewed correction to
the merged governance contract before it may be implemented, regardless
of any future implementation contract's own approval.*
