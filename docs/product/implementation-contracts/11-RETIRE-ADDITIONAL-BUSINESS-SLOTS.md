# Implementation Contract 11 — Retire Additional-Business-Slots Flow

**Status:** Planning contract only. Does not authorize implementation.
Depends on Contract 10 being merged and its migration run and verified
first.

## 1. Objective

Freeze new sales of `additional_business_slots`/
`additional_business_slot_agreements`, with an explicit preflight that
determines whether any already-paid holder exists — a conditional
migration/operations gate, not an open architecture decision (Blueprint's
own closing section, Roadmap A7).

## 2. Governing authority

- Addendum §17 (RFC-004 §13/§17 superseded), §18 step 5.
- Blueprint §21, §35, and its "BLOCKING UNRESOLVED PRODUCT DECISIONS"
  closing note (Phase A A7 addition).
- Roadmap Slice 11, as corrected by Phase A's A7.

## 3. Current repository reality

**`app/Library/Entitlement/EntitlementManager.php`** — the three methods
this slice freezes, confirmed by line number:
- `assertCanCreateAnotherBusiness(Workspace $workspace): void` (line 417)
  — the capacity-check gate a new-Business-creation call currently passes
  through; this is the **general** capacity gate, used by both the
  slot-purchase-dependent Core/Growth path and the unlimited-Agency path
  — freezing "new sales" does **not** mean deleting this method (Contract
  14 does eventual cleanup; this slice only stops the *purchase* flow).
- `setAdditionalBusinessSlots(Workspace $workspace, int $count, int
  $actorUserId, ?string $reason = null): WorkspacePlanAssignment` (line
  1396) — the admin-only allocation-mutation method (per RFC-004 §13's
  own "only the platform admin... may allocate or revoke" rule, confirmed
  original audit).
- `allocateAdditionalBusinessSlotsFromVerifiedPayment(...)` (line 1465) —
  the payment-triggered allocation path, called from the checkout flow
  once payment succeeds.

**`app/Enums/Usage/SlotAgreementState.php`** (full file read, Phase A):
`QuoteCreated | CheckoutPending | PaymentSucceeded | AllocationPending |
Completed | PaymentFailed | AllocationFailed | RefundPending | Refunded |
Canceled` — `Completed` is the state representing a fully paid, allocated,
still-active commercial commitment (Phase A A7's own precise finding,
reused here unchanged).

## 4. Delta from current state to target

**Changes:** the checkout/purchase **entry points** (routes/controllers
initiating a new slot agreement) are disabled — new `QuoteCreated`/
`CheckoutPending` agreements can no longer be started. The preflight
(§8) determines the rest of this slice's actual scope at run time.

**Explicitly does NOT change:** `assertCanCreateAnotherBusiness()` itself
(still needed — Contract 13 is where the underlying multi-Business
capacity concept finally goes away, not this slice); any *existing*
`Completed` agreement's own renewal/charge mechanics (frozen from **new**
sales, not retroactively altered, pending the preflight's outcome).

## 5. Data model contract

No schema change. This slice is a code-path freeze plus a read-only
preflight query, not a migration.

**Preflight query (Phase A A7, restated as the authoritative
specification):**
```sql
SELECT COUNT(*) FROM additional_business_slot_agreements
WHERE state = 'completed' AND cancellation_effective_at IS NULL
```

## 6. Authority / security contract

Freezing the purchase flow is a platform-level, code-deploy action, not
an end-user-facing authorization change — no new actor-facing permission
is introduced. The **existing** admin-only authority on
`setAdditionalBusinessSlots()` is unaffected (still admin-only; this
slice does not touch who may call it, only whether the customer-facing
checkout entry point that leads to it remains reachable).

## 7. Transaction / concurrency boundary

The preflight query is a plain read, no lock needed. Disabling the
checkout entry points is a routing/controller-availability change, not a
data write.

## 8. Migration / backfill — the conditional gate itself (Phase A A7, operationalized)

1. **Run the preflight query above.**
2. **Zero rows:** no commercial-treatment decision is needed. Proceed:
   disable the checkout entry points (§4); no further action.
