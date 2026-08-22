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

---

## 0. Repository facts this design is built on

Verified by direct read of `origin/main` at
`0d25be2ce070e6167a7320a044f22bfdd392ea32` before drafting:

- `platform_feature_usage_classifications` (migration
  `2026_08_16_120004_...`): `id`, `feature_key` (`string(64)`, unique),
  `is_metered` (`boolean`, default `false`), `active_rate_id`
  (`unsignedBigInteger`, nullable, FK → `business_usage_rates.id`,
  `restrictOnDelete()`), `updated_by_user_id` (nullable),
  `created_at`/`updated_at`. Backfilled with one row per
  `PlatformFeature` case, `is_metered = false`, at M1. **No row has ever
  had `is_metered` flipped to `true` in any real deployment.**
- `platform_feature_usage_classification_transitions` (migration
  `2026_08_16_120005_...`): `id`, `feature_key` (`string(64)`),
  `from_is_metered`/`to_is_metered` (`boolean`), `from_active_rate_id`/
  `to_active_rate_id` (nullable FK → `business_usage_rates.id`,
  `restrictOnDelete()`, short constraint names `pfuct_from_active_rate_id_foreign`/
  `pfuct_to_active_rate_id_foreign` because the auto-generated name
  exceeds MySQL's 64-character identifier limit), `actor_user_id`
  (`unsignedBigInteger`), `reason` (`text`), `created_at`. **Confirmed
  empty in every real deployment** — no code path has ever called
  `UsageWalletManager::activateMetering()`.
- `business_usage_rates` (migration `2026_08_16_120002_...`): `id`,
  `feature_key` (`string(64)`), `version` (`unsignedInteger`),
  `retail_rate_micro`/`provider_cost_micro` (`bigInteger unsigned`),
  `unit_label` (`string(64)`), `rounding_rule` (`string(32)`, default
  `round_half_up`), `currency_id` (FK → `currencies.id`,
  `restrictOnDelete()`), `created_by_user_id` (`unsignedBigInteger`),
  `created_at` only (no `updated_at` — rows are immutable). Unique on
  `(feature_key, version)`. **Confirmed empty in every real deployment.**
- `business_usage_rate_activations` (migration `2026_08_16_120003_...`):
  `id`, `feature_key` (`string(64)`), `rate_id` (FK, `restrictOnDelete()`),
  `activated_at`, `activated_by_user_id`, `reason` (`text`), `created_at`,
  index on `feature_key`. **Confirmed empty in every real deployment.**
- `business_usage_reservations` (migration `2026_08_16_120006_...`):
  `id`, `business_id`, `wallet_id` (both `unsignedBigInteger`),
  `feature_key` (`string(64)`), `period_key` (`string(7)`), `status`
  (`string(16)`, default `pending`), `reserved_amount_micro`
  (`bigInteger`), `estimated_quantity` (`decimal(14,6)`, nullable),
  `rate_id` (FK, `restrictOnDelete()`, **not nullable**), `rate_version`
  (`unsignedInteger`), `retail_rate_micro`/`provider_cost_micro`
  (`bigInteger unsigned`), `rounding_rule` (`string(32)`),
  `idempotency_key` (`string(191)`, unique), `correlation_key`
  (`string(191)`), `reserved_at`/`expires_at` (not nullable),
  `committed_at`/`released_at` (nullable), `final_quantity`
  (`decimal(14,6)`, nullable), `final_amount_micro` (`bigInteger`,
  nullable); composite FK `(wallet_id, business_id)` →
  `business_usage_wallets(id, business_id)`; indexes on
  `(wallet_id, business_id)` and `status`. **Confirmed empty in every
  real deployment** (`reserve()` cannot create a row without a
  pre-existing active rate, and no rate has ever been activated).
- `business_usage_ledger_entries` (migration `2026_08_16_120007_...`):
  `id`, `business_id`/`wallet_id` (`unsignedBigInteger`), `entry_type`
  (`string(32)`), `available_delta_micro`/`reserved_delta_micro`/
  `debt_delta_micro` (`bigInteger`, default `0`), `gross_amount_micro`
  (`bigInteger unsigned`, nullable), `currency_id` (FK,
  `restrictOnDelete()`), `feature_key` (`string(64)`, **nullable**),
  `period_key` (`string(7)`, nullable), `quantity` (`decimal(14,6)`,
  nullable), `rate_id` (FK, nullable, `restrictOnDelete()`),
  `rate_version` (`unsignedInteger`, nullable), `retail_rate_micro`/
  `provider_cost_micro` (`bigInteger unsigned`, nullable), `unit_label`
  (`string(64)`, nullable), `rounding_rule` (`string(32)`, nullable),
  `reservation_id` (FK, nullable, `restrictOnDelete()`),
  `funding_attempt_id` (plain `unsignedBigInteger`, nullable, **no FK at
  M1** — deferred until M3's own table exists), `correlation_key`
  (`string(191)`, unique), `provider_reference` (`string(191)`,
  nullable), `actor_user_id` (nullable), `reason` (`text`, nullable),
  `reversed_entry_id` (nullable, self-FK), `created_at`; composite FK
  `(wallet_id, business_id)`; self-FK on `reversed_entry_id`; indexes on
  `(wallet_id, business_id)`, `entry_type`, `reservation_id`. **Real
  production rows exist here** — every manual top-up, auto-recharge, and
  add-on-credit entry — **but every one of them has `feature_key = null`
  today**, since no feature has ever been metered.
- `UsageWalletManager`'s complete public API (16 methods, read in full):
  `initializeWalletForNewBusiness`, `reserve`, `commit`, `release`,
  `creditFromFunding`, `expireStaleReservations`, `setActiveRate`,
  `activateMetering`, `evaluateCoarseCapacity`, `setSpendCap`,
  `setFeatureLimit`, `setSafetyLimit`, `setBillingStatus`,
  `configureAutoRecharge`, `recordAutoRechargeFailure`, plus its
  constructor. Its constructor already injects eleven repository
  contracts, including `PlatformFeatureUsageClassificationRepository`
  (`findByFeatureKey`, `findForUpdateByFeatureKey`, `create`, `update`),
  `PlatformFeatureUsageClassificationTransitionRepository` (`create`
  only), `BusinessUsageRateRepository` (`findById`,
  `findByFeatureAndVersion`, `latestVersionForFeature`, `create`), and
  `BusinessUsageRateActivationRepository`.
- `evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision`
  (read verbatim):
  ```php
  public function evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision
  {
      $classification = $this->classificationRepository->findByFeatureKey($feature->value);

      if ($classification === null || ! $classification->is_metered) {
          return new UsageCapacityDecision(true);
      }

      $wallet = $this->walletRepository->findByBusinessId($business->id);

      if ($wallet === null) {
          return new UsageCapacityDecision(false, 'wallet_missing');
      }

      if ($wallet->billing_status === WalletBillingStatus::Suspended) {
          return new UsageCapacityDecision(false, 'wallet_suspended');
      }

      if ($wallet->debt_balance_micro > 0) {
          return new UsageCapacityDecision(false, 'outstanding_debt');
      }

      return new UsageCapacityDecision(true);
  }
  ```
- `RealUsageAuthorizationGateway::check()` (read verbatim): delegates
  entirely to the method above, and its own docblock already states
  *"provably behaviorally identical to `NullUsageAuthorizationGateway`
  ... since every `PlatformFeature` classification stays
  `is_metered=false`"* — a self-documented equivalence this amendment
  extends, not invents.
- `EntitlementManager::decide()`'s final step (read verbatim, unchanged):
  `$usageResult = $this->usageAuthorizationGateway->check($currentBusiness, $feature); if (! $usageResult->authorized) { return new EntitlementDecision(false, $usageResult->reason ?? 'usage_unauthorized'); }`.
- The nine-key `decide()` denial surface (`platform_feature_unknown`,
  `platform_feature_unavailable`, `workspace_plan_unassigned`,
  `denied_by_workspace_override`/`not_entitled_by_plan`,
  `disabled_for_business`, `plan_suspended`, `plan_inactive`,
  `usage_unauthorized`) is asserted end-to-end by the already-existing
  `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`.
- `app/Providers/AppServiceProvider.php` is the exact, existing binding
  location for `PlatformFeatureUsageClassificationRepository` and every
  other repository contract this document names.

---

## A. Domain model

Four concepts, precisely distinguished:

**`PlatformFeature`** — the **product entitlement taxonomy**. A stable,
code-defined identity (the existing enum, unchanged) answering exactly
one question: *is this Business, on this plan, with this Workspace's
overrides and toggles, allowed to use this product feature at all?*
`PlatformFeature` never represents an economic unit, a price, or a
quantity. It is owned entirely by RFC-004 (`PlatformFeatureRegistry`'s
`Available`/`Planned` floor, `EntitlementManager::decide()`'s
plan/override/toggle/suspension chain) and this amendment changes none
of that ownership.

**`UsageMeter`** (new) — the **billable economic identity**. A
`UsageMeter` answers a different question: *is this specific, real,
variable-cost operation currently metered, and at what rate?* A meter
is independently created, independently activated, and independently
priced. It is *labeled* with the `PlatformFeature` it economically
belongs to (for grouping and reporting), but that label is descriptive,
not authoritative over entitlement — a meter's own `is_metered`/
`active_rate_id` state never feeds back into whether the owning
feature is presented as usable.

**A rate** — unchanged in shape from today's `business_usage_rates`
(`retail_rate_micro`, `provider_cost_micro`, `unit_label`,
`rounding_rule`, `currency_id`, immutable, versioned). A rate now
belongs to exactly one `UsageMeter` (via `meter_key`) rather than to a
`PlatformFeature` (via `feature_key`).

**A reservation / ledger entry** — unchanged in shape and mechanics
(`reserve()`/`commit()`/`release()`'s existing algorithms, verbatim).
Each reservation and each metered ledger entry now carries **both** a
`meter_key` (which precise economic unit was charged) **and** a
`feature_key` (which product feature that unit belongs to, an immutable
snapshot taken at `reserve()`-time from the meter's own label) — see §F
and §G for the exact reasoning.

**Ownership relationships, exact:**

```
PlatformFeature (1) ──owns (0..N, by label only)──> UsageMeter
UsageMeter      (1) ──owns (1..N, versioned)────────> business_usage_rate
UsageMeter      (1) ──owns (0..N)────────────────────> business_usage_reservation
business_usage_reservation (1) ──produces (1..N)─────> business_usage_ledger_entry
```

**Why they are intentionally different identities, not one taxonomy
wearing two hats:** `PlatformFeature` must remain small, stable, and
product-facing — every plan-catalog row, every entitlement override,
every presentation surface keys off it, and it changes only when the
product itself grows a new capability. `UsageMeter` must be free to be
as narrow as one real execution context requires (a specific pilot
tuple) or as broad as a feature's entire surface, and to be created,
repriced, and retired independently of any product/plan decision — a
retail-price change or a new provider contract should never require
touching `PlatformFeature`, `PlatformFeatureRegistry`, or any plan
catalog row. Collapsing the two, as the pre-amendment schema did by
construction, forces every feature's entitlement state to depend on
wallet health that the feature's own presentation layer has no way to
scope correctly — this is the exact contradiction Draft PR #107 found.

---

## B. `usage_meters` schema

```php
Schema::create('usage_meters', function (Blueprint $table) {
    $table->id();
    $table->string('meter_key', 128)->unique();
    $table->string('feature_key', 64);
    $table->boolean('is_metered')->default(false);
    $table->foreignId('active_rate_id')->nullable()
        ->constrained('business_usage_rates')->restrictOnDelete();
    $table->text('description');
    $table->unsignedBigInteger('updated_by_user_id');
    $table->timestamps();

    $table->index('feature_key');
});
```

Column-by-column:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `meter_key` | `string(128)`, unique | No | — | The durable, auditable economic identity. **A plain, human-assigned, versioned-by-convention unique string — not a JSON blob and not a new normalized dimension table.** This mirrors exactly how `feature_key` itself already achieves durability and auditability today: a unique string is sufficient identity as long as it is never reused for a different real-world meaning and its meaning is documented (via `description`, below, and via whichever future milestone contract names it). §J locks the naming *rules*, not the names themselves. |
| `feature_key` | `string(64)` | No | — | The owning `PlatformFeature`'s value (e.g. `conversations`). Not a foreign key — validated in application code against `PlatformFeature::tryFrom()`, identical to `feature_key`'s existing validation discipline everywhere else in this schema. Purely a label for grouping/reporting; never consulted by `EntitlementManager::decide()` or `RealUsageAuthorizationGateway`. |
| `is_metered` | `boolean` | No | `false` | Mutable only via the future `activateMetering()` (re-pointed, §H). |
| `active_rate_id` | `unsignedBigInteger`, FK → `business_usage_rates.id`, `restrictOnDelete()` | Yes | `NULL` | Sole pointer the future `setActiveRate()` maintains — identical mechanic to today's `platform_feature_usage_classifications.active_rate_id`. |
| `description` | `text` | No | — | Human-readable documentation of exactly what real-world execution context this meter represents (e.g. "Plain-SMS Conversations sends for Business #42, destination country US, via SendingServer #12 (Twilio)"). Mutable (clarifying documentation is not an economic decision), tracked via `updated_at`/`updated_by_user_id`, but **`meter_key` itself is never renamed once created** — a meter that needs to represent something different is retired (`is_metered = false`, no new rate activated) and a new meter is created under a new key, exactly mirroring how `business_usage_rates` rows are immutable and superseded by a new version rather than edited. |
| `updated_by_user_id` | `unsigned bigint`, no FK | No | — | Unlike `platform_feature_usage_classifications.updated_by_user_id` (nullable, because M1's own system-authored backfill needed a null actor), **no `usage_meters` row is ever system-backfilled** (§O) — every real row is created by an explicit human action, so this column is `NOT NULL` from its first row. |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | Standard Eloquent timestamps. |

Indexes/constraints: `unique(meter_key)`; `index(feature_key)` (for "show every meter under this feature" admin/reporting queries); `active_rate_id`'s FK matches `platform_feature_usage_classifications.active_rate_id`'s own `restrictOnDelete()` behavior exactly (an active rate can never be deleted while referenced).

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

Structurally identical to `platform_feature_usage_classification_transitions`
in every respect, including the identical short-constraint-name
workaround for MySQL's 64-character identifier limit (`umt_*` mirroring
`pfuct_*`). **Append-only** — a row is written exactly once, every time
`activateMetering()`'s future implementation flips `is_metered`, never
updated or deleted. `actor_user_id` and `reason` are both mandatory
(`NOT NULL`), identical to the existing table's own discipline. **No
transition row is ever written for the mutation of `description`** —
that is documentation, not an economic-state transition, and is not
audited by this table (its own `updated_at`/`updated_by_user_id`
columns on `usage_meters` itself are sufficient).

**Exact transition cases:**

1. First rate activation for a new meter: `setActiveRate()` creates the
   rate and sets `active_rate_id`; no `usage_meter_transitions` row is
   written by `setActiveRate()` itself (mirroring today's
   `setActiveRate()`, which likewise writes no
   `platform_feature_usage_classification_transitions` row — only
   `activateMetering()` writes that table, both today and after this
   amendment).
2. Metering activation: `activateMetering()` writes exactly one row,
   `from_is_metered = false, to_is_metered = true`, `from_active_rate_id`/
   `to_active_rate_id` both set to the currently-active rate (identical
   to today's `activateMetering()` body, which sets both to/from
   `active_rate_id` to the same, already-active value — metering
   activation never changes which rate is active, only whether it is
   consulted).
3. **New case this amendment must define exactly, since it did not exist
   for whole features:** deactivating a meter (`is_metered: true → false`)
   without retiring it — e.g. a temporary pause. This amendment
   authorizes the schema and the transition-row shape for this case
   (`from_is_metered = true, to_is_metered = false`) but does **not**
   design a new `deactivateMetering()` method — that remains a smaller,
   separate, later addition if a future milestone needs it; nothing in
   this amendment requires it, and inventing an unused method would
   violate "no unnecessary schema rewrite." The schema is forward-
   compatible with it without further change.
4. Rate rotation on an already-metered meter (a new rate version
   activated to replace the current one): `setActiveRate()` creates the
   new rate; a **new** call to a rotation-aware activation step (not
   `activateMetering()`, since the meter is already metered) would write
   `from_is_metered = true, to_is_metered = true, from_active_rate_id = <old>, to_active_rate_id = <new>`.
   This amendment specifies the transition-row shape for this case but
   defers the exact new/rotated method name to the implementation
   contract (§P) — it is a mechanical extension of the existing pattern,
   not a new architectural decision.

---

## D. `business_usage_rates` — exact correction

**Decision: rename `feature_key` → `meter_key` in place.** The table is
confirmed empty in every real deployment (§0) — this is a column rename
on zero real rows, not a reinterpretation of historical data.

```php
Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->renameColumn('feature_key', 'meter_key');
});

Schema::table('business_usage_rates', function (Blueprint $table) {
    $table->dropUnique(['feature_key', 'version']); // the pre-rename index name
    $table->unique(['meter_key', 'version']);
});
```

Every other column (`retail_rate_micro`, `provider_cost_micro`,
`unit_label`, `rounding_rule`, `currency_id`, `created_by_user_id`,
`created_at`) is **completely unchanged** in type, nullability, and
meaning — the merged contract's own expectation that this shape
survives is honored exactly.

- **Is `feature_key` retained on this table:** No. A rate has no
  independent need for a feature-level label of its own — any
  feature-level rollup (e.g. "total retail rate history for
  Conversations across every meter it has ever owned") is computed by
  joining through `usage_meters.feature_key`, never by duplicating that
  label onto every rate row.
- **Uniqueness/version constraints:** `unique(meter_key, version)`,
  replacing `unique(feature_key, version)` — identical mechanic, new
  key.
- **Active-rate lookup:** unchanged mechanic — `usage_meters.active_rate_id`
  is the sole pointer, exactly as `platform_feature_usage_classifications.active_rate_id`
  is today.
- **Migration/backfill behavior:** a pure rename plus an index swap; no
  data migration, since no row exists to migrate.
- **How old rows remain valid without reinterpretation:** there are no
  old rows (§0) — this is the direct, structural reason this rename
  carries zero real-world risk.
- **No real rate value is invented anywhere in this section** — only
  identity scoping changes; every numeric column is untouched.

---

## E. `business_usage_rate_activations` — corresponding change

**Decision: identical rename treatment.**

```php
Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->renameColumn('feature_key', 'meter_key');
});

Schema::table('business_usage_rate_activations', function (Blueprint $table) {
    $table->dropIndex(['feature_key']); // the pre-rename index name
    $table->index('meter_key');
});
```

`rate_id`, `activated_at`, `activated_by_user_id`, `reason`,
`created_at` are unchanged. Audit behavior is identical to today's
table: one append-only row per rate activation, keyed now by
`meter_key`. Confirmed empty in every real deployment (§0) — zero data
migration required.

---

## F. Reservations — exact treatment, and the `reserve()` naming question resolved without ambiguity

**`business_usage_reservations` gains one new column, additively —
`feature_key` is not renamed here:**

```php
Schema::table('business_usage_reservations', function (Blueprint $table) {
    $table->string('meter_key', 128)->after('feature_key');
    $table->index('meter_key');
});
```

**Why additive here, unlike §D/§E:** a reservation is the row real
Business-billing history hangs off — even though the table is
confirmed empty today, *future* rows must carry both identities
permanently, for a reason §D/§E's tables do not share: **a rate has no
independent reason to know its owning feature, but a reservation is
exactly the row a future feature-level report ("total Conversations
spend this month, across every meter") must be able to answer directly,
without requiring every historical `meter_key` to still resolve to a
live `usage_meters` row years later.** `meter_key` becomes the precise
economic identity (replacing `feature_key`'s old role as *the*
metering identity); the existing `feature_key` column is **kept,
unchanged in type**, and its *population source* changes from "the
metered thing itself" to **"an immutable snapshot of the owning
`PlatformFeature`, copied from `usage_meters.feature_key` at
`reserve()`-time."** This is a refinement of an already-nullable-in-
spirit column's meaning, not a reinterpretation of any existing row,
since none exist.

**The `reserve()` signature-naming question, answered exactly, not
hand-waved:** the merged contract locks `UsageWalletManager`'s public
method signatures unchanged. Read literally and conservatively — the
safest interpretation, and the one this design adopts — that includes
**parameter names**, not only arity/types/order/defaults. Therefore:

> **`reserve(Business $business, string $featureKey, string $idempotencyKey, ?string $estimatedQuantity = null): ReservationResult`
> keeps this exact declaration, including the literal parameter name
> `$featureKey`, unchanged.** After this amendment's implementation, the
> string a caller passes into that parameter **must be a `usage_meters.meter_key`
> value, never a `PlatformFeature::value`.** This is a deliberate,
> explicit naming mismatch between the parameter's historical name and
> its new accepted meaning — not an oversight. It is resolved, not left
> ambiguous, by requiring the implementation to add a prominent doc-
> comment directly above the declaration (e.g. *"Despite its name,
> `$featureKey` must be a `usage_meters.meter_key` value after RFC-005
> Amendment 1 — see `RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY.md`"*) and
> by every future caller (including the eventual M5 execution boundary)
> being written with a variable literally named `$meterKey` at the call
> site, so the mismatch is visible at every call site even though the
> declaration's own parameter name cannot change under the locked
> constraint. Renaming the parameter was considered and rejected
> specifically because zero real callers exist to protect today, but a
> parameter rename is a strictly larger, less conservative change than
> a doc-comment for no behavioral benefit — the locked constraint's own
> purpose (predictability for whoever eventually calls this method) is
> better served by the declaration never moving at all.

**How `reserve()` resolves the meter internally:** its existing line
`$classification = $this->classificationRepository->findByFeatureKey($featureKey);`
is replaced by `$meter = $this->meterRepository->findByMeterKey($featureKey);`
(new `UsageMeterRepository`, §H), and every subsequent reference to
`$classification->active_rate_id` becomes `$meter->active_rate_id`. The
existing `NoActiveRateForFeatureException` (thrown when
`$meter === null || $meter->active_rate_id === null`) is **not
renamed** — the same conservative reasoning as the parameter above
applies, and the exception's own message/constructor already takes the
raw key string, so its behavior is unaffected by what that string now
represents.

**Immutable meter/rate snapshots stored on the reservation, exact:**
unchanged mechanically — `rate_id`, `rate_version`, `retail_rate_micro`,
`provider_cost_micro`, `rounding_rule` are copied from the resolved
rate at `reserve()`-time exactly as today; the only addition is
`meter_key` (copied from the resolved `$meter->meter_key` — trivially
equal to the input `$featureKey` parameter's value, since that *is* the
meter key by definition) and `feature_key` (copied from
`$meter->feature_key`, the owning feature's label, **not** from any
`PlatformFeature` object the caller might separately have — `reserve()`
does not take a `PlatformFeature` parameter and does not gain one; the
feature-key snapshot comes entirely from the resolved meter row).

**Idempotency scoping:** completely unaffected by this amendment.
`idempotency_key`'s uniqueness and `findByIdempotencyKey()`'s lookup
semantics are orthogonal to whether the scoping key is a feature or a
meter. (The pre-existing, separately-documented Draft PR #107 finding
that `findByIdempotencyKey()` is not Business-scoped is unaffected and
unresolved by this amendment — it remains a concern for whichever
future execution boundary derives an idempotency key, exactly as PR
#107 already specified for its own now-superseded design.)

**Unsupported/unpriced meter execution, fail-closed:** unchanged
mechanically — `reserve()` throws `NoActiveRateForFeatureException`
exactly as today, now meaning "no such `usage_meters` row exists, or it
exists with no active rate." The execution boundary that calls
`reserve()` must catch this and decide, per its own feature-specific
design, whether that means "fall back to a legacy charging path" or
"fail the operation outright" — this amendment does not decide that for
any specific feature, since it selects no feature (§Q).

---

## G. Ledger — exact treatment

**Identical additive pattern to §F:**

```php
Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
    $table->string('meter_key', 128)->nullable()->after('feature_key');
    $table->index('meter_key');
});
```

`meter_key` is nullable, mirroring `feature_key`'s own existing
nullability — every non-metered entry type (manual top-up, auto-
recharge, add-on credit) continues to leave both columns `null`,
exactly as today. For a metered entry (`Reservation`, `UsageCharge`,
`UsageOverageCharge`, `ReservationRelease`), both `meter_key` and
`feature_key` are populated, copied directly from the owning
reservation's own already-stored values (§F) — `commit()` and
`release()` already read the full reservation row before writing a
ledger entry, so this is one additional field copied into an existing
`create()` call, not a new lookup.

**Why both columns, stated exactly:** `meter_key` answers "exactly what
was charged, at what rate" — the precise accounting question.
`feature_key` answers "which product feature does this spend roll up
under" — the reporting/presentation question. Historical accounting
remains fully reconstructible from either axis independently: a report
scoped to one meter's own lifetime economics groups by `meter_key`; a
report scoped to "everything Conversations has ever cost this Business"
groups by `feature_key`, and does not require every historical
`meter_key` to still resolve to a live `usage_meters` row.

---

## H. `UsageWalletManager` — per-method treatment, exact

| Method | Internal change | Public signature | Backward compatible | Deprecated/inert |
|---|---|---|---|---|
| `initializeWalletForNewBusiness` | None | Unchanged | Fully | — |
| `reserve` | Resolves `$featureKey` against the new `UsageMeterRepository` (`findByMeterKey`) instead of `PlatformFeatureUsageClassificationRepository`; writes `meter_key`/`feature_key` (from the resolved meter) into the reservation and its `Reservation`-type ledger entry, alongside every existing field, unchanged | **Unchanged, including the `$featureKey` parameter's literal name** (§F) | Fully — no caller supplying a valid string identity observes any behavior difference except that the string must now be a `meter_key`, not a `PlatformFeature::value` | — |
| `commit` | Copies the reservation's own already-stored `meter_key` into the `UsageCharge`/`UsageOverageCharge`/`ReservationRelease` ledger entries it creates, alongside the existing `feature_key` copy — no new lookup, no new parameter | Unchanged | Fully | — |
| `release` | Identical one-field addition to its own `ReservationRelease` ledger entry | Unchanged | Fully | — |
| `expireStaleReservations` | None — delegates to `release()` per reservation | Unchanged | Fully | — |
| `setActiveRate` | Resolves the identity string against `UsageMeterRepository::findForUpdateByMeterKey()` instead of `PlatformFeatureUsageClassificationRepository::findForUpdateByFeatureKey()`; if no `usage_meters` row exists for that key, throws `NoActiveRateForFeatureException` (a **new** first-check case this amendment introduces — see below); `latestVersionForFeature()` becomes `latestVersionForMeter()` on `BusinessUsageRateRepository`; writes the new rate with `meter_key` instead of `feature_key`; writes the activation row with `meter_key`; updates `usage_meters.active_rate_id` instead of `platform_feature_usage_classifications.active_rate_id` | **Unchanged, including the `$featureKey` parameter's literal name**, for the identical reason as `reserve()` | Fully, with one new failure mode named explicitly below | — |
| `activateMetering` | Same resolution-target swap as `setActiveRate`; writes to `usage_meter_transitions` instead of `platform_feature_usage_classification_transitions`; updates `usage_meters.is_metered` instead of `platform_feature_usage_classifications.is_metered` | **Unchanged, including the `$featureKey` parameter's literal name** | Fully | — |
| `evaluateCoarseCapacity` | **None.** See below — this is the design's central finding. | Unchanged | Fully | Its own internal `platform_feature_usage_classifications` read becomes permanently inert in practice (always `is_metered = false`), by construction, not by new conditional logic |
| `setSpendCap`, `setFeatureLimit`, `setSafetyLimit`, `setBillingStatus`, `configureAutoRecharge`, `recordAutoRechargeFailure`, `creditFromFunding` | None — none of these methods reads or writes `platform_feature_usage_classifications`, `business_usage_rates`, `business_usage_rate_activations`, or `business_usage_reservations`'s metering-identity columns | Unchanged | Fully | — |

**The one genuinely new failure mode, named exactly:** `setActiveRate()`
today can assume a `platform_feature_usage_classifications` row already
exists for any known `PlatformFeature`, because M1's backfill created
one for every case unconditionally. **This amendment's own §O locks
that no equivalent backfill happens for `usage_meters`** — a meter must
be explicitly created before a rate can be activated for it. This
means `setActiveRate()`'s future implementation needs a first check
(`findForUpdateByMeterKey($featureKey) === null`) that has no exact
pre-amendment analogue, and must fail closed the same way the existing
`$classification === null` branch already does today
(`NoActiveRateForFeatureException`, unchanged exception, §F) — this is
not a contradiction to flag as a stop condition; it is a natural,
correctly-fail-closed consequence of meters being opt-in rather than
auto-created, and this document specifies it rather than leaving it
implicit.

**`UsageWalletManager`'s constructor** gains two new injected
dependencies — `UsageMeterRepository` and
`UsageMeterTransitionRepository` — **additively, alongside** (not
replacing) the existing `PlatformFeatureUsageClassificationRepository`/
`PlatformFeatureUsageClassificationTransitionRepository`, since
`evaluateCoarseCapacity()` still reads the classification repository
(see below) even though nothing writes to it anymore.

**If a public method could not be made semantically coherent under the
signature lock:** no such method was found. Every one of the sixteen
public methods either requires zero change, or requires only an
internal repository-resolution swap plus one or two additional fields
copied through an already-existing `create()` call — none requires a
new parameter, a changed parameter type, or a changed return type.
**This is not a coincidence — it is the direct consequence of every
identity-bearing parameter in this API already being a plain `string`,
never a typed `PlatformFeature` object**, which is exactly what makes
re-pointing that string's *meaning* possible without touching the
method's own shape.

---

## I. Entitlement / usage authorization — the exact mechanical rule, and why it requires zero code change

**The distinction, restated exactly as required:**

> `EntitlementManager::decide()` = *may this Business use this product
> feature at all?* — governed entirely by RFC-004's plan/override/
> toggle/suspension chain, unchanged.
>
> A `UsageMeter`-scoped `reserve()` call at the real execution boundary
> = *may this particular billable operation consume wallet funds right
> now?* — governed entirely by that one meter's own `is_metered`/
> `active_rate_id`/rate-affordability state.

**RFC-005 does currently conflate these** — confirmed directly:
`decide()`'s own final step unconditionally calls
`usageAuthorizationGateway->check($currentBusiness, $feature)`, whose
answer depends on `evaluateCoarseCapacity()`'s read of
`platform_feature_usage_classifications` for that *whole feature*. This
is precisely the coupling Draft PR #107 found produces a contradiction
once even one execution context under a feature is genuinely metered
while others are not.

**The exact resolution, mechanically precise:**

`RealUsageAuthorizationGateway::check(Business, PlatformFeature)` and
`EntitlementManager::decide()` **require zero source-code changes**.
Their signatures are unchanged (already locked), and — critically —
**their existing bodies, completely unmodified, already produce the
correct new behavior**, because of a single structural fact this
amendment locks: **`platform_feature_usage_classifications.is_metered`
is never flipped to `true` again, for any feature, ever, once this
amendment's implementation re-points `activateMetering()`/
`setActiveRate()` at `usage_meters` instead.** `evaluateCoarseCapacity()`'s
own first branch —
`if ($classification === null || ! $classification->is_metered) { return new UsageCapacityDecision(true); }`
— therefore returns `authorized: true` **unconditionally, for every
feature, forever**, by the same construction its own docblock already
uses to describe its M1-era behavior (*"provably behaviorally identical
to `NullUsageAuthorizationGateway`"*) — this amendment simply makes that
equivalence permanent rather than temporary-until-M5.

**This is the design's central finding: the separation the audit asked
for is achieved entirely by which table holds the write-authoritative
`is_metered` flag going forward, not by any new conditional branch in
the entitlement or gateway layer.** `PlatformFeatureUsageClassificationRepository`'s
`update()` method is simply never called again by `UsageWalletManager`
after this amendment — `evaluateCoarseCapacity()` keeps reading a table
that nothing writes to, and reads exactly the safe, inert value M1's
own backfill already put there.

**Consequence, stated exactly as the requirement demands:** generic
feature entitlement (`decide()`'s answer for `Conversations`, or any
other feature) never again depends on any meter-specific wallet health,
because the coarse gate's own data source is permanently disconnected
from real metering the moment this amendment's implementation lands —
**before any feature is ever metered under the new model, not
conditionally afterward.** No tenth `EntitlementManager` denial key is
introduced; `usage_unauthorized` remains exactly as reachable (or, in
practice, unreachable until a future, different design chooses to wire
a whole-feature-level meter back through it — a future option this
amendment does not foreclose, since `evaluateCoarseCapacity()`'s code
still exists and would faithfully honor a `platform_feature_usage_classifications`
row if one were ever again written) as it is today.

---

## J. Meter identity rules

**What makes two executions the same `UsageMeter`:** they share
identical values across every dimension a human has decided *this
specific meter* prices as one unit — no more, no less. This is a
human/product decision made per meter, not a structural rule this
amendment can derive generically; the two stress tests below illustrate
the reasoning without creating any real key.

**Telecom stress test (Conversations/Automations-shaped):** a real
per-segment SMS cost genuinely varies by destination country, by the
sending Customer's own negotiated plan, and — conditionally — by the
selected `SendingServer`. A meter naming rule for this shape should
therefore identify: the channel (e.g. plain SMS), the pricing context
that determines cost (which, per Draft PR #107's own audit, may require
pinning a specific Business/Customer, a specific destination country,
and a specific `SendingServer`, since the legacy pricing surface varies
by all three). **This amendment does not name a real telecom meter key**
— it only confirms the schema can hold one (`meter_key` is a plain,
sufficiently long string; `description` documents the real-world
meaning) and that a future M5 contract choosing this shape must specify
its exact dimensions explicitly, never implicitly.

**AI/token stress test (a future feature, unnamed):** a per-token or
per-request AI provider cost typically varies by model family and
possibly by request type (text/image/etc.), with materially lower
cardinality than telecom pricing. A meter naming rule for this shape
would identify: the model family and the unit type (tokens vs.
requests). **This amendment does not name a real AI meter key either.**

**Rules a later M5 (or any future metering) contract must follow,
locked here:**

1. A `meter_key` must name a real, auditable execution context — never
   an arbitrary or placeholder value.
2. Every dimension that genuinely changes real economic cost must be
   reflected in either a distinct `meter_key` or an explicit, documented
   decision (in that future contract, not here) that the variance is
   deliberately ignored for a stated reason (e.g. "provider cost differs
   by under $0.0001 per unit across these two contexts; treated as one
   meter by explicit human decision, recorded in `description`").
3. A `meter_key`, once any real reservation references it, is never
   reused for a different real-world meaning — a meter that must change
   what it represents is retired and superseded by a new key, mirroring
   `business_usage_rates`' own immutable-version discipline.
4. A `PlatformFeature` with no genuine variable cost (e.g. `Crm`, per
   the preceding audit) is expected to own **zero** meters — creating
   one anyway to "prove metering works" is explicitly not required or
   encouraged by this amendment.

---

## K. Quantity

- **Quantity belongs to the meter/rate pairing, not to `PlatformFeature`.**
  `usage_meters` itself carries no quantity — quantity is supplied at
  `reserve()`/`commit()` call time and priced against whichever rate is
  currently active for that meter.
- **`rate × quantity` accounting is unchanged:** `reservedAmountMicro = round(retail_rate_micro × estimatedQuantity)`
  at `reserve()`-time; `finalAmountMicro = round(retail_rate_micro × finalQuantity)`
  at `commit()`-time — both using the exact existing `bcRoundHalfUp()`
  algorithm, untouched.
- **`provider_cost_micro` may, and typically will, differ from
  `retail_rate_micro`** — unchanged; both are independently snapshotted
  at `reserve()`-time from the meter's active rate, exactly as today.
- **Quantity must never be abused as encoded money.** This amendment
  introduces no mechanism that would make that abuse easier or harder
  than it already is — `commit()`'s existing overage/unused-release
  formula already assumes quantity is a genuine unit count, and this
  amendment does not touch that formula.
- **Estimated/final quantity rules:** unchanged — `reserve()` accepts an
  optional `estimatedQuantity` (defaulting to `'1'`); `commit()` accepts
  an optional `finalQuantity` (defaulting to the reservation's own
  stored estimate). A meter whose real quantity is always knowable
  before execution (e.g. a deterministic segment count) is expected to
  supply identical estimated and final values, exactly as Draft PR
  #107's own withdrawn Conversations design already established for
  that specific case — this amendment does not change that expectation
  for future meters of the same shape.
- **Rate snapshotting:** unchanged — `reserve()` snapshots
  `rate_id`/`rate_version`/`retail_rate_micro`/`provider_cost_micro`/
  `rounding_rule` onto the reservation; `commit()`/`release()` copy
  those same already-stored values onto their own ledger entries. A
  later rate rotation (§C, case 4) never rewrites an already-created
  reservation's own snapshot.

---

## L. Unsupported meter behavior — fail-closed, exact

| Condition | Where it surfaces | Behavior |
|---|---|---|
| Unknown `meter_key` (no `usage_meters` row) | `reserve()` | `NoActiveRateForFeatureException` (unchanged exception, §F) |
| Meter exists but never metered (`is_metered = false`) | *(not a `reserve()` failure — `reserve()` does not check `is_metered` at all today, and does not gain that check; it only requires an active rate to exist. `is_metered` is consulted exclusively by `evaluateCoarseCapacity()`, which no execution boundary is required to call before `reserve()`.)* | The execution boundary's own §5.1-style guard (a future M5 contract's own concern) is expected to check `is_metered` itself before ever attempting `reserve()`, exactly as Draft PR #107's own withdrawn design did |
| Meter exists, `is_metered = true`, no active rate | `setActiveRate()`'s own precondition already prevents this state from existing (`activateMetering()` requires `active_rate_id !== null` first) — unreachable by construction, unchanged from today's identical guarantee | — |
| Missing active rate at `reserve()`-time (race with a rate being retired — not possible today, since rates are never deleted, only superseded) | `reserve()` | `NoActiveRateForFeatureException` |
| Unsupported currency | `setActiveRate()`'s own `currency_id` FK constraint (`restrictOnDelete()` on `currencies`) | A currency that does not exist fails the FK insert; this amendment invents no new currency-resolution logic beyond what `business_usage_rates.currency_id` already enforces |
| Missing wallet | `reserve()` (`UsageWalletNotFoundException`, unchanged) / `evaluateCoarseCapacity()` (`wallet_missing`, unchanged — though see §I, now permanently unreachable via `decide()` since `is_metered` never flips) | Unchanged |
| Suspended wallet | `reserve()` (`ReservationResult(false, ..., 'wallet_suspended')`, unchanged) | Unchanged |
| Outstanding debt | `reserve()` (`'outstanding_debt'`, unchanged) | Unchanged |
| Insufficient balance | `reserve()` (`'insufficient_balance'`, unchanged) | Unchanged |

**Externally-visible entitlement denial keys are unchanged** — the
nine-key `decide()` surface is untouched (§I). **Execution-boundary
errors use the existing Usage-layer exception vocabulary
(`NoActiveRateForFeatureException`, `UsageWalletNotFoundException`,
`InvalidReservationStateTransitionException`, `UsageReservationNotFoundException`) —
no new entitlement key is invented anywhere in this document.**

---

## M. Business attribution — explicitly not solved by this amendment

**Stated exactly, not hidden:** the `UsageMeter` architecture **does
not, by itself, solve legacy Business ownership.** A metered execution
must resolve exactly one authoritative Business before calling
`reserve()` — that resolution problem is completely orthogonal to
whether the identity passed to `reserve()` is a `feature_key` or a
`meter_key`, and this amendment changes nothing about how a Business is
resolved.

The preceding audit confirmed no legacy execution surface (`ChatBox`,
`Automations`, `Crm`/`Contacts`) carries a `business_id` column, and no
current/active-Business execution context exists anywhere in the
customer-facing HTTP layer. `Customer::primaryBusiness()`, gated by
"the Workspace owns exactly one Business," remains the only mechanism
that exists today.

**Disposition, stated exactly:**

> `Customer::primaryBusiness()` + "Workspace owns exactly one Business"
> **may** be used as an interim compatibility rule by whichever future
> execution boundary needs it — for a Workspace failing that guard, the
> correct behavior is to decline metered execution entirely (falling
> through to whatever legacy charging path already existed, or denying
> outright if none exists), never to guess which Business among several
> is the authoritative one.
>
> **A real, general Business-ownership/context solution (either adding
> `business_id` to the relevant legacy execution tables, or building a
> genuine current-Business execution context) is explicitly NOT part of
> Amendment 1's own implementation.** It is a separate prerequisite,
> required only by whichever future feature's execution boundary
> actually needs correct multi-Business attribution — and until that
> prerequisite exists, any such feature's metering remains correctly
> bounded to the single-Business-Workspace case, exactly as Draft PR
> #107's own withdrawn design already bounded it.

---

## N. M1–M4 compatibility

| Table / API | Touched by this amendment | Change | Historical data reinterpreted? |
|---|---|---|---|
| `business_usage_wallets` | No | None | No |
| `business_usage_ledger_entries` | Yes | Additive `meter_key` column (nullable) | No — every existing row (`feature_key = null`) is unaffected; `meter_key` is simply also `null` for them |
| `business_usage_reservations` | Yes | Additive `meter_key` column (not nullable) | No real rows exist |
| `business_usage_rates` | Yes | Column rename `feature_key` → `meter_key`; unique index swap | No real rows exist |
| `business_usage_rate_activations` | Yes | Column rename `feature_key` → `meter_key`; index swap | No real rows exist |
| `platform_feature_usage_classifications` | No (schema); Yes (write behavior) | No schema change; **no code ever calls `update()` on it again** | Every backfilled row (`is_metered = false`) remains exactly as it is, permanently — this *is* the design, not a side effect |
| `platform_feature_usage_classification_transitions` | No | None — remains permanently empty, exactly as today | No real rows exist |
| `business_feature_usage_limits`, `platform_feature_usage_safety_limits`, `business_usage_limit_transitions` | No | None — these remain keyed by `feature_key` (Business-configured spend limits are a *product* concept scoped to `PlatformFeature`, not to `UsageMeter`; this amendment does not extend limits to meter granularity, since no locked constraint requires it and doing so would be new, unrequested scope) | Unaffected |
| `business_usage_wallet_billing_status_transitions`, `business_billing_contacts`, `business_payer_assignments`, `business_payer_transitions` | No | None | Unaffected |
| `payment_provider_customers`, `business_payment_instruments`, `business_funding_attempts`, `payment_provider_events`, `business_funding_attempt_transitions` (M3) | No | None | **M3 funding behavior is completely unchanged** — none of these tables or `UsageBillingCheckoutManager`'s own funding-attempt logic reads `feature_key`/`meter_key` at all |
| M4 additional-slot/add-on tables (`additional_business_slot_agreements` and siblings, `business_usage_addon_catalog` and siblings) | No | None | **M4 slot/add-on behavior is completely unchanged** — none of these read `platform_feature_usage_classifications`, call `evaluateCoarseCapacity()`, or otherwise depend on feature-level metering |
| `UsageWalletManager` public API | Yes (internal only) | §H | Fully backward compatible — no real caller exists to break, and the specification proves compatibility even if one did |
| `EntitlementManager::decide()` | No | None | Fully unchanged |
| `RealUsageAuthorizationGateway::check()` | No | None | Fully unchanged |

**Preferred result achieved exactly:** existing wallets remain valid;
existing ledger history remains valid and reconstructible; no existing
production payment record is reinterpreted; old feature-classification
rows remain inert history, permanently, by design rather than by
accident.

---

## O. Migration/backfill strategy

**No migration is created by this document.** The future implementation
contract must sequence exactly as follows:

1. **Additive, schema-only, zero behavior change:**
   - Create `usage_meters` (§B) — empty at creation, no seeded rows.
   - Create `usage_meter_transitions` (§C) — empty at creation.
   - Add `business_usage_reservations.meter_key` (§F) — safe, since the
     table itself is empty; the column may be created `NOT NULL` with
     no default, since there is no existing row to violate it.
   - Add `business_usage_ledger_entries.meter_key`, nullable (§G) —
     safe regardless of existing rows, since it is nullable and no
     existing row's `feature_key` is touched.
2. **Rename-only, zero behavior change:**
   - `business_usage_rates.feature_key` → `meter_key`, unique index
     swap (§D).
   - `business_usage_rate_activations.feature_key` → `meter_key`, index
     swap (§E).
3. **Code changes, after schema is fully in place:**
   - New `UsageMeter`/`UsageMeterTransition` models.
   - New `UsageMeterRepository`/`UsageMeterTransitionRepository`
     contracts and Eloquent implementations, bound in
     `AppServiceProvider` alongside the existing classification
     bindings (not replacing them).
   - `UsageWalletManager`'s constructor and the six methods named in §H
     updated per that section's exact specification.
4. **Zero fabricated rows, stated exactly:** **no `usage_meters` row is
   ever auto-created from `PlatformFeature::cases()`**, unlike M1's own
   `platform_feature_usage_classifications` backfill, which
   deliberately created one row per feature unconditionally. This
   amendment's own §J.4 requires the opposite discipline: a feature
   with no genuine variable cost owns zero meters, and a meter is
   created only by an explicit, later, human-authorized action (the
   future M5 contract's own implementation, or whichever feature
   eventually needs one). **This is a deliberate asymmetry with M1's
   own precedent, stated exactly so it is never mistaken for an
   oversight.**
5. **Rollback posture:** every migration in steps 1–2 is reversible in
   the standard Laravel sense (`down()` drops what `up()` created/
   renamed); step 3's code changes carry no independent rollback
   concern beyond normal source control, since no behavior changes
   until a future milestone actually creates a real meter and activates
   it.
6. **No constraint is deferred "until data is safe"** — every
   constraint in §B–§G is safe to apply immediately, precisely because
   every table this amendment touches is either empty or only gains a
   nullable/unused column.

---

## P. Amendment implementation decomposition

Two bounded slices, each independently mergeable and independently
regression-tested, recommended (not drafted — no implementation
contract exists yet):

**Slice 1 — Schema and repository foundation.** Everything in §O steps
1–2, plus the new model and repository/contract files named in §O step
3 (created, but `UsageWalletManager` not yet touched). Conceptual
responsibility: prove the new tables exist, are correctly constrained,
and are readable/writable through their own repositories, with **zero**
change to any existing runtime behavior — full regression suite passes
identically to before this slice, since nothing yet calls the new
code.

**Slice 2 — `UsageWalletManager` re-pointing.** The six method bodies
named in §H, plus the constructor widening. Conceptual responsibility:
prove `reserve()`/`setActiveRate()`/`activateMetering()` correctly
resolve against `usage_meters` instead of
`platform_feature_usage_classifications`, that `commit()`/`release()`
correctly propagate `meter_key` onto their ledger entries, and — the
single most important regression this slice must prove explicitly —
that `EntitlementManager::decide()`, `RealUsageAuthorizationGateway::check()`,
and `evaluateCoarseCapacity()` are **byte-identical to their pre-
amendment source** (a literal diff-based test, not merely a behavioral
one), directly demonstrating §I's central claim.

Both slices exist entirely to make `UsageMeter` real and correctly
wired; **neither slice creates a real meter, activates a real rate, or
selects a feature.** M5 (§Q) is explicitly not part of either slice.

---

## Q. M5 resumption

RFC-005 §36's Milestone 5 entry is amended conceptually as follows:
after this amendment's implementation (both slices) is merged, M5
performs a **fresh** candidate/meter audit — not a retrofit of Draft PR
#107, which remains closed to further work (disposition: it stays
open, Draft, unmerged, as historical blocker evidence, per the merged
contract).

The future M5 contract must name, explicitly, none of which this
document names or selects:

- the exact `PlatformFeature` chosen;
- the exact `UsageMeter` identity (or identities) that feature will own,
  following §J's rules;
- the exact real execution boundary that will call `reserve()`/
  `commit()`/`release()`;
- the exact authoritative Business-resolution mechanism for that
  boundary (§M — either the interim single-Business-Workspace rule, or
  a by-then-solved general mechanism);
- the exact, human-approved `retail_rate_micro`/`provider_cost_micro`/
  `unit_label`/`currency_id` for the chosen meter — never fabricated;
- the exact quantity unit and estimated/final-quantity rule (§K);
- every provider/idempotency rule the chosen execution boundary's own
  real transport layer requires.

RFC-005 §39 item 11 ("the first actual metered feature(s)") **remains
open** until that future M5 contract resolves it — this amendment does
not close it, and states so explicitly rather than leaving its status
ambiguous.

---

## Governance — exact relationship to the master RFC-005 document

This amendment supersedes, specifically and only:

- **§14 (Metered-feature classification and usage authorization)** —
  its classification model is corrected exactly as §A–§L specify;
  every other provision of §14 not touched by those sections (e.g. the
  existing `RealUsageAuthorizationGateway`/`evaluateCoarseCapacity()`
  mechanism itself, proven unchanged in §I) remains governing text.
- **§11 (`business_usage_rates`)** — referenced, not restructured; its
  rate shape is confirmed to survive unchanged (§D), only its scoping
  key changes.
- **§36 (Milestone decomposition), Milestone 5's entry** — amended
  conceptually per §Q; every other milestone's entry (M1–M4, M6) is
  untouched.
- **§39 item 11** — confirmed to remain open per §Q, not resolved by
  this document.

No other RFC-005 section is amended. This document does not edit
`docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` directly —
the merged governance contract authorizes exactly this one new file.
Once this document is itself human-merged, it becomes the authoritative
text for the four provisions named above; every other RFC-005 provision
continues to be governed by the original document unchanged.

---

## Unresolved human decisions (explicitly listed, separated from structural design)

None of the following are structural design questions this document
leaves open by omission — every structural question §A–§L posed is
answered exactly. The following are genuine, separate human/product
decisions this document does not have the authority to make, and does
not fabricate an answer for:

1. **No real `meter_key` value is chosen anywhere in this document** —
   §J locks the *rules* a future meter name must follow, never a real
   name.
2. **No real rate value (`retail_rate_micro`, `provider_cost_micro`,
   `unit_label`, `currency_id`) is chosen anywhere in this document.**
3. **No `PlatformFeature` is selected for M5** — §Q states exactly what
   a future M5 contract must supply; this document supplies none of it.
4. **The general, non-interim Business-attribution solution (§M)** is
   not designed here — only its interim compatibility rule is
   confirmed usable, and only for whichever future feature's Workspace
   population makes that bound acceptable.
5. **Whether `business_feature_usage_limits`/`platform_feature_usage_safety_limits`
   should ever gain meter-level granularity** is not decided — this
   amendment leaves them feature-scoped, since no locked constraint
   requires otherwise, but a future milestone could revisit this if a
   real need arises.
6. **The exact new method name for rate rotation on an already-metered
   meter** (§C, case 4) is named as a needed capability but not given
   an exact signature — deferred to the implementation contract as a
   mechanical extension, not a new design question.

---

*End of RFC-005 Amendment 1 design document. Implementation of any kind
— migration, model, repository, manager, gateway, controller, route,
view, test, or configuration — requires a separate, later, explicitly
bounded implementation contract, itself requiring separate human
approval before any code is written.*
