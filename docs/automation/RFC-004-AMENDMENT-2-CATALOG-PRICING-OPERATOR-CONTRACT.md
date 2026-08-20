# RFC-004 Amendment 2 — Catalog-Pricing Operator Surface

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This contract authorizes drafting this one document only.** Merging it
authorizes the narrowly scoped RFC-004 amendment it specifies — extending
`EntitlementManager::updateCatalogPricing()` into a complete, durably
audited pricing-operator entry point for `workspace_plan_catalog` — to be
implemented as its own separate, later, explicitly bounded implementation
PR. Merging this document does **not** itself change any `app/`,
`database/`, `routes/`, or `config/` file, does not begin RFC-005 Milestone
4, does not implement Stripe/checkout of any kind, and does not invent a
retail price or ratio value. `docs/automation/AI-AUTONOMY-STATE.json`
carries no authorization weight for any of this.

---

## 0. Governance

- Drafted on branch `chore/rfc-004-catalog-pricing-operator-amendment`, in
  an isolated linked worktree
  (`../rfc-004-amendment-2-catalog-pricing-operator-worktree`), based on
  `origin/main` at `74546b585c7cc74ec37a414f5589490b62b773a0` (merge of PR
  #98, RFC-004 Amendment 1 Correction Round 1 — the payment-verified
  additional-slot allocation seam).
