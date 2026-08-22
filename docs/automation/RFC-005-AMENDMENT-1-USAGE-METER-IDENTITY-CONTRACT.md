# RFC-005 Amendment 1 — Usage Meter Identity (Decoupling Metering from `PlatformFeature`)

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This contract authorizes drafting a future design document only.**
Merging it authorizes a **separate, later** branch/PR to draft the actual
RFC-005 amendment design document this contract specifies the shape of.
Merging this document does **not** itself write, or authorize writing,
any `app/`, `database/`, `routes/`, `config/`, or `resources/` file; does
not modify any migration, model, repository, manager, gateway, or
entitlement behavior; does not begin RFC-005 Milestone 5 or resume it;
and does not authorize any production implementation of any kind. This
contract's own scope is this one document, drafted on this one branch,
and nothing else.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-amendment-1-usage-meter-identity-contract`,
  in an isolated linked worktree
  (`../rfc-005-amendment-1-usage-meter-identity-contract-worktree`), based
  on `origin/main` at `24fd1730e535d2360bb3a6fef7caf97f3272457c` (merge of
  PR #105, RFC-005 M4 — Additional-Slot Agreement and Add-ons).
- **This amendment is authorized directly by explicit human approval of a
  preceding bounded architecture audit** (a read-only comparison of three
  candidate designs — global multi-dimensional rates, a separate
  `UsageMeter` identity, and an execution-context-aware
  `UsageAuthorizationGateway` — conducted after Draft PR #107's own
  contract discovered that no currently-`Available` `PlatformFeature`
  (`Crm`, `Conversations`, `Automations`) can be safely metered under the
  current global `is_metered`/`EntitlementManager::decide()` coupling).
  The human approved Option B — a separate `UsageMeter` economic identity,
  decoupled from `PlatformFeature`'s own product-entitlement identity —
  as the architecture this amendment will formalize.
- `maximum_correction_rounds: 2` applies to this contract, matching every
  prior RFC-004/RFC-005 contract's own convention. Unconsumed.
- Any path required during this contract's own drafting but absent from
  §2's file scope is a stop-and-report condition. The future design-
  document PR carries its own, separately-defined scope (§3) — this
  contract does not pre-authorize any path for that later PR beyond the
  one file §3 names.
- Drafting this contract makes **zero** application changes. No `app/`,
  `database/`, `routes/`, `config/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by this
  branch — only this one new document (§2).
- **Audit discipline — bounded to this contract's own scope:** the
  preceding architecture audit's own findings (repository facts about
  `platform_feature_usage_classifications`, `business_usage_rates`,
  `business_usage_reservations`, `business_usage_ledger_entries`,
  `UsageWalletManager`'s public API, `EntitlementManager::decide()`, and
  `RealUsageAuthorizationGateway`) are treated as already-established and
  are not re-derived here. This contract itself performs no new
  repository audit — it only locks the shape and boundaries of the
  future design-document work the human has already approved the
  direction of.

---

## 1. Purpose

RFC-005 §14's classification model — one `is_metered` boolean per
`feature_key`, with `EntitlementManager::decide()` unconditionally
consulting that same feature-wide flag as its final authorization step —
cannot correctly represent a feature whose real, variable provider cost
requires narrower-than-feature-wide metering (confirmed by Draft PR #107
for `PlatformFeature::Conversations`, and found to apply identically to
`PlatformFeature::Automations`, since both execute through the same
underlying `SendCampaignSMS` cost mechanism). This amendment's purpose is
to introduce a separate, additive economic/metering identity —
`UsageMeter` — so that `PlatformFeature` can remain a pure, stable
product-entitlement identity forever, while real metering can be scoped
as narrowly or as widely as an individual feature's actual cost
structure requires, without corrupting `EntitlementManager`'s existing
entitlement semantics or fabricating a feature-wide charge that does not
reflect reality.

This contract authorizes drafting the design document that will specify
this amendment in full. **It does not draft that design itself.**

---

## 2. This contract's own exact file scope

Exactly one file: `docs/automation/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY-CONTRACT.md`.

No other file is touched by this branch.

---

## 3. Future design-document identity (locked now, drafted later)

- **Future branch:** `chore/rfc-005-amendment-1-usage-meter-identity-design`,
  created only after this contract is merged, from the then-current
  `main`.
- **Future document:** `docs/rfcs/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY.md`
  — exactly one new file.
- **The future design PR may create that one file only**, unless a
  human-reviewed amendment to *this* contract explicitly authorizes an
  additional path first. Consistent with RFC-005's own original design-
  contract precedent and RFC-004's own two amendments: **no migration,
  model, repository, manager, gateway, controller, route, view, test, or
  configuration file is authorized by the design-document PR under any
  circumstance.** A design document is documentation, not implementation.
- Merging the future design document authorizes drafting a **separate,
  later, explicitly bounded implementation contract** — not
  implementation itself. The same two-step discipline (contract, then
  design, then implementation contract, then implementation) that
  governed RFC-005's own original creation and both RFC-004 amendments
  applies identically here.

---

## 4. RFC-005 sections the future design document must amend

- **§14 (Metered-feature classification and usage authorization) is the
  primary normative section this amendment corrects.** The future design
  document must specify, in full: the `usage_meters` and
  `usage_meter_transitions` schema; how `UsageWalletManager`'s existing
  `reserve()`/`commit()`/`release()`/`setActiveRate()`/`activateMetering()`
  resolve a meter instead of (or in addition to) a whole feature
  internally; and the exact, narrow behavioral change to
  `evaluateCoarseCapacity()`/`RealUsageAuthorizationGateway` that allows a
  meter-owning feature's coarse entitlement check to stop depending on
  wallet health that has nothing to do with the Business's actual
  execution.
