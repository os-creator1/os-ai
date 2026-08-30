# RFC-005 Milestone 6 Contract (Post-Remediation Refresh)

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

After this exact one-file contract PR is human-reviewed and merged, manual RFC-005 Milestone 6 release-readiness work is directly authorized under this document — no target-marker PR, no inert implementation PR, and no separate authorization PR. See "1. Contract status / authorization model" below for the explicit reasoning behind that choice, re-derived against the current Remediation #6/#7 governance precedent rather than assumed by inheritance from the superseded document below.

## Historical note — this document supersedes an earlier, now-stale draft

`docs/automation/RFC-005-M6-CONTRACT.md` previously held a different document, merged via PR [#133](https://github.com/os-creator1/os-ai/pull/133) (`chore/rfc-005-m6-contract`, merge `31b16c55c9b2a3cc7fe1a8c34aa738ae348dddb4`), drafted against base `main` = `103ed528436c91ffba10648c026c935dd4e6677a` (the RFC-005 Milestone 5 closure, PR #132). **That document is entirely superseded by this one and is not current authorization for anything.** It never produced its own release-readiness PR: its own "Gap rule" (its own §3) required that any conformance gap discovered during the first real M6 attempt be reported and separately corrected rather than silently patched — and the very first static conformance pass against it *did* discover a genuine gap (reservation-admission controls designed but never wired into `UsageWalletManager::reserve()`). That discovery correctly froze M6 and triggered a sequence of **nine** separately-authorized post-M5 remediation efforts (self-numbered 1 through 7 in their own governance text, with three of the nine — Reconciliation-Race Correction, Funding Confirmation Concurrency Correction, and Admin Usage Billing Surface — inserted between #4 and #6 without renumbering, "to avoid disturbing existing cross-references," per those documents' own stated convention; this contract counts the nine distinct documents mechanically rather than propagating the highest self-declared label), each with its own contract, implementation, and (where applicable) closure documents, culminating in RFC-005 Remediation #7's own closure (PR #162, merge `6627e1abad1c49456ef5d2cafc0a1bfd9891b24e` — this document's own base). **Note:** that closure document's own prose summarized this same sequence as "eight" remediations — an undercount by one, made the same way, by anchoring on the final item's own self-declared "#7" label rather than counting the full list. This contract's own count of nine, re-derived mechanically against the table below, is the one to treat as authoritative going forward. The old document's repository-state evidence, its six-gate regression-gate file counts, and its exact PR/SHA citations are now years-of-development stale relative to that work; this document re-derives every one of those facts from scratch against current `origin/main`, per the task that produced it. The old document's *structure and precedent reasoning* (mirroring RFC-003 Milestone 6 and RFC-004 Milestone 4) remain sound and are preserved deliberately.

## Purpose

Close out RFC-005 with its final milestone: a conformance audit against the full, now-corrected RFC-005 architecture (Milestones 1 through 5, Amendment 1, and all nine post-M5 remediations, all already implemented, corrected, and merged), a deployment guide grounded in what actually exists in this repository today, a final cross-RFC regression pass, and — only after every gate below passes and a human explicitly authorizes it — the annotated release tag. This mirrors RFC-003 Milestone 6's and RFC-004 Milestone 4's identical discipline, per RFC-005 §36 item 6's own instruction.

## RFC-005 §36/§37/§38 exact scope

Re-read directly, this pass, from `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` — unchanged since the superseded document quoted it; the RFC design document itself was never touched by any post-M5 remediation, only the separate governance documents under `docs/automation/` were.

RFC-005 §36 item 6 defines Milestone 6 as:

> **M6 — Conformance, Deployment, and Tag.** Full conformance matrix, deployment guide, full six-gate regression, post-merge exact-tag-candidate gate, and — only after separate explicit human authorization — the proposed annotated tag `rfc-005-business-usage-billing-and-wallets`.

RFC-005 §37 ("Acceptance criteria"), the RFC-level completion clause, reproduced exactly:

> At the RFC level, acceptance-complete only when: every table in §25 exists and is backfilled where required (per each table's own milestone, §32); `NullUsageAuthorizationGateway` has been replaced; the cross-RFC blocker has been resolved before M4 allocates any slot; M6's own conformance document shows the full aggregate §35 test set passing; and the M6 conformance matrix shows every item in §40 resolved.

RFC-005 §38 ("Release/tag gate"), reproduced exactly:

> Unchanged: no tag before M6; M6's post-merge exact-tag-candidate gate must pass before separate, explicit human authorization of the annotated tag `rfc-005-business-usage-billing-and-wallets`.

This contract preserves that scope exactly: conformance, deployment guide, final regression, and the tag gate — nothing else. It does not reopen, redesign, or re-scope any already-closed RFC-005 milestone, Amendment 1, or remediation, and it does not begin any next RFC or design module.

---

## Repository state verified before drafting

Inspected directly, this pass, against `origin/main` at `6627e1abad1c49456ef5d2cafc0a1bfd9891b24e` (PR #162's merge, closing RFC-005 Remediation #7) — the exact base this contract branches from.

### Milestone / Amendment / Remediation completion, mechanically re-confirmed

**M1–M5 and Amendment 1** (unchanged from the superseded contract's own already-verified evidence, re-cited here rather than re-derived, since none of the nine remediations below touched their own governance history):

- **M1 — Wallet & Ledger Foundation.** Contract PRs #72–#73. Implementation PR #74 (`agent/rfc-005-m1`). Schema: `business_usage_wallets`, `business_usage_rates`, `business_usage_rate_activations`, `platform_feature_usage_classifications`, `platform_feature_usage_classification_transitions`, `business_usage_reservations`, `business_usage_ledger_entries`.
- **M2 — Budgets, Payer, Billing Contact.** Contract PRs #75–#77. Implementation PR #78 (`agent/rfc-005-m2`). Schema: `business_feature_usage_limits`, `platform_feature_usage_safety_limits`, `business_usage_limit_transitions`, `business_usage_wallet_billing_status_transitions`, `business_billing_contacts`, `business_payer_assignments`, `business_payer_transitions`.
- **M3 — Provider Customers, Instruments, Stripe Integration.** Contract PRs #79–#81. Implementation PR #82 (`agent/rfc-005-m3`). Schema: `payment_provider_customers`, `business_payment_instruments`, `business_funding_attempts`, `business_funding_attempt_transitions`, `payment_provider_events`. **Status header preserved: `READY_FOR_TEST_MODE_IMPLEMENTATION — BLOCKED_FOR_LIVE_CHARGING`** — live production Stripe charging remains a distinct, still-blocked readiness question no subsequent milestone or remediation has resolved.
- **M4 — Additional-Slot Agreement and Add-ons.** Contract PR #103, correction PRs #104/#106. Implementation PR #105 (`agent/rfc-005-m4`). Schema: `additional_business_slot_agreements`, `additional_business_slot_agreement_transitions`, `additional_business_slot_renewal_charges`, `additional_business_slot_renewal_charge_transitions`, `business_usage_addon_catalog` (zero seeded rows, by design), `business_usage_addon_purchases`, `business_usage_addon_purchase_transitions`. The cross-RFC additional-slot allocation blocker (§39 item 14) confirmed resolved via RFC-004 Amendment 1 before M4's own contract was drafted.
- **Amendment 1 — Usage Meter Identity.** Contract/design/slice sequence PRs #108–#125 (Slice 1 EXPAND, Slice 2 CUTOVER, Slice 3 CONTRACT + 3 exceptional corrections + 2 test-alignment corrections). Closure PR #126, merge `3ecff57a53f892edbbee9f01e05d49eb3d989ac5`. Schema: adds `usage_meters`, `usage_meter_transitions`; adds then later drops `feature_key` from `business_usage_rates`/`business_usage_rate_activations` in favor of `meter_key`, widened to `NOT NULL` on rates/activations/reservations in Slice 3 (`business_usage_ledger_entries.meter_key` deliberately remains nullable).
- **M5 — Metered Feature Classification (Conversations pilot).** Contract PR #107, authorization PRs #127/#129. Implementation PR #128 (`agent/rfc-005-m5`), exceptional-correction PR #130, test-alignment-correction PR #131. Closure PR #132, merge `103ed528436c91ffba10648c026c935dd4e6677a`.

**The nine post-M5 remediations — independently re-confirmed this pass via `git show --no-patch --format='%H %P'` against every cited SHA below, not assumed from any prior document's prose:**

| # | Remediation | Contract PR(s) | Dedicated authorization PR | Implementation PR | Closure PR | Final implementation merge SHA |
|---|---|---|---|---|---|---|
| 1 | Reservation Admission Correction | #134 (merge `208c3da`) | none | #135 (`agent/rfc-005-reservation-admission-correction`) | — | `311bf0bf08cd4bf6c0939aec0cdf45962c4bb9de` |
| 2 | Funding Provider-Flow Correction | #136 (merge `a23e501`) | none | #137 (`agent/rfc-005-funding-provider-flow-correction`) | — | `1eba17ae4112a1e5e832627d44c185a0ee3f56ca` |
| 3 | Receipt Boundary Correction | #138 (merge `bd3d289`) | none | #139 (`agent/rfc-005-receipt-boundary-correction`) | — | `ae0aba36057360eb1149ef980beeb90f9d2d250f` |
| 4 | Job/Event Dispatch Completion Correction | #140 (merge `d50979e`) | none | #141 (`agent/rfc-005-job-event-dispatch-completion-correction`) | — | `6a0456b5606113eca8f9b3dce12af7d97d0fae38` |
| 5 | Reconciliation-Race Correction | #142 (merge `1d733a2`) | none | #143 (`agent/rfc-005-reconciliation-race-correction`) | — | `ccee46b6197dfd70980091cae97ecb283a52aed7` |
| 6 | Funding Confirmation Concurrency Correction | #144 (merge `70ceedb`) + exceptional correction #145 (merge `f08d185`) | none | #146 (`agent/rfc-005-funding-confirmation-concurrency-correction`) | — | `376fda52ecf449bbb622d2dd0ec40f4411587cc5` |
| 7 | Admin Usage Billing Surface | #147 (merge `92cb208`) | none | #148 (`agent/rfc-005-admin-usage-billing-surface`) | — | `a8d2a0f9c03f8295262a9ec7b36374b4b0437294` |
| 8 | Provider Refund/Dispute Outcome Handling | #149→#150→#151→#152 (contract corrected in place across 2 ordinary + several exceptional correction rounds) | **#154**, head `e6792e625215e823a77425a0283e44dc37cbcd3a`, merge `f10cc6922de3525f7f1118880f3c6212de4d99d6` — independently re-confirmed this pass via `git show`: pinned PR #153's exact implementation target and set `implementation_authorized: true` | #153 (`agent/rfc-005-provider-refund-dispute-outcome-handling`) | **#155**, merge `2ada6872ed2689361100c98f1ff38ca7843f6f89` | `ea88967af83897bcdf207f05e34c21e2177bcaba` |
| 9 | RFC-005 §35 Test-Coverage Completion | #156 (merge `cad1b2811d866ef5fadd04b8727eddc60c6ab32f`) | **#157**, merge `5658ed57aa18ae0b2cca20ca01e06b04d308a5f9`, plus two subsequent branch-hygiene correction PRs — #158 (merge `1115e899bff527ede087363de7fa43914afc9f61`) and clean-target-binding #160 (merge `b1ad5e92ede7de147cb3283038479f7df26a87e7`) — required after an accidental temporary path was created and deleted on the originally authorized branch; those two corrections were caused by that branch incident, not by the existence of the authorization PR itself | #159 (`agent/rfc-005-test-coverage-completion-v2`), final head `d44f919814ddce9caed126f3e93081469d20ad5b` | **#162**, merge `6627e1abad1c49456ef5d2cafc0a1bfd9891b24e` | `c0f3f3a19fecb13e1a72390f26033c0c9873a4c3` |

**Corrected classification, mechanically re-verified this pass via direct `git show` against every PR above (superseding this document's own prior draft, which incorrectly stated Remediation 8 used no separate authorization PR):** Remediations 1 through 7 used no dedicated implementation-authorization PR — a contract merges, a human gives the go-ahead informally, and a fresh implementation branch is opened and merged directly. **Remediation 8 used a dedicated authorization PR, #154**, whose own branch name (`chore/rfc-005-provider-refund-dispute-outcome-implementation-authorization`) states its purpose explicitly, confirmed by direct inspection to pin PR #153's exact branch/starting head and flip `implementation_authorized: true` before any of PR #152's authorized implementation corrections were applied. **Remediation 9 also used a dedicated authorization PR, #157.** So the correct count is **7 of 9 remediations used no dedicated authorization PR, while the two most recent, largest remediations (8 and 9) both used one.** Remediation 9's own two branch-hygiene correction PRs (#158, #160) were caused by an accidental temporary path created and deleted on its originally authorized branch — a hygiene incident, not a consequence of having an authorization PR at all; Remediation 8's own authorization PR (#154) produced no such incident and no correction PRs of any kind.

(Remediation numbering above follows each document's own self-declared sequence position, exactly as its own governance text states — items 5 and 6 in the table are drafted and merged in a different order than their own contract text numbers them, a pre-existing naming quirk in the source documents this contract does not attempt to renumber or repair.)

**Cumulative schema delta introduced by the nine remediations, beyond M1–M5/Amendment 1:**

- Remediation 3 (Receipt Boundary): new table `business_billing_receipts` — the first real implementation of the receipt table M3 had deferred.
- Remediation 7 (Admin Usage Billing Surface): adds a nullable `reason` column to `business_funding_attempt_transitions` (supports admin-supplied retry justification).
- Remediation 8 (Provider Refund/Dispute Outcome Handling): 8 migrations — independently-unique `provider_payment_intent_reference`/`provider_charge_reference` columns and a backfill on `business_funding_attempts`; a new `provider_refund_mismatch` `BillingStatusTransitionSource` enum case (schema-adjacent, not a migration); a `refundable_paid_available_micro` counter on `business_usage_wallets`; a `paid_attributable_amount_micro` snapshot on `business_usage_reservations`; a `refundable_paid_delta_micro` audit field on `business_usage_ledger_entries`; widened retry/lease indexes and `normalized_outcome`/related columns on `payment_provider_events`.
- Remediations 1, 2, 4, 5, 6, 9: **confirmed zero schema/migration change** — pure code, job-wiring, or test corrections.

**Full current RFC-005 migration inventory — 49 files, independently re-listed this pass via direct directory search** (`ls database/migrations/`, filtered to every RFC-005-owned table): the 7 M1 tables + 2 M1 backfills, the 7 M2 tables + 1 M2 backfill, the 5 M3 tables, the 7 M4 tables, the 7 Amendment-1 migrations (2 new tables + 5 column-shape changes across 3 slices), 1 Receipt Boundary table, 1 Admin Usage Billing Surface column addition, and 8 Provider Refund/Dispute Outcome Handling migrations. This count and file list must be independently re-confirmed by the M6 conformance document itself against `origin/main` at the time that document is written, not copied from this contract without re-verification.

### `docs/automation/AI-AUTONOMY-STATE.json`, read directly in full

`active_pull_request: null`, `head_branch: "none"`, `implementation_authorized: false`, `status: "remediation_7_closed_pending_m6_contract_reaudit"`, `next_candidate: "RFC-005 M6 contract fresh audit/replacement — not authorized by this state"`, `contract_source: null`, `completed_pull_request: 159`, `completed_product_head_sha: "d44f919814ddce9caed126f3e93081469d20ad5b"`, `completed_merge_commit_sha: "c0f3f3a19fecb13e1a72390f26033c0c9873a4c3"`. Idle and non-authorized, confirming no other RFC-005 work is in flight and that this contract's own eventual merge is exactly what this state file is waiting for. **This contract does not modify this file** — matching the superseded contract's own instruction and every one of the nine remediation contracts' own confirmed practice of never touching it during implementation.

### The stale, zero-commit `agent/rfc-005-m6` branch — confirmed and addressed

A local-only branch named `agent/rfc-005-m6` exists in this repository's shared git object store, confirmed this pass via `git rev-parse`/`git log` to point at exactly `31b16c55c9b2a3cc7fe1a8c34aa738ae348dddb4` — the superseded M6 contract's own merge commit (PR #133) — with **zero** commits of its own beyond that merge, and confirmed via `git ls-remote --heads origin` to have **never been pushed to `origin`**. This is exactly the "discard/reset the zero-commit old `agent/rfc-005-m6`, recreate it fresh from corrected `origin/main`" instruction the Funding Provider-Flow Correction Contract itself recorded as the required action once all pre-M6 corrections land. **§2 below requires the eventual M6 release-readiness branch to be created fresh from post-merge `main`, under this exact same name, never reusing or rebasing this stale local ref.** Since the stale ref was never pushed, it carries no shared history any other clone could depend on; deleting or ignoring it locally is safe and is the eventual M6 implementer's own responsibility, not something this contract performs.

### No RFC-005 tag exists yet

`git tag -l` and `git ls-remote --tags origin`, re-run this pass, confirm exactly four annotated tags on `origin`: `rfc-002-opportunity-engine`, `rfc-003-workspace-and-business-account-core`, `rfc-004-plans-and-business-feature-entitlements`, and — unchanged from the superseded contract's own finding — **`rfc-001-business-core` still exists only as a local annotated tag object in this clone, never pushed to `origin`.** This contract does not fabricate remote presence for it and does not push or repair it. **No `rfc-005-*` tag exists on `origin` or locally.**

### Release-readiness precedent, re-confirmed directly

- `docs/automation/RFC-003-M6-CONTRACT.md` / `RFC-003-M6-CONFORMANCE.md` and `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE-DEPLOYMENT.md` — RFC-003's own conformance/deployment/tag milestone: two new files, `agent/rfc-003-m6` branch, a four-command regression gate, `human_only_tag: true`, no separate authorization PR, pushed tag `rfc-003-workspace-and-business-account-core`.
- `docs/automation/RFC-004-M4-CONTRACT.md` / `RFC-004-M4-CONFORMANCE.md` and `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md` — RFC-004's own final milestone, explicitly "Mirrors RFC-003 Milestone 6 exactly": the same two-file shape, `agent/rfc-004-m4` branch, a five-command regression gate, no separate authorization PR, pushed tag `rfc-004-plans-and-business-feature-entitlements`.
- **Confirmed this pass: `docs/automation/RFC-005-M6-CONFORMANCE.md` and `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md` do not exist anywhere in this repository.** No release-readiness work of any kind has ever been performed under the superseded contract or since.

**This contract follows the identical two-file, no-separate-authorization pattern exactly, extended by exactly one more targeted suite — RFC-005's own — same as the superseded contract already established; only the underlying facts below are refreshed.**

### Deriving RFC-005's own six-gate regression mechanically, re-confirmed against current `main`

Confirmed by direct inspection this pass, all six domains non-empty on current `main`, method counts via `grep -rc "public function test_"` (a mechanical proxy the eventual M6 conformance document must re-confirm with an actual `php artisan test` run, not rely on this static count — see the caveat below the table):

| Domain | Directories | File count | Method count (mechanical, to be re-confirmed by real test run) |
|---|---|---|---|
| Usage (RFC-005's own) | `tests/Unit/Usage`, `tests/Feature/Usage` | 4 + 128 | 919 |
| Entitlement (RFC-004) | `tests/Unit/Entitlement`, `tests/Feature/Entitlement` | 1 + 30 | 292 |
| Workspace (RFC-003) | `tests/Unit/Workspace`, `tests/Feature/Workspace` | 4 + 37 | 836 |
| Business (RFC-001) | `tests/Unit/Business`, `tests/Feature/Business` | 3 + 11 | 251 |
| Opportunity (RFC-002) | `tests/Unit/Opportunity`, `tests/Feature/Opportunity` | 10 + 37 | 1090 |

This is exactly RFC-004 M4's own five domains (Entitlement, Workspace, Business, Opportunity, full suite) with RFC-005's own Usage domain prepended as the sixth — the identical mechanical progression RFC-004 M4 itself used against RFC-003 M6's four, unchanged in shape since the superseded contract. **§6 below locks these six commands; the file/method counts above are current-state evidence for the eventual M6 implementer to re-confirm, not a promise this contract itself re-verifies at implementation time.**

**The 3517-versus-3514 discrepancy, mechanically resolved this pass — not left as an unexplained residue.** A repository-wide `grep -c "public function test_"` across every `*Test.php` file (the exact suffix `phpunit.xml` itself uses for test discovery, `<directory suffix="Test.php">`) returns **3517** declared methods, across 295 files. The most recently recorded actual `php artisan test --stop-on-failure` run (Remediation 9's own closure evidence) reported **3514 passed**. Re-run this pass via `php artisan test --list-tests` against current `main` and cross-checked method-by-method, method-per-class, against the same 295-file grep inventory, the gap is fully and exactly explained by three independent, verified mechanisms — no residue remains:

1. **57 declared methods, across 6 files, are excluded from execution entirely** by `phpunit.xml`'s own `<groups><exclude>` block (`historical-m1a`, `workspace-pre-enforcement`, `workspace-enforcement`), matched against each file's own class-level `#[Group(...)]` attribute — confirmed directly via `php artisan test --list-tests`, which lists zero methods for any of these six classes: `BackfillWorkspacesCommandTest` (9, `#[Group('historical-m1a')]`), `WorkspaceBackfillMigrationTest` (6, `historical-m1a`), `WorkspaceBackfillV1ConcurrencyTest` (2, `historical-m1a`), `WorkspaceBackfillV1Test` (24, `historical-m1a`), `WorkspaceEnforcementMigrationTest` (13, `workspace-enforcement`), `WorkspaceManagerPreEnforcementTest` (3, `workspace-pre-enforcement`). These are deliberately excluded, pre-existing historical/pre-enforcement regression fixtures, openly declared, not a hidden gap.
2. **Exactly one executed, discovered test method uses PHPUnit's own bare `test`-prefix convention without an underscore**, which grep's `public function test_` pattern (requiring a literal underscore) structurally cannot match: `tests/Unit/ExampleTest.php::testBasicTest()` — Laravel's own unmodified default scaffold test, confirmed present in the actual `--list-tests` output, contributing exactly 1 executed case grep never counted.
3. **Ordinary `#[DataProvider]` fan-out** on a number of the remaining, correctly-counted, non-excluded `test_`-prefixed methods causes each such method to execute as more than one case — for example `PlatformThemePresetAuthorizationTest::test_guest_cannot_access_protected_routes` alone executes as 2 cases (`#0`, `#1`), confirmed directly in `--list-tests`' own output. This is the ordinary, expected reason a "number of declared methods" and a "number of executed cases" are different metrics in any PHPUnit suite that uses data providers at all — not a defect or an omission.

**The exact reconciling arithmetic, mechanically verified:** 3517 (declared `test_`-prefixed methods) − 57 (excluded by the three named groups, 0 cases each) = 3460 declared, executing `test_`-prefixed methods. Those 3460, once every data provider's own fan-out is applied, execute as exactly 3513 cases. 3513 + 1 (`testBasicTest()`, mechanism 2, which was never part of the 3517 base to begin with, since it lacks the underscore grep requires) = **3514 — exactly matching the authoritative `php artisan test` run, with zero unexplained residue.** No genuinely mysterious or unaccounted-for method exists; the apparent near-equality of 3517 and 3514 is coincidental — a 57-method exclusion very nearly offset by ordinary data-provider fan-out plus one naming-convention outlier, not a literal "three specific methods" gap. **The eventual M6 conformance document must record the actual `php artisan test` output as its own §37 evidence — a static grep count is supporting inventory only, never authoritative, exactly because of the three independent mechanisms shown above.**

### Open §39 items and the pilot, re-confirmed directly against current code (not resolved by this contract)

- `config/usage_billing.php`'s `conversations_metering.pilot_business_id`/`pilot_country_id`/`pilot_sending_server_id` are each `env(...)`-sourced with no default value set anywhere in the repository — re-confirmed by direct read this pass. Real activation (`usage:activate-conversations-rate`, confirmed still present at `app/Console/Commands/ActivateConversationsUsageRate.php`) remains a separate explicit human operator action, distinct from M5 (closed), every remediation (closed), and M6 (this contract).
- RFC-005's own "Implementation readiness" section names four gates for **production payment collection** specifically: (1) additional-slot allocation authority — resolved (RFC-004 Amendment 1); (2) RFC-004 catalog-pricing operator surface — resolved (RFC-004 Amendment 2); (3) production tax/VAT legal sufficiency — **unresolved**, explicitly "a legal/compliance gate... this RFC is not legal advice"; (4) recurring additional-slot provider model — resolved (Option A). **Only item 3 remains open**, scoped by the RFC's own text to production payment collection, never to RFC-005's technical conformance or its tag gate.
- RFC-005 §39's remaining ten open product/commercial decisions (exact retail rates — item 1; default Business monthly spend cap — item 2; default per-feature limits — item 3; auto-recharge default threshold — item 4; owner/operator Agency subsidy — item 5; Agency client rebilling timing — item 7; v1 add-on roster/pricing — item 8; per-feature platform safety-limit ceilings — item 9; currency/multi-currency scope — item 10; default monthly auto-recharge cap — item 12) — **re-confirmed still unresolved** by any of M1–M5, any of the nine remediations, or this contract, and none required to be resolved by RFC-005 §37's own acceptance-criteria text.
- **§39 item 13 — additional-slot agreement `payment_lapsed` grandfathered-capacity revocation policy — re-confirmed still open.** M4 implements every RFC-defined lapse/recovery mechanism (`payment_lapsed`, `payment_lapsed_at`, stopped automatic renewals, manual/owner recovery, `payment_lapsed_cleared_at`, forward-only `next_renewal_at` recomputation, confirmed unchanged by any remediation) but performs **zero** Business-slot revocation of any kind. Not a hidden technical or tag blocker — no section of RFC-005 §36–§38 names it as an M6 or tag precondition.
- **§39 item 11 — the first actual metered feature — remains resolved** (Conversations pilot, M5), with real activation still a distinct, unexercised human operator action, unchanged by any remediation.
- **§39 item 14 — the cross-RFC additional-slot allocation blocker — remains resolved** (RFC-004 Amendment 1), unchanged by any remediation.
- One additional, non-§39, explicitly-disclosed-and-deferred item surfaced by the remediation sequence itself, carried forward here rather than silently dropped: a low-balance-notification-after-successful-auto-recharge timing observation, first disclosed by the Job/Event Dispatch Completion Correction Contract, explicitly classified there as non-blocking and deferred for a separate future human decision, and consistently re-referenced-and-declined-to-absorb by every later remediation contract through Provider Refund/Dispute Outcome Handling. **This contract does not resolve it and the conformance document must record it as still open, not silently omitted.**

If a future step of this work discovers repository reality conflicting with any claim above, that is a STOP-and-report condition, not something to silently reconcile.

---

## 1. Contract status / authorization model

Before human merge of this contract PR: **PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

After this exact one-file contract PR is human-reviewed and merged: manual RFC-005 Milestone 6 release-readiness work is directly authorized under this document. **No separate implementation-authorization PR is required.**

**This choice is re-derived this pass, explicitly and mechanically, against the corrected remediation-authorization history in the table above — not inherited blindly from the superseded contract's own identical conclusion, and not from an earlier, incorrect draft of this same section:**

- **Remediations 1 through 7 (of 9) used no dedicated implementation-authorization PR** — a contract merges, a human gives the go-ahead informally, and a fresh implementation branch is opened and merged directly. This is the dominant convention across most of RFC-005's own post-M5 governance history.
- **Remediation 8 (Provider Refund/Dispute Outcome Handling) used a dedicated authorization PR, #154**, independently re-confirmed this pass (head `e6792e625215e823a77425a0283e44dc37cbcd3a`, merge `f10cc6922de3525f7f1118880f3c6212de4d99d6`), which pinned implementation PR #153's exact branch/starting head and set `implementation_authorized: true` before any of PR #152's authorized corrections were applied. This produced no branch-hygiene incident and no correction PRs of any kind.
- **Remediation 9 (Test-Coverage-Completion) also used a dedicated authorization PR, #157** (merge `5658ed57aa18ae0b2cca20ca01e06b04d308a5f9`). Its own two subsequent correction PRs (#158, #160) were caused by an accidental temporary path created and deleted on the originally authorized implementation branch — a branch-hygiene incident distinct from, and not caused by, the authorization PR's own existence. Remediation 8's own authorization PR shows a dedicated authorization step does not inherently produce that kind of incident.
- **The correct count is therefore 7 of 9 remediations used no dedicated authorization PR, while the two most recent, largest remediations (8 and 9) both used one.** Read plainly, RFC-005's own governance history shows no single, uniform convention on this question — it has used both patterns, with the two most recent, most substantial remediations both choosing the heavier one.
- **The superseded M6 contract itself already locked "no separate authorization PR"** for M6 specifically (its own §1: *"Do not introduce a target-marker PR, an inert implementation PR, a separate authorization PR"*), citing RFC-003 M6's and RFC-004 M4's own identical, successful precedent for exactly this kind of terminal, two-file, conformance-and-deployment-only milestone.
- **The structural reason Remediations 8 and 9 chose a dedicated authorization PR does not apply to M6.** Both of those remediations locked an exact, narrow production/test method-count-and-file-count contract against a long-lived implementation branch, where pinning the exact authorized starting SHA in a separate, reviewable document had real value (and, for Remediation 9, additional value in precisely re-pinning a *replacement* branch after its own hygiene incident). M6's own release-readiness work is a single, bounded, two-new-file documentation PR, containing zero product/schema/test changes, created fresh, once, directly from post-merge `main` — there is no pre-existing branch to re-pin, no production method-count lock to enforce across a correction round, and no history of hygiene incidents on any of the three prior terminal-milestone contracts (M6 itself, RFC-003 M6, RFC-004 M4) that would justify the extra step.

**Conclusion: M6 uses the simplified, no-dedicated-authorization-PR pattern — the pattern seven of nine remediations and both directly-analogous terminal-milestone precedents (RFC-003 M6, RFC-004 M4) already use, and one this contract's own two-document, zero-product-change scope does not need the heavier pattern to protect.** Milestone 6 follows the same workflow those two established:

```
this M6 contract PR → human merge →
one M6 release-readiness branch/PR (conformance + deployment guide) →
human regression/conformance review → human merge →
post-merge exact-tag-candidate regression →
separate, explicit human tag authorization →
annotated tag creation and verification →
one final governance-only RFC-005 closure/state PR (§10)
```

Do **not** introduce a target-marker PR, an inert implementation PR, a separate implementation-authorization PR, or any `AI-AUTONOMY-STATE.json` churn at any point before the final closure/state PR named in §10 — that closure PR is the one and only point in this entire sequence where `AI-AUTONOMY-STATE.json` is touched, and it happens only after the tag is created and verified, never before.

**This contract's own eventual human merge is manual authority only — it is not, and cannot become, automatic authorization.** `docs/automation/AI-AUTONOMY-STATE.json` remains, throughout this entire sequence, in its current idle, non-authorizing state (`implementation_authorized: false`, `active_pull_request: null`) — this contract does not flip it, and nothing in this document causes any automation to start M6 work. Every step above (the release-readiness branch, its PR, the tag-candidate regression, the tag itself, and the final closure PR) requires its own explicit human action; none is triggered by this contract's merge alone.

Locked:

- `human_only_merge: true`
- `human_only_tag: true`
- `require_exact_scope: true`
- `advance_automatically: false`
- `start_automatically_after_contract_merge: false`
- `maximum_correction_rounds: 2`
- `codex_review_required_for_completion: false`
- `automatic_model_handoff_required: false`

No paid model API or usage-credit requirement is authorized at any step. **M6 completion must not automatically start any next RFC or design module.** Any such work remains separately designed and separately authorized (see "10. M6 completion semantics").

---

## 2. M6 release-readiness branch

After this contract is human-merged, use exactly one branch: `agent/rfc-005-m6`, created **fresh from the then-current `main` containing the human merge of this exact M6 contract**. **Do not reuse, rebase, or build on the existing stale, zero-commit, never-pushed local branch of the same name** (confirmed above to point only at the superseded contract's own merge commit, `31b16c55c9b2a3cc7fe1a8c34aa738ae348dddb4`) — delete or ignore that local ref and branch anew. No release-readiness work of any kind begins before this contract's own merge.

---

## 3. Exact M6 file scope

The default M6 release-readiness PR may create **exactly two files, both new**:

1. `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md`
2. `docs/automation/RFC-005-M6-CONFORMANCE.md`

**No product implementation change is authorized by this contract.** Explicitly forbidden by default:

- `app/**`, `routes/**`, `database/**`, `config/**`, `resources/**`, `tests/**`, `cron/**`
- any workflow/CI file
- `docs/automation/AI-AUTONOMY-STATE.json`
- RFC-005 itself (`docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`)
- any existing RFC-005 M1–M5 contract or closure document, the Amendment 1 contract/design/closure documents, or **any of the nine remediation contract/correction/closure documents listed in the table above** (Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, Reconciliation-Race, Funding Confirmation Concurrency, Admin Usage Billing Surface, Provider Refund/Dispute Outcome Handling + its closure, Test-Coverage Completion + its closure)
- this document itself (`docs/automation/RFC-005-M6-CONTRACT.md`)
- anything belonging to RFC-001, RFC-002, RFC-003, or RFC-004

No schema change, migration, model, controller, request, route, repository, manager, permission, or view change. No test change. No billing, plan, entitlement, wallet, or Stripe behavior change. No feature expansion of any kind — Milestone 6 is a conformance/release milestone, not a feature milestone.

### Gap rule (important — this is the exact mechanism that produced the nine remediations above)

Milestone 6 is a conformance/release milestone, **not** a hidden bug-fix milestone. If the read-only conformance audit (§4) discovers that an RFC-005 acceptance criterion, an inherited RFC-001/002/003/004 invariant, or a required test class is actually missing, incorrectly implemented, exhibits schema drift, or lacks the coverage the RFC requires:

**STOP.** Record exactly: the RFC/design section, the expected behavior, the concrete repository evidence (or absence of evidence) found, the exact gap, and the exact path(s) that would require correction. Do **not** silently modify product code or tests to close the gap. A human-reviewed amendment to this contract, or a new separately bounded remediation contract (Remediation 10, following the same discipline as the nine already closed), is required before any such code/test correction.

---

## 4. RFC-005 conformance document

Lock the purpose of `docs/automation/RFC-005-M6-CONFORMANCE.md`: it must be an **evidence-based conformance matrix**, not a generic narrative summary — following `RFC-003-M6-CONFORMANCE.md`'s and `RFC-004-M4-CONFORMANCE.md`'s exact proven format (one row/section per RFC concern, each citing concrete evidence, marked `PASS` only when that evidence is concrete). For every row, cite concrete implementation and/or test evidence: a migration filename and its exact constraint, a class and method name, a repository/manager boundary, an enum/registry entry, an event/transition type, a controller/route/permission/view, an exact test class and test method, a mechanical code-search result, relevant M1–M5/Amendment-1/remediation contract/closure evidence (commit SHA, PR number), or actual regression results. **Do not mark anything PASS merely because it sounds correct or because a prior contract claimed it; do not fabricate test counts, SHAs, PR numbers, or evidence of any kind.** If evidence is insufficient or contradictory, mark the row **BLOCKED / GAP** and stop release progression per the gap rule above.

The audit must cover the full RFC-005 architecture end to end, at minimum:

- Business-scoped wallet foundation and calendar-month period accounting (§10, §12, M1)
- append-only usage ledger and accounting invariants — no cross-Business/cross-currency transfer (§12, §13)
- reservation/commit/release/expire lifecycle and the authoritative committed-amount formula, **including the corrected cross-period-commit guard and the debt-split overage formula** (§13, M1, re-tested by Remediation 9)
- meter identity and the immutable meter-key model (RFC-005 Amendment 1, all three slices)
- rate catalog, versioning, and activation, including rate-snapshot immutability and the direct-FK `active_rate_id` resolution mechanism (§11, superseding the original activation-history-query design)
- Business monthly spend cap, per-feature limits, platform safety limits, **and the admission-control wiring correction** (§15, M2, Remediation 1)
- payer selection and Workspace fallback, **including the narrowed platform-administrator posture** (§16, M2)
- billing contact, **including funding-attempt snapshot immutability** (§17.A, M2, re-tested by Remediation 9)
- payment-provider customer/instrument model, including schema-enforced provider consistency (§17.B, M3)
- Stripe test-mode boundary and the explicit live-charging-blocked posture, **including the fail-closed `StripePaymentProviderGateway` constructor validation of mode/secret/webhook-secret/api-version** (§20, M3)
- **the corrected Checkout-Session-based `ManualTopUp`/`AddonPurchase` funding flow** (Remediation 2), replacing the original, incorrect off-session-PaymentIntent design for those two purposes
- webhook verification, idempotency, claim/lease/exhaustion, disposition, and retention/reconciliation behavior, **including the corrected reconciliation race fix, the redesigned fair round-robin retry-reclaim query and `PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING`, and dual-reference/metadata-hint resolution** (§21, M3, Remediations 5 and 8)
- auto-recharge behavior and the centralized after-commit trigger, **including the funding-confirmation concurrency fix (the shared, always-row-locked idempotent finalizer and its outer transaction)** (§19, M3, Remediation 6)
- receipt issuance, **the first real `business_billing_receipts` implementation and its exact five financial-success dispatch points** (§23, Remediation 3)
- the full RFC-005 domain-event roster and job roster, **including the seven previously-missing events and the `SendLowBalanceNotification`/`SendAutoRechargeDisabledNotification`/`RetryStuckPaymentProviderEvents` jobs** (§29, Remediation 4, Remediation 8)
- provider refund/chargeback/dispute-outcome accounting, **the `refundable_paid_available_micro` counter, paid-first consumption allocation, and the `ProviderRefundMismatch` billing-status-transition source** (Remediation 8)
- the platform-administrator surface — manual credit issuance, transition-history views, the margin aggregate (exact bcmath string arithmetic), and admin retry-with-reason (§24, §30, Remediation 7)
- additional Business-slot agreement and payment flow, including cancellation/proration/dunning (§22, M4)
- the payment-verified additional-slot allocation authority and the resolved cross-RFC blocker (§39 item 14, RFC-004 Amendment 1, M4)
- add-on purchase and accounting behavior, including purchase-transition auditing **and its `source` field correctness** (§18, M4, re-tested by Remediation 9)
- entitlement/usage-wallet separation — `UsageAuthorizationGateway::check()` as the sole seam, `EntitlementManager::decide()` unmodified, `RealUsageAuthorizationGateway` confirmed bound (§14, RFC-004 §19)
- Conversations first-metered-feature pilot, including its pilot-tuple gating and its M5 correction history (§39 item 11, M5)
- idempotent provider send/accounting behavior for the pilot (M5)
- legacy non-metered feature behavior preservation across every milestone and remediation (§37)
- multi-Business/multi-Workspace safety and isolation, including concurrency (§24, §31)
- migration, backfill, and rollback invariants across every milestone and remediation (§32)
- RFC-001/002/003/004 compatibility (Workspace/Business tenancy model, `EntitlementManager`/`PlatformFeatureRegistry`/plan-entitlement chain, unchanged)
- every RFC-005 §37 acceptance criterion
- every §40 contract-coverage-matrix row
- release/tag prerequisites (§38)
- **the complete aggregate §35 test set, cumulatively, as it now stands after Remediation 9's own coverage-completion pass** — this is the specific evidence §37 itself requires M6 to show passing

**Technical conformance versus production-launch readiness.** The conformance document must explicitly separate two distinct questions and never conflate them:

1. **Technical RFC-005 conformance** — whether the architecture described by RFC-005 §1–§35 actually exists, passes its own test set, and satisfies §37's acceptance criteria. This is what M6 itself proves.
2. **Full production-launch readiness** — a strictly larger question RFC-005's own "Implementation readiness" section already scopes to production payment collection specifically, currently gated by one unresolved item: production tax/VAT legal sufficiency (§39 item 6). **The conformance document must record this item as open and unresolved, must not provide a legal conclusion of any kind, and must not silently mark it resolved.** No §39 item is named anywhere in §36–§38 as a tag precondition. The conformance document must state this distinction explicitly.

The remaining §39 open items (exact retail rates, default spend cap, default per-feature limits, auto-recharge defaults/caps, Agency subsidy policy, add-on roster/pricing, safety-limit ceilings, currency scope, Agency rebilling timing) must each be listed as an explicitly open, unresolved commercial/product decision — never fabricated, never defaulted, never silently marked resolved. The non-§39 low-balance-notification-timing observation (re-confirmed open above) must also be listed, not silently dropped.

**Additional-slot grandfathered-capacity revocation posture (§39 item 13).** The conformance document must record, as a repository-backed distinction and nothing more: M4 implements every RFC-defined `payment_lapsed` lapse/recovery mechanism in full; the currently implemented revocation posture is **zero** automatic Business-slot revocation of any kind; and the grandfathered-capacity revocation policy remains a still-open, separate human product-policy decision. **M6 must not invent a revocation policy and must not add revocation code of any kind.**

**Conversations pilot activation.** The conformance document must record, as a repository-backed distinction and nothing more: M5 built and tested the pilot mechanism in full; real activation (`usage:activate-conversations-rate`, real pilot tuple, a real rate) is a separate, explicit human operator action; **M6 itself does not execute that command, does not activate anything, and does not require prior activation as a conformance or tag precondition.**

The conformance document must record, for the manual regression run required before the M6 PR merges (§6), the **actual** results the human supplies — never invented counts, and never the static mechanical counts recorded in this contract's own "Repository state" section above without a fresh, real re-run.

---

## 5. Deployment guide

Lock the purpose of `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md`: it must be grounded in the actual repository, following the shape already established by `RFC-001-BUSINESS-CORE-DEPLOYMENT.md`, `RFC-002-OPPORTUNITY-ENGINE-DEPLOYMENT.md`, `RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE-DEPLOYMENT.md`, and `RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md`. **Do not copy any prior document's content — describe RFC-005 as it actually exists, post-remediation.** At minimum cover:

- scope (all of M1 through M5, Amendment 1, and all nine remediations, now shipped; explicitly note M3's live-charging-blocked posture as distinct from test-mode readiness)
- **environment prerequisites, confirmed present this pass, never an invented key:**
  - `config/services.php` `stripe` block: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_WEBHOOK_TOLERANCE` (default `300`), `STRIPE_MODE` (`test`|`live`, both required to be validated, no default), `STRIPE_API_VERSION` (required, no default) — `StripePaymentProviderGateway`'s own constructor fails closed on any blank/mismatched value, confirmed by direct read
  - `config/usage_billing.php`: `USAGE_BILLING_WEBHOOK_LEASE_MINUTES` (default 5), `USAGE_BILLING_WEBHOOK_MAX_ATTEMPTS` (default 5, hard-ceilinged at `PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING = 20` regardless of configured value), `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` (no default — fails closed rather than retaining forever or purging immediately), `USAGE_BILLING_CONVERSATIONS_PILOT_BUSINESS_ID`/`_PILOT_COUNTRY_ID`/`_PILOT_SENDING_SERVER_ID` (all null by default, all three required simultaneously for a qualifying send, optional/pilot-only, never required for deployment)
  - installed SDK: `stripe/stripe-php` (confirm the exact locked version in `composer.lock` at deployment time; do not assume a version number without checking)
- database migration ordering — the real, current 49-file RFC-005 migration set (§ "Repository state," above), in real timestamp order; confirm the exact current count and file list against `origin/main` at deployment-guide-drafting time, not copied from this contract without re-verification
- upgrade posture and supported deployment ordering
- Business wallet/payer/usage-profile initialization and backfill behavior, described accurately against what `App\Listeners\Usage\InitializeBusinessUsageProfile` and each milestone's own backfill migration actually do
- Stripe configuration required for applicable environments, test-mode versus live-mode, stated plainly, including the fail-closed constructor validation named above
- **queue/worker and webhook operational requirements, confirmed present this pass:**
  - this application's existing `queue:work --queue=automation,default,batch --timeout=120 --tries=1 --max-time=180 --stop-when-empty` scheduled command (`app/Console/Kernel.php`, `->everyMinute()`) already processes every RFC-005 queued job — no separate, dedicated Usage-billing worker process is required, matching this repository's own established convention
  - scheduled jobs actually registered in `app/Console/Kernel.php`, confirmed this pass, with their exact cadence: `PurgeExpiredWebhookPayloads` (hourly), `ReconcileProviderPendingState` (every 5 minutes), `RetryStuckPaymentProviderEvents` (every 5 minutes), `InitiateSlotAgreementRenewal` (every 5 minutes), `FinalizeSlotAgreementCancellation` (every 5 minutes), `ReconcileSlotAgreementAllocation` (hourly), `ExpireStaleUsageReservations` (every 5 minutes) — all registered unconditionally whenever `config('app.stage') !== 'demo'`, alongside this application's other existing scheduled work; none is gated by a separate RFC-005-specific feature flag
  - webhook endpoint: `POST /stripe/webhook/usage-billing` (`routes/public.php`, unauthenticated, CSRF-exempted via the existing `VerifyCsrfToken` `$except` mechanism) — confirm the exact current route definition at deployment-guide-drafting time
- encryption/secret requirements actually present (`payment_provider_events.payload_encrypted`, Laravel's `encrypted` Eloquent cast — the first real use of that cast in this codebase)
- cache/config handling only where actually applicable — verify before writing, do not invent a cache-clear step with no corresponding real config source
- migration verification and post-deploy smoke verification
- payer/payment-instrument considerations
- usage wallet/meter verification steps
- rollback/recovery posture, and an explicit statement of what must **never** be destructively rolled back (backfilled wallet/ledger/payer/refund/dispute data, per every milestone's and remediation's own restrictive-FK, no-hard-delete posture)
- webhook reconciliation/recovery procedure, **including the retry/reclaim/exhaustion/disposition/purge lifecycle** (`ProcessPaymentProviderEvent`, `RetryStuckPaymentProviderEvents`, `PurgeExpiredWebhookPayloads`, the administrator exhausted-events queue and dispose action)
- separation from the legacy SMS subscription/quota billing stack (`Plan`/`Subscription`/`PaymentMethods`), stated explicitly as untouched
- Conversations M5 pilot activation procedure, documented as **optional and human-operator-only** — the deployment guide may describe the existing `usage:activate-conversations-rate` command and its prerequisites for an operator who chooses to activate the pilot, but must not imply activation is required for deployment, and must not fabricate example values for any pilot configuration key
- explicit production-launch limitations, including the unresolved tax/VAT item (§39 item 6) and every other open §39 item, stated plainly rather than silently omitted
- exact final regression commands (§6 below)
- release/tag verification (§8/§9 below)

**Do not invent artisan commands, classes, migrations, or configuration keys.** Only document what direct inspection during the M6 branch itself confirms exists. Do not add RFC-001/RFC-002/RFC-003/RFC-004 deployment instructions beyond what is necessary to state compatibility.

---

## 6. Locked pre-merge regression gates

Verified present in this repository before locking these commands (see "Deriving RFC-005's own six-gate regression mechanically," above): `tests/Unit/Usage`, `tests/Feature/Usage`, `tests/Unit/Entitlement`, `tests/Feature/Entitlement`, `tests/Unit/Workspace`, `tests/Feature/Workspace`, `tests/Unit/Business`, `tests/Feature/Business`, `tests/Unit/Opportunity`, `tests/Feature/Opportunity` all exist and are non-empty. No repository incompatibility was found, so these manual, human-run regression commands are locked as-is, run against the disposable `ultimatesms_testing` database:

```
php artisan test tests/Unit/Usage tests/Feature/Usage
php artisan test tests/Unit/Entitlement tests/Feature/Entitlement
php artisan test tests/Unit/Workspace tests/Feature/Workspace
php artisan test tests/Unit/Business tests/Feature/Business
php artisan test tests/Unit/Opportunity tests/Feature/Opportunity
php artisan test --stop-on-failure
```

Purpose, in order: (1) RFC-005's own targeted Usage regression, proving the full aggregate §35 test set §37 requires; (2) RFC-004 Entitlement regression; (3) RFC-003 Workspace regression; (4) RFC-001 Business regression; (5) RFC-002 Opportunity regression; (6) complete application regression.

Every command must exit successfully and discover a positive test count — a zero/"no tests found" result is a failure. **The human runs these locally. Claude must never claim any of them passed if PHP is unavailable in its own environment, and must never invent a test count.** The actual results supplied by the human must be recorded in `docs/automation/RFC-005-M6-CONFORMANCE.md` before the M6 release-readiness PR is considered ready to merge — the exact positive counts, re-confirmed fresh, not the mechanical grep-based counts recorded in this contract's own "Repository state" section, which this contract has already disclosed may differ slightly from the actual PHPUnit-discovered count. If one fails, Milestone 6 is blocked until the failure is understood and resolved under valid scope — per the gap rule, a real product/test defect discovered here is reported and separately authorized as a new remediation, not silently patched inside the M6 PR.

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

**NO CLAUDE-AUTOMATIC TAGGING. NO AUTOMATIC TAG PUSH. NO TAG DURING THE M6 IMPLEMENTATION PR. NO LIGHTWEIGHT TAG. MERGING THE M6 IMPLEMENTATION PR MUST NOT ITSELF CREATE THE TAG — the tag is a separate, later, explicitly human-authorized action performed only after §8's own gate passes.**

Tag creation requires an explicit final human authorization **after** every gate in §6, §7, and §8 has passed. Exact tag name, confirmed directly against RFC-005 §36 item 6 and §38: `rfc-005-business-usage-billing-and-wallets`. It must be an **annotated** tag — matching the verified precedent of `rfc-002-opportunity-engine`, `rfc-003-workspace-and-business-account-core`, and `rfc-004-plans-and-business-feature-entitlements`, all three re-confirmed present on `origin` as real annotated tag objects via `git ls-remote --tags origin` this pass. `rfc-001-business-core` is also a real annotated tag object, but only locally in this clone — it is not present on `origin`. This contract does not repair or push `rfc-001-business-core`; RFC-005's own eventual tag must be explicitly verified against `origin` itself (§9's own verification list below), never assumed present by analogy to a local-only tag.

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

## 10. M6 completion semantics and the final governance closure PR

**The annotated tag remains the immutable RFC-005 release marker.** It is created and verified once, per §9, and is never re-created, re-pointed, or superseded by anything that follows. The final closure PR required below is **not a second release gate** — it performs no regression, blocks nothing, and cannot be a precondition of anything §6–§9 already gate. Its only purpose is to record completion and restore `docs/automation/AI-AUTONOMY-STATE.json` to a truthful idle state, since the repository's own authoritative governance state must not be left permanently reading `remediation_7_closed_pending_m6_contract_reaudit` after RFC-005 is actually done.

Milestone 6 — and RFC-005 as a whole — becomes **COMPLETE** only after every one of these, in order:

1. this M6 contract is merged
2. a fresh, manual `agent/rfc-005-m6` branch is created from post-merge `main` (§2)
3. the two-document M6 release-readiness PR is opened and merged (§3–§7)
4. all required human regression gates (§6, §8) pass
5. every §37 acceptance condition is satisfied
6. the post-merge exact-tag-candidate regression (§8) passes
7. explicit, separate human tag authorization occurs, and the annotated tag is created and pushed (§9)
8. the annotated tag is verified against the exact intended commit (§9)
9. **one final, governance-only RFC-005 closure/state pull request is drafted and human-merged**, per its own exact requirements below

**Step 9 — the final closure PR — exact requirements:**

- Creates exactly one new closure document (e.g. `docs/automation/RFC-005-CLOSURE.md` or `docs/automation/RFC-005-M6-CLOSURE.md` — the exact filename is the future closure author's own choice, following the naming convention `RFC-005-PROVIDER-REFUND-DISPUTE-OUTCOME-HANDLING-CLOSURE.md` and `RFC-005-TEST-COVERAGE-COMPLETION-CLOSURE.md` already established), recording: the M6 release-readiness PR number and its exact final head/merge SHAs, the exact tag-candidate SHA captured in §8, the verified annotated tag's own name/SHA/annotation (§9's own verification output), and a summary of every gate that passed.
- May append one concise completion record to `docs/automation/RFC-005-M6-CONFORMANCE.md` where appropriate (mirroring how prior remediation contracts each received a concise closure record appended in place) — it must not rewrite or restate that document's own conformance rows.
- Updates `docs/automation/AI-AUTONOMY-STATE.json` to an idle, non-authorizing state reflecting RFC-005's own completion: `active_pull_request: null`, `head_branch: "none"`, `implementation_authorized: false`, a `status` value indicating RFC-005 is complete (e.g. `"rfc_005_complete_tagged"`), `next_candidate: null` (or a future RFC's own name, only if a human has separately, explicitly selected one — never invented by this closure PR itself), and `completed_pull_request`/`completed_product_head_sha`/`completed_merge_commit_sha` updated to the M6 release-readiness PR's own final evidence.
- Authorizes no next RFC, no design module, and no further work of any kind beyond recording completion.
- Contains **zero** product, test, schema, config, or route changes — governance documents and `AI-AUTONOMY-STATE.json` only, exactly like every prior remediation's own closure PR.
- Is merged by a human, same as every other step in this sequence.

**M6 itself still uses no dedicated implementation-authorization PR (§1) — this final closure PR is a distinct, later, one-time governance action, required only once, after the tag exists and is verified, never before it and never in place of it.** `AI-AUTONOMY-STATE.json` is touched exactly once in this entire sequence: by this final closure PR, and by nothing before it.

M6 completion must **not** automatically:

- activate the Conversations pilot, execute `usage:activate-conversations-rate`, or fabricate any pilot configuration value, retail rate, provider cost, currency, or unit-label value
- charge any real payment method, process any real refund, or simulate any real dispute in any non-test environment
- make production Stripe charging legally or commercially ready (the tax/VAT item remains open and is not resolved by tagging)
- resolve any RFC-005 §39 open decision, or the non-§39 low-balance-notification-timing observation
- deploy, activate, or configure any real (non-test) environment of any kind
- start RFC-006 or any other next RFC or design module
- select or begin any post-RFC-005 work of any kind
- change `advance_automatically`, `start_automatically_after_contract_merge`, or `require_exact_scope`
- enable Codex-required completion or an automatic model handoff
- enable automatic merge or automatic tagging
- treat the final closure PR (step 9 above) as itself a release gate, or as authorization for any product/test/schema/config/route change

**No automatic next-RFC start.** Any RFC beyond RFC-005 remains separately designed and separately authorized work. Any next project selection remains a separate, explicit human decision, made after RFC-005's tag is verified and its closure PR merged, not implied by either.

---

## 11. Contract PR itself

This contract-refresh branch (`chore/rfc-005-m6-contract-refresh`) changes **exactly one file**: `docs/automation/RFC-005-M6-CONTRACT.md`. Nothing else.

Do not modify `docs/automation/AI-AUTONOMY-STATE.json`. Do not create a target marker. Do not create either of the two future M6 release-readiness files now. Do not create a tag now. Do not execute `usage:activate-conversations-rate` or any other product command. Do not delete or rebase the stale local `agent/rfc-005-m6` branch as part of this PR — that is the eventual M6 implementer's own responsibility, performed only after this contract merges (§2).

---

## Forbidden governance / automation (summary)

- No automatic implementation start.
- No automatic merge.
- No force push. No direct push to `main`.
- No paid model API or usage-credit requirement.
- No Codex completion requirement.
- No automatic model handoff.
- No tag of any kind created by this contract or by the eventual M6 documentation PR — only by the explicit, separately-authorized §9 procedure, and merging the M6 implementation PR must not itself create the tag.
- No deployment, activation, or configuration of any real (non-test) environment.
- No live Stripe charge, live refund, or live dispute simulation of any kind.
- No activation of the Conversations pilot or any other real-environment meter/rate/pilot-tuple mutation.
- No resolution of any RFC-005 §39 open commercial/product decision, no resolution of the non-§39 low-balance-notification-timing observation, and no legal conclusion regarding tax/VAT sufficiency.
- No RFC-006 or other next-RFC implementation, design, or selection.
- `advance_automatically` remains `false`.
- `start_automatically_after_contract_merge` remains `false`.
- `require_exact_scope` remains `true`.
- `maximum_correction_rounds` remains `2`.
- No modification of `docs/automation/AI-AUTONOMY-STATE.json` at any point in this sequence except the one final governance-only closure PR (§10), performed only after the tag is created and verified — this contract's own merge does not touch it, does not flip `implementation_authorized`, and authorizes no automation to act on it.