- **RFC-004 is closed and tagged.** The annotated tag
  `rfc-004-plans-and-business-feature-entitlements` resolves to
  `b94141fcdd8f6fe43cfa7726ec4c83c89eb6eb55` locally, and `git merge-base
  --is-ancestor rfc-004-plans-and-business-feature-entitlements HEAD` exits
  `0` against this branch's own base — the tag is a genuine ancestor. **This
  document is a second, separate amendment to that already-tagged RFC**,
  independent of Amendment 1 (PR #98, human-merged). Amending an
  already-tagged, complete RFC is a separate, explicitly human-authorized
  action outside any standing contract's scope — that authorization is this
  document itself, requested directly by the human opening this task.
- **The prerequisite this amendment addresses is recorded verbatim in
  `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`** (§1 below) —
  a **different, separate** RFC-005 M4 prerequisite than the one Amendment 1
  resolved (the additional-slot allocation-authority blocker). RFC-005 line
  12 names both prerequisites explicitly and independently; this document
  addresses only the catalog-pricing one.
- `maximum_correction_rounds: 2` applies to this contract, matching every
  prior RFC-004/RFC-005 contract's own convention.
- Any path required during the future implementation but absent from §11's
  own numbered allowlist is a stop-and-report condition — not a silent
  workaround. The stop threshold is the allowlist's own final count
  (9) **plus one** — the tenth path.
- Drafting this amendment contract makes **zero** application changes. No
  `app/`, `database/`, `routes/`, `config/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by this branch —
  only this one new document (§2).
- This audit was performed narrowly — the two named RFCs, the four named
  `WorkspacePlanCatalog`-adjacent production files, `EntitlementManager`'s
  pricing-relevant methods, the one existing catalog controller, and the two
  existing catalog-pricing-adjacent test files. No repository-wide search
  and no delegated research agent was used, per explicit instruction.

---

## 1. The prerequisite — verbatim, and audit conclusion

`docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`, line 12, under
its cross-RFC-blocker framing:

> 2. **RFC-004 catalog-pricing operator surface** — a repository-confirmed
>    gap (§22), unresolved.

And again, at line 916, restated unchanged across every RFC-005 revision:

> **Operational prerequisite — unchanged:** an authorized RFC-004
> catalog-pricing operator surface remains a prerequisite for this
> section's own M4.

RFC-005 §22 (`Additional Business-slot agreement`) has already designed its
own consumption of this data — `price_per_slot_micro_snapshot` (derived
`from workspace_plan_catalog.price`/`additional_business_slot_price_ratio`
at quote time) and `plan_catalog_id_snapshot` (an FK captured once, "which
catalog was quoted against, at initial purchase") — confirming RFC-005 M4
needs exactly two `workspace_plan_catalog` fields to be genuinely,
durably, operator-mutable: **`price`/`currency_id`** and
**`additional_business_slot_price_ratio`**. RFC-005 line 93 separately
confirms `billing_cycle` is a plain, uncast string column RFC-005 does
**not** reuse (`SlotAgreementBillingCadence` is RFC-005's own, independent
enum) — so `billing_cycle` mutation is not part of this prerequisite.

**Audit conclusion — not a stop condition, but not "already sufficient"
either.** A real, partial, already-authorized operator surface exists today:
`EntitlementManager::updateCatalogPricing()` (§3). It correctly mutates
`price`/`currency_id` under platform-admin authority, inside a locked
transaction, with exact-decimal handling and the both-null-or-both-populated
pairing invariant. It does **not**: mutate
`additional_business_slot_price_ratio` at all; validate that a supplied
`currency_id` references an existing, active currency; require or record a
reason; or write any durable audit trail or dispatch any event for the
mutation it already performs. RFC-004's own Milestone 4 conformance audit
correctly marked `updateCatalogPricing()` as present and conformant — this
is not a defect against RFC-004's original scope (§12.5 never required
ratio mutation or audit for this specific method). It is a genuine gap
against what **RFC-005 M4 additionally needs**, which is exactly what an
amendment — not a correction — exists to close.

---

## 2. This contract's own exact file scope

Exactly one file, this document:
`docs/automation/RFC-004-AMENDMENT-2-CATALOG-PRICING-OPERATOR-CONTRACT.md`.

---

## 3. Repository audit — findings relied on

1. **`EntitlementManager::updateCatalogPricing()` already exists**
   (`app/Library/Entitlement/EntitlementManager.php:1082`):
   ```php
   public function updateCatalogPricing(WorkspacePlanCatalog $catalog, ?string $price, ?int $currencyId, int $actorUserId): WorkspacePlanCatalog
   ```
   Confirmed by direct read: wraps `assertPlatformAdministrator()`,
   `$this->catalogRepository->findForUpdate($catalog->id)` inside
   `DB::transaction()`, `normalizePrice()`'s exact-decimal-string handling
   (rejects a `float`-shaped or over-precision input, matches
   `DECIMAL(16,2)` exactly), the both-null-or-both-populated pairing check,
   and `PlanCatalogPricingInUseException` when clearing price/currency
   while a non-complimentary assignment still references the row. Returns
   the updated model via `$this->catalogRepository->update()`.
2. **No mutation of `additional_business_slot_price_ratio` exists
   anywhere.** Confirmed by direct read of the full `EntitlementManager`
   class: the column is read (`assertSlotRatioDefined()`, the seed-time
   value `0.5000`) but never written by any method.
3. **No currency-existence/active validation exists.** `$currencyId` is
   accepted as a bare `?int` and passed straight to the update — no check
   that it references a real `currencies` row, and no check of
   `currencies.status` (confirmed present on the table,
   `database/migrations/2018_12_01_191808_create_currencies_table.php:23`).
4. **No durable audit and no event dispatch for this method.** Confirmed by
   direct read — no `workspace_entitlement_transitions` write, no
   `Event::dispatch()` call anywhere in `updateCatalogPricing()`, unlike
   every other commercially-significant `EntitlementManager` mutation
   (`changePlanStatus()`, `setAdditionalBusinessSlots()`,
   `createOrChangeOverride()` — each writes a transition row and dispatches
   an event, per RFC-004 §21).
5. **No production controller/route calls `updateCatalogPricing()`.**
   `app/Http/Controllers/Admin/WorkspacePlanCatalogController.php` (the
   only controller referencing `WorkspacePlanCatalog`) is confirmed
   read-only — a single `index()` action behind the `'view workspace
   plans'` permission, sourcing only
   `EntitlementManager::listPlanCatalogSummaries()`. No mutation route
   exists anywhere in this repository today. This confirms the
   "controllers must not become independent pricing authorities" concern
   is currently true by simple absence, not by any enforced boundary —
   this amendment must state that boundary explicitly (§4) so a future
   admin-UI implementation cannot informally drift around it.
6. **`WorkspacePlanCatalogRepository`/`EloquentWorkspacePlanCatalogRepository`
   are plain data-access** (`findById()`, `findByTier()`, `findForUpdate()`,
   `create()`, generic `update(array $attributes)`) — confirmed by direct
   read of the contract's own docblock: "no pricing-invariant/business-rule
   logic here… enforced exclusively by `EntitlementManager`." Extending the
   generic `update()` call to also pass
   `additional_business_slot_price_ratio` requires no repository change.
7. **`tests/Feature/Entitlement/EntitlementManagerCatalogPricingTest.php`
   already exists**, covering: price normalization/rejection, the exact
   `DECIMAL(16,2)` boundary, the both-null-or-both-populated pairing, the
   in-use clearing guard (including the complimentary-reference carve-out),
   and non-administrator denial (including that denial is checked before
   any in-use-clearing side effect). It does not cover ratio mutation,
   currency validity, reason/audit, or event dispatch — all new surface
   this amendment adds.
8. **`workspace_entitlement_transitions.workspace_id` is `NOT NULL`**
   (RFC-004 §10.4, confirmed unchanged by Amendment 1, which added only two
   new nullable columns and never touched `workspace_id`). A catalog
   pricing change is tier-scoped, not Workspace-scoped — it has no natural
   `workspace_id` to populate, and no existing row shape in that table
   accommodates one. This is the architectural fact driving §8's design
   choice.
9. **RFC-005 §22 already owns the "stable price" design** —
   `price_per_slot_micro_snapshot`/`plan_catalog_id_snapshot`, captured
   once into RFC-005's own future schema at agreement-creation time, not a
   live re-read of the catalog row. This amendment does not need to invent
   that mechanism; §9 below states the guarantee this amendment owes
   RFC-005 so that design continues to hold.
10. **`app/Repositories/Contracts/CurrencyRepository.php` (legacy, pre-RFC-004)
    already exists**, extending the same `BaseRepository` (confirmed by
    direct read) that gives `EntitlementManager`'s existing
    `$this->userRepository->query()->whereKey($actorUserId)->value('is_admin')`
    pattern (`assertPlatformAdministrator()`) its `query()` builder access.
    Reusing this existing repository via the identical established pattern
    — rather than adding a new repository or a new interface method —
    keeps currency validation the smallest possible addition (§5/§6),
    consistent with this project's reuse-first discipline.

---

## 4. Exact internal authority boundary

- **Unchanged from RFC-004 §20/§22: platform administrator only.**
  `assertPlatformAdministrator()` (existing, reused verbatim, not modified)
  remains the sole authority check — the same `users.is_admin` truth
  `EnsureUserIsAdministrator` already establishes, never a new or parallel
  authorization mechanism.
- **`EntitlementManager` remains the sole authority for catalog pricing
  mutation.** This amendment extends the existing
  `updateCatalogPricing()` method; it does not add a second entry point, a
  static helper, or a controller-level shortcut.
- **Explicit, locked statement (new, requested by this amendment):**
  no controller, route, job, or repository may mutate any
  `workspace_plan_catalog` pricing column directly. Every future
  admin-UI/route surface for catalog pricing (RFC-005 M4's own scope, or
  any earlier ad hoc admin need) **must** call
  `EntitlementManager::updateCatalogPricing()` — never
  `WorkspacePlanCatalogRepository::update()` directly, never a raw Eloquent
  save. This mirrors RFC-004 §20's existing "no direct entitlement-table
  query… outside `EntitlementManager` and its repositories" discipline,
  restated here specifically for pricing because no controller exercised it
  before now.

---

## 5. Exact method signature — extended

```php
public function updateCatalogPricing(
    WorkspacePlanCatalog $catalog,
    ?string $price,
    ?int $currencyId,
    ?string $additionalBusinessSlotPriceRatio,
    int $actorUserId,
    string $reason,
): WorkspacePlanCatalog
```

- **`$additionalBusinessSlotPriceRatio`** — new, nullable decimal *string*
  (never a PHP `float`, matching `$price`'s own established discipline,
  §12.5's "exact-decimal boundary" extended here to the ratio column for
  the identical reason — `DECIMAL(6,4)` precision loss through a float
  round-trip is exactly the risk already named for `price`). Normalized by
  a new `normalizeRatio()` private method, structurally parallel to the
  existing `normalizePrice()` (§6).
- **`$reason`** — new, **mandatory**, non-empty (validation failure
  otherwise) — matching `changePlanStatus()`'s own precedent that a
  commercially/security-significant mutation requires a mandatory reason
  (RFC-004 §18/§23). Unlike `$price`/`$currencyId`/`$ratio`,
  `$actorUserId` and `$reason` are never optional for this method — there
  is no system/backfill-authored catalog-pricing change anywhere in this
  RFC's design, unlike `plan_assigned`'s narrow null-actor cases (§10.4).
- **`$actorUserId`** — unchanged in type and position; every call now also
  becomes the actor recorded in the new durable audit row (§8), not merely
  the value `assertPlatformAdministrator()` checks.
- **Signature widening, not a new method.** The two new required/optional
  parameters are added; no existing parameter changes type, order (before
  the two new ones), or meaning. `$price`/`$currencyId`'s own existing
  validation (normalization, pairing, in-use guard) is **unchanged** by
  this amendment.
- **Only one call site exists to update**: the existing test file (§3
  item 7) — confirmed by the same repository-wide search that found no
  controller/route caller (§3 item 5). No production behavior migration is
  required; this is a pre-release-to-any-controller signature change.

---

## 6. Validation/invariants

- **`price`** — unchanged: existing `normalizePrice()`, existing
  `DECIMAL(16,2)`/14-integer-digit boundary, existing rejection of any
  non-decimal-string shape.
- **`additional_business_slot_price_ratio`** — **locked, exact.** Nullable.
  When non-null, must be a plain, exact, non-negative decimal string —
  never a PHP `float` — validated and normalized by a new
  `normalizeRatio()`, structurally mirroring `normalizePrice()`: at most 4
  fractional digits, at most 2 integer digits (the `DECIMAL(6,4)` storage
  boundary — 2 integer + 4 fractional = 6 total significant digits),
  rejected otherwise with `InvalidArgumentException`, the same exception
  class the existing pairing check already uses in this method. **The
  maximum precision/scale this amendment enforces is exactly
  `DECIMAL(6,4)`'s own storage boundary — no additional, narrower
  product/commercial-policy upper bound (e.g. rejecting a ratio at or above
  `1.0000`) is introduced by Amendment 2.** This is deliberate: Amendment 2
  is an authority/integrity amendment (who may write, under what locking,
  with what durable record), not a pricing-policy amendment — RFC-004 §8
  already establishes that this repository does not invent commercial
  values, and a narrower cap would itself be a commercial-policy choice.
  **A future product-policy cap, if ever desired, requires its own
  separate, explicit authorization — it is not introduced by, implied by,
  or left as a discretionary implementation choice under this contract.**
- **`currency_id` — new existence/active check.** When non-null, must
  reference an existing `currencies` row with `status = true` — checked via
  the existing (reused, unmodified) `CurrencyRepository`'s `query()`
  builder, the identical pattern `assertPlatformAdministrator()` already
  uses against `$this->userRepository->query()`. Rejected otherwise with
  `InvalidArgumentException`. The existing both-null-or-both-populated
  pairing check is unchanged and runs first (a null/non-null mismatch is
  rejected before this new existence check ever queries `currencies`).
- **Tier constraint (new).** `additional_business_slot_price_ratio` may
  only be set non-null when `$catalog->tier` is `Core` or `Growth`
  (`WorkspacePlanTier`, existing enum, unmodified) — matching the seed
  invariant RFC-004 §12.1/§10.1 already states (`0.5000` for Core/Growth,
  `null` for Agency, "which has no additional-slot concept"). Attempting to
  set it non-null for an Agency-tier catalog row is rejected with
  `InvalidArgumentException`. Setting it to `null` is always permitted for
  any tier (matches §12.5's own "decreasing/revoking is always permitted"
  posture for the *allocation* side; this is the *catalog* side's
  analogous floor).
- **`billing_cycle` — explicitly out of scope, not mutable by this
  amendment** (§1: RFC-005 does not consume this column; no RFC-005 M4
  need justifies touching it; touching it would be scope creep against
  "smallest safe amendment").
- **`is_active` — explicitly out of scope, not mutable by this amendment.**
  Activating/deactivating a tier is a materially higher-stakes,
  differently-shaped decision (it changes whether the row can receive *new
  assignments at all*, RFC-004 §10.1) than correcting its price — a future,
  separately authorized amendment if a genuine need arises, never folded
  into this one.
- **Inactive catalog row.** This amendment's pricing mutation is
  **permitted** against an `is_active = false` catalog row — `is_active`
  only gates *new assignments* (§10.1), and correcting the price of an
  already-inactive (e.g. retired) tier for record-keeping or future
  reactivation must not be blocked by its own inactivity. No new check is
  added to prevent this; none of RFC-004's existing invariants require one.
- **Undefined pricing (`UndefinedPlanPricingException`)** is unchanged and
  unaffected — it is a *read-side* concern (assignment creation,
  slot-increase time, §12.5) entirely separate from this *write-side*
  amendment. This amendment does not relax, tighten, or otherwise touch
  when that exception is thrown.
- **`PlanCatalogPricingInUseException`** — unchanged, existing, reused
  verbatim for the clearing-while-referenced case.

---

## 7. Locking/concurrency behavior

**Unchanged from the existing method, exactly.** The entire
authority-check-through-update-through-audit-write sequence remains one
real `DB::transaction()` closure: `findForUpdate()`'s row lock (documented
in the method's own existing docblock as depending on an ambient open
transaction — unchanged, still true) is held across the normalization,
every new validation check (§6), the update, and the new audit-row write
(§8) — all before commit. No new lock, no new transaction boundary, no new
concurrency primitive. Two concurrent pricing-mutation calls against the
same catalog row serialize on the identical existing row lock exactly as
today.