- **§11 (`business_usage_rates`) is referenced, not restructured** — a
  rate's own shape (`retail_rate_micro`, `provider_cost_micro`,
  `unit_label`, `rounding_rule`, `currency_id`, immutability, versioning)
  is confirmed sound and is expected to be re-scoped to `meter_key`
  rather than `feature_key`, not redesigned.
- **§36 (Milestone decomposition) requires M5 to be re-scoped** against
  the corrected model once this amendment's own implementation merges —
  M5's own contract must be redrafted from a fresh candidate audit,
  informed by the new `UsageMeter` model, not retrofitted onto Draft PR
  #107.
- **§39 item 11 ("the first actual metered feature(s)") remains open.**
  This amendment does not select a feature, and the future design
  document must not select one either — that remains M5's own decision,
  to be made after this amendment's architecture exists.

---

## 5. Locked architectural constraints the future design document may not reopen

The following were established by the human-approved architecture audit
and are locked inputs to the future design document — it may specify
their exact mechanics in full, but it may not revisit whether they hold:

1. **`PlatformFeature` remains product-entitlement identity** — its enum
   cases, its `Available`/`Planned` availability floor
   (`PlatformFeatureRegistry`), and its role in `EntitlementManager::decide()`'s
   plan/override/toggle/suspension logic are unchanged by this amendment.
2. **`UsageMeter` becomes the economic/metering identity** — a new,
   separate concept, additive to `PlatformFeature`, never replacing it.
3. **One `PlatformFeature` may own zero, one, or many `UsageMeter`s.** A
   feature with no genuine variable cost (e.g. `Crm`) owns none; a
   feature whose cost is uniform may own exactly one; a feature whose
   cost genuinely varies by execution context may own several, each
   scoped as narrowly as its own real economics require.
4. **`usage_meters` and `usage_meter_transitions` are the proposed
   additive schema** — new tables, mirroring `platform_feature_usage_classifications`/
   `platform_feature_usage_classification_transitions`'s own existing,
   already-proven shape, keyed by `meter_key` instead of `feature_key`.
5. **The existing `platform_feature_usage_classifications` table remains
   untouched and inert** — no row is migrated, reinterpreted, or dropped;
   every feature's row there remains exactly as backfilled at M1.
6. **`UsageWalletManager`'s public method signatures remain unchanged.**
   `reserve()`, `commit()`, `release()`, `setActiveRate()`,
   `activateMetering()`, and every other existing public method keep
   their exact current signatures; only their internal resolution target
   (which repository answers "is this metered, what is the active rate")
   may change.
7. **`EntitlementManager::decide()` and `UsageAuthorizationGateway`'s
   signatures remain unchanged.** No new parameter, no execution-context
   object, and no change to the existing nine-key denial surface. No
   tenth denial key is introduced.
8. **Meter-scoped `reserve()` at the actual execution boundary is
   authoritative for whether a specific billable operation may consume
   wallet funds** — decoupled from whether the owning `PlatformFeature`
   is presented as entitled, which remains `decide()`'s own, separate,
   unchanged question.
9. **Historical M1–M4 data and every M3 funding/payment and M4
   additional-slot/add-on flow must remain semantically unchanged** — the
   future design document must demonstrate this explicitly, citing that
   no table this amendment touches carries any real row in production
   today (confirmed by the preceding audit for `business_usage_rates`,
   `business_usage_rate_activations`, `platform_feature_usage_classification_transitions`,
   and `business_usage_reservations`).

---

## 6. Explicit prohibitions

Both this contract's own branch and the future design-document branch
(§3) are bound by the following, without exception:

- No migration file may be created or modified.
- No model, repository, manager, gateway, controller, route, view, or
  form-request file may be created or modified.
- No `EntitlementManager`, `UsageWalletManager`,
  `RealUsageAuthorizationGateway`, or `PlatformFeatureRegistry` behavior
  may be changed.
- No test file may be created or modified.
- No configuration file may be created or modified.
- No numeric rate, meter key, or pilot value of any kind may be
  fabricated or seeded anywhere.
- `docs/automation/AI-AUTONOMY-STATE.json` carries no authorization
  weight for any of this and is not touched.

---

## 7. M5 status

**RFC-005 Milestone 5 remains blocked.** It may not resume — no M5
implementation contract may be drafted, and no M5 candidate feature may
be selected — until **both** this amendment's design document **and**
its own separate implementation are independently approved and merged.
Approval of this contract alone does not unblock M5; approval of the
future design document alone does not unblock M5; only the completed,
merged implementation does.

---

## 8. Disposition of Draft PR #107

**Draft PR #107 remains open, in Draft state, unmerged.** It is
preserved as the exact, audited evidentiary record of why
`PlatformFeature::Conversations` (and, by the same reasoning,
`PlatformFeature::Automations`) cannot be safely metered under the
architecture this amendment corrects. It is not closed, not merged, and
not rewritten by this contract or by the future design document. Once
this amendment's implementation merges, a **fresh** M5 contract — backed
by a fresh candidate audit run against the corrected architecture — is
the correct next step, rather than retrofitting PR #107.

---

## 9. Verification and publication (this document only)

- `git diff origin/main --name-only` from this branch must show exactly
  one path: `docs/automation/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY-CONTRACT.md`.
- `git diff --check` must be clean.
- Commit message: `docs: draft RFC-005 Amendment 1 usage meter identity contract`.
- Push branch `chore/rfc-005-amendment-1-usage-meter-identity-contract`;
  open a Draft PR against `main`, docs-only. Keep it Draft for human
  review. Do not merge. Do not begin drafting the future design document
  (§3) until this contract itself is merged.