3. **One or more rows:** **STOP.** Do not disable renewal/entitlement for
   those specific Workspaces yet. Produce a report (Workspace, current
   allocation, next renewal date) and require an explicit, separately
   authorized commercial decision (refund, grandfather until natural
   expiry, or convert) before those specific Workspaces' agreements are
   touched. The checkout **entry point for new agreements** may still be
   disabled immediately regardless of this outcome (that part is never
   conditional — only *existing*-holder treatment is).

## 9. Backwards compatibility

Any Workspace with a `Completed` agreement continues to receive its paid-
for capacity and renewal billing exactly as today, until and unless the
separately-authorized commercial decision (§8, case 3) changes that.

## 10. Events / audit

No new event. The preflight report itself is the deliverable — treat it
as a durable artifact (saved output, not just console text) so the
commercial decision in §8 case 3 has a stable reference.

## 11. Billing/provider safety

This slice's entire purpose is billing safety: preventing an
existing paid holder's already-purchased slots from silently vanishing
(Roadmap's own adversarial test theme, restated) by gating the
retirement on the preflight's outcome rather than executing
unconditionally.

## 12. Exact implementation allowlist

**New files:**
- `app/Console/Commands/AdditionalBusinessSlotRetirementPreflight.php` (the preflight report command)
- `tests/Feature/Entitlement/AdditionalBusinessSlotRetirementPreflightTest.php`

**Existing files modified:**
- Whichever controller/route currently exposes the slot-purchase checkout entry point (to be confirmed at implementation time — not definitively located in this evidence pass; likely under `app/Http/Controllers/Customer/Workspace/` given the `{workspaceUid}/additional-business-slots/*` route group confirmed in the original audit) — disabled/removed, not `EntitlementManager` itself.

**No change to `EntitlementManager.php`'s three named methods.**

## 13. Required tests

`AdditionalBusinessSlotRetirementPreflightTest.php`: zero-`Completed`-rows
case proceeds without requiring a decision; one-or-more-rows case
produces the correct report and does **not** disable anything for those
specific Workspaces; the checkout entry point is unreachable after this
slice regardless of which preflight case occurred.

## 14. Acceptance criteria

1. Preflight query matches §5 exactly.
2. Zero-row case requires no human decision to proceed.
3. Non-zero case halts and reports rather than silently choosing a
   treatment.
4. New purchase checkout is unreachable after this slice, in both cases.
5. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not implement the actual refund/grandfather/convert treatment for
any existing `Completed` agreement found (that is the separately-
authorized commercial decision this slice defers to, per §8). Does not
remove `EntitlementManager`'s slot-related methods (Contract 14). Does not
touch `assertCanCreateAnotherBusiness()`'s general capacity logic.

## 16. Merge prerequisites

Contract 10 merged, run, and verified (Roadmap ordering — cannot retire
the mechanism Agency accounts still depend on before they're migrated
off it).

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 10 | this slice must follow it | Serialize (prerequisite) |
| Contract 12 | different scope (non-Agency Workspaces) | Serialize, per Roadmap order, though no direct file conflict |
| Contract 09 | independent | Safe concurrent if not already landed |

## 18. Implementation prompt

```
You are implementing Slice 11 of the V1 architecture migration for the
os-creator1/os-ai repository: retiring the additional-business-slots
purchase flow, per docs/product/implementation-contracts/
11-RETIRE-ADDITIONAL-BUSINESS-SLOTS.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contract 10 is merged, and that its migration has actually been
   run and verified against the target database -- this is a hard
   prerequisite specifically because this slice's preflight query only
   means what this contract says it means once Contract 10's migration
   has run. If unverified, STOP and report.
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-11-retire-additional-business-slots).
4. Re-read the full contract, especially SS8's conditional gate -- it is
   the authoritative specification.
5. Locate the actual current checkout entry point for additional-
   business-slot purchases (this contract did not definitively locate it
   -- confirm the real controller/route before disabling anything).

Implement exactly the scope in this contract: the preflight command
running the exact query in SS5, and disabling the purchase checkout entry
point. Run the preflight against your test database as part of your own
verification. If it reports any Completed agreement, STOP and report --
do not invent a commercial treatment yourself; that decision belongs to
the human, not to you. Do NOT modify EntitlementManager's three named
methods. Do NOT remove any existing slot-related code (Contract 14).

After implementing:
- Run the new focused test file, covering both the zero-row and
  non-zero-row preflight cases.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, and the actual preflight result against your test
database. Do NOT begin or authorize Contract 12 or any other later slice.
```