---

## 8. Durable audit/provenance requirements for pricing changes — exact schema change

**One new, narrowly-scoped table** —
`workspace_plan_catalog_pricing_changes` — rather than extending
`workspace_entitlement_transitions`. Reasoning, stated explicitly per this
project's "every migration must be justified, every table must have a
purpose" discipline:

- `workspace_entitlement_transitions.workspace_id` is `NOT NULL` (§3 item
  8). A catalog pricing change has no Workspace to attribute — it is a
  tier-level, not a Workspace-level, event. Loosening an existing `NOT
  NULL` column's nullability on a table already relied upon by every other
  RFC-004 transition type (and by Amendment 1's own new columns, which
  deliberately added nullable columns rather than touching any existing
  one) is a materially larger, riskier change than one new, narrow,
  purpose-built table — and would leave every existing row's
  `workspace_id` "accidentally always populated" as an unstated, brittle
  convention rather than a real schema guarantee.
- A dedicated table keeps the two audit streams cleanly separable by
  construction (queryable by catalog, not by Workspace) rather than by a
  `WHERE workspace_id IS NULL` convention layered onto a table whose whole
  point is being Workspace-scoped.

Schema:

```php
Schema::create('workspace_plan_catalog_pricing_changes', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('workspace_plan_catalog_id');
    $table->unsignedBigInteger('actor_user_id');
    $table->text('reason');
    $table->decimal('from_price', 16, 2)->nullable();
    $table->decimal('to_price', 16, 2)->nullable();
    $table->unsignedBigInteger('from_currency_id')->nullable();
    $table->unsignedBigInteger('to_currency_id')->nullable();
    $table->decimal('from_additional_business_slot_price_ratio', 6, 4)->nullable();
    $table->decimal('to_additional_business_slot_price_ratio', 6, 4)->nullable();
    $table->timestamp('created_at')->useCurrent();

    $table->foreign('workspace_plan_catalog_id')->references('id')->on('workspace_plan_catalog')->restrictOnDelete();
    $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
    $table->index('workspace_plan_catalog_id');
});
```

