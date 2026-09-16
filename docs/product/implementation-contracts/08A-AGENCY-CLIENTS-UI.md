# Implementation Contract 08A — Agency Clients UI

**Status:** Planning contract only. Does not authorize implementation.
Depends on Contract 07 being merged first (hard dependency — Roadmap
Wave 3a/3b split, Phase A correction A8).

## 1. Objective

Build the Agency-facing "Clients" list/management screen (Blueprint §28):
list linked Client Workspaces, open one, trigger View As, and initiate
provisioning — a thin controller/UI layer over Contracts 01, 04, and 07's
already-designed backend primitives. **No new authorization logic** is
introduced here; this slice consumes each prior contract's authority
methods as-is.

## 2. Governing authority

- Blueprint §28 (Clients surface), §2 (corrected actor authority — not
  owner-only).
- Roadmap Slice 8(a), Wave 3b (per the Phase A correction: this slice
  must not begin consuming Slice 7's interface until Slice 7 is merged
  and stable — not ordinary concurrency).
- Contracts 01, 04, 07 (this slice's entire backend surface).

## 3. Current repository reality

No existing "Clients" list screen exists (confirmed by the original
architecture audit: no Agency↔Client relationship UI of any kind is
present on `main`). The closest existing precedent for a customer-facing
list-of-accounts screen is `customer-context-switcher.blade.php` (read in
an earlier audit pass) — its filterable, per-row-action list pattern is a
reasonable **visual** precedent to follow for this new screen's UX shape,
but its underlying data source (the old Business-switcher/multi-Business-
per-Workspace model) is exactly what this slice must **not** reuse
structurally — Blueprint §35 names this exact contract as correcting that
same switcher's old tenancy mapping. This slice's list is powered
entirely by Contract 01's relationship repository, not by
`businessesForWorkspace()` or any Business-switcher code.

`app/Http/Controllers/Customer/Workspace/WorkspaceController.php` is the
existing home for Workspace-management customer controllers (confirmed
via `git grep` in the original audit — `storeBusiness()` lives here) and
is the natural sibling location for a new Agency-Clients controller,
though this slice adds a **new** controller file rather than growing that
one, to keep Agency-specific orchestration separate (matching Contract
01/07's own "new class, not more methods on an existing large class"
principle).

## 4. Delta from current state to target

**Changes:** one new controller (`AgencyClientsController` or similar),
new routes under the Agency's own Workspace path, new Blade views (list,
detail), wiring to Contract 01's relationship repository (list/read),
Contract 07's provisioning manager (create), and Contract 04's
`startAgencyView()` (the View As entry point).

**Explicitly does NOT change:** any of the three consumed contracts'
backend code — this is purely a new consumption layer.

## 5. Data model contract

None — no new table or column. This slice reads Contract 01's
relationship table and writes to it only via Contract 07's orchestrator
(create) — never a direct write from the controller.

## 6. Authority / security contract

Identical to Contract 01 §6 / Contract 07 §6 (reused, not reimplemented):
Agency owner or an active Agency Admin/Staff member of that exact Agency
Workspace — by membership alone, with no additional Agency-management
permission (Contract 04 authority correction) — may list/open/provision/
View-As; anyone else is refused. The controller's
own job is limited to: resolving the current actor and their Agency
Workspace, then delegating every authorization decision to the already-
built manager classes — **the controller must contain no independent
authorization logic of its own** (a common source of drift between a
controller's ad hoc checks and the canonical manager's checks, which this
slice must avoid by construction, not by discipline alone).

## 7. Transaction / concurrency boundary

None beyond what Contracts 01/07 already provide — this slice's
controller actions are thin delegations, not their own transactional
units.

## 8. Migration / backfill

None.

## 9. Backwards compatibility

Fully additive — no existing route or view changes.

## 10. Events / audit

None beyond what Contracts 01/04/07 already dispatch — the controller
does not dispatch its own events.

## 11. Billing/provider safety

Not applicable directly — no payer/wallet action in this slice (SaaS Plan
assignment, Agency billing, and AgencyRebill configuration are Blueprint
§28's later sub-surfaces, out of this slice's scope per §15).

## 12. Exact implementation allowlist

**New files:**
- `app/Http/Controllers/Customer/Agency/AgencyClientsController.php`
- `routes/customer.php` — new route group additions only (no existing route modified)
- `resources/views/customer/agency/clients/index.blade.php`
- `resources/views/customer/agency/clients/show.blade.php`
- `tests/Feature/Agency/AgencyClientsHttpTest.php`

**No existing controller, manager, or model file modified.**

## 13. Required tests

`AgencyClientsHttpTest.php`: authorization matrix (mirrors Contract 01/07
§6 exactly, at the HTTP layer — Agency owner/active Admin/active Staff
succeed; inactive or cross-Agency members/unrelated actors/Client-side
actors refused);
list-shows-only-this-Agency's-clients (cross-Agency isolation); the View
As entry point correctly starts a Contract 04 session and redirects into
it.

## 14. Acceptance criteria

1. Every §6/§13 authorization case passes.
2. No cross-Agency data leakage in the list view.
3. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not implement SaaS Plan assignment, White Label configuration, or
AgencyRebill configuration UI (Blueprint §28's other sub-surfaces, each
its own future, unscoped slice). Does not add any authorization logic
beyond delegating to Contracts 01/04/07.

## 16. Merge prerequisites

Contract 07 merged and stable (hard gate, per the Phase A Wave 3a/3b
correction — this slice must not begin consuming Slice 7's interface
before that merge). Contracts 01 and 04 also merged (transitively
required by Contract 07 itself).

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 07 | consumes its manager | **Serialize** — hard dependency (Wave 3a → 3b, not concurrent) |
| Contract 08B | different files (controllers for different domains) | Safe concurrent, once both prerequisites are met |

## 18. Implementation prompt

```
You are implementing Slice 8(a) of the V1 architecture migration for the
os-creator1/os-ai repository: the Agency Clients UI, per docs/product/
implementation-contracts/08A-AGENCY-CLIENTS-UI.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contracts 01, 04, and 07 are all merged to main -- hard
   prerequisites, per this repository's own Wave 3a/3b dependency
   ordering (Slice 8a must not begin consuming Slice 7's interface before
   Slice 7 is merged and stable). If any is missing, STOP and report.
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-08a-agency-clients-ui).
4. Re-read the full contract end to end.
5. Inspect the actual merged shape of Contract 01's relationship
   repository, Contract 04's startAgencyView(), and Contract 07's
   provisioning manager -- confirm their real method signatures match
   this contract's assumptions; if not, STOP and report the contradiction.

Implement exactly the scope in this contract: a thin controller/view
layer delegating every authorization decision to the existing manager
classes, with no independent authorization logic of its own. Do NOT
implement SaaS Plans, White Label, or AgencyRebill UI.

After implementing:
- Run the new focused test file, covering the full authorization matrix.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts. Do NOT begin or authorize Contract 08B, 09, or any other
later slice.
```
