# RFC-004 Amendment 1 — Payment-Verified Additional-Business-Slot Allocation Seam

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This contract authorizes drafting this one document only.** Merging it
authorizes the narrowly scoped RFC-004 amendment it specifies — a new
internal, payment-proof-gated allocation entry point on `EntitlementManager`
— to be implemented as its own separate, later, explicitly bounded
implementation PR. Merging this document does **not** itself change any
`app/`, `database/`, `routes/`, or `config/` file, does not begin RFC-005
Milestone 4, and does not implement checkout, Stripe webhook processing, or
billing of any kind. `docs/automation/AI-AUTONOMY-STATE.json` carries no
authorization weight for any of this.

---

## 0. Governance

- Drafted on branch
  `chore/rfc-004-additional-business-slot-payment-allocation-amendment`, in
  an isolated linked worktree
  (`../rfc-004-additional-business-slot-payment-allocation-amendment-worktree`),
  based on `origin/main` at `36f2735b5fda995c0c729151e81518016eb57fb6`
  (merge of PR #94, Design System M2 Platform Branding).
- **RFC-004 is closed and tagged.** The annotated tag
  `rfc-004-plans-and-business-feature-entitlements` exists both locally and
  on `origin` (`git ls-remote --tags origin` lists both the tag object
  `b94141f...` and its dereferenced commit `221e18f...`; `git cat-file -p`
  confirms tagger `Matt <gvidas@jazminmedia.com>`, message `RFC-004 Plans
  and Business Feature Entitlements · Milestone 4 complete`). `git
  merge-base --is-ancestor rfc-004-plans-and-business-feature-entitlements
  origin/main` exits `0` — the tag is a genuine ancestor of this branch's
  own base. **This document is therefore a genuine amendment to an
  already-tagged, complete RFC** — exactly the situation
  `docs/automation/RFC-005-DESIGN-CONTRACT.md` §6 names explicitly:
  "Amending an already-tagged, complete RFC is a separate, explicitly
  human-authorized action outside this contract's scope entirely." That
  separate, explicit human authorization is this document itself, requested
  directly by the human opening this task.
- **The blocker this amendment resolves is recorded, verbatim, in
  `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`**, under its own
  `## Cross-RFC implementation blocker` heading (reproduced in full in §1
  below). That document is explicit that it does **not** itself authorize or
  perform any RFC-004 amendment, and that "no RFC-005 additional-slot
  implementation contract (M4, §36) may be drafted until a human explicitly
  chooses and authorizes one of these options." A human has now chosen
  option 3 of that blocker's three named options and directed this document
  to be drafted. This document performs exactly that authorization for
  RFC-004's own side; it does not draft, authorize, or begin the RFC-005 M4
  contract itself (§13).
- `maximum_correction_rounds: 2` applies to this contract, matching every
  prior RFC-004/RFC-005 contract's own convention.
- Any path required during the future implementation but absent from §11's
  own numbered allowlist is a stop-and-report condition — not a silent
  workaround. The stop threshold is the allowlist's own final count **plus
  one** (§12).
- Drafting this amendment contract makes **zero** application changes. No
  `app/`, `database/`, `routes/`, `config/`, or `docs/automation/AI-
  AUTONOMY-STATE.json` file is touched by this branch — only this one new
  document (§2).

---

## 1. The blocker — verbatim, and the chosen resolution

Quoted in full from `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`,
`## Cross-RFC implementation blocker` (its own opening section, immediately
after the document's status block):

> RFC-004 requires that additional-Business-slot allocation occur **only**
> through `EntitlementManager::setAdditionalBusinessSlots(Workspace
> $workspace, int $count, int $actorUserId, ?string $reason = null):
> WorkspacePlanAssignment` — the sole authoritative allocation mutation
> (locked decision 9). Re-confirmed by direct code read this round: that
> method's first authority check, immediately after acquiring the Workspace
> row lock, is `$this->assertPlatformAdministrator($actorUserId)` (`(bool)
> $this->userRepository->query()->whereKey($actorUserId)->value('is_admin')`,
> throwing `AuthorizationException` if false) — no lower-privilege entry
> point exists. RFC-004 §20/§18 is explicit that ordinary Workspace
> customers may **inspect** their allocation but may **never self-grant** a
> slot.
>
> A customer-paid checkout whose webhook-driven completion calls
> `setAdditionalBusinessSlots()` using the purchasing customer's own action
> as the trigger has no real platform-administrator `$actorUserId` available
> to it — that call would fail authorization in production, exactly as
> RFC-004 designed.
>
> This RFC does **not** invent a fake platform-administrator actor, bypass
> `EntitlementManager`, pass an unrelated admin's identity, or silently
> weaken RFC-004's authority check. Concrete options remain:
>
> 1. Customer pays, then a real platform administrator manually reviews and
>    allocates the slot — via §22's saga. Requires zero RFC-004 change;
>    immediately implementable.
> 2. Keep the checkout/platform allocation platform-admin-initiated only —
>    no customer self-service purchase flow at all in v1.
> 3. **Recommended: a separate, explicitly human-authorized amendment to
>    RFC-004** introducing a narrowly scoped, payment-proof-backed internal
>    allocation entry point, preserving `EntitlementManager` as sole
>    allocation authority, requiring a verified idempotent successful-
>    payment record, recording the requesting customer separately from the
>    system/payment actor, receiving its own contract/tests/review/tag
>    decision entirely separate from this document.
>
> **This RFC-005 design document does not authorize or perform that
> amendment.** No RFC-005 additional-slot implementation contract (M4, §36)
> may be drafted until a human explicitly chooses and authorizes one of
> these options. §22 designs the rest of the additional-slot payment flow in
> full and is implementation-ready **up to** the allocation step, which
> remains `NON-IMPLEMENTATION-READY`. Recorded in §39 as open item 14.

**A human has chosen option 3** and directed this document to specify it.
This document is that specification. It does not choose option 1 or option
2 for the actual RFC-005 M4 implementation to use later — it only makes
option 3 *available* by removing the RFC-004-side authorization obstacle;
whether RFC-005 M4 actually uses this seam, and how, remains RFC-005 M4's
own future, separately authorized decision (§13).

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**:
`docs/automation/RFC-004-AMENDMENT-1-PAYMENT-VERIFIED-SLOT-ALLOCATION-CONTRACT.md`
(this document). No `app/`, `database/`, `routes/`, `config/`, `resources/`,
`tests/`, or `AI-AUTONOMY-STATE.json` file is touched by drafting this
contract. No existing RFC-004 or RFC-005 contract, and no existing product
file, is modified.

---

## 3. Repository audit — findings relied on

Verified directly, at this contract's own base SHA, before drafting (not
assumed from any prior document's claims):

1. **`EntitlementManager::setAdditionalBusinessSlots()`**
   (`app/Library/Entitlement/EntitlementManager.php:691-740`) — read in
   full. Confirmed shape: opens `DB::transaction()`; locks the Workspace via
   `$this->workspaceRepository->findForUpdate($workspace->id)`
   (`SELECT ... FOR UPDATE`); calls
   `$this->assertPlatformAdministrator($actorUserId)` as the *first*
   substantive check after the lock (lines 1027-1034 — a plain scalar `int
   $actorUserId` checked against `users.is_admin`, no Gate, no permission
   string, no policy class, no lower-privilege branch); loads the current
   `WorkspacePlanAssignment`; resolves tier; calls
   `assertValidAdditionalBusinessSlots($tier, $count)` (lines 1078-1091 —
   `{0,1,2}` for Core/Growth, must be `0` for Agency); if increasing and not
   complimentary, locks the catalog row and asserts base pricing and slot
   ratio are defined; short-circuits as a same-value no-op if
   `$count === $fromCount`; otherwise mutates
   `workspace_plan_assignments.additional_business_slots`, writes a
   `workspace_entitlement_transitions` row (`transition_type =
   AdditionalBusinessSlotsChanged`, real non-nullable `actor_user_id`), and
   dispatches `WorkspaceAdditionalBusinessSlotsChanged`. This confirms the
   blocker's own re-confirmation exactly — no lower-privilege entry point
   exists today, and this method's own five-step shape (lock → authority →
   validate → mutate+audit → dispatch) is the class's uniform pattern,
   repeated identically by every other mutating method on the class.
2. **The class's only two existing null-`actor_user_id` precedents**, both
   read in full:
   - `EntitlementManager::createLegacyOnboardingCompatibilityAssignment()`
     (lines 418-452) — a narrow, dedicated public method (not a parameter
     variant of an existing method), preceded by the docblock: *"Narrow,
     system-provenance-only path — reachable only from
     `WorkspaceManager::provisionWorkspaceRecord()`'s own legacy-provisioning
     integration (§13.D). Never a general admin-bypass precedent."* It
     writes `actor_user_id => null` and a fixed constant reason
     (`self::LEGACY_COMPATIBILITY_REASON`) — no separate discriminator
     column exists for it; the fixed reason string is the only thing that
     distinguishes this null-actor case from any other.
   - The Milestone-1 backfill (`WorkspaceEntitlementBackfillV1`) writes its
     own null-actor `PlanAssigned` rows directly, predating
     `EntitlementManager`'s existence.
   - **This is the exact structural template this amendment follows**: a
     new, narrowly named, dedicated public method — never a new parameter
     branch inside `setAdditionalBusinessSlots()` itself — with its own
     docblock naming exactly which future caller may reach it and stating
     "never a general admin-bypass precedent."
3. **`workspace_entitlement_transitions` schema**
   (`database/migrations/2026_08_13_120006_create_workspace_entitlement_transitions_table.php`,
   read in full) — confirmed columns: `id`, `workspace_id` (FK,
   `restrictOnDelete()`), `transition_type` (`string(48)`), `actor_user_id`
   (nullable `unsignedBigInteger`, **no FK** — the migration's own docblock:
   *"actor_user_id/created_by-style identity columns are deliberately plain
   scalars with no foreign key — must never block a legitimate
   user-deletion feature elsewhere in the system"*), `from_plan_catalog_id`/
   `to_plan_catalog_id` (nullable FKs), `feature_key`,
   `from_override_state`/`to_override_state`,
   `from_additional_business_slots`/`to_additional_business_slots`
   (nullable `unsignedTinyInteger`), `from_status`/`to_status`, `reason`
   (nullable `text`), `created_at` only (immutable, append-only, no
   `updated_at`), composite index `(workspace_id, created_at)`. **No
   `source`/`actor_type`/idempotency-key column of any kind exists today.**
4. **RFC-004's own repeatedly stated "sole allocation authority" invariant**
   — quoted verbatim from three separate locations, all still in force:
   - RFC-004 §20: *"`App\Library\Entitlement\EntitlementManager` — the sole
     authority for: ... allocating/revoking additional Business slots
     (§17) ... No direct entitlement-table query is authorized anywhere
     outside `EntitlementManager` and its six repositories."*
   - RFC-004 §13: *"Allocation authority: only the platform admin, under
     RFC-004's admin authority (§22), may allocate or revoke an additional
     Business slot ... A Workspace customer may inspect their allocation
     and effective capacity ... but may never self-grant an unpaid
     additional slot."*
   - RFC-005 §7 locked decision 9 (inherited from
     `RFC-005-DESIGN-CONTRACT.md` §4 item 9): *"RFC-005 owns payment
     collection; RFC-004's mutation remains sole allocation authority."*
   This amendment does not relax this invariant. The new entry point
   specified below is a method **on `EntitlementManager` itself** — no
   entitlement table is ever written by anything else.
5. **The closest reusable precedent for a payment-proof-gated, no-admin-
   actor mutation already exists in RFC-005's own shipped M3 code**, and
   this amendment's mechanism deliberately mirrors its *shape* without
   taking a *dependency* on it (§8 explains why):
   - `business_funding_attempts.local_idempotency_key` (`string(191)`,
     **unique**) and `.provider_session_or_intent_reference` (nullable,
     **unique**) —
     `database/migrations/2026_08_16_140003_create_business_funding_attempts_table.php`
     — a locally generated, deterministic idempotency key created *before*
     any provider call, plus a nullable-until-known unique provider
     reference populated once the provider confirms it.
   - `UsageWalletManager::creditFromFunding()`
     (`app/Library/Usage/UsageWalletManager.php:631-676`) — a manager-owned
     mutation with **no `actorUserId` parameter at all**, documented: *"this
     method itself performs no provider call and makes no confirmation
     decision; it is purely the accounting effect of an already-verified
     successful charge."*
   - `UsageBillingCheckoutManager::confirmAttemptFromWebhook()`
     (`app/Library/Usage/UsageBillingCheckoutManager.php:274-281`) — the
     caller-side idempotency guard: `if ($attempt->state ===
     FundingAttemptState::Succeeded) { return; }` before ever crediting.
   - `App\Enums\Usage\TransitionSource` (`SyncResponse`/`WebhookEvent`/
     `AdminAction`/`ReconciliationJob`) — RFC-005's own non-admin
     transition-source discriminator, already proven in production code.
6. **RFC-005 §22's own already-designed (not yet built) M4 schema**
   pre-anticipates exactly this split: its planned
   `additional_business_slot_agreements` table already includes a
   `requesting_customer_user_id` column, explicitly commented "distinguished
   from the system/payment actor per the blocker's option 3 requirement" —
   i.e. the customer-identity half of this amendment's requirement is
   already designed on the RFC-005 side; only the RFC-004-side allocation
   call was blocked. This amendment does not create or modify that RFC-005
   table (§13).
7. **No conflicting invariant found.** Nothing in `EntitlementManager`, its
   repositories, its tables, or RFC-004's own text makes the chosen
   resolution direction (a narrowly scoped internal seam, still owned by
   `EntitlementManager`, still writing only the existing entitlement
   tables) structurally impossible. Three genuine design consequences were
   found and are resolved explicitly below rather than silently assumed:
   - **Schema gap (§6):** `workspace_entitlement_transitions` has no
     structured column for "which customer requested this" or "which
     payment event already consumed this call" — §6 adds exactly two new
     nullable columns to close this, the smallest change that satisfies the
     blocker's own explicit "recording the requesting customer separately"
     requirement without inventing a new table.
   - **Authority-shape deviation (§4):** every existing mutating method on
     `EntitlementManager` is gated by `assertPlatformAdministrator()` or
     `assertWorkspaceOwnerOrActiveAdmin()`. The new method is gated by
     neither — it is the first mutation on this class gated by verified
     payment evidence instead of a human actor. This is the intended,
     directed outcome, not an accident, and is called out explicitly so a
     reviewer does not mistake it for a missed authority check.
   - **RFC-005 M4 has a second, unrelated precondition** (RFC-005 §36 item
     4(b): "an authorized RFC-004 catalog-pricing operator surface") that
     this amendment does **not** address and does not claim to address
     (§13).

---

## 4. Exact internal authority boundary

- The new entry point is **exactly one new public method on
  `App\Library\Entitlement\EntitlementManager`**:
  `allocateAdditionalBusinessSlotsFromVerifiedPayment()` (full signature,
  §5). It is not a new parameter, flag, or branch inside
  `setAdditionalBusinessSlots()`, and it does not change
  `setAdditionalBusinessSlots()`'s signature, behavior, or authority check
  in any way — that method, its `assertPlatformAdministrator()` gate, and
  every one of its existing tests remain byte-for-byte unchanged (§12
  regression requirement).
- **`setAdditionalBusinessSlots()` remains the only entry point reachable by
  a platform administrator, and the only entry point that can *decrease* an
  allocation.** The new method is allocate-only (net increase; §9) and is
  never customer-callable, never exposed on any HTTP route, controller,
  Blade view, or customer-facing API surface authorized by this amendment
  (§11) — it is a plain PHP method callable only from trusted internal
  application code (a future RFC-005 M4 manager/job, analogous to
  `UsageBillingCheckoutManager`/`ProcessPaymentProviderEvent`), never from
  user input.
- **No administrator actor is fabricated.** `actor_user_id` is written as
  `null` for every row this method creates — the same convention
  `createLegacyOnboardingCompatibilityAssignment()` already established,
  extended to a third named, narrow, explicitly documented case (§6, §7).
  No real admin's identity is borrowed, no synthetic "system admin" user
  row is created or referenced anywhere.
- **No Stripe webhook, job, or any other caller may mutate
  `workspace_plan_assignments` or `workspace_entitlement_transitions`
  directly.** The only way any code anywhere may change
  `additional_business_slots` remains a call into `EntitlementManager` —
  either `setAdditionalBusinessSlots()` (admin path, unchanged) or the new
  `allocateAdditionalBusinessSlotsFromVerifiedPayment()` (payment path,
  this amendment). RFC-004 §20's "no direct entitlement-table query
  authorized outside `EntitlementManager` and its six repositories"
  invariant is preserved without exception.
- **This amendment does not weaken `assertPlatformAdministrator()` in any
  way.** It is not called by the new method, not modified, not made
  optional, not given a bypass parameter.

---

## 5. Exact new method signature

```php
/**
 * Narrow, payment-proof-provenance-only path — reachable only from a
 * future RFC-005 Milestone 4 checkout/webhook-completion manager, after
 * that caller has already independently verified a durable, successful,
 * idempotent payment record for exactly this allocation (§7). This method
 * itself performs no provider call, makes no payment-confirmation
 * decision, and trusts the caller's prior verification exactly as
 * UsageWalletManager::creditFromFunding() trusts
 * UsageBillingCheckoutManager's own prior verification (RFC-005 §22/M3
 * precedent) — its own responsibility is confined to: re-asserting the
 * proof's own recorded Workspace matches the target Workspace (§7),
 * enforcing this method's own independent idempotency guarantee (§8)
 * under the same Workspace row lock every other EntitlementManager
 * mutator uses (§9), applying the exact same tier/pricing validation
 * `setAdditionalBusinessSlots()` already applies (§9), and writing the
 * exact same durable audit trail with two additional, purely additive
 * columns (§6). Never a general admin-bypass precedent. Never callable
 * from customer-facing input.
 */
public function allocateAdditionalBusinessSlotsFromVerifiedPayment(
    Workspace $workspace,
    int $additionalSlotsToAdd,
    int $requestingCustomerUserId,
    int $paymentVerifiedForWorkspaceId,
    string $paymentIdempotencyKey,
    string $paymentProviderReference,
    ?string $reason = null,
): WorkspacePlanAssignment
```

**Parameter contract, exact:**

| Parameter | Meaning | Required invariant |
|---|---|---|
| `$workspace` | The Workspace to allocate against. | Must be a real, persisted `Workspace`. |
| `$additionalSlotsToAdd` | The **delta** to add to the current `additional_business_slots` value — never an absolute target. | Must be `> 0`. A payment event always means "add N slots"; it is never a vehicle for a decrease (§9). |
| `$requestingCustomerUserId` | The real `users.id` of the customer whose purchase caused this allocation — recorded separately from the (always-null) system/payment actor, satisfying the blocker's own explicit requirement. | Must reference a real user; not independently re-verified against a Workspace-membership check by this method (the caller's own upstream checkout flow already establishes that this user is a legitimate purchaser for this Workspace — re-deriving that here would require this RFC-004 method to understand RFC-005's own membership/payer rules, which is exactly the wrong-direction dependency §8 forbids). |
| `$paymentVerifiedForWorkspaceId` | The Workspace id the caller's own verified payment evidence was actually recorded against. | Must equal `$workspace->id` (§7) — the one payment-evidence fact this method *can* and *does* check itself without depending on any RFC-005 table. |
| `$paymentIdempotencyKey` | A caller-supplied, globally unique string identifying the specific successful-payment event driving this call (the RFC-005 M3 precedent: mirrors `business_funding_attempts.local_idempotency_key`'s own shape). | Must be non-empty. Governs this method's own idempotency/replay behavior (§8) independently of whatever idempotency the caller already enforces at its own layer. |
| `$paymentProviderReference` | A caller-supplied opaque string identifying the underlying provider object (e.g. a Stripe PaymentIntent or Checkout Session id) — recorded for audit/support traceability only; never independently re-verified against Stripe by this method. | Must be non-empty. |
| `$reason` | Optional free-text audit note, exactly like every other `EntitlementManager` mutator's own `$reason` parameter. | No new constraint beyond the existing convention. |

**Return value:** the resulting `WorkspacePlanAssignment` — on a genuine
new allocation, the just-updated row; on an idempotent replay (§8), the
already-current row, unchanged by this call.

---

## 6. Durable entitlement transition / audit requirements — exact schema change

**Exactly one additive migration**, extending
`workspace_entitlement_transitions` with two new, nullable columns — no new
table, no new enum, no change to any existing column, row, or index:

```php
Schema::table('workspace_entitlement_transitions', function (Blueprint $table) {
    $table->unsignedBigInteger('requesting_customer_user_id')->nullable()->after('actor_user_id');
    $table->string('payment_idempotency_key', 191)->nullable()->unique()->after('reason');
});
```

- **`requesting_customer_user_id`** — plain nullable scalar, **no foreign
  key**, matching `actor_user_id`'s own established no-FK rationale exactly
  (§3 item 3: "must never block a legitimate user-deletion feature elsewhere
  in the system"). Populated only by the new method (§5), from
  `$requestingCustomerUserId`; `null` on every existing and every
  admin-initiated row, including future `setAdditionalBusinessSlots()`
  calls — that method is not changed to populate it (§4).
- **`payment_idempotency_key`** — nullable, **globally unique** when
  present (MySQL's unique index permits unlimited `NULL`s, so every
  existing/admin-initiated row, which leaves this column `null`, never
  collides). Mirrors `business_funding_attempts.local_idempotency_key`'s
  exact type (`string(191)`) and uniqueness convention. Populated only by
  the new method, from `$paymentIdempotencyKey`. This column is the durable
  record this method's own idempotency guarantee (§8) is built on.
- **`payment_provider_reference` is deliberately *not* a new column.** It
  is recorded in the existing free-text `reason` column instead (prefixed,
  e.g. `"payment_verified_allocation: provider_reference={$providerReference}"`,
  exact format fixed at implementation time and covered by the future
  implementation's own tests) — a third structured column was considered
  and rejected as unnecessary schema surface for a value that is
  audit/support context only, never queried or joined against, keeping this
  the *smallest* safe schema change rather than the most schema-symmetric
  one.
- **No new `WorkspaceEntitlementTransitionType` case is added.** The new
  method writes the existing `AdditionalBusinessSlotsChanged` type,
  unchanged — the transition *type* already correctly describes "the
  additional-slot count changed"; *who/what* caused it is what the two new
  columns (plus the existing `actor_user_id = null` convention) now
  distinguish. This keeps `setAdditionalBusinessSlots()`'s own transitions
  and this method's own transitions in the same queryable stream
  (`forWorkspace()`), distinguishable by `requesting_customer_user_id
  IS NOT NULL` / `payment_idempotency_key IS NOT NULL` rather than by type.
- **No `source`/`actor_type` discriminator enum is added.** Considered and
  explicitly rejected (§8's own audit trail already names why: RFC-005's
  `App\Enums\Usage\TransitionSource` was evaluated as a candidate to reuse
  and rejected for wrong dependency direction — RFC-004 must not import an
  RFC-005/`Usage`-namespaced enum; RFC-004 is RFC-005's stable foundation,
  never the reverse). If a future amendment genuinely needs a third or
  fourth distinct non-admin provenance case beyond what `reason` text plus
  these two new columns can already distinguish, that is itself a new,
  separately authorized amendment — not something this one silently grows
  to cover.
- **Repository addition, exactly one new method**, on both
  `WorkspaceEntitlementTransitionRepository` (contract) and
  `EloquentWorkspaceEntitlementTransitionRepository` (implementation):
  ```php
  public function findByPaymentIdempotencyKey(string $key): ?WorkspaceEntitlementTransition;
  ```
  A single indexed lookup (`where('payment_idempotency_key', $key)->first()`),
  used exclusively by the new method's own idempotency check (§8). No
  `update()` method is added to this repository — it remains append-only,
  exactly as its own existing docblock requires ("deliberately no update(),
  mirroring `WorkspaceTransitionRepository`'s exact precedent").
- **Event:** the new method dispatches the **existing**
  `WorkspaceAdditionalBusinessSlotsChanged` event, unchanged, with
  `$actorUserId = null` — no new event class. A future listener that needs
  to distinguish payment-triggered changes from admin-triggered ones does
  so by re-reading the transition row this method already wrote (its
  `requesting_customer_user_id`/`payment_idempotency_key` columns), not by
  a different event shape.

---

## 7. Payment-proof requirements and requester/actor provenance

This method does **not** verify a payment with any payment provider, and
does **not** read any RFC-005 table (`business_funding_attempts`,
`payment_provider_events`, or the not-yet-built
`additional_business_slot_agreements`) — RFC-004 must not take a
compile-time or run-time dependency on RFC-005 schema (§8, §3 finding 7,
wrong dependency direction). Instead, this method requires its **caller**
(a future RFC-005 M4 manager) to have already independently established, by
its own means, a **durable, verified, idempotent, successful-payment
record** before ever calling this method — exactly the same trust boundary
`UsageWalletManager::creditFromFunding()` already establishes for
`UsageBillingCheckoutManager` (§3 finding 5). "Durable, verified,
idempotent, successful" means, at minimum, that the caller's own record:

1. was created **before** any provider call was made (so a crash between
   creation and the provider call cannot silently lose evidence — the
   `business_funding_attempts` precedent's own shape);
2. carries a payment-provider-confirmed "succeeded" state, not merely
   "created" or "pending" (mirroring `FundingAttemptState::Succeeded`, the
   exact gate `confirmAttemptFromWebhook()` already checks before crediting);
3. is keyed by a globally unique idempotency key generated once, before the
   provider call, and never regenerated for a retried delivery of the same
   logical payment event (the `local_idempotency_key` precedent);
4. records which Workspace the payment was actually collected for.

This method's **own, independent** responsibility — the one part of
"payment-proof requirements" that genuinely belongs to RFC-004, since it is
the one check `EntitlementManager` can perform without knowing RFC-005's
schema — is asserting that the Workspace the caller says the proof belongs
to is the same Workspace being allocated against:

```php
if ($paymentVerifiedForWorkspaceId !== $workspace->id) {
    throw new PaymentAllocationWorkspaceMismatchException($workspace->id, $paymentVerifiedForWorkspaceId);
}
```

This is the exact, and only, defense this method has against "payment
evidence belongs to another Workspace" (§10) — a structural, cheap,
caller-supplied-value check, not a re-verification against a payment table
this RFC does not own. **The correctness of `$paymentVerifiedForWorkspaceId`
itself is the future RFC-005 M4 caller's own contractual obligation** —
this amendment specifies that obligation exists and states exactly what
`EntitlementManager` does if the caller violates it (rejects, §10), but
does not and cannot independently audit the caller's own upstream provider
verification; that remains RFC-005 M4's own scope, tested by RFC-005 M4's
own tests, exactly as `creditFromFunding()`'s own documented trust boundary
already establishes as this repository's precedent.

**Requester/customer identity vs. system/payment actor provenance — exact
split:**

| Concept | Column | Value for this new method |
|---|---|---|
| "Who/what performed this mutation" (RFC-004's existing actor concept) | `actor_user_id` | Always `null` — no administrator, real or fabricated, ever appears here for a payment-triggered allocation (§4). |
| "Which customer's purchase caused this" (the blocker's own explicit new requirement) | `requesting_customer_user_id` (§6, new) | The real `$requestingCustomerUserId` the caller supplies — always a real user id, never null, for every row this method writes. |
| "Why was `actor_user_id` null this time" (disambiguating this method's rows from the two pre-existing null-actor cases) | `reason` (existing column, new fixed-prefix convention) | A fixed constant prefix, e.g. `self::PAYMENT_VERIFIED_ALLOCATION_REASON_PREFIX = 'payment_verified_allocation'`, distinguishing this method's rows from `createLegacyOnboardingCompatibilityAssignment()`'s own fixed reason string — exact final string format is an implementation detail fixed by the future implementation PR and covered by its own tests, not by this contract. |

---

## 8. Idempotency and replay behavior

`EntitlementManager` enforces its **own**, independent idempotency
guarantee for this method — it never merely trusts that the caller's own
upstream idempotency (whatever RFC-005 M4 builds at its own layer, e.g. a
funding-attempt/agreement state machine) is sufficient, because this method
is the sole allocation authority and must independently guarantee that the
same successful-payment evidence can never allocate twice, exactly as
required.

**Exact behavior, all under the same Workspace row lock (§9) the method
already acquires as its first step:**

1. After locking the Workspace, before any tier/pricing validation, look up
   `payment_idempotency_key = $paymentIdempotencyKey` via the new
   `findByPaymentIdempotencyKey()` repository method (§6).
2. **Not found** → this is a genuinely new allocation; proceed to §9's
   validation and mutation.
3. **Found, and its recorded `workspace_id`, `requesting_customer_user_id`,
   and `to_additional_business_slots` all exactly match this call's own
   `$workspace->id`, `$requestingCustomerUserId`, and the count this call
   would have produced** (i.e. this is a true replay of the identical
   logical event — a retried webhook delivery, a retried job, a duplicate
   caller invocation) → **idempotent success, no error.** Perform **no**
   further mutation, write **no** new transition row, dispatch **no** new
   event — return the current `WorkspacePlanAssignment` unchanged by this
   call, exactly as `confirmAttemptFromWebhook()`'s own "if already
   Succeeded, return" precedent does.
4. **Found, but any of `workspace_id` / `requesting_customer_user_id` /
   `to_additional_business_slots` differ from this call's own values** —
   the same idempotency key is being reused for a *different* logical
   event (a caller-side generation bug, key collision, or a mismatched
   retry with altered parameters) → throw
   `PaymentAllocationIdempotencyConflictException` immediately. **Never**
   silently overwrite, never merge, never apply a partial mutation. This
   is the amendment's exact definition of "stale"/"already-consumed
   evidence reused" (§10) — a structural mismatch between what was
   recorded and what is now being claimed, not a time-based staleness
   window (§10 explains why no expiry/max-age concept is introduced).
5. **Concurrency guarantee:** because the idempotency-key lookup happens
   *after* the Workspace row lock is acquired, two concurrent calls for the
   **same Workspace** (whatever their idempotency keys) are already fully
   serialized by the existing `findForUpdate()` primitive — the second call
   blocks until the first call's transaction commits, then sees whatever
   the first call actually wrote. The **unique** database index on
   `payment_idempotency_key` (§6) is the backstop for the separate,
   should-never-happen case of the *same* key being used for *two
   different Workspaces* concurrently (which would mean two distinct
   payment events collided on one key value — a caller-side bug): if the
   `INSERT` itself violates that unique constraint despite the pre-check
   not finding a conflict (a genuine cross-Workspace race, not covered by
   the single-Workspace lock), the transaction rolls back and
   `PaymentAllocationIdempotencyConflictException` is thrown rather than
   allowing a raw database exception to leak — this is a fail-safe
   backstop, not the primary mechanism.

---

## 9. Workspace locking / concurrency and mutation semantics

The new method reuses the existing `EntitlementManager` mutation shape
exactly, with the payment-proof checks (§7, §8) inserted in place of
`assertPlatformAdministrator()`:

1. `DB::transaction()` wraps the entire method body — identical to every
   other mutator.
2. `$lockedWorkspace = $this->workspaceRepository->findForUpdate($workspace->id)` —
   the identical pessimistic row lock every other mutator acquires first;
   `WorkspaceNotFoundException` if missing, unchanged.
3. **Workspace-mismatch check** (§7) — reject before any other check if
   `$paymentVerifiedForWorkspaceId !== $lockedWorkspace->id`.
4. **Evidence-shape check** — reject with
   `InvalidPaymentAllocationEvidenceException` if
   `$paymentIdempotencyKey === ''`, `$paymentProviderReference === ''`, or
   `$additionalSlotsToAdd <= 0`. No DB write of any kind occurs if this
   check fails — not even the idempotency lookup.
5. **Idempotency check** (§8) — replay short-circuit or conflict rejection.
6. Load the current `WorkspacePlanAssignment` — `WorkspacePlanUnassignedException`
   if none, unchanged from `setAdditionalBusinessSlots()`.
7. **Complimentary-Workspace guard (new, this method only):** if
   `$assignment->is_complimentary === true`, throw
   `ComplimentaryWorkspaceCannotAllocatePaidSlotsException` — a
   complimentary Workspace is, by definition, not being charged; a verified
   payment record against one is a contradiction this method refuses to
   silently accept rather than quietly allocating an unbilled-context slot
   under a paid-looking transition row. `setAdditionalBusinessSlots()`
   itself is unaffected — an administrator may still freely change a
   complimentary Workspace's allocation through the existing method exactly
   as today.
8. Resolve tier from the catalog exactly as `setAdditionalBusinessSlots()`
   does.
9. Compute `$toCount = $fromCount + $additionalSlotsToAdd` (the delta
   contract, §5) and call the **existing, unchanged**
   `assertValidAdditionalBusinessSlots($tier, $toCount)` — the same
   `{0,1,2}`/Agency-must-be-zero rule, throwing the same
   `InvalidAdditionalBusinessSlotsException` on violation. **This is a
   hard cap even for a verified payment**: a payment for a slot count that
   would push the Workspace's total past its tier's own maximum is
   rejected, exactly as an admin-initiated over-cap request already is
   today — this amendment does not create a new, payment-specific ceiling
   exception.
10. Assert base pricing and slot ratio are defined (`assertBasePricingDefined()`/
    `assertSlotRatioDefined()`, unchanged, reused as-is) — a payment cannot
    complete an allocation against undefined catalog pricing, exactly as an
    admin-initiated increase cannot today.
11. Mutate `additional_business_slots` to `$toCount`.
12. Write the transition row (§6) — `transition_type =
    AdditionalBusinessSlotsChanged`, `actor_user_id = null`,
    `requesting_customer_user_id = $requestingCustomerUserId`,
    `payment_idempotency_key = $paymentIdempotencyKey`,
    `from_additional_business_slots = $fromCount`,
    `to_additional_business_slots = $toCount`, `reason` per §7's table.
13. Dispatch `WorkspaceAdditionalBusinessSlotsChanged::dispatch($lockedWorkspace->id, $fromCount, $toCount, null)`.
14. Return the updated `WorkspacePlanAssignment`.

No optimistic/version-column locking is introduced — the pessimistic
Workspace row lock remains this codebase's single, uniform concurrency
primitive (§3 finding, no exception found anywhere in the repository for
Workspace-scoped mutations).

---

## 10. Exact failure behavior — every named case

| Evidence condition | Detection | Result |
|---|---|---|
| **Absent** (empty idempotency key, empty provider reference, or non-positive delta) | Step 4 (§9) | `InvalidPaymentAllocationEvidenceException` — no DB write at all. |
| **Belongs to another Workspace** (`$paymentVerifiedForWorkspaceId !== $workspace->id`) | Step 3 (§9) | `PaymentAllocationWorkspaceMismatchException` — no DB write at all. |
| **Mismatched** (idempotency key already recorded, but with a *different* Workspace, requesting customer, or resulting count than this call) | Step 5 / §8 case 4 | `PaymentAllocationIdempotencyConflictException` — the entire transaction rolls back; the original, earlier row is left completely untouched. |
| **Stale** (same as "mismatched" above — this amendment defines "stale reused evidence" as a structural mismatch between the recorded transition and the new claim, never a time-based expiry; §8 explains why no staleness window is introduced by RFC-004) | Step 5 / §8 case 4 | Same as "mismatched," above — one unified conflict outcome, one exception type. |
| **Already consumed** (idempotency key already recorded, and every recorded fact matches this call exactly — a true replay) | Step 5 / §8 case 3 | **Not a failure.** Idempotent success: no new mutation, no new transition row, no new event; the current assignment is returned unchanged. |
| **Workspace has no assignment at all** | Step 6 (§9) | `WorkspacePlanUnassignedException` — unchanged from `setAdditionalBusinessSlots()`. |
| **Target Workspace is complimentary** | Step 7 (§9) | `ComplimentaryWorkspaceCannotAllocatePaidSlotsException` — new, this method only. |
| **Computed total exceeds the tier's cap, or is nonzero for Agency** | Step 9 (§9) | `InvalidAdditionalBusinessSlotsException` — unchanged, reused as-is. |
| **Catalog pricing/slot-ratio undefined** | Step 10 (§9) | `UndefinedPlanPricingException` — unchanged, reused as-is. |
| **Workspace row not found** | Step 2 (§9) | `WorkspaceNotFoundException` — unchanged. |

**Every rejection path leaves the database exactly as it was before the
call** — every check that can fail happens either before the transaction
opens any write, or inside the same `DB::transaction()` that is rolled back
on any thrown exception, matching this class's own existing all-or-nothing
convention for every other mutator.

---

## 11. Exact production/test paths permitted for the future implementation

**Closed, numbered allowlist. This contract authorizes no path beyond this
list for the future implementation PR. Any additional path discovered as
genuinely necessary during that implementation is a stop-and-report
condition (§12) — not something to add silently.**

### Migration (1)

1. `database/migrations/2026_08_2X_XXXXXX_add_payment_columns_to_workspace_entitlement_transitions_table.php`
   (exact timestamp fixed at implementation time, following this
   repository's own existing timestamp-ordering convention) — the two-column
   additive migration specified in §6. Modifies no other table, no existing
   column, no existing index.

### Manager (1 — modified, not created)

2. `app/Library/Entitlement/EntitlementManager.php` — adds exactly the one
   new public method (§5), the four new private/dedicated exception throws
   it needs (§10), and the `PAYMENT_VERIFIED_ALLOCATION_REASON_PREFIX`
   constant (§7). **Every existing method on this class, including
   `setAdditionalBusinessSlots()` itself, is byte-for-byte unchanged** —
   this file's diff must be purely additive.

### Repository contract + implementation (2 — modified, not created)

3. `app/Repositories/Contracts/WorkspaceEntitlementTransitionRepository.php`
   — adds exactly the one new `findByPaymentIdempotencyKey()` method
   signature (§6). No `update()` method is added.
4. `app/Repositories/Eloquent/EloquentWorkspaceEntitlementTransitionRepository.php`
   — implements the new method.

### Model (1 — modified, not created)

5. `app/Models/WorkspaceEntitlementTransition.php` — adds
   `requesting_customer_user_id` and `payment_idempotency_key` to
   `$fillable`/casts exactly as the model's own existing convention for its
   other nullable scalar columns.

### Exceptions (4 — new)

6. `app/Exceptions/Entitlement/InvalidPaymentAllocationEvidenceException.php`
7. `app/Exceptions/Entitlement/PaymentAllocationWorkspaceMismatchException.php`
8. `app/Exceptions/Entitlement/PaymentAllocationIdempotencyConflictException.php`
9. `app/Exceptions/Entitlement/ComplimentaryWorkspaceCannotAllocatePaidSlotsException.php`

Each following this directory's own existing exception-class shape
(constructor accepting the relevant identifiers, a clear message, extending
the same base exception class the existing eleven `Entitlement` exceptions
already extend).

### Tests (exact allowlist, new test methods within existing or one new file)

10. `tests/Feature/Entitlement/EntitlementManagerAdditionalBusinessSlotsTest.php`
    (or, if this exact file does not exist at implementation time, a
    correctly named equivalent within `tests/Feature/Entitlement/` — the
    future implementation PR states the exact chosen path explicitly rather
    than assuming) — new test methods covering, at minimum, one method per
    row of §10's table, plus: a genuine new allocation succeeds and is
    correctly audited (§6 columns populated, `actor_user_id` null); a true
    replay is idempotent (no second transition row, no second event
    dispatch — assert via `Event::fake()`/count query); concurrent same-key
    calls for the same Workspace serialize correctly under the row lock;
    `setAdditionalBusinessSlots()`'s own existing tests are unaffected
    (regression, §12).
11. `tests/Feature/Entitlement/WorkspaceEntitlementTransitionRepositoryTest.php`
    (existing file, modified) — new test method(s) for
    `findByPaymentIdempotencyKey()`.

**Total: 11 files (1 migration + 1 manager-modified + 2 repository-modified
+ 1 model-modified + 4 new exceptions + 2 test files, one modified one
possibly new). No unrelated path may be authorized.** No route, no
controller, no Blade view, no Stripe/webhook code of any kind, no
`additional_business_slot_*` table, no RFC-005-namespaced file is created
or modified by the future implementation this contract authorizes — all of
that is explicitly RFC-005 M4's own, separate, later scope (§13).

---

## 12. Regression requirements for the future implementation

The future implementation PR must run, and report exact pass counts for,
at minimum:

1. `php artisan test tests/Unit/Entitlement tests/Feature/Entitlement` —
   must report the same **278 passing** established by the RFC-004 M4
   conformance pass **plus** the new test methods added under §11 items
   10-11, with **zero** existing test modified, skipped, or deleted (a
   diff to any existing test file inside these two directories, other than
   the one explicitly authorized addition to
   `WorkspaceEntitlementTransitionRepositoryTest.php`, is itself a
   stop-and-report condition).
2. `php artisan test tests/Unit/Workspace tests/Feature/Workspace` — must
   continue reporting the same **779 passing** established by the RFC-004
   M4 conformance pass, unchanged, confirming the new migration's additive
   columns introduce no regression to Workspace-adjacent behavior.
3. A full `php artisan test --stop-on-failure` run — must exit `0` with a
   positive test count strictly greater than the RFC-004 M4 conformance
   pass's own **2427 passing** baseline (the new tests' own count added, no
   existing test lost).
4. A dedicated regression assertion that `setAdditionalBusinessSlots()`'s
   own existing authorization behavior is completely unchanged — every
   existing test asserting `AuthorizationException` for a non-admin actor
   on that method must still pass unmodified, verbatim.

No PHP/JS tests are run for **this** docs-only contract itself (§14) — the
requirements above bind the future implementation PR, not this one.

---

## 13. Explicit non-scope statement

**This amendment does exactly one thing: it creates a secure, narrowly
scoped, internal entitlement-allocation seam on `EntitlementManager`, gated
by caller-supplied payment evidence and this method's own independent
idempotency guarantee, so that a future RFC-005 Milestone 4 caller has a
real, authorization-compliant way to increase a Workspace's additional
Business slots after a payment succeeds.**

This amendment explicitly does **not**:

- implement Stripe checkout, a Stripe webhook handler, or any payment
  collection of any kind — no `app/Http/Controllers`, no
  `app/Jobs`/`app/Listeners` webhook-processing class, no route of any kind
  is authorized (§11);
- create, modify, or reference any RFC-005-owned table, model, enum, or
  manager (`business_usage_wallets`, `business_funding_attempts`,
  `payment_provider_events`, `App\Enums\Usage\*`,
  `App\Library\Usage\*`, or the not-yet-built
  `additional_business_slot_agreements`/`additional_business_slot_agreement_transitions`
  and related M4 tables) — RFC-004 remains fully independent of RFC-005's
  schema in both directions (§8);
- draft, authorize, or begin RFC-005 Milestone 4's own implementation
  contract in any way — that remains its own, entirely separate, future
  human-authorized document, and per RFC-005 §36 it has **two**
  preconditions, of which this amendment resolves only one (the allocation
  blocker); the "authorized RFC-004 catalog-pricing operator surface"
  precondition (RFC-005 §36 item 4(b)) is untouched and unresolved by this
  document;
- decide, design, or implement decrease, refund, cancellation, proration,
  or non-renewal semantics for a payment-allocated slot in any way. The new
  method is allocate-only (§5, §9: `$additionalSlotsToAdd` must be
  positive). If a payment is later refunded, a subscription is canceled, or
  a renewal charge fails, **removing** the corresponding slot(s) remains
  entirely RFC-005 M4's own future design decision — implemented either (a)
  through the existing, unmodified, admin-only `setAdditionalBusinessSlots()`
  (an administrator or an admin-triggered automated process decreasing the
  count, exactly as today), or (b) through a symmetric future amendment
  introducing its own equally narrowly scoped, equally payment-proof-gated
  "deallocate from verified refund/cancellation" seam — this document
  neither designs nor pre-authorizes option (b); RFC-005 M4 must make and
  separately justify that choice when it is actually contracted;
- change RFC-004's tier catalog, pricing, feature registry, override, or
  Business-toggle behavior in any way — every other `EntitlementManager`
  method, table, and invariant not named above is completely untouched;
- re-open, re-tag, or otherwise touch RFC-004's own release state — the
  existing `rfc-004-plans-and-business-feature-entitlements` tag is not
  moved, replaced, or superseded; this amendment's own future
  implementation, once merged, simply becomes part of `main` going forward,
  exactly as any other post-tag bugfix or amendment to a closed RFC would.

---

## 14. Stop conditions

The future implementation must stop, leave the working tree unstaged, and
report rather than proceed, if:

- Any path beyond §11's eleven-item allowlist is found necessary — the
  twelfth path.
- `setAdditionalBusinessSlots()`'s own signature, body, or authorization
  check is found to require any change to accommodate the new method —
  the two methods must remain fully independent.
- The two new `workspace_entitlement_transitions` columns (§6) are found
  insufficient to satisfy §7's provenance-split requirement without adding
  a third column or a new enum — a genuine schema-adequacy gap here is
  itself a stop-and-report condition for a further bounded amendment, not
  something to silently expand.
- Any RFC-005-owned file, table, or namespace is found necessary to
  reference, import, or depend on from `app/Library/Entitlement/**` — this
  is the exact wrong-direction dependency §8/§13 forbid.
- Any test in `tests/Unit/Entitlement`, `tests/Feature/Entitlement`,
  `tests/Unit/Workspace`, or `tests/Feature/Workspace` fails for a reason
  not fixable within §11's own allowlist.
- Any regression count in §12 is lower than its stated baseline.
- A genuine need to expose this method (or any variant of it) to an HTTP
  route, controller, or any customer-facing surface is discovered — this
  amendment authorizes an **internal PHP method only**; any HTTP exposure
  is out of scope entirely and would itself require its own separate,
  explicitly authorized design.

---

## 15. Contract self-audit

1. Every element the task required this contract to specify is present:
   exact internal authority boundary (§4); exact payment-proof requirements
   (§7); requester/customer identity vs. system/payment actor provenance
   (§7's table); idempotency and replay behavior (§8); Workspace
   locking/concurrency (§9, reusing the existing primitive exactly);
   decrease/refund/cancellation semantics and what is deferred to RFC-005
   M4 (§13); durable entitlement transition/audit requirements (§6); exact
   failure behavior for absent/mismatched/stale/already-consumed/wrong-
   Workspace evidence (§10); exact production/test paths permitted for the
   future implementation (§11); regression requirements (§12); an explicit
   statement that this amendment only creates the seam and does not
   implement checkout, webhook processing, billing, or RFC-005 M4 (§13). ✓
2. `setAdditionalBusinessSlots()` is not made customer-callable, its
   existing platform-administrator authority is not weakened, and it is not
   modified at all (§4, §11 item 2, §12 item 4). ✓
3. No administrator actor is fabricated anywhere — `actor_user_id` is
   always `null` for the new method's own rows, following the existing,
   named, narrow precedent exactly, extended (not redefined) to a third
   case (§4, §7). ✓
4. No Stripe webhook or any other caller may mutate entitlement tables
   directly — the new seam is a method on `EntitlementManager` itself, and
   `EntitlementManager` remains the sole allocation authority, restated and
   preserved, not merely claimed (§4, §13). ✓
5. Allocation without durable, verified, idempotent successful-payment
   evidence is not possible: the evidence-shape check (§9 step 4) and the
   Workspace-match check (§7, §9 step 3) run before any other work, and the
   idempotency guarantee (§8) is enforced independently by
   `EntitlementManager` itself, never merely trusted from the caller. ✓
6. RFC-005 Milestone 4 is not implemented, drafted, or begun by this
   document in any way (§13). ✓
7. This document itself changes exactly one file (§2), verified mechanically
   before commit (§16). ✓
8. The chosen resolution direction was checked against the repository's
   actual code, not assumed from the RFC text alone — §3 records seven
   concrete, direct-inspection findings, and no conflict was found that
   would require stopping instead of drafting (the three genuine design
   consequences found are resolved explicitly within this document, not
   silently assumed away). ✓
9. The amendment deliberately does not import RFC-005's own
   `TransitionSource` enum or any other RFC-005-namespaced code, preserving
   the correct RFC-004-is-the-foundation dependency direction, and states
   why (§6, §8, §13). ✓
10. The second, independent RFC-005 M4 precondition (the catalog-pricing
    operator surface gap) is named explicitly as unresolved and out of this
    amendment's own scope, so this document cannot be misread as clearing
    M4 for drafting in full (§3 item 7, §13). ✓

---

## 16. Verification and publication (this document only)

Performed, in order, before commit:

1. `git status --short` — exactly one untracked path,
   `docs/automation/RFC-004-AMENDMENT-1-PAYMENT-VERIFIED-SLOT-ALLOCATION-CONTRACT.md`,
   nothing else, nothing staged.
2. `git diff --check` — clean (file newly created; run against staged
   content).
3. Stage the one file by its exact path only
   (`git add docs/automation/RFC-004-AMENDMENT-1-PAYMENT-VERIFIED-SLOT-ALLOCATION-CONTRACT.md`),
   never `git add -A`/`.`.
4. Commit exactly:
   `docs: authorize RFC-004 payment-verified slot allocation amendment`.
5. Push normally to
   `origin chore/rfc-004-additional-business-slot-payment-allocation-amendment`.
   No force push. Do not push `main`.
6. Open a Draft PR into `main` if tooling permits; otherwise report the
   exact GitHub comparison URL.
7. **Do not merge. Do not begin the implementation this document
   authorizes.** Both require this document to be merged first, and the
   implementation itself remains its own separate, later, explicitly
   bounded PR.
8. PHP/JS tests are not required for this one-file docs-only change and are
   not run — reported honestly as not run, no count fabricated.

---

*End of RFC-004 Amendment 1 — Payment-Verified Additional-Business-Slot
Allocation Seam. This document authorizes drafting itself only. The seam it
specifies requires its own separate, later, explicitly bounded
implementation PR (§11), which itself does not implement RFC-005 Milestone
4 (§13). RFC-004's own tagged, complete release state
(`rfc-004-plans-and-business-feature-entitlements`) is unmoved.*