- **No `updated_at`** — immutable, append-only, matching
  `workspace_transitions`/`workspace_entitlement_transitions`' own
  established `created_at`-only convention exactly.
- **`actor_user_id` is `NOT NULL`, with a real foreign key** (unlike
  `workspace_entitlement_transitions.actor_user_id`, which is nullable
  with **no** FK for its own documented reason — must never block a
  legitimate user-deletion feature for the rare system-authored rows).
  This table has no system-authored row at all (§5 — `$actorUserId` is
  never optional for this mutation), so there is no equivalent case to
  protect against, and a real FK gives referential integrity a plain
  nullable scalar would not.
- **`reason` is `NOT NULL`**, matching §5's mandatory `$reason` parameter
  exactly.
- **`from_*`/`to_*` triples, not a single "new value" row** — records the
  complete before/after state for all three mutable fields on every write,
  even when only one field actually changed on a given call, so a reader
  never has to reconstruct "what was true before" from a preceding row.
  Mirrors `workspace_entitlement_transitions.from_status`/`to_status`'s own
  established shape.
- **`from_currency_id`/`to_currency_id` — locked, exact.** Nullable plain
  `unsignedBigInteger` scalars — **no foreign key of any kind**, not to
  `currencies`, not `restrictOnDelete()`-constrained or otherwise. This is
  a locked requirement of this contract, not an implementation-time choice:
  the audit table is immutable historical evidence, and a historical
  pricing row must remain readable exactly as recorded even after a later
  currency lifecycle change or deletion — a foreign key here would let a
  legitimate future currency-maintenance action (deactivation, deletion, or
  reconciliation of the legacy `currencies` table) be blocked by, or forced
  to cascade into, an unrelated historical audit row. **Current-value
  currency validity/activity is already fully verified at mutation time**
  by §6's existence/active check against the live `currencies` table before
  any write occurs — these two columns exist purely to preserve what was
  historically true, not to re-assert a live relationship, so no
  referential constraint is added or needed here.
