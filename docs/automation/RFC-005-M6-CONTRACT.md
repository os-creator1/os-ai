# RFC-005 Milestone 6 Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

After this exact one-file contract PR is human-reviewed and merged, manual RFC-005 Milestone 6 release-readiness work is directly authorized under this document — no target-marker PR, no inert implementation PR, and no separate authorization PR. See "1. Contract status / authorization model" below.

## Purpose

Close out RFC-005 with its final milestone: a conformance audit against the full RFC-005 architecture (Milestone 1 through Milestone 5 and Amendment 1, all already implemented, corrected, and merged), a deployment guide grounded in what actually exists in this repository today, a final cross-RFC regression pass, and — only after every gate below passes and a human explicitly authorizes it — the annotated release tag. This mirrors RFC-003 Milestone 6's and RFC-004 Milestone 4's identical discipline, per RFC-005 §36 item 6's own instruction.

## RFC-005 §36/§37/§38 exact scope

RFC-005 §36 item 6 defines Milestone 6 as:

> **M6 — Conformance, Deployment, and Tag.** Full conformance matrix, deployment guide, full six-gate regression, post-merge exact-tag-candidate gate, and — only after separate explicit human authorization — the proposed annotated tag `rfc-005-business-usage-billing-and-wallets`.

RFC-005 §37 ("Acceptance criteria"), the RFC-level completion clause, reproduced exactly:

> At the RFC level, acceptance-complete only when: every table in §25 exists and is backfilled where required (per each table's own milestone, §32); `NullUsageAuthorizationGateway` has been replaced; the cross-RFC blocker has been resolved before M4 allocates any slot; M6's own conformance document shows the full aggregate §35 test set passing; and the M6 conformance matrix shows every item in §40 resolved.

RFC-005 §38 ("Release/tag gate"), reproduced exactly:

> Unchanged: no tag before M6; M6's post-merge exact-tag-candidate gate must pass before separate, explicit human authorization of the annotated tag `rfc-005-business-usage-billing-and-wallets`.

This contract preserves that scope exactly: conformance, deployment guide, final regression, and the tag gate — nothing else. It does not reopen, redesign, or re-scope any already-closed RFC-005 milestone or Amendment 1, and it does not begin any next RFC or design module.

## Repository state verified before drafting

Inspected directly, not assumed, before writing this contract — base `origin/main` at `103ed528436c91ffba10648c026c935dd4e6677a` (the RFC-005 Milestone 5 closure merge, PR #132):

### Milestone/Amendment completion, mechanically confirmed

- **M1 — Wallet & Ledger Foundation.** Contract PRs #72 (`chore/rfc-005-m1-contract`), #73 (correction round 2). Implementation PR #74 (`agent/rfc-005-m1`). Schema confirmed live: `business_usage_wallets`, `business_usage_ledger_entries`, `business_usage_reservations`, `business_usage_rates`, `business_usage_rate_activations`, `platform_feature_usage_classifications`, `platform_feature_usage_classification_transitions`.
- **M2 — Budgets, Payer, Billing Contact.** Contract PRs #75, #76, #77 (correction rounds 1–2). Implementation PR #78 (`agent/rfc-005-m2`).
- **M3 — Provider Customers, Instruments, Stripe Integration.** Contract PRs #79, #80, #81 (correction rounds 1–2). Implementation PR #82 (`agent/rfc-005-m3`). Schema confirmed live: `payment_provider_customers`, `business_payment_instruments`, `business_funding_attempts`, `payment_provider_events`. The merged M3 contract's own status header, `READY_FOR_TEST_MODE_IMPLEMENTATION — BLOCKED_FOR_LIVE_CHARGING`, is preserved unchanged by this contract: M3 shipped Stripe test-mode integration; **live production charging remains a distinct, still-blocked readiness question** this contract does not resolve (§9 below).
- **M4 — Additional-Slot Agreement and Add-ons.** Contract PR #103, correction PRs #104/#106. Implementation PR #105 (`agent/rfc-005-m4`). Schema confirmed live: `additional_business_slot_agreements`, `additional_business_slot_agreement_transitions`, `additional_business_slot_renewal_charges`, `additional_business_slot_renewal_charge_transitions`, `business_usage_addon_catalog`, `business_usage_addon_purchases`, `business_usage_addon_purchase_transitions`. The cross-RFC additional-slot allocation authority blocker (RFC-005 §39 item 14) is confirmed resolved via RFC-004 Amendment 1 (`EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()`, PR #98) before M4's own contract was drafted.
- **Amendment 1 — Usage Meter Identity.** Contract/design/slice sequence PRs #108–#125 (contract, design, Slice 1 EXPAND, Slice 2 CUTOVER, Slice 3, three exceptional corrections, two test-alignment corrections). Closure: [`docs/automation/RFC-005-AMENDMENT-1-CLOSURE.md`](RFC-005-AMENDMENT-1-CLOSURE.md), PR #126, merge `3ecff57a53f892edbbee9f01e05d49eb3d989ac5`.
- **M5 — Metered Feature Classification (Conversations pilot).** Contract PR #107 (remediated post-Amendment-1), authorization PRs #127/#129. Implementation PR #128 (`agent/rfc-005-m5`), exceptional correction PR #130, test-alignment correction PR #131. Closure: [`docs/automation/RFC-005-M5-CLOSURE.md`](RFC-005-M5-CLOSURE.md), PR #132, merge `103ed528436c91ffba10648c026c935dd4e6677a` — the exact base this contract branches from.
- **`docs/automation/AI-AUTONOMY-STATE.json`, read directly in full:** `active_pull_request: null`, `implementation_authorized: false`, `next_candidate: null`, `status: "m5_closed_pending_next_locked_contract"`. Idle and non-authorized, confirming no other RFC-005 work is in flight.

### No RFC-005 M6 artifact exists yet

Confirmed by direct repository search: no `docs/automation/RFC-005-M6-CONTRACT.md` or `docs/automation/RFC-005-M6-CONFORMANCE.md` file, no `chore/rfc-005-m6-contract`/`agent/rfc-005-m6` branch, and no M6-referencing pull request exists anywhere in this repository before this contract. Every existing mention of "M6" across `RFC-005-M1-CONTRACT.md` through `RFC-005-M5-CONTRACT.md` and their correction documents consistently and exclusively refers forward to this same conformance/deployment/tag milestone — never a feature scope, and never invented by this contract.

### No RFC-005 tag exists yet

`git ls-remote --tags origin` confirms `rfc-001-business-core`, `rfc-002-opportunity-engine`, `rfc-003-workspace-and-business-account-core`, and `rfc-004-plans-and-business-feature-entitlements` all exist as pushed annotated tags. No `rfc-005-*` tag exists on `origin` or locally.

### Release-readiness precedent, read directly

- `docs/automation/RFC-003-M6-CONTRACT.md` / `RFC-003-M6-CONFORMANCE.md` — RFC-003's own conformance/deployment/tag milestone: two new files only (a deployment guide and a conformance matrix), `agent/rfc-003-m6` release-readiness branch, a **four-command** regression gate (Workspace, Business, Opportunity, full suite — RFC-003 was the third RFC, so its own targeted suite plus the two prior RFCs' suites plus the full-suite gate), `human_only_tag: true`, and the pushed annotated tag `rfc-003-workspace-and-business-account-core`.
- `docs/automation/RFC-004-M4-CONTRACT.md` / `RFC-004-M4-CONFORMANCE.md` — RFC-004's own final milestone (called M4, since RFC-004 planned only four milestones), explicitly stating "Mirrors RFC-003 Milestone 6 exactly": the same two-file shape, `agent/rfc-004-m4` branch, a **five-command** regression gate (Entitlement — RFC-004's own targeted suite, prepended to RFC-003's four), and the pushed annotated tag `rfc-004-plans-and-business-feature-entitlements`.
- `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE-DEPLOYMENT.md` and `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md` — both deployment guides follow one consistent shape (scope, prerequisites, migration order, backfill behavior, database integrity, cache/queue steps only where actually applicable, deployment verification/smoke checks, rollback posture, regression commands, release/tag verification), reused below.

**This contract follows the identical two-generation pattern exactly, extended by exactly one more targeted suite — RFC-005's own.**

### Deriving RFC-005's own "six-gate regression" mechanically

RFC-005 §35 states "Gate shape — unchanged, six commands," without re-enumerating them in the final v1.4 text (the enumeration was compressed across earlier remediation rounds and never fully restated). This contract resolves that compression the same way the M1 contract resolved its own §36 compression: narrowly and conservatively, from direct repository evidence, not invention.

Confirmed by direct inspection, all six non-empty on current `main`:

| Domain | Directories | File count |
|---|---|---|
| Usage (RFC-005's own) | `tests/Unit/Usage`, `tests/Feature/Usage` | 3 + 106 |
| Entitlement (RFC-004) | `tests/Unit/Entitlement`, `tests/Feature/Entitlement` | 1 + 31 |
| Workspace (RFC-003) | `tests/Unit/Workspace`, `tests/Feature/Workspace` | 4 + 53 |
| Business (RFC-001) | `tests/Unit/Business`, `tests/Feature/Business` | 3 + 12 |
| Opportunity (RFC-002) | `tests/Unit/Opportunity`, `tests/Feature/Opportunity` | 10 + 39 |

This is exactly RFC-004 M4's own five domains (Entitlement, Workspace, Business, Opportunity, full suite) with RFC-005's own Usage domain prepended as the sixth — the identical mechanical progression RFC-004 M4 itself used against RFC-003 M6's four. §6 below locks these six commands.

### Open §39 items and the pilot, re-confirmed directly (not resolved by this contract)

- `config/usage_billing.php`'s `conversations_metering.pilot_business_id`/`pilot_country_id`/`pilot_sending_server_id` are each `env(...)`-sourced with no default value set anywhere in the repository — confirmed by direct read. Real activation (`usage:activate-conversations-rate`) remains, per `RFC-005-M5-CLOSURE.md`, a separate explicit human operator action, distinct from both M5 (already closed) and M6 (this contract).
- RFC-005's own top-level "Implementation readiness" section names four gates for **production payment collection** specifically: (1) additional-slot allocation authority — resolved (RFC-004 Amendment 1); (2) RFC-004 catalog-pricing operator surface — resolved (RFC-004 Amendment 2); (3) production tax/VAT legal sufficiency — unresolved, explicitly "a legal/compliance gate... this RFC is not legal advice"; (4) recurring additional-slot provider model — resolved (Option A, §22). **Only item 3 remains open**, and it is scoped by the RFC's own text to production payment collection, not to RFC-005's technical conformance or its tag gate (§38 names only the post-merge exact-tag-candidate regression as the tag precondition — no §39 item is named as a tag precondition anywhere in §36–§38). This contract does not reinterpret that scoping; §4 and §9 below require the future conformance document to preserve it explicitly rather than silently resolve or silently ignore it.
- RFC-005 §39 also lists nine further open product/commercial decisions (exact retail rates, default spend cap, default per-feature limits, auto-recharge defaults/caps, Agency subsidy policy, add-on roster/pricing, safety-limit ceilings, currency scope, Agency rebilling timing) — none resolved by M1–M5, none resolved by this contract, and none required to be resolved by RFC-005 §37's own acceptance-criteria text.

If a future step of this work discovers repository reality conflicting with any claim above, that is a STOP-and-report condition, not something to silently reconcile.

---

## 1. Contract status / authorization model

Before human merge of this contract PR: **PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

After this exact one-file contract PR is human-reviewed and merged: manual RFC-005 Milestone 6 release-readiness work is directly authorized under this document. Milestone 6 uses the same simplified workflow RFC-003 Milestone 6 and RFC-004 Milestone 4 both established:

```
this M6 contract PR → human merge →
one M6 release-readiness branch/PR (conformance + deployment guide) →
human regression/conformance review → human merge →
post-merge exact-tag-candidate regression →
separate, explicit human tag authorization →
annotated tag
```

Do **not** introduce a target-marker PR, an inert implementation PR, a separate authorization PR, or any `AI-AUTONOMY-STATE.json` churn at any point in this sequence — matching RFC-003 Milestone 6 §11's and RFC-004 Milestone 4 §11's identical instruction, and this repository's own confirmed precedent that neither prior final-milestone contract touched `AI-AUTONOMY-STATE.json` at any stage.

Locked:

- `human_only_merge: true`
- `human_only_tag: true`
- `advance_automatically: false`
- `start_automatically_after_contract_merge: false`
- `maximum_correction_rounds: 2`
- `codex_review_required_for_completion: false`
- `automatic_model_handoff_required: false`

No paid model API or usage-credit requirement is authorized at any step. **M6 completion must not automatically start any next RFC or design module.** Any such work remains separately designed and separately authorized (see "10. M6 completion semantics").

---

## 2. M6 release-readiness branch

After this contract is human-merged, use exactly one branch: `agent/rfc-005-m6`, created from the then-current `main` containing the human merge of this exact M6 contract. No release-readiness work of any kind begins before that contract merge.

---

## 3. Exact M6 file scope

The default M6 release-readiness PR may create **exactly two files**, both new:

1. `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md`
2. `docs/automation/RFC-005-M6-CONFORMANCE.md`

**No product implementation change is authorized by this contract.** Explicitly forbidden by default:

- `app/**`, `routes/**`, `database/**`, `config/**`, `resources/**`, `tests/**`, `cron/**`
- any workflow/CI file
- `docs/automation/AI-AUTONOMY-STATE.json`
- RFC-005 itself (`docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`)
- any existing RFC-005 M1–M5 contract or closure document, or the Amendment 1 contract/design/closure documents
- anything belonging to RFC-001, RFC-002, RFC-003, or RFC-004

No schema change, migration, model, controller, request, route, repository, manager, permission, or view change. No test change. No billing, plan, entitlement, wallet, or Stripe behavior change. No feature expansion of any kind — Milestone 6 is a conformance/release milestone, not a feature milestone.

### Gap rule (important)

Milestone 6 is a conformance/release milestone, **not** a hidden bug-fix milestone. If the read-only conformance audit (§4) discovers that an RFC-005 acceptance criterion, an inherited RFC-003/RFC-004 invariant, or a required test class is actually missing, incorrectly implemented, exhibits schema drift, or lacks the coverage the RFC requires:

**STOP.** Record exactly: the RFC/design section, the expected behavior, the concrete repository evidence (or absence of evidence) found, the exact gap, and the exact path(s) that would require correction. Do **not** silently modify product code or tests to close the gap. A human-reviewed amendment to this contract, or a new separately bounded contract, is required before any such code/test correction — the same discipline every prior RFC-003/RFC-004/RFC-005 milestone has followed.

---

## 4. RFC-005 conformance document

Lock the purpose of `docs/automation/RFC-005-M6-CONFORMANCE.md`: it must be an **evidence-based conformance matrix**, not a generic narrative summary — following `RFC-003-M6-CONFORMANCE.md`'s and `RFC-004-M4-CONFORMANCE.md`'s exact proven format (one row/section per RFC concern, each citing concrete evidence, marked `PASS` only when that evidence is concrete). For every row, cite concrete implementation and/or test evidence: a migration filename and its exact constraint, a class and method name, a repository/manager boundary, an enum/registry entry, an event/transition type, a controller/route/permission/view, an exact test class and test method, a mechanical code-search result, relevant M1–M5/Amendment-1 contract/closure evidence (commit SHA, PR number), or actual regression results. **Do not mark anything PASS merely because it sounds correct or because a prior milestone's contract claimed it; do not fabricate test counts, SHAs, PR numbers, or evidence of any kind.** If evidence is insufficient or contradictory, mark the row **BLOCKED / GAP** and stop release progression per the gap rule above.

The audit must cover the full RFC-005 architecture end to end, at minimum:

- Business-scoped wallet foundation and calendar-month period accounting (§10, §12, M1)
- append-only usage ledger and accounting invariants — no cross-Business/cross-currency transfer (§12, §13)
- reservation/commit/release/expire lifecycle and the authoritative committed-amount formula (§13)
- meter identity and the immutable meter-key model (RFC-005 Amendment 1)
- rate catalog, versioning, and activation, including rate-snapshot immutability (§11)
- Business monthly spend cap, per-feature limits, and platform safety limits (§15, M2)
- payer selection and Workspace fallback (§16, M2)
- billing contact (§17.A, M2)
- payment-provider customer/instrument model, including schema-enforced provider consistency (§17.B, M3)
- Stripe test-mode boundary and the explicit live-charging-blocked posture (§20, M3)
- webhook verification, idempotency, claim/lease/exhaustion, disposition, and retention/reconciliation behavior (§21, M3)
- auto-recharge behavior and the centralized after-commit trigger (§19, M3)
- additional Business-slot agreement and payment flow, including cancellation/proration/dunning (§22, M4)
- the payment-verified additional-slot allocation authority and the resolved cross-RFC blocker (§39 item 14, RFC-004 Amendment 1, M4)
- add-on purchase and accounting behavior, including purchase-transition auditing (§18, M4)
- entitlement/usage-wallet separation — `UsageAuthorizationGateway::check()` as the sole seam, `EntitlementManager::decide()` unmodified (§14, RFC-004 §19)
- Conversations first-metered-feature pilot, including its pilot-tuple gating and its M5 correction history (§39 item 11, M5)
- idempotent provider send/accounting behavior for the pilot (M5)
- legacy non-metered feature behavior preservation across every milestone (§37)
- multi-Business/multi-Workspace safety and isolation, including concurrency (§24, §31)
- migration, backfill, and rollback invariants across every milestone (§32)
- RFC-003 compatibility (Workspace/Business tenancy model unchanged)
- RFC-004 compatibility (`EntitlementManager`, `PlatformFeatureRegistry`, plan/entitlement chain unchanged)
- every RFC-005 §37 acceptance criterion
- every §40 contract-coverage-matrix row
- release/tag prerequisites (§38)

**Technical conformance versus production-launch readiness.** The conformance document must explicitly separate two distinct questions and never conflate them:

1. **Technical RFC-005 conformance** — whether the architecture described by RFC-005 §1–§35 actually exists, passes its own test set, and satisfies §37's acceptance criteria. This is what M6 itself proves.
2. **Full production-launch readiness** — a strictly larger question RFC-005's own "Implementation readiness" section already scopes to production payment collection specifically, currently gated by one unresolved item: production tax/VAT legal sufficiency (§39 item 6). **The conformance document must record this item as open and unresolved, must not provide a legal conclusion of any kind, and must not silently mark it resolved.** RFC-005 §36–§38 name only the post-merge exact-tag-candidate regression as a precondition to the tag — no §39 item is named anywhere in §36–§38 as a tag precondition. The conformance document must state this distinction explicitly rather than assume either direction.

The remaining §39 open items (exact retail rates, default spend cap, default per-feature limits, auto-recharge defaults/caps, Agency subsidy policy, add-on roster/pricing, safety-limit ceilings, currency scope, Agency rebilling timing) must each be listed as an explicitly open, unresolved commercial/product decision — never fabricated, never defaulted, never silently marked resolved.

**Conversations pilot activation.** The conformance document must record, as a repository-backed distinction and nothing more: M5 built and tested the pilot mechanism in full; real activation (`usage:activate-conversations-rate`, real `pilot_business_id`/`pilot_country_id`/`pilot_sending_server_id`, a real rate) is a separate, explicit human operator action; **M6 itself does not execute that command, does not activate anything, and does not require prior activation as a conformance or tag precondition** — no section of RFC-005 §36–§38 names pilot activation as a Milestone 6 or tag requirement.

The conformance document must record, for the manual regression run required before the M6 PR merges (§6), the **actual** results the human supplies — never invented counts. It is the document of record for "is RFC-005 actually done," not a checklist filled in from memory of what the RFC says should be true.

---

## 5. Deployment guide

Lock the purpose of `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md`: it must be grounded in the actual repository, following the shape already established by `RFC-001-BUSINESS-CORE-DEPLOYMENT.md`, `RFC-002-OPPORTUNITY-ENGINE-DEPLOYMENT.md`, `RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE-DEPLOYMENT.md`, and `RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md`. **Do not copy any prior document's content — describe RFC-005 as it actually exists.** At minimum cover:

- scope (all of M1 through M5 and Amendment 1, now shipped; explicitly note M3's live-charging-blocked posture as distinct from test-mode readiness)
- environment prerequisites, including required environment variables actually defined in `config/usage_billing.php` and any Stripe configuration keys actually present in the repository — never an invented key
- database migration ordering (the real RFC-005/Amendment-1 migration files, in their real timestamp order)
- upgrade posture and supported deployment ordering
- Business wallet/payer/usage-profile initialization and backfill behavior, described accurately against what `InitializeBusinessUsageProfile` and each milestone's own backfill actually do
- Stripe configuration required for applicable environments, test-mode versus live-mode, stated plainly
- queue/worker and webhook operational requirements actually present (`ProcessPaymentProviderEvent`, `PurgeExpiredWebhookPayloads`, `EvaluateBusinessAutoRecharge`, and any scheduled job actually registered in `app/Console/Kernel.php`)
- encryption/secret requirements actually present (e.g. `payment_provider_events.payload_encrypted`)
- cache/config handling only where actually applicable — verify before writing, do not invent a cache-clear step with no corresponding real config source
- migration verification and post-deploy smoke verification
- payer/payment-instrument considerations
- usage wallet/meter verification steps
- rollback/recovery posture, and an explicit statement of what must **never** be destructively rolled back (backfilled wallet/ledger/payer data, per every milestone's own restrictive-FK, no-hard-delete posture)
- webhook reconciliation/recovery procedure
- separation from the legacy SMS subscription/quota billing stack (`Plan`/`Subscription`/`PaymentMethods`), stated explicitly as untouched
- Conversations M5 pilot activation procedure, documented as **optional and human-operator-only** — the deployment guide may describe the existing `usage:activate-conversations-rate` command and its prerequisites for an operator who chooses to activate the pilot, but must not imply activation is required for deployment, and must not fabricate example values for any pilot configuration key
- explicit production-launch limitations, including the unresolved tax/VAT item (§39 item 6) and every other open §39 item, stated plainly rather than silently omitted
- exact final regression commands (§6 below)
- release/tag verification (§8/§9 below)

**Do not invent artisan commands, classes, migrations, or configuration keys.** Only document what direct inspection during the M6 branch itself confirms exists. Do not add RFC-001/RFC-002/RFC-003/RFC-004 deployment instructions beyond what is necessary to state compatibility — those remain each RFC's own deployment guide's responsibility.

---

## 6. Locked pre-merge regression gates

Verified present in this repository before locking these commands (see "Deriving RFC-005's own six-gate regression mechanically" above): `tests/Unit/Usage`, `tests/Feature/Usage`, `tests/Unit/Entitlement`, `tests/Feature/Entitlement`, `tests/Unit/Workspace`, `tests/Feature/Workspace`, `tests/Unit/Business`, `tests/Feature/Business`, `tests/Unit/Opportunity`, `tests/Feature/Opportunity` all exist and are non-empty. No repository incompatibility was found, so these manual, human-run regression commands are locked as-is, run against the disposable `ultimatesms_testing` database:

```
php artisan test tests/Unit/Usage tests/Feature/Usage
php artisan test tests/Unit/Entitlement tests/Feature/Entitlement
php artisan test tests/Unit/Workspace tests/Feature/Workspace
php artisan test tests/Unit/Business tests/Feature/Business
php artisan test tests/Unit/Opportunity tests/Feature/Opportunity
php artisan test --stop-on-failure
```

Purpose, in order: (1) RFC-005's own targeted Usage regression, proving the full aggregate §35 test set §37 requires; (2) RFC-004 Entitlement regression; (3) RFC-003 Workspace regression; (4) RFC-001 Business regression; (5) RFC-002 Opportunity regression; (6) complete application regression.

Every command must exit successfully and discover a positive test count — a zero/"no tests found" result is a failure, matching the discipline already applied to every prior RFC-003/RFC-004/RFC-005 milestone. **The human runs these locally. Claude must never claim any of them passed if PHP is unavailable in its own environment, and must never invent a test count.** The actual results supplied by the human must be recorded in `docs/automation/RFC-005-M6-CONFORMANCE.md` before the M6 release-readiness PR is considered ready to merge. If one fails, Milestone 6 is blocked until the failure is understood and resolved under valid scope — per the gap rule, a real product/test defect discovered here is reported and separately authorized, not silently patched inside the M6 PR.

---

## 7. M6 release-readiness PR gate

Before the single M6 PR may be merged, require:

- exactly the two authorized docs changed — no more, no fewer
- the conformance matrix is complete, including the explicit technical-conformance-versus-production-launch-readiness distinction (§4) and the pilot-activation distinction (§4, §10)
- no unresolved GAP/BLOCKED item remains in it
- the deployment guide is complete
- all six required regression commands (§6) manually passed, with actual results recorded
- `git diff --check` clean
- exact scope independently verified (`git status --short` / `git diff --name-only` show only the two authorized files)
- no product/test/governance-state drift
- human review

**Human-only merge.** No tag is created before this PR merges.

---

## 8. Post-merge tag-candidate gate

After the M6 release-readiness PR is human-merged, the human must:

1. `git checkout main`
2. `git pull --ff-only`
3. capture the **exact** `main` HEAD SHA — this is the tag candidate
4. confirm the working tree is clean
5. confirm `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md` exists on `main`
6. confirm `docs/automation/RFC-005-M6-CONFORMANCE.md` exists on `main`
7. confirm no unresolved conformance gap remains
8. confirm no unexpected commit or content drift landed between the M6 PR merge and this check

Then require **one final human-run complete regression** against the exact tag-candidate tree:

```
php artisan test --stop-on-failure
```

It must pass. **Do not fabricate its test count.** If it fails: **NO TAG.** If `main` has moved in a way that changes the tested tree before tagging actually happens, the tag candidate must be reevaluated and retested against the new HEAD before proceeding — a stale tested tree is not an acceptable substitute for testing the actual commit about to be tagged.

---

## 9. Annotated tag gate

**NO CLAUDE-AUTOMATIC TAGGING. NO AUTOMATIC TAG PUSH. NO TAG DURING THE M6 IMPLEMENTATION PR. NO LIGHTWEIGHT TAG.**

Tag creation requires an explicit final human authorization **after** every gate in §6, §7, and §8 has passed. Exact tag name, confirmed directly against RFC-005 §36 item 6 and §38: `rfc-005-business-usage-billing-and-wallets`. It must be an **annotated** tag (matching the verified `rfc-001-business-core`/`rfc-002-opportunity-engine`/`rfc-003-workspace-and-business-account-core`/`rfc-004-plans-and-business-feature-entitlements` precedent — all four real annotated tag objects confirmed present on `origin` via `git ls-remote --tags` before writing this contract).

Fixed annotation message, consistent with the prior RFC tags' style:

```
RFC-005 Business Usage Billing and Wallets · Milestone 6 complete
```

This contract documents the eventual commands; **they must not be executed during contract creation or during the M6 document-implementation PR** — only after explicit human authorization following a passing §8 gate:

```
git tag -a rfc-005-business-usage-billing-and-wallets -m "RFC-005 Business Usage Billing and Wallets · Milestone 6 complete" <EXACT_TAG_CANDIDATE_SHA>
git push origin rfc-005-business-usage-billing-and-wallets
```

Tag verification must prove all of the following before Milestone 6 is considered complete:

- the tag exists on the `origin` remote (`git ls-remote --tags origin`)
- it is an annotated tag object, not a lightweight ref (`git cat-file -t <tag>` must report `tag`, not `commit`; `git cat-file -p <tag>` must show a `tagger` line and the message above)
- the tag name is exactly `rfc-005-business-usage-billing-and-wallets`
- the tag resolves to the exact human-approved tag-candidate commit captured in §8
- the annotation message is present and matches

If verification fails on any point, Milestone 6 is not complete.

**No §39 open item, and no pilot-activation state, is a condition of this tag gate.** RFC-005 §36–§38 name exactly one tag precondition — the post-merge exact-tag-candidate regression (§8) — and this contract does not add another. The unresolved production tax/VAT item and every other open §39 item govern **production-launch readiness**, a distinct question from RFC-005's own technical completion and tag, and must not be conflated with either.

---

## 10. M6 completion semantics

Milestone 6 — and RFC-005 as a whole — becomes **COMPLETE** only after:

- this M6 contract is merged
- the M6 conformance/deployment PR is merged
- all required human regression gates (§6, §8) pass
- every §37 acceptance condition is satisfied
- explicit human tag authorization occurs
- the annotated tag is created and pushed
- the annotated tag is verified against the exact intended commit (§9)

The verified annotated tag itself is the immutable RFC-005 release marker. **Do not require another redundant post-tag closure PR by default** — the tag, once verified, is the completion record.

M6 completion must **not** automatically:

- activate the Conversations pilot, execute `usage:activate-conversations-rate`, or fabricate `pilot_business_id`/`pilot_country_id`/`pilot_sending_server_id`/`retail_rate_micro`/`provider_cost_micro`/currency/unit-label values
- make production Stripe charging legally or commercially ready (the tax/VAT item remains open and is not resolved by tagging)
- resolve any RFC-005 §39 open decision
- start RFC-006 or any other next RFC or design module
- select or begin any post-RFC-005 work of any kind
- change `advance_automatically` or `start_automatically_after_contract_merge`
- enable Codex-required completion or an automatic model handoff
- enable automatic merge or automatic tagging

**No automatic next-RFC start.** Any RFC beyond RFC-005 remains separately designed and separately authorized work, requiring its own future design contract, exactly as RFC-005 itself required before any of its own milestones began. Any next project selection remains a separate, explicit human decision, made after RFC-005's tag is verified, not implied by it.

---

## 11. Contract PR itself

This contract-creation branch (`chore/rfc-005-m6-contract`) may change exactly one file: `docs/automation/RFC-005-M6-CONTRACT.md`. Nothing else.

Do not modify `docs/automation/AI-AUTONOMY-STATE.json`. Do not create a target marker. Do not create either of the two future M6 release-readiness files now. Do not create a tag now. Do not execute `usage:activate-conversations-rate` or any other product command.

---

## Forbidden governance / automation (summary)

- No automatic implementation start.
- No automatic merge.
- No force push. No direct push to `main`.
- No paid model API or usage-credit requirement.
- No Codex completion requirement.
- No automatic model handoff.
- No tag of any kind created by this contract or by the eventual M6 documentation PR — only by the explicit, separately-authorized §9 procedure.
- No activation of the Conversations pilot or any other real-environment meter/rate/pilot-tuple mutation.
- No resolution of any RFC-005 §39 open commercial/product decision, and no legal conclusion regarding tax/VAT sufficiency.
- No RFC-006 or other next-RFC implementation, design, or selection.
- `advance_automatically` remains `false`.
- `start_automatically_after_contract_merge` remains `false`.
