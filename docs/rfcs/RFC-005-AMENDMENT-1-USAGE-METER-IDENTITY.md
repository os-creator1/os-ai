# RFC-005 Amendment 1 — Usage Meter Identity

**Status: DESIGN — NOT AUTHORIZED FOR IMPLEMENTATION UNTIL HUMAN MERGE AND A SEPARATE IMPLEMENTATION CONTRACT.**

Authorized for drafting by the merged
`docs/automation/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY-CONTRACT.md`
(PR #108, human-merged). This document is the one file that contract
authorizes. Merging this document does **not** itself change any `app/`,
`database/`, `routes/`, `config/`, or `resources/` file; does not create
any migration, model, repository, manager, gateway, controller, route,
view, or test; does not resume RFC-005 Milestone 5; and does not select
a first metered feature. A separate, later, explicitly bounded
implementation contract is required before any of the schema or code
changes this document specifies may be written.

This document is the authoritative superseding text for the specific
RFC-005 provisions it names (§14 primarily, §11 by reference, §36's
Milestone 5 entry, §39 item 11) once it is human-merged. It does not
edit `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` directly
— the merged contract authorizes exactly one new file, this one — and
every provision it does not name remains governed by that original
document unchanged.

**Revision note (this refinement pass):** an independent review found
three defects in the initial draft: `reserve()`'s proposed design
re-pointed the existing rate/classification lookup to `UsageMeter` but
never actually required `is_metered = true` before authorizing a
charge, leaving a real gap between a meter's rate being set and its
metering being activated; the claim that the old
`platform_feature_usage_classifications` seam was "decoupled" rested on
a data invariant (nobody writes `is_metered = true` there again) rather
than on code that is structurally incapable of reintroducing Draft PR
#107's contradiction; and meter/rate ownership was specified only in
prose (a `meter_key` string plus a human-readable description), with no
machine-enforced guarantee that a resolved Business, a resolved meter,
and a resolved rate all actually belong together. All three are
resolved below. A fourth, smaller defect (an unresolved future
"rotation-aware activation step" with no name or design) is also
removed, since `setActiveRate()` already is that mechanism. This is
still pre-merge design refinement — no implementation contract exists
for a correction round to apply against.

---

## 0. Repository facts this design is built on

Verified by direct read of `origin/main` at
`0d25be2ce070e6167a7320a044f22bfdd392ea32` before drafting (unchanged
from the prior pass):

- `platform_feature_usage_classifications` (migration
  `2026_08_16_120004_...`): `id`, `feature_key` (`string(64)`, unique),
  `is_metered` (`boolean`, default `false`), `active_rate_id`
  (`unsignedBigInteger`, nullable, FK → `business_usage_rates.id`,
  `restrictOnDelete()`), `updated_by_user_id` (nullable),
  `created_at`/`updated_at`. Backfilled with one row per
  `PlatformFeature` case, `is_metered = false`, at M1. **No row has ever
  had `is_metered` flipped to `true` in any real deployment.**
- `platform_feature_usage_classification_transitions` — confirmed empty
  in every real deployment.
- `business_usage_rates` (migration `2026_08_16_120002_...`): `id`,
  `feature_key` (`string(64)`), `version` (`unsignedInteger`),
  `retail_rate_micro`/`provider_cost_micro` (`bigInteger unsigned`),
  `unit_label` (`string(64)`), `rounding_rule` (`string(32)`, default
  `round_half_up`), `currency_id` (FK → `currencies.id`,
  `restrictOnDelete()`), `created_by_user_id` (`unsignedBigInteger`),
  `created_at` only (immutable, no `updated_at`). Unique on
  `(feature_key, version)`. **Confirmed empty in every real
  deployment.**
- `business_usage_rate_activations` — `id`, `feature_key` (`string(64)`),
  `rate_id` (FK, `restrictOnDelete()`), `activated_at`,
  `activated_by_user_id`, `reason` (`text`), `created_at`, index on
  `feature_key`. **Confirmed empty in every real deployment.**
- `business_usage_reservations` — `id`, `business_id`, `wallet_id`
  (`unsignedBigInteger`), `feature_key` (`string(64)`), `period_key`
  (`string(7)`), `status` (`string(16)`, default `pending`),
  `reserved_amount_micro` (`bigInteger`), `estimated_quantity`
  (`decimal(14,6)`, nullable), `rate_id` (FK, `restrictOnDelete()`, not
  nullable), `rate_version` (`unsignedInteger`), `retail_rate_micro`/
  `provider_cost_micro` (`bigInteger unsigned`), `rounding_rule`
  (`string(32)`), `idempotency_key` (`string(191)`, unique),
  `correlation_key` (`string(191)`), `reserved_at`/`expires_at` (not
  nullable), `committed_at`/`released_at` (nullable), `final_quantity`
  (`decimal(14,6)`, nullable), `final_amount_micro` (`bigInteger`,
  nullable); composite FK `(wallet_id, business_id)` →
  `business_usage_wallets(id, business_id)`; indexes on
  `(wallet_id, business_id)` and `status`. **Confirmed empty in every
  real deployment.**
- `business_usage_ledger_entries` — `id`, `business_id`/`wallet_id`
  (`unsignedBigInteger`), `entry_type` (`string(32)`),
  `available_delta_micro`/`reserved_delta_micro`/`debt_delta_micro`
  (`bigInteger`, default `0`), `gross_amount_micro` (`bigInteger
  unsigned`, nullable), `currency_id` (FK, `restrictOnDelete()`),
  `feature_key` (`string(64)`, nullable), `period_key` (`string(7)`,
  nullable), `quantity` (`decimal(14,6)`, nullable), `rate_id` (FK,
  nullable, `restrictOnDelete()`), `rate_version` (`unsignedInteger`,
  nullable), `retail_rate_micro`/`provider_cost_micro` (`bigInteger
  unsigned`, nullable), `unit_label` (`string(64)`, nullable),
  `rounding_rule` (`string(32)`, nullable), `reservation_id` (FK,
  nullable, `restrictOnDelete()`), `funding_attempt_id` (plain
  `unsignedBigInteger`, nullable, no FK at M1), `correlation_key`
  (`string(191)`, unique), `provider_reference` (`string(191)`,
  nullable), `actor_user_id` (nullable), `reason` (`text`, nullable),
  `reversed_entry_id` (nullable, self-FK), `created_at`. **Real
  production rows exist here** (top-ups, auto-recharge, add-on
  credits), **every one with `feature_key = null`.**
- `businesses` table exists and is a valid FK target (used throughout
  RFC-004/005 already, e.g. `business_feature_usage_limits.business_id`).
- `UsageWalletManager::reserve()` (read verbatim, unchanged from the
  prior pass's own quotation): resolves the classification, then checks
  only `$classification === null || $classification->active_rate_id === null`
  before proceeding to wallet-health checks and writing a reservation —
  **it does not check `is_metered` at all today.** This is the exact gap
  §1 below closes.
- `evaluateCoarseCapacity()` and `RealUsageAuthorizationGateway::check()`
  (read verbatim, unchanged from the prior pass's own quotation) —
  requoted and re-analyzed in §I below.
- `EntitlementManager::decide()`'s final step (unchanged): delegates to
  `UsageAuthorizationGateway::check()`, surfacing `usage_unauthorized` on
  denial.

---

## A. Domain model

Four concepts, precisely distinguished — unchanged in kind from the
prior pass, with one addition (Business scope, §3 below folded in as a
first-class part of `UsageMeter`'s own identity, not a bolt-on):

**`PlatformFeature`** — the **product entitlement taxonomy**. Unchanged
enum, unchanged ownership by RFC-004's plan/override/toggle/suspension
chain. Never represents an economic unit, a price, a quantity, or a
Business scope.

**`UsageMeter`** (new) — the **billable economic identity**, now
precisely: *a specific, real, variable-cost operation, optionally
scoped to one specific Business, that is either currently metered (with
exactly one active, verifiably-its-own rate) or not metered at all —
with no state in between that can authorize a charge.* A meter is
labeled with the `PlatformFeature` it economically belongs to (for
grouping/reporting only, never for entitlement) and, where its
real-world economics require it, scoped to exactly one Business (§3.A)
— both labels are immutable once the meter is created (§3.D).

**A rate** — unchanged shape (`retail_rate_micro`, `provider_cost_micro`,
`unit_label`, `rounding_rule`, `currency_id`, immutable, versioned).
Belongs to exactly one `UsageMeter`, now **database-enforced**, not
merely asserted (§3.B).

**A reservation / ledger entry** — unchanged mechanics. Carries both
`meter_key` and `feature_key` (§F, §G), with the pairing of `meter_key`
and the specific `rate_id` it snapshots now also **database-enforced**
to belong to the same meter (§3.C).

**Ownership relationships, exact, revised to show enforcement, not just
direction:**

```
PlatformFeature (1) ──owns (0..N, label only, unenforced)──> UsageMeter
Business        (0..1) ──scopes (0..N, FK-enforced)─────────> UsageMeter
UsageMeter      (1) ──owns (1..N, versioned, FK-enforced)───> business_usage_rate
UsageMeter      (1) ──points to (0..1, composite-FK-enforced, must be its own)─> its active business_usage_rate
UsageMeter      (1) ──owns (0..N, FK-enforced)───────────────> business_usage_reservation
business_usage_reservation ──snapshots (composite-FK-enforced, must be the same meter's own)─> business_usage_rate
business_usage_reservation (1) ──produces (1..N)─────────────> business_usage_ledger_entry
```

**Why `PlatformFeature`'s own ownership of a meter stays label-only,
unenforced, while Business scope and rate ownership become database-
enforced:** a `PlatformFeature` mislabeling on a meter is a reporting
inconvenience (a meter appears under the wrong grouping), never a
billing-correctness failure — nothing charges the wrong party because a
report groups it oddly. A Business-scope mismatch or a cross-meter rate
reference is a genuine billing-correctness failure — the exact class of
error this amendment exists to make structurally impossible, not merely
unlikely.

---

## B. `usage_meters` schema

```php
Schema::create('usage_meters', function (Blueprint $table) {
    $table->id();
    $table->string('meter_key', 128)->unique();
    $table->string('feature_key', 64);
    $table->unsignedBigInteger('business_id')->nullable();
    $table->boolean('is_metered')->default(false);
    $table->unsignedBigInteger('active_rate_id')->nullable();
    $table->text('description');
    $table->unsignedBigInteger('updated_by_user_id');
    $table->timestamps();

    $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
    $table->index('feature_key');
    $table->index('business_id');
});
```

**`active_rate_id` is declared here as a plain nullable column, without
its own FK yet** — its real constraint is the composite FK added in a
later migration step (§3.C, §O), once `business_usage_rates` carries
the composite unique index that FK must reference. This is a staging
requirement (§O), not a design ambiguity: the *eventual* constraint is
locked in §3.C; only its *migration ordering* is deferred to keep the
schema always creatable.

Column-by-column:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `meter_key` | `string(128)`, unique | No | — | The durable, auditable economic identity — a plain, human-assigned, unique string, mirroring `feature_key`'s own existing durability discipline. **Immutable after creation (§3.D).** |
| `feature_key` | `string(64)` | No | — | The owning `PlatformFeature`'s value, label only (§A). Not a foreign key — validated in application code against `PlatformFeature::tryFrom()`. **Immutable after creation (§3.D).** |
| `business_id` | `unsignedBigInteger`, FK → `businesses.id`, `restrictOnDelete()` | Yes | `NULL` | **New this pass (§3.A).** `NULL` = a globally reusable meter, usable by any otherwise-authorized Business. A non-null value scopes the meter to exactly that Business — `reserve()` must enforce this (§1, §3.A) before any mutation. **Immutable after creation (§3.D)** — a meter that needs a different scope is retired and replaced, never re-scoped. |
| `is_metered` | `boolean` | No | `false` | Mutable only via `activateMetering()` (re-pointed, §H). **`reserve()` now requires this to be `true` before authorizing any charge (§1)** — the exact gap the independent review found. |
| `active_rate_id` | `unsignedBigInteger`, nullable | Yes | `NULL` | Sole pointer `setActiveRate()` maintains. Its real integrity constraint is the composite FK in §3.C/§O, not a plain single-column FK. |
| `description` | `text` | No | — | Human-readable documentation of the real-world execution context this meter represents. **Mutable** — the only mutable identity-adjacent field on this table. |
| `updated_by_user_id` | `unsigned bigint`, no FK | No | — | **Not nullable** — unlike `platform_feature_usage_classifications.updated_by_user_id`, no `usage_meters` row is ever system-backfilled (§O); every row is created by an explicit human action from its first write. |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | Standard Eloquent timestamps. |

Indexes/constraints: `unique(meter_key)`; `index(feature_key)`;
`index(business_id)`; `foreign(business_id) → businesses.id,
restrictOnDelete()`; the composite FK on `(meter_key, active_rate_id)`
added in a later migration step (§3.C, §O).

---

## C. `usage_meter_transitions` schema

```php
Schema::create('usage_meter_transitions', function (Blueprint $table) {
    $table->id();
    $table->string('meter_key', 128);
    $table->boolean('from_is_metered');
    $table->boolean('to_is_metered');
    $table->foreignId('from_active_rate_id')->nullable();
    $table->foreignId('to_active_rate_id')->nullable();
    $table->unsignedBigInteger('actor_user_id');
    $table->text('reason');
    $table->timestamp('created_at');

    $table->foreign('from_active_rate_id', 'umt_from_active_rate_id_foreign')
        ->references('id')->on('business_usage_rates')->restrictOnDelete();
    $table->foreign('to_active_rate_id', 'umt_to_active_rate_id_foreign')
        ->references('id')->on('business_usage_rates')->restrictOnDelete();

    $table->index('meter_key');
});
```

Unchanged in shape from the prior pass. **Scope narrowed this pass, per
the independent review's §4 instruction:** this table audits
**metering-state changes only** — `false → true` (via `activateMetering()`)
and, if a future `deactivateMetering()` is ever added, `true → false`.
**It does not, and must not, audit rate rotations** —
`business_usage_rate_activations` already exists precisely for that
purpose (§4). A rate rotation on an already-metered meter therefore
writes **zero** rows to this table; it writes exactly one row to
`business_usage_rate_activations` (unchanged mechanic, §D) and updates
`usage_meters.active_rate_id` (§E, §H). This removes the prior pass's
unresolved "rotation-aware activation step" entirely — there is no
separate `true → true` transition case, because there is no separate
method that would produce one.

`actor_user_id` and `reason` remain mandatory; append-only; identical
short-constraint-name workaround (`umt_*`) for MySQL's 64-character
identifier limit, mirroring `pfuct_*` exactly.

---

## D. `business_usage_rates` — exact correction, revised for referential integrity

**Rename `feature_key` → `meter_key`, exactly as the prior pass
specified, plus two new constraints this pass adds:**

```php
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->renameColumn('feature_key', 'meter_key');
});

Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->dropUnique(['feature_key', 'version']); // pre-rename index name
    $table->unique(['meter_key', 'version']);
    $table->unique(['meter_key', 'id']); // new this pass — the composite-FK target for §3.C
});
```

**Why `unique(meter_key, id)` in addition to `unique(meter_key, version)`:**
`id` is already unique on its own (the primary key), so
`(meter_key, id)` is trivially unique too — this is not a new
constraint on real-world data, it is the exact composite index MySQL
requires to exist before another table can declare a composite foreign
key against `(meter_key, id)` (§3.C). No `meter_key → usage_meters.meter_key`
FK is added on this table in this same migration step — that FK is
added in a later step (§O), once `usage_meters` exists with its own
unique index on `meter_key`, avoiding a circular-reference creation
order problem.

Every other column is unchanged (§D of the prior pass, restated: no
real rate value is invented anywhere in this section; the rename
carries zero real-world risk since the table is confirmed empty, §0).

---

## E. `business_usage_rate_activations` — corresponding change

**Identical rename, plus the new referential-integrity FK this pass
adds:**

```php
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->renameColumn('feature_key', 'meter_key');
});

Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->dropIndex(['feature_key']); // pre-rename index name
    $table->index('meter_key');
});

// Added in a later migration step (§O), once usage_meters exists:
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->foreign('meter_key')->references('meter_key')->on('usage_meters')->restrictOnDelete();
});
```

`rate_id`, `activated_at`, `activated_by_user_id`, `reason`,
`created_at` unchanged. Confirmed empty in every real deployment (§0) —
zero data migration required for either the rename or the new FK.

---

## F. Reservations — exact treatment, revised for the same-meter integrity guarantee, and the fail-closed rule restated precisely

**`business_usage_reservations` gains `meter_key`, exactly as the prior
pass specified, plus the composite integrity FK this pass adds:**

```php
Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->string('meter_key', 128)->after('feature_key');
    $table->index('meter_key');
});

// Added in a later migration step (§O), once business_usage_rates carries unique(meter_key, id):
Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->foreign(['meter_key', 'rate_id'], 'busage_reservations_meter_rate_foreign')
        ->references(['meter_key', 'id'])->on('business_usage_rates')
        ->restrictOnDelete();
});
```

**Why this composite FK, exactly:** the existing plain
`rate_id → business_usage_rates.id` FK (unchanged) already guarantees
`rate_id` points to *some* real rate row. It does **not** guarantee
that rate's own `meter_key` matches the reservation's own `meter_key` —
nothing before this pass prevented a reservation from claiming
`meter_key = A` while its `rate_id` actually pointed at meter B's rate.
The new composite FK closes this precisely: MySQL will refuse to insert
or update a `business_usage_reservations` row whose
`(meter_key, rate_id)` pair does not exist as a `(meter_key, id)` pair
on `business_usage_rates` — a reservation's own financial snapshot can
never claim one meter while actually referencing another meter's rate.
This FK coexists with, and does not replace, the existing plain
`rate_id` FK.

`feature_key` is **retained, not renamed**, exactly per the prior
pass's own reasoning (unchanged): it becomes an immutable snapshot of
the owning `PlatformFeature`, copied from the resolved meter's own
`feature_key` label at `reserve()`-time, for feature-level reporting
independent of any specific meter's continued existence.

**The `reserve()` parameter-naming decision is unchanged from the prior
pass:** `reserve(Business $business, string $featureKey, string $idempotencyKey, ?string $estimatedQuantity = null): ReservationResult`
keeps its exact declaration, including the literal parameter name
`$featureKey`, which must be a `usage_meters.meter_key` value after this
amendment — documented via a prominent doc-comment at the declaration,
not a rename, for the identical reasons given in the prior pass (zero
real callers to protect, and the locked-signature constraint is read
conservatively to include parameter names).

**The fail-closed rule, corrected and made exact — this section's
central correction:**

The prior pass's own text, stating that an execution boundary
encountering an "unknown/unpriced meter" during `reserve()` might
"fall back to a legacy charging path," is **withdrawn as stated**. It
conflated two genuinely different moments, and only one of them may
ever fall back to legacy:

> **Before calling `reserve()` at all**, a future feature-specific
> execution boundary may — and, per Draft PR #107's own now-superseded
> precedent, should — decide that a particular operation does not map
> to any `UsageMeter` at all (e.g. the operation's own execution
> context does not match any meter this feature currently owns), and
> therefore remains on its existing legacy/non-metered charging path.
> This decision happens entirely outside `reserve()`, using information
> the execution boundary already has before it ever resolves a
> `meter_key` string to pass in.
>
> **Once an execution boundary has selected a specific `meter_key` and
> invoked `reserve()` with it, every failure — unknown meter, an
> inactive meter (rate set but `is_metered = false`), a metered meter
> with no valid active rate, or a rate/meter integrity violation —
> fails closed.** None of these outcomes may ever be reinterpreted by
> the caller as permission to charge the identical operation through a
> legacy path instead. An invalid or misconfigured meter identity is a
> **stop condition for that billing attempt**, never an implicit
> authorization to bill the same real-world event through a different
> system. (This mirrors, and is required by, the same discipline the
> original RFC-005 wallet design already applies everywhere else:
> `reserve()`'s existing `wallet_suspended`/`outstanding_debt`/
> `insufficient_balance` denials have never been treated as "so charge
> them some other way instead," and meter-identity failures receive the
> identical posture.)

**Exact `reserve()` check sequence, locked (resolves independent review
issue 1 completely):**

Inside `reserve()`'s existing `DB::transaction()` closure, immediately
after the wallet is locked and rolled over, **before any reservation,
ledger, or wallet-balance write occurs** — mirroring exactly where the
existing `NoActiveRateForFeatureException` check already sits today:

```php
$meter = $this->meterRepository->findByMeterKey($featureKey);

if ($meter === null) {
    throw new NoActiveRateForFeatureException($featureKey);
}

if ($meter->business_id !== null && (int) $meter->business_id !== (int) $business->id) {
    throw new UsageMeterBusinessScopeMismatchException($featureKey, (int) $business->id);
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

if ($rate->meter_key !== $meter->meter_key) {
    throw new UsageMeterRateIntegrityException($featureKey, $rate->id);
}
```

Only after every one of these checks passes does the existing
wallet-health sequence (`wallet_suspended`/`outstanding_debt`/
`insufficient_balance`) and the existing reservation/ledger/wallet
writes proceed, completely unchanged.

**Three exception classes, exact, none reusing a name whose meaning
would become false:**

- **`NoActiveRateForFeatureException`** (existing, unchanged name and
  constructor) — reused **only** where its meaning stays literally
  true: the meter does not exist, the meter has no `active_rate_id` at
  all, or the pointed rate row does not exist (a dangling-pointer
  defensive check, expected unreachable given `restrictOnDelete()`). In
  every one of these cases, there genuinely is no usable active rate —
  reusing this exception here is not a semantic drift.
- **`UsageMeterNotMeteredException`** (new) — the exact case the
  independent review named: a meter with a real, valid `active_rate_id`
  that has simply never had `activateMetering()` called for it (or has
  had metering explicitly turned off). Reusing
  `NoActiveRateForFeatureException` here would be false — a rate
  unambiguously exists; metering does not.
- **`UsageMeterBusinessScopeMismatchException`** (new) — a
  Business-scoped meter (§3.A) invoked by a Business other than the one
  it is scoped to. Never conflated with "no rate" or "not metered" —
  the meter may be perfectly active and priced; it simply does not
  belong to this caller.
- **`UsageMeterRateIntegrityException`** (new) — the cross-meter
  rate-reference case. Expected **structurally unreachable** once the
  composite FK (§3.C) is in place; retained as an explicit,
  precisely-named defensive check rather than silently trusting the
  database constraint alone, consistent with this table's own general
  "trust but verify" posture elsewhere (e.g. `reserve()`'s existing
  dangling-rate check, which is likewise defensive given
  `restrictOnDelete()` already prevents it structurally).

**Immutable snapshots stored on the reservation:** unchanged mechanically
from the prior pass — `rate_id`, `rate_version`, `retail_rate_micro`,
`provider_cost_micro`, `rounding_rule` copied from the resolved,
now-verified-same-meter rate; `meter_key` copied from `$meter->meter_key`
(equal to the input `$featureKey` by definition); `feature_key` copied
from `$meter->feature_key`.

**Idempotency scoping:** unchanged, unaffected by this pass — the
pre-existing, separately-documented Draft PR #107 finding about
`findByIdempotencyKey()` not being Business-scoped remains a distinct,
unresolved concern for whichever future execution boundary derives an
idempotency key, out of this amendment's own scope.

---

## G. Ledger — exact treatment

Unchanged from the prior pass: `business_usage_ledger_entries` gains
`meter_key` (nullable, mirroring `feature_key`'s own nullability),
populated for every metered entry type directly from the owning
reservation's already-verified, already-snapshotted values — no new
lookup, no new integrity concern beyond what §F already guarantees at
the reservation level (a ledger entry's `meter_key`/`rate_id` pair is
copied from a reservation whose own pairing is already FK-verified, so
no additional composite FK is needed on the ledger table itself).

---

## H. `UsageWalletManager` — per-method treatment, exact, revised

| Method | Internal change | Public signature | Notes |
|---|---|---|---|
| `initializeWalletForNewBusiness` | None | Unchanged | |
| `reserve` | Resolves `$featureKey` against `UsageMeterRepository::findByMeterKey()`; performs the full six-check sequence in §F (business scope, active-rate presence, `is_metered`, dangling-rate defense, same-meter integrity defense) **before any wallet-health check or write**; writes `meter_key`/`feature_key` into the reservation and its ledger entry | **Unchanged, including the literal `$featureKey` parameter name** | The independent review's central fix lives entirely here |
| `commit` | Copies the reservation's own already-verified `meter_key` into its ledger entries, alongside the existing `feature_key` copy | Unchanged | No new lookup — the reservation was already verified at `reserve()`-time |
| `release` | Identical one-field addition | Unchanged | |
| `expireStaleReservations` | None | Unchanged | |
| `setActiveRate` | Resolves via `UsageMeterRepository::findForUpdateByMeterKey()`; if no `usage_meters` row exists, throws `NoActiveRateForFeatureException` (an explicit, named precondition — a meter must be created before a rate can be activated for it, §O); computes the next version via `latestVersionForMeter()`; inserts the new immutable rate with `meter_key`; inserts the activation row with `meter_key`; updates `usage_meters.active_rate_id`. **Works identically whether `is_metered` is currently `false` or `true` — the method never branches on it** (§4) | Unchanged | This is now the **sole, complete rate-lifecycle method** — see §4 |
| `activateMetering` | Same resolution-target swap; writes to `usage_meter_transitions` (metering-state only, §C); updates `usage_meters.is_metered` | Unchanged | |
| `evaluateCoarseCapacity` | **Becomes a pure, unconditional pass-through — see below.** | Unchanged (`(Business $business, PlatformFeature $feature): UsageCapacityDecision`) | Both parameters are now unused inside the body; kept because the signature is locked |
| Every other public method | None | Unchanged | |

**`evaluateCoarseCapacity()`'s new, complete body, locked exactly —
resolves independent review issue 2:**

```php
/**
 * RFC-005 Amendment 1 — generic PlatformFeature entitlement is
 * permanently decoupled from meter-specific wallet health. This method
 * never reads platform_feature_usage_classifications, never reads any
 * wallet, and never denies. The $business/$feature parameters are
 * retained only because UsageWalletManager's public signatures are
 * locked; neither is consulted. The real wallet-capacity question for
 * any specific billable operation is answered exclusively by
 * reserve(Business, meterKey, ...) at the actual execution boundary,
 * never by this coarse, feature-wide gate.
 */
public function evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision
{
    return new UsageCapacityDecision(true);
}
```

**No classification read. No wallet read. No wallet-health denial. No
data invariant required to keep this correct** — this is now a
structural, code-level guarantee, not a hoped-for consequence of nobody
writing to a table. Even if a stale test fixture, manual DB state, or a
future accidental write set some
`platform_feature_usage_classifications` row's `is_metered = true`,
this method would never read that row at all, and
`EntitlementManager::decide()` would be completely unaffected — exactly
the mechanical (not merely data-driven) guarantee the independent
review required.

**`RealUsageAuthorizationGateway::check()` is unchanged** — it still
calls `evaluateCoarseCapacity()` and still maps a `false` decision to
`'usage_unauthorized'`; it simply never observes a `false` decision
again, since the method above never produces one. Its own signature,
its binding in `AppServiceProvider`, and `EntitlementManager::decide()`'s
exact call site are all untouched.

**Constructor reconciliation, addressed exactly as asked:**
`PlatformFeatureUsageClassificationRepository` and
`PlatformFeatureUsageClassificationTransitionRepository` become
genuinely unused inside `UsageWalletManager` after this change —
`evaluateCoarseCapacity()` no longer reads the former, and
`setActiveRate()`/`activateMetering()` no longer read or write either
(§H, both re-pointed to the new meter repositories). **This design
removes both from `UsageWalletManager`'s constructor.** This is
judged permitted by the merged contract's own "public method signatures
remain unchanged" constraint for a precise reason: `UsageWalletManager`
is resolved exclusively through Laravel's dependency-injection
container everywhere it is used (no manual `new UsageWalletManager(...)`
call exists anywhere in the repository, confirmed) — the constructor's
own shape is therefore an internal wiring detail no real caller depends
on by position or count, unlike `reserve()`/`commit()`/`release()`/
`setActiveRate()`/`activateMetering()`, which the contract names
explicitly and which real (future) callers do depend on. Removing two
now-dead parameters is judged more honest than retaining them
unused and undocumented, which the independent review correctly
identified as the worse alternative. **The `PlatformFeatureUsageClassificationRepository`/
`PlatformFeatureUsageClassificationTransitionRepository` contracts,
their Eloquent implementations, and the `PlatformFeatureUsageClassification`/
`PlatformFeatureUsageClassificationTransition` models are not deleted**
— they still validly represent real, untouched schema (§N) and may
still be read elsewhere (e.g. a future admin diagnostic view listing
every feature's historical classification row) even though
`UsageWalletManager` no longer references them.

**New Usage-layer exception classes this amendment requires** (named
precisely, none implemented here): `UsageMeterNotMeteredException`,
`UsageMeterBusinessScopeMismatchException`, `UsageMeterRateIntegrityException`
— each constructed with the meter key (and, where relevant, the
conflicting Business id or rate id), following the exact existing
constructor-argument convention `NoActiveRateForFeatureException`
already uses.

---

## I. Entitlement / usage authorization — the exact mechanical rule, now structurally enforced

**The distinction, unchanged in statement from the prior pass:**
`EntitlementManager::decide()` answers product entitlement; a
meter-scoped `reserve()` call at the execution boundary answers
billable-operation wallet authority.

**The mechanism, corrected this pass:** the prior pass's claim rested on
a **data invariant** (`platform_feature_usage_classifications.is_metered`
is expected to stay `false` forever, because nothing is expected to
write to it again). The independent review correctly identified this as
insufficient — a data invariant is not a decoupling; it is a hope.
**This pass replaces it with a structural guarantee:** `evaluateCoarseCapacity()`
(§H) no longer reads that table **at all**, under any circumstance. Its
new body is a two-line, unconditional pass-through. There is no code
path — not a stale fixture, not a manual `UPDATE`, not a future
accidental write — that can make `EntitlementManager::decide()`
sensitive to any meter's wallet health, because the method that used to
bridge the two now contains no read of either. The old table's own
schema and every existing row remain completely untouched (§N) — only
the *code that used to consult it* is removed.

**No tenth `EntitlementManager` denial key is introduced.**
`usage_unauthorized` remains a defined string in `decide()`'s reason
space, now permanently unreachable through this path (it could only
ever become reachable again if a *future*, separate design decision
chose to wire a whole-feature-level gate back through
`evaluateCoarseCapacity()` — a door this amendment does not lock shut in
the schema, only in this method's own current body).

---

## J. Meter identity rules

Unchanged in substance from the prior pass — the telecom and AI/token
stress tests, and the four locked naming rules — with one addition
reflecting §3.A: **a meter naming decision must also state, explicitly,
whether the meter is globally reusable or Business-scoped, and if
scoped, to which real Business id** — this is now a structural field
(`business_id`) a future contract must populate deliberately, not an
implicit assumption buried in `description` prose. This amendment names
no real meter and selects no real Business scope for any real meter.

---

## K. Quantity

Unchanged from the prior pass in every respect — quantity belongs to
the meter/rate pairing, `rate × quantity` accounting is untouched,
`provider_cost_micro` may differ from `retail_rate_micro`, quantity must
never encode money, estimated/final quantity rules are unchanged, and
rate snapshotting is unchanged.

---

## L. Unsupported meter behavior — fail-closed, exact, revised

| Condition | Where it surfaces | Behavior |
|---|---|---|
| Unknown `meter_key` | `reserve()` | `NoActiveRateForFeatureException` |
| Meter resolved, but `business_id` set and does not match the caller's Business | `reserve()` | **New this pass:** `UsageMeterBusinessScopeMismatchException` |
| Meter exists, no `active_rate_id` | `reserve()` | `NoActiveRateForFeatureException` |
| Meter exists, has an `active_rate_id`, but `is_metered = false` | `reserve()` | **New this pass, closing the exact gap the independent review found:** `UsageMeterNotMeteredException` |
| Referenced rate row does not exist (dangling pointer — expected unreachable, `restrictOnDelete()`) | `reserve()` | `NoActiveRateForFeatureException` |
| Referenced rate's own `meter_key` does not match the meter's `meter_key` (expected structurally unreachable given §3.C's composite FK) | `reserve()` | **New this pass, defensive:** `UsageMeterRateIntegrityException` |
| Missing wallet / suspended wallet / outstanding debt / insufficient balance | `reserve()`, after every check above passes | Unchanged (`wallet_missing` via `evaluateCoarseCapacity()`'s now-unreachable-through-`decide()` path is no longer relevant here — `reserve()` itself still separately checks wallet existence/health directly, unchanged from today) |

**Every one of these fails closed: zero reservation row, zero ledger
row, zero wallet-balance mutation** — all seven checks occur inside the
existing transaction, strictly before the first write, mirroring
exactly where today's single existing check already sits. **No
execution boundary may reinterpret any of these failures as permission
to charge the same operation through a legacy path** (§F). Externally-
visible entitlement denial keys remain completely unchanged — none of
these six new/reused exceptions is an `EntitlementManager` denial key;
all six are execution-boundary, Usage-layer exceptions, exactly the
existing vocabulary's own shape.

---

## M. Business attribution — explicitly not solved by this amendment

Unchanged from the prior pass in its core finding: the `UsageMeter`
architecture does not, by itself, resolve which Business a legacy
execution surface belongs to — that remains a separate, named
prerequisite (§M of the prior pass, restated verbatim in substance).

**Clarified this pass, given §3.A's new `business_id` column:** a
meter's own `business_id` scope is a **safety rail against consuming
the wrong meter once a Business has already been correctly resolved**
— it is not, and cannot be, a substitute for that resolution. An
execution boundary that has not correctly resolved its authoritative
Business first could still, in principle, pass the *correct* Business
object alongside an *incorrectly chosen* `meter_key` and be denied
correctly by `UsageMeterBusinessScopeMismatchException` — but if the
Business resolution itself is wrong (e.g. the wrong Business object was
resolved in the first place), no meter-level check can detect that;
§M's own interim compatibility rule (`primaryBusiness()` + "Workspace
owns exactly one Business") remains the only defense against that
distinct failure mode, and remains explicitly out of this amendment's
own scope to solve generally.

---

## N. M1–M4 compatibility

Unchanged from the prior pass in every row, with two additions
reflecting this pass's schema changes:

| Table / API | Touched | Change | Historical data reinterpreted? |
|---|---|---|---|
| `usage_meters` | New | §B | N/A — new table |
| `usage_meter_transitions` | New | §C | N/A — new table |
| `business_usage_rates` | Yes | Rename + two new unique indexes (§D) | No real rows exist |
| `business_usage_rate_activations` | Yes | Rename + index + new FK (§E) | No real rows exist |
| `business_usage_reservations` | Yes | Additive `meter_key` + composite FK (§F) | No real rows exist |
| `business_usage_ledger_entries` | Yes | Additive `meter_key` (§G) | No — every existing row (`feature_key = null`) is unaffected |
| `platform_feature_usage_classifications` | No (schema) | **No code ever reads or writes it from `UsageWalletManager` again** (§H, §I) | Every backfilled row remains exactly as it is, permanently, by structural guarantee |
| `platform_feature_usage_classification_transitions` | No | Remains permanently empty | No real rows exist |
| `business_feature_usage_limits`, `platform_feature_usage_safety_limits`, `business_usage_limit_transitions` | No | None — remain feature-scoped (§N of prior pass, unchanged) | Unaffected |
| `business_usage_wallet_billing_status_transitions`, `business_billing_contacts`, `business_payer_assignments`, `business_payer_transitions` | No | None | Unaffected |
| M3 funding tables (`payment_provider_customers`, `business_payment_instruments`, `business_funding_attempts`, `payment_provider_events`, `business_funding_attempt_transitions`) | No | None | **M3 funding behavior is completely unchanged** |
| M4 slot/add-on tables | No | None | **M4 slot/add-on behavior is completely unchanged** |
| `UsageWalletManager` public API | Yes (internal + constructor) | §H | Fully backward compatible for every real (nonexistent) caller |
| `EntitlementManager::decide()` | No | None | Fully unchanged |
| `RealUsageAuthorizationGateway::check()` | No | None | Fully unchanged |

---

## O. Migration/backfill strategy — exact staged order, resolving the circular-reference problem

**No migration is created by this document.** The final constraint
graph is genuinely circular — `usage_meters.active_rate_id` must
eventually reference `business_usage_rates(meter_key, id)`, while
`business_usage_rates.meter_key` must reference
`usage_meters.meter_key` — so both tables must exist, with their own
primary identity constraints in place, **before either cross-table FK
can be added.** The future implementation contract must sequence
exactly as follows:

1. **Create `usage_meters`** (§B) with `active_rate_id` as a plain,
   unconstrained nullable `unsignedBigInteger` — no FK yet. Include the
   `business_id` FK (§3.A) immediately; `businesses` already exists, so
   this one is not circular. Empty at creation.
2. **Rename `business_usage_rates.feature_key` → `meter_key`** (§D);
   drop the old `unique(feature_key, version)`; add
   `unique(meter_key, version)` **and** `unique(meter_key, id)` (the
   composite-FK target for steps 4 and 7). Safe — table is empty.
3. **Add `business_usage_rates.meter_key → usage_meters.meter_key`
   FK, `restrictOnDelete()`** (§D) — now valid, since `usage_meters`
   exists with a unique index on `meter_key` (step 1) and
   `business_usage_rates` now has the renamed column (step 2).
4. **Add the composite FK `usage_meters(meter_key, active_rate_id) → business_usage_rates(meter_key, id)`**
   (§3.C, §B) — now valid, since step 2 created the required
   `unique(meter_key, id)` target. `NULL` values in `active_rate_id`
   are exempted from enforcement by standard SQL multi-column FK
   semantics, so a freshly-created meter with no rate yet remains
   valid.
5. **Rename `business_usage_rate_activations.feature_key` → `meter_key`**
   (§E); swap the index; add its own
   `meter_key → usage_meters.meter_key` FK (now valid, same reasoning
   as step 3). Safe — table is empty.
6. **Create `usage_meter_transitions`** (§C) — no circularity concern;
   its FKs point only to `business_usage_rates.id` (the plain PK, not
   the composite), exactly as `platform_feature_usage_classification_transitions`
   already does today.
7. **Add `business_usage_reservations.meter_key`** (§F), `NOT NULL`
   (table is empty, no default needed), plus the composite FK
   `(meter_key, rate_id) → business_usage_rates(meter_key, id)` — valid
   immediately, since step 2 already created the target index.
8. **Add `business_usage_ledger_entries.meter_key`, nullable** (§G) —
   safe regardless of existing rows.
9. **Code changes**, strictly after every schema step above: new
   `UsageMeter`/`UsageMeterTransition` models; new
   `UsageMeterRepository`/`UsageMeterTransitionRepository` contracts and
   Eloquent implementations, bound in `AppServiceProvider` alongside
   (steps 1–8 having made `PlatformFeatureUsageClassificationRepository`
   itself still bound but no longer consumed by `UsageWalletManager`,
   §H); the three new exception classes (§H, §L); `UsageWalletManager`'s
   constructor and the seven methods named in §H updated per that
   section's exact specification.

**Zero fabricated rows, unchanged from the prior pass:** no
`usage_meters` row is ever auto-created from `PlatformFeature::cases()`
— every real row is created only by an explicit, later, human-
authorized action. **Rollback posture:** every schema step above is
reversible in the standard Laravel sense; step 9's code changes carry
no independent rollback concern, since no real behavior changes until a
future milestone creates and activates a real meter.

---

## P. Amendment implementation decomposition

**Two bounded slices, revised this pass to reflect the corrected
design:**

**Slice 1 — Schema and repository foundation.** §O steps 1–8, plus the
new model/repository/contract files from step 9 (created, but
`UsageWalletManager` not yet touched, and the three new exception
classes created but not yet thrown anywhere). Conceptual
responsibility: prove the new tables, columns, and — critically, new
this pass — **every composite FK** are correctly created and correctly
reject a manually-attempted cross-meter insert at the database level,
with zero change to any existing runtime behavior.

**Slice 2 — `UsageWalletManager` re-pointing.** The seven method bodies
in §H, the constructor change, and `evaluateCoarseCapacity()`'s new
two-line body. Conceptual responsibility, revised per the independent
review's own required proofs (§13, restated below): prove the full
`reserve()` check sequence (§F) denies every one of the five failure
modes with zero writes; prove `setActiveRate()` correctly rotates an
already-metered meter's rate without disturbing existing reservations'
own snapshots; and — replacing the withdrawn "byte-identical source"
requirement — prove **behaviorally** that `EntitlementManager::decide()`
is unaffected by meter-specific wallet health even when a
`platform_feature_usage_classifications` row is deliberately, manually
set to `is_metered = true` against a Business whose wallet is
suspended, in debt, or missing.

Neither slice creates a real meter, activates a real rate, or selects a
feature. M5 (§Q) is not part of either slice.

---

## Q. M5 resumption

Unchanged from the prior pass in every respect: a fresh candidate/meter
audit, not a retrofit of Draft PR #107 (disposition unchanged: open,
Draft, unmerged, as blocker evidence); the future M5 contract must name
the exact `PlatformFeature`, the exact `UsageMeter` identity (now
additionally: its exact Business scope, per §3.A — global or one named
Business), the exact execution boundary, the exact authoritative
Business-resolution mechanism, the exact human-approved rate, the exact
quantity unit, and every provider/idempotency rule its own transport
layer requires. RFC-005 §39 item 11 remains open.

---

## Governance — exact relationship to the master RFC-005 document

Unchanged from the prior pass: this amendment supersedes §14
(corrected exactly per §A–§L), references §11 (rate shape confirmed to
survive, §D), amends §36's Milestone 5 entry conceptually (§Q), and
confirms §39 item 11 remains open. No other RFC-005 section is amended,
and this document does not edit the master RFC-005 file directly.

---

## Required future implementation proofs

Restated and expanded per the independent review's own required list —
every one of these is a test the future implementation contract must
specify, none written here:

1. Generic `PlatformFeature` entitlement is wallet-independent even if
   an old `platform_feature_usage_classifications` row is manually set
   to `is_metered = true` against a Business whose wallet is suspended,
   in debt, or missing — `decide()`'s answer is unaffected in every
   case.
2. A meter with an active rate but `is_metered = false` cannot reserve
   — `UsageMeterNotMeteredException`, zero writes.
3. A metered meter with no valid active rate cannot reserve —
   `NoActiveRateForFeatureException`, zero writes.
4. An unknown meter key cannot reserve — `NoActiveRateForFeatureException`,
   zero writes.
5. Business A cannot reserve against a meter scoped to Business B —
   `UsageMeterBusinessScopeMismatchException`, zero writes.
6. A globally-scoped meter (`business_id = null`) may be used by any
   otherwise-authorized Business.
7. `usage_meters.active_rate_id` can never be made to reference another
   meter's rate — attempted directly at the database layer, the
   composite FK rejects the write.
8. A reservation's `meter_key`/`rate_id` pair can never contradict one
   another — attempted directly at the database layer, the composite FK
   rejects the write.
9. `setActiveRate()` rotates an already-metered meter's rate safely —
   the meter's `active_rate_id` updates, a new
   `business_usage_rate_activations` row is written, and **no**
   `usage_meter_transitions` row is written (§C).
10. An existing `Pending` reservation continues using its own
    originally-snapshotted `rate_id`/`rate_version` after a rotation —
    unaffected by any later `setActiveRate()` call against the same
    meter.
11. Every existing `platform_feature_usage_classifications` row remains
    byte-identical before and after the full implementation lands.
12. M3 funding and M4 add-on/slot flows remain unchanged (full
    regression, unaffected by every schema/code change above).
13. Metered success (all checks passing) still produces exactly one
    reservation and, on `commit()`, exactly one charge — the corrected
    fail-closed checks add no new success-path behavior.

---

## Unresolved human decisions (explicitly listed, separated from structural design)

1. No real `meter_key` value, real Business scope, or real rate value is
   chosen anywhere in this document.
2. No `PlatformFeature` is selected for M5.
3. The general, non-interim Business-attribution solution (§M) remains
   undesigned.
4. Whether `business_feature_usage_limits`/`platform_feature_usage_safety_limits`
   should ever gain meter-level (or Business-scope-aware) granularity
   remains undecided — left feature-scoped, unchanged from the prior
   pass.
5. Whether a future `deactivateMetering()` method is ever needed is
   left open — the schema (§C) is forward-compatible with it without
   further change, but this amendment does not design or require it.

**Removed this pass:** the prior pass's unresolved decision about a
"rotation-aware activation step"'s method name — no longer applicable,
since §4/§H/§C establish `setActiveRate()` as the complete, sole
rate-lifecycle mechanism, with no second method required.

---

*End of RFC-005 Amendment 1 design document. Implementation of any kind
— migration, model, repository, manager, gateway, controller, route,
view, test, or configuration — requires a separate, later, explicitly
bounded implementation contract, itself requiring separate human
approval before any code is written.*