- **New model**: `app/Models/WorkspacePlanCatalogPricingChange.php` —
  plain Eloquent model, `$fillable` for all columns above, `catalog(): BelongsTo`
  relation only. No business logic on the model itself, matching every
  other RFC-004 model.
- **New repository**, contract + Eloquent implementation, mirroring
  `WorkspaceEntitlementTransitionRepository`'s own "deliberately no
  `update()`, append-only" precedent exactly:
  ```php
  interface WorkspacePlanCatalogPricingChangeRepository extends BaseRepository
  {
      public function create(array $attributes): WorkspacePlanCatalogPricingChange;
      public function forCatalog(int $catalogId): Collection;
  }
  ```
- **New event**: `WorkspacePlanCatalogPricingChanged`
  (`app/Events/Entitlement/`) — immutable, scalar-only payload
  (`catalogId`, `actorUserId`), dispatched after commit, matching RFC-004
  §21's exact convention. New because every existing RFC-004 event is
  Workspace-scoped (carries a Workspace id) and none fits a tier-level
  change.
- **No new `WorkspaceEntitlementTransitionType` case is added** — this
  amendment's audit trail is intentionally a separate table with its own
  shape, not a tenth case bolted onto a Workspace-scoped enum it does not
  structurally fit.
- **Binding**: one new line in `AppServiceProvider::register()`'s existing
  `$bindings` array, identical pattern to every other RFC-004 repository
  binding (§11 item 8 below).

---

## 9. How RFC-005 reads a stable price for checkout/agreement creation

- **RFC-005 §22 already owns this design; this amendment changes nothing
  about it.** `price_per_slot_micro_snapshot` and `plan_catalog_id_snapshot`
  (both already specified in RFC-005's own schema, §1 above) are captured
  **once**, by value, at agreement-creation time, into RFC-005's own
  future table — never a live re-read of `workspace_plan_catalog` at a
  later renewal or reconciliation moment. `plan_catalog_id_snapshot`
  remains an FK for traceability/audit only, per RFC-005's own text, never
  re-dereferenced for price.
- **Locked cross-RFC guarantee this amendment adds:**
  `EntitlementManager::updateCatalogPricing()` never reads, writes, or
  otherwise touches any RFC-005 table (identical to every other
  RFC-004/RFC-005 boundary statement in this repository, e.g. RFC-004 §19).
  A future catalog price correction therefore **cannot** silently rewrite
  an already-authorized purchase, because RFC-005's own snapshot columns
  are never re-derived from the live catalog row after the moment they were
  first written — this holds by construction of RFC-005's own design, not
  because this amendment adds a new technical safeguard against it.
- **What this amendment's own new audit table (§8) adds, additionally**: a
  durable, catalog-scoped "what did the price used to be and when did it
  change, and who changed it" record RFC-005 (or a human) can consult
  during support/reconciliation to explain why a given
  `plan_catalog_id_snapshot`'s historical price differs from today's live
  catalog value — an optional diagnostic aid, never a correctness
  dependency for RFC-005's own snapshot mechanism.

---

## 10. Exact failure behavior — every named case

- Non-administrator actor → `AuthorizationException` — **unchanged**,
  checked first, before the catalog row is even locked (matching the
  existing test's own already-proven ordering, §3 item 7).
- Catalog row not found → `RuntimeException` — **unchanged**.
- `$price`/`$currencyId` mismatched null pairing → `InvalidArgumentException`
  — **unchanged**.
- `$price` failing `normalizePrice()`'s shape/precision check →
  `InvalidArgumentException` — **unchanged**.
- Clearing `price`/`currency_id` while a non-complimentary assignment still
  references the catalog row → `PlanCatalogPricingInUseException` —
  **unchanged**.
- Empty/whitespace `$reason` → `InvalidArgumentException` — **new**.
- `$additionalBusinessSlotPriceRatio` failing `normalizeRatio()`'s
  shape/precision check → `InvalidArgumentException` — **new**.
- `$additionalBusinessSlotPriceRatio` supplied non-null for an Agency-tier
  catalog row → `InvalidArgumentException` — **new**.
- `$currencyId` supplied non-null but referencing no existing (or an
  inactive) `currencies` row → `InvalidArgumentException` — **new**.
- Every rejection above occurs **before** the update/audit write — no
  partial mutation, no partial audit row, matching every existing
  `EntitlementManager` method's own fail-closed, all-inside-one-transaction
  posture.

---

## 11. Exact production/test path allowlist for the later implementation

**Nine paths total — five new, four modified. No path beyond this list is
authorized; discovering a genuine tenth-path need during implementation is
a stop-and-report condition (§0), not a silent addition.**

1. `app/Library/Entitlement/EntitlementManager.php` — modified: extend
   `updateCatalogPricing()`'s signature and body (§5/§6), add
   `normalizeRatio()`, add the new `$currencyRepository` constructor
   dependency (reusing the existing `CurrencyRepository`, §3 item 10) and
   its validation helper, add the audit-row write and event dispatch
   (§8) — all inside the method's existing transaction.
2. `database/migrations/{new-timestamp}_create_workspace_plan_catalog_pricing_changes_table.php`
   — new (§8).
3. `app/Models/WorkspacePlanCatalogPricingChange.php` — new (§8).
4. `app/Repositories/Contracts/WorkspacePlanCatalogPricingChangeRepository.php`
   — new (§8).
5. `app/Repositories/Eloquent/EloquentWorkspacePlanCatalogPricingChangeRepository.php`
   — new (§8).
6. `app/Events/Entitlement/WorkspacePlanCatalogPricingChanged.php` — new
   (§8).
7. `app/Providers/AppServiceProvider.php` — modified: exactly one new line
   in the existing `$bindings` array (§8, mirroring line 152's existing
   `WorkspacePlanCatalogRepository` binding).
8. `tests/Feature/Entitlement/EntitlementManagerCatalogPricingTest.php` —
   modified: extend existing coverage with ratio mutation, currency
   validity, Agency-ratio rejection, mandatory-reason rejection, and audit
   row/event assertions. No existing test method's assertions are weakened
   or removed.
9. `tests/Feature/Entitlement/WorkspacePlanCatalogPricingChangeRepositoryTest.php`
   — new, mirroring `WorkspaceEntitlementTransitionRepositoryTest.php`'s
   own existing precedent (§3 item 7's sibling file) for a new append-only
   repository.

**Explicitly not authorized by this allowlist:** any controller, route, or
admin view (§4); any migration touching `workspace_entitlement_transitions`,
`workspace_plan_catalog`, or any other existing table's columns (only a
brand-new table is authorized, §8); any change to
`WorkspacePlanCatalogRepository`/`EloquentWorkspacePlanCatalogRepository`
(the existing generic `update()` is reused as-is); any change to
`CurrencyRepository` (reused as-is, §3 item 10); any change to
`WorkspacePlanCatalogController` (remains read-only).

---

## 12. Focused and full regression requirements

For the later implementation PR, in order:

1. `php artisan test tests/Feature/Entitlement/EntitlementManagerCatalogPricingTest.php
   tests/Feature/Entitlement/WorkspacePlanCatalogPricingChangeRepositoryTest.php`
   — zero failures required; exact passing/assertion count reported by
   that run, never assumed.
2. `php artisan test tests/Unit/Entitlement tests/Feature/Entitlement` —
   zero failures required (the same Gate 1 scope Amendment 1's own
   correction round re-verified, §6 of that document) — exact count
   reported, never assumed to match any prior round's number.
3. `php artisan test tests/Unit/Workspace tests/Feature/Workspace` — zero
   failures required (Gate 2), exact count reported.
4. `php artisan test --stop-on-failure` — must exit `0`.
5. A dedicated re-confirmation that `setAdditionalBusinessSlots()`,
   `changePlan()`, and every other existing `EntitlementManager` method's
   own existing tests still pass unmodified, verbatim — this amendment
   touches only `updateCatalogPricing()` and adds new, additive surface; no
   existing method's behavior changes.
6. Before any of the above: `.env.testing`'s `APP_NAME` and any branding
   overrides must be confirmed neutral first (this session's own
   established local-environment hazard, unrelated to this amendment's
   code) if any Branding test is included in a full-suite run.

---

## 13. Explicit non-scope statement

This amendment authorizes exactly what §5–§11 specify and nothing else. It
explicitly does **not**:

- perform any Stripe, payment-provider, checkout, or webhook work of any
  kind;
- implement RFC-005 Milestone 4, or any other RFC-005 milestone, in any
  way;
- add a customer-facing pricing editor of any kind — this remains a
  platform-admin-only operator surface (§4); a customer-facing view is out
  of scope unless a separately approved architecture already requires one,
  which none does today;
- redesign `workspace_plan_catalog`'s schema, add a fourth plan tier,
  introduce a metered/usage-based pricing model, or change
  `business_slot_included`/`business_slot_max`/`unlimited_business_slots`
  in any way;
- invent, choose, or record any actual retail price, currency, or ratio
  value for `core`, `growth`, or `agency` — every commercial value remains
  human-configured, exactly as RFC-004 §8 already established and this
  amendment does not revisit;
- add a controller, route, or admin view exposing this surface over HTTP
  (§4/§11) — that remains RFC-005 M4's own, separately contracted scope,
  or a separate future admin-UI slice, neither begun here;
- change `billing_cycle` or `is_active` mutability (§6);
- change `EntitlementManager::assertPlatformAdministrator()`,
  `WorkspacePlanCatalogController`, `WorkspacePlanCatalogRepository`, or
  `CurrencyRepository` in any way beyond what §11 explicitly authorizes;
- extend `maximum_correction_rounds` beyond the 2 this document sets — a
  future correction round to *this* amendment (once implemented) would
  consume that same budget, not a new one.

---

## 14. Stop conditions

Implementation must stop, leave the working tree unstaged, and report
rather than proceed, if:

- Any path beyond §11's nine-item allowlist is found necessary — the
  **tenth** path.
- Any change to `workspace_entitlement_transitions`,
  `workspace_plan_catalog`'s own columns, `WorkspacePlanCatalogController`,
  `WorkspacePlanCatalogRepository`, or `CurrencyRepository` is found
  necessary beyond what §11 authorizes.
- Any RFC-005-owned file, table, or namespace is found necessary to
  reference from `EntitlementManager` or any file this amendment touches.
- Any existing test — in `EntitlementManagerCatalogPricingTest.php` or
  anywhere else — fails for a reason not fixable within this amendment's
  own allowlist.
- Any §12 regression gate lands lower than its own stated baseline (zero
  failures) for a reason not attributable to this amendment's own change.
- A genuine need for a customer-facing surface, a fourth plan tier, a
  metered pricing model, or any other broader schema/product redesign is
  discovered during implementation — report it; do not implement it under
  this contract's authorization.
- Any actual retail price, currency, or ratio value is found necessary to
  choose or hard-code anywhere in the implementation.

---

## 15. Contract self-audit

1. The prerequisite is quoted verbatim from its own source and is confirmed
   distinct from the prerequisite Amendment 1 already resolved (§0/§1). ✓
2. The existing partial operator surface is identified precisely, by exact
   method signature and line, with its exact gaps named individually
   (§1/§3), rather than either wrongly claiming nothing exists or wrongly
   claiming the gap is already closed. ✓
3. Authority remains platform-admin-only, `EntitlementManager` remains the
   sole mutation authority, and controllers/routes/repositories are
   explicitly barred from becoming independent pricing authorities (§4). ✓
4. Every mutable field RFC-005 M4 actually needs
   (`price`/`currency_id`/`additional_business_slot_price_ratio`) is
   covered; every field it does not need (`billing_cycle`, `is_active`) is
   explicitly excluded with a stated reason (§6). ✓
5. Validation/invariants are stated for currency existence/activity,
   positive/exact-decimal pricing, slot-ratio precision, the
   Core/Growth/Agency ratio constraint, inactive-row permissibility, and
   the read-side/write-side separation from `UndefinedPlanPricingException`
   (§6). ✓
6. Locking/concurrency is confirmed unchanged and reasoned through
   explicitly rather than merely asserted (§7). ✓
7. Durable audit is a new, narrowly-justified table rather than a
   nullability change to an existing shared table, with the tradeoff
   explained rather than silently decided (§8). ✓
8. RFC-005's own stable-price mechanism is confirmed already sufficient by
   its own design, and this amendment's one additional guarantee (never
   touching an RFC-005 table) is stated explicitly (§9). ✓
9. Every failure case is named with its exact exception type, new vs.
   unchanged (§10). ✓
10. The allowlist is exact, minimal (9 paths, 5 new/4 modified), and
    excludes everything not required (§11). ✓
11. Non-goals are stated explicitly and completely, matching the task's own
    list (§13). ✓
12. The two decisions previously left open (the ratio's upper bound, the
    audit table's currency-column FK shape) are now locked with explicit
    reasoning rather than left discretionary — no unresolved
    implementation-time decision remains anywhere in this document (§6,
    §8). ✓
13. No retail price, currency, or ratio value is invented anywhere in this
    document, and locking the ratio's precision boundary in §6
    deliberately does not introduce a commercial-policy cap. ✓
14. This document itself changes exactly one file, verified mechanically
    before commit (§16). ✓

---

## 16. Verification and publication (this document only)

Performed, in order, before commit:

1. `git status --short` — exactly one untracked path,
   `docs/automation/RFC-004-AMENDMENT-2-CATALOG-PRICING-OPERATOR-CONTRACT.md`,
   nothing else, nothing staged.
2. Stage the one file by its exact path only
   (`git add docs/automation/RFC-004-AMENDMENT-2-CATALOG-PRICING-OPERATOR-CONTRACT.md`),
   never `git add -A`/`.`.
3. Commit exactly:
   `docs: authorize RFC-004 Amendment 2 catalog-pricing operator surface`.
4. Push normally to `origin chore/rfc-004-catalog-pricing-operator-amendment`.
   No force push. Do not push `main`.
5. Open a Draft PR into `main` if tooling permits; otherwise report the
   exact GitHub comparison URL.
6. **Do not merge. Do not begin the amendment this document authorizes.**
   Both require this document to be merged first; implementing §5–§11
   remains its own separate, later, explicitly bounded PR — not this one,
   and not folded into PR #98 (already merged and closed).
7. PHP/JS tests are not required for this one-file docs-only change and are
   not run — reported honestly as not run, no count fabricated.

---

*End of RFC-004 Amendment 2 — Catalog-Pricing Operator Surface. This
document authorizes drafting itself only. The extension it specifies
requires its own separate, later, explicitly bounded implementation PR
(§5–§11), after which the full §12 gate set must be run in full. RFC-004's
own tagged foundation
(`rfc-004-plans-and-business-feature-entitlements`) and Amendment 1 (merged
PR #98) are unmoved and unmodified by this document.*
