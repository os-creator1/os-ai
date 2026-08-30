# RFC-005 Remediation #7 — §35 Test-Coverage Completion Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**Authoring only. This document locks a test-coverage-completion design derived from a fresh, mechanical coverage audit against current `origin/main`. It contains zero product-file changes. Implementing it — writing or modifying any test file — is separately, explicitly authorized future work, exactly as every prior RFC-005 remediation's own contract/implementation split has worked.**

## Governance

- `human_only_merge`: true — this document, and any implementation branch built from it, is merged only by a human.
- `maximum_correction_rounds`: 2 — **0 of 2 consumed.** This is a fresh contract, not a correction of an existing one.
- `advance_automatically`: false.
- `start_automatically_after_contract_merge`: false.
- **Future implementation branch:** `agent/rfc-005-test-coverage-completion`. Implementation requires a separate, explicit authorization pull request after this contract merges — mirroring the established pattern (contract PR → implementation-authorization PR → implementation PR) used by every prior RFC-005 milestone and remediation.
- `docs/automation/AI-AUTONOMY-STATE.json` is untouched by this document and remains untouched by any commit on this branch.
- **No M6 conformance document, no deployment guide, no tag, no deployment or activation, no live Stripe/refund/dispute action, no production rate/meter/pilot activation is authorized by this document or by authoring it.**
- **No product, schema, migration, config, route, controller, manager, repository, model, job, event, or notification modification is authorized by this document.** The production allow-list below is empty — no genuine current product defect was independently discovered by this audit (see "Blocking-finding assessment," §2).
- **M6 remains frozen throughout this contract and throughout any future implementation of it.**
- **Remediation #7 completion does not itself authorize M6.** After Remediation #7 is implemented, reviewed, merged, and closed, the existing `docs/automation/RFC-005-M6-CONTRACT.md` — drafted against base `103ed528436c91ffba10648c026c935dd4e6677a`, before any of the seven remediations that followed M5's closure existed — must undergo a fresh post-remediation audit/correction or replacement before any M6 work is authorized. This document does not perform that audit; it only states the requirement.
- **The proposed RFC-005 tag (`rfc-005-business-usage-billing-and-wallets`) remains forbidden** until M6's own separately-authorized post-merge exact-tag-candidate gate passes and a human explicitly authorizes it.

**Base SHA:** `2ada6872ed2689361100c98f1ff38ca7843f6f89` — PR #155's merge commit, closing RFC-005 Remediation #6 (Provider Refund/Dispute Outcome Handling), confirmed as `origin/main` before this branch was created.

**Branch:** `chore/rfc-005-test-coverage-completion-contract`.

**No human product decision remains open.** Every gap this audit found is a test-coverage gap or a stale-wording issue in RFC-005's own §35 prose — none requires a product/design decision.

---

## 1. Required reading, confirmed by direct re-read this pass

- `AGENTS.md` — verification rules (disposable `ultimatesms_testing` only, positive test counts, exact evidence) and code-review rules (enforce `AI-AUTONOMY-STATE.json`'s locked slice, treat later-slice work as scope creep).
- `docs/automation/AI-AUTONOMY-STATE.json` — confirmed idle: `active_pull_request: null`, `head_branch: "none"`, `implementation_authorized: false`, `status: "remediation_6_closed_pending_next_locked_contract"`, `completed_pull_request: 153`, `completed_merge_commit_sha: "ea88967af83897bcdf207f05e34c21e2177bcaba"`. `contract_source: null`, `next_candidate: null` — no other RFC-005 work is in flight.
- `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` §35–§40 — read in full (lines 1216–1352); §35's exact prose is the audit's own subject and is quoted verbatim throughout §2 below, never paraphrased into a different claim.
- `docs/automation/RFC-005-M6-CONTRACT.md` — read in full (366 lines) as historical/stale M6 planning evidence only, per this contract's own instruction. It was drafted against base `103ed528436c91ffba10648c026c935dd4e6677a` (the M5 closure merge) and predates all seven remediations that shipped afterward (Reservation Admission Correction, Funding Provider-Flow Correction, Receipt Boundary Correction, Job/Event Dispatch Completion Correction, Reconciliation-Race Correction, Funding Confirmation Concurrency Correction, Admin Usage Billing Surface, and Provider Refund/Dispute Outcome Handling — eight remediations, not seven; RFC-005 §35's own text was itself amended across some of these). It is not treated as current authorization anywhere in this document. Its one durably useful finding, reused directly in §2 below: RFC-005 §35's "six commands" gate-shape line is a *mechanically derived pattern* (one regression command per shipped RFC's own targeted suite, plus one full-suite command), not a literal fixed count — confirmed independently this pass against RFC-003's four-command and RFC-004's five-command precedents cited in that same document.
- Every RFC-005 milestone contract and closure — `RFC-005-M1-CONTRACT.md`, `RFC-005-M2-CONTRACT.md`, `RFC-005-M3-CONTRACT.md`, `RFC-005-M4-CONTRACT.md` (+ `RFC-005-M4-CORRECTION-1.md`, `RFC-005-M4-CORRECTION-2.md`), `RFC-005-M5-CONTRACT.md` (+ `RFC-005-M5-EXCEPTIONAL-CORRECTION-AUTHORIZATION.md`, `RFC-005-M5-TEST-ALIGNMENT-CORRECTION-AUTHORIZATION.md`, `RFC-005-M5-CLOSURE.md`), and Amendment 1's full slice/correction sequence (`RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY-CONTRACT.md`, `RFC-005-AMENDMENT-1-SLICE-1-EXPAND-CONTRACT.md`, `RFC-005-AMENDMENT-1-SLICE-2-CUTOVER-CONTRACT.md`, `RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md` + its three exceptional-correction and two test-alignment-correction authorizations, `RFC-005-AMENDMENT-1-CLOSURE.md`).
- Every completed pre-M6 remediation contract and its implementation/closure evidence — `RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md`, `RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md`, `RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md`, `RFC-005-JOB-EVENT-DISPATCH-COMPLETION-CORRECTION-CONTRACT.md`, `RFC-005-RECONCILIATION-RACE-CORRECTION-CONTRACT.md`, `RFC-005-FUNDING-CONFIRMATION-CONCURRENCY-CORRECTION-CONTRACT.md`, `RFC-005-ADMIN-USAGE-BILLING-SURFACE-CONTRACT.md`, `RFC-005-PROVIDER-REFUND-DISPUTE-OUTCOME-HANDLING-CONTRACT.md` (+ its own closure).
- Every current test file under `tests/Feature/Usage/` (128 files) and `tests/Unit/Usage/` (4 files) — read at the complete-method-body level, not by name, per this contract's own audit discipline (§2). The full file inventory was enumerated directly (`ls tests/Feature/Usage/*.php`, `ls tests/Unit/Usage/*.php`) before any classification was made.
- Relevant production files, read directly where a test's genuine coverage needed independent confirmation: `app/Library/Usage/UsageWalletManager.php`, `app/Library/Usage/UsageBillingCheckoutManager.php`, `app/Repositories/Eloquent/EloquentPaymentProviderEventRepository.php`, `app/Repositories/Eloquent/EloquentBusinessUsageRateActivationRepository.php`, `app/Repositories/Contracts/BusinessUsageRateActivationRepository.php`, `database/migrations/2026_08_20_150003_create_additional_business_slot_renewal_charges_table.php`.

---

## 2. Coverage audit — every §35 requirement, mechanically classified

§35's own text (`docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`, lines 1216–1249) is organized as prose bullets, not a numbered list. This audit numbers them 1–25 in the section's own reading order, for exact cross-reference. Every method named as "confirmed covering" below was read in full — body, fixture, and assertions — not matched by name alone. Every method now proposed (§3) was checked against the exact production code path it would exercise.

### 2.1 Money, rates, and classification

**#1 — "Money/precision, rate snapshot immutability, concurrent initial rate activation, deterministic tied-activation-timestamp lookup, classification transition audit — unchanged."**

- Money/precision: `AmountCurrencyConversionTest::test_bc_round_half_up_at_representative_and_boundary_values`, `::test_conversion_fails_closed_for_an_unrecognized_currency_code`. **COMPLETE.**
- Rate snapshot immutability: `BusinessUsageRateSchemaTest::test_rate_table_has_no_updated_at_column`, `::test_rate_id_restricts_deletion_while_activation_exists`. **COMPLETE.**
- Concurrent initial rate activation: `UsageWalletManagerSetActiveRateConcurrencyTest::test_same_meter_concurrent_rotations_serialize_with_strictly_increasing_versions_and_no_lost_update` — a genuine separate OS process (`Symfony\Process`) holds an uncommitted transaction on `usage_meters`, the waiter blocks on `findForUpdateByMeterKey()`'s real row lock, no lost update is observed. **COMPLETE.**
- Classification transition audit: `UsageWalletManagerMeterAuthorityTest::test_activate_metering_writes_transition_row_and_never_touches_the_classification_table` — asserts a real `usage_meter_transitions` row is written and the legacy `platform_feature_usage_classification_transitions` count is unchanged. **COMPLETE.**
- Deterministic tied-activation-timestamp lookup: **SUPERSEDED — architecturally moot, confirmed by direct code read.** RFC-005 §11's original design (a live `SELECT ... ORDER BY activated_at DESC, id DESC LIMIT 1` query with an explicit tiebreak) was superseded by the Amendment 1 Slice 3 design (`RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md` §5.3, cited directly in the production code's own comment at `UsageWalletManager.php:1075`): the currently active rate is resolved via a direct, atomically-written foreign key, `usage_meters.active_rate_id` (`UsageWalletManager.php:327,335`), written under a row lock by `setActiveRate()` (`UsageWalletManager.php:1083–1123`). There is no query-time historical lookup anywhere in the codebase — confirmed by reading `EloquentBusinessUsageRateActivationRepository` (implements only `create()`) and its interface (`BusinessUsageRateActivationRepository`, declares only `create()`). A tie on `activated_at` between two audit-log activation rows is harmless because nothing ever queries "which row is current by timestamp": the FK pointer is the single source of truth, and `UsageWalletManagerSetActiveRateConcurrencyTest` already proves that pointer converges correctly, without a lost update, under a genuine concurrent race. **No test gap exists because no such query exists to test; §35's own wording describes a superseded design.** No test authorized for this sub-item.
- **Stale-test-smell noted, no action:** `PlatformFeatureUsageClassificationSchemaTest` exercises the legacy `platform_feature_usage_classifications`/`..._transitions` tables, which are confirmed dead/unwritten by any current code path since the Amendment 1 Slice 2 cutover (`RFC-005-AMENDMENT-1-SLICE-2-CUTOVER-CONTRACT.md`). The file remains valid proof of that dead table's own schema constraints and is harmless to keep, but a reader of this matrix should not mistake it for proof of the classification-transition-audit requirement — the real proof is `UsageWalletManagerMeterAuthorityTest`, above. No test change authorized; observation only.

**#2 — "Reservation/commit/release lifecycle — unchanged."**

`UsageWalletManagerReservationLifecycleTest`: `test_reserve_then_exact_match_commit`, `test_reserve_then_under_reservation_commit_releases_unused_portion`, `test_reserve_then_overage_commit_draws_available_then_debt`, `test_reserve_then_release`, `test_expire_stale_reservations_releases_without_committing`, `test_repeat_reserve_with_same_idempotency_key_is_a_no_op`, `test_repeat_commit_on_already_committed_reservation_is_a_no_op`, `test_commit_or_release_on_released_reservation_throws`. Every assertion re-fetches persisted rows (`DB::table(...)->first()`), never trusts an in-memory model. **COMPLETE.**

**#3 — "Committed-spend formula correctness — new this round — `UsageCommittedSpendFormulaTest`: a mixed sequence of exact-match commits, under-reservation commits, and overage commits (split across available and debt) reconstructs `committed_spend_this_period_micro` exactly via the corrected `-reserved_delta_micro` / `(-available_delta_micro)+debt_delta_micro` formula (§13), independently verified against a from-scratch ledger recomputation."**

`UsageWalletManagerCommittedSpendFormulaTest::test_committed_spend_matches_from_scratch_ledger_recomputation` exists and does perform a genuine from-scratch ledger recomputation compared against the persisted counter. **PARTIAL.** Traced the exact fixture numbers through `UsageWalletManager::commit()`: with the wallet seeded at 20,000,000 available, the overage commit's `$overageFromAvailable = min(8,000,000, 15,000,000) = 8,000,000`, so `$overageToDebt` is always `0` for every ledger row this test produces. **The overage commit is never actually "split across available and debt" as the requirement's own text names — `debt_delta_micro` is `0` throughout, so a regression specifically in the debt half of the `(-available_delta_micro)+debt_delta_micro` term would not be caught by this test.** A genuine available+debt split exists elsewhere (`UsageWalletManagerReservationLifecycleTest::test_reserve_then_overage_commit_draws_available_then_debt`, debt=500,000) but that test does not perform the from-scratch recomputation this requirement calls for. **Missing assertion:** the fixture's overage scenario must be sized so the wallet's available balance is smaller than the overage amount at commit time, producing `debt_delta_micro > 0` for at least one ledger row, before the from-scratch recomputation is run. **Strengthening of the existing method — no new file, no new method.**

**#4 — "Spend/recharge cached counters are formula-derived and never manually mutated — corrected this round — `UsageChargeReversalDoesNotReopenSpendCapTest`: a `UsageChargeReversal`/`Refund`/`DisputeChargeback`/`CorrectionReversal` entry never decrements `committed_spend_this_period_micro`, and no code path — including an administrator action — writes directly to `committed_spend_this_period_micro`/`recharged_this_period_micro` at all; the reconciliation job's independent recomputation from the ledger is asserted to match the cached value at every step, proving there is never a divergence a 'manual correction' would need to paper over. `UsageCapConfigurationChangeIsProspectiveOnlyTest` — new this round — changing `monthly_spend_cap_micro`/`monthly_recharge_cap_micro` via `business_usage_limit_transitions` affects only future reservation/recharge admission decisions and never rewrites either historical cached counter."**

No file named `UsageChargeReversalDoesNotReopenSpendCapTest` or `UsageCapConfigurationChangeIsProspectiveOnlyTest` exists; coverage is genuinely present but scattered under different names. **PARTIAL, plus one requirement-wording correction (not a product defect — see "Blocking-finding assessment," §2.6 below):**

- "No code path writes directly to the counters" — `FormulaDerivedCountersNeverManuallyMutatedM2Test::test_no_m2_method_source_assigns_to_a_formula_derived_counter` (mechanical source grep) and `::test_setting_the_spend_cap_leaves_the_committed_and_reserved_counters_untouched`. **COMPLETE** for M2-era methods.
- "Changing `monthly_spend_cap_micro` via `business_usage_limit_transitions` is prospective-only" — `UsageWalletManagerSpendCapTest::test_setting_the_cap_records_a_transition`, `::test_a_cap_below_already_committed_spend_is_allowed_and_does_not_touch_history`, `::test_business_spend_cap_tightened_below_already_committed_spend_clamps_headroom_to_zero` — all drive the real `setSpendCap()` method and re-assert the DB row. **COMPLETE**, and is the authoritative coverage for this half of the requirement (see stale-test-smell note below).
- "Changing `monthly_recharge_cap_micro` via `business_usage_limit_transitions`" — **SUPERSEDED wording, not a gap.** `monthly_recharge_cap_micro` is written only by `UsageWalletManager::configureAutoRecharge()`, which never touches `business_usage_limit_transitions` — confirmed directly against the migration `database/migrations/2026_08_16_130003_create_business_usage_limit_transitions_table.php`, whose own docblock scopes that table to `business_spend_cap`/`feature_limit`/`platform_safety_limit` only, per the M2 contract's own §11.3 design. §35's text describing a recharge-cap change as routing through that table does not match any milestone contract's own shipped design; no test can or should assert a `business_usage_limit_transitions` row for a recharge-cap change. No test authorized for this half.
- "A `Refund`/`DisputeChargeback`/`CorrectionReversal`/`UsageChargeReversal` entry never decrements `committed_spend_this_period_micro`" — **MISSING as a genuine production-boundary proof.** The only existing attempt, `UsageWalletManagerCommittedSpendFormulaTest::test_reversal_entry_types_never_decrement_committed_spend`, uses a raw, hand-inserted `DB::table('business_usage_ledger_entries')->insert([...])` row rather than driving a real reversal method — this proves only that nothing recomputes the counter on ledger insert (trivially true), not that `UsageWalletManager::applyProviderRefund()`/`applyDisputeWithdrawal()`/`reinstateDisputedFunds()` themselves leave the counter alone. **Missing assertion, two new methods, placed in `UsageWalletManagerReversalTest.php`** (§3) where the real reversal methods are already driven — no new file.
- "The reconciliation job's independent recomputation... is asserted to match the cached value at every step" — **see Blocking-finding assessment, §2.6.** No such job exists in production. This is not classified as a coverage gap requiring a new job; the underlying safety property is already provable by other means.
- **Stale-test-smell noted, action taken (§3):** `UsageWalletManagerCommittedSpendFormulaTest::test_cap_configuration_change_is_prospective_only` mutates `monthly_spend_cap_micro` via a raw `DB::table('business_usage_wallets')->update(...)`, bypassing the real `setSpendCap()` boundary entirely. `UsageWalletManagerSpendCapTest`'s own methods are the authoritative, production-boundary-correct proof of this requirement; this raw-update method is rewritten (§3) to go through the real method, removing the misleading bypass rather than leaving two contradictory-looking proofs of the same claim.

**#5 — "Cross-period reservations and counter reconciliation — unchanged, now asserted against the corrected formula."**

**MISSING.** No test anywhere exercises `UsageWalletManager::commit()`'s `$isCurrentPeriod` branch (`commit()` line ~596: `$isCurrentPeriod = $reservation->period_key === $wallet->spend_period_key`) for a reservation created in one calendar period and committed after the wallet has already rolled over into a later one — the exact "cross-period" scenario the requirement names. A new method is required (§3) — no existing method's fixture naturally accommodates a mid-test rollover.

### 2.2 Calendar rollover, reservation buckets, auto-recharge

**#6 — "Calendar-month rollover — corrected and expanded this round — `UsageCalendarMonthRolloverTest`: explicit cases for a February rollover (28 and 29 days), a 31-day month, and a DST spring-forward/fall-back boundary in the Business's own timezone... `UsagePeriodMultiMonthDormancyTest`: a wallet dormant 3+ months lands directly in the correct current calendar month in one step."**

`UsageCalendarMonthRolloverTest::test_february_28_day_rollover`, `::test_february_leap_year_29_day_rollover`, `::test_thirty_one_day_month_rollover`, `::test_dst_spring_forward_boundary_in_business_timezone` (asserts exact UTC boundaries across the `America/New_York` spring-forward transition), `::test_multi_month_dormancy_lands_in_current_month_in_one_step` (Jan→Jul, asserts `spend_period_key='2026-07'` and a zeroed counter in one `reserve()` call — this is `UsagePeriodMultiMonthDormancyTest`'s own scenario, folded into the same file rather than a separate one), `::test_business_timezone_change_affects_only_the_next_period`. **PARTIAL.** Only the DST **spring-forward** boundary is present; a repository-wide search for "fall-back," "fall_back," "November," "autumn," or "standard time" in `tests/Feature/Usage/` returns zero matches. The **fall-back** boundary (the requirement's own explicitly-named other half) is entirely absent. **Missing assertion, one new method** (§3) — same file, same pattern, a repeat-hour date.

**#7 — "Reservation bucket delta/reconciliation, overage debt, refund/chargeback exceeding available, top-up clears debt first, outstanding debt denies reservations — unchanged."**

Overage debt: `UsageWalletManagerReservationLifecycleTest::test_reserve_then_overage_commit_draws_available_then_debt`. Refund exceeding available (never creates debt, by policy): `UsageWalletManagerReversalTest::test_apply_provider_refund_debits_only_the_lesser_of_available_balance_and_refundable_paid_available_and_records_policy_excess_for_the_remainder`. Chargeback exceeding available (creates debt, by policy — §8 of the Provider Refund/Dispute Outcome Handling contract confirms this is intentional, not a bug): `UsageWalletManagerReversalTest::test_apply_dispute_withdrawal_creates_debt_when_available_balance_is_insufficient`. Top-up clears debt first: `UsageWalletDomainEventDispatchTest::test_credit_from_funding_dispatches_both_credited_and_debt_cleared_from_the_same_call`, `::test_credit_from_funding_fully_clearing_debt_with_no_remainder_dispatches_only_debt_cleared`. Outstanding debt denies reservations: `UsageWalletManagerReservationLifecycleTest::test_outstanding_debt_denies_a_new_reservation`. **COMPLETE.**

**#8 — "Reservation-triggered auto-recharge, low-balance notification reset, consecutive-recharge-failure counter, recharge-cap never auto-reopened — unchanged."**

Consecutive-failure counter and reset: `AutoRechargeFailedPaymentRetryTest` (5 methods, full lifecycle including disable-after-third-failure and re-enable-resets-counter). Recharge-cap denial: `AutoRechargeThresholdAndCapTest::test_a_recharge_that_would_exceed_the_monthly_cap_is_denied`. Low-balance notification: `SendLowBalanceNotificationTest`. **PARTIAL.** A repository-wide search for `Queue::assertPushed(EvaluateBusinessAutoRecharge::class` found only negative-case (`assertNotPushed`) usages; `AutoRechargeThresholdAndCapTest`'s own "triggers a successful recharge" method calls `EvaluateBusinessAutoRecharge::dispatch()` directly rather than through a real `reserve()`/`commit()` call — **no test proves the reservation itself is the trigger**, end to end, the exact claim "reservation-triggered auto-recharge" makes. Separately, no test proves a `Refund`/`DisputeChargeback`/`CorrectionReversal` leaves `recharged_this_period_micro` untouched (the "never auto-reopened" half, beyond the already-covered same-period cap-denial case). **Two missing assertions, two new methods** (§3): one end-to-end reservation-triggers-recharge proof, one recharge-cap-never-reopened-by-reversal proof.

**#9 — "Exact RFC-004 nine-key set unchanged, missing-wallet/currency coarse gateway behavior, cap enforcement, concurrency — unchanged."**

Nine-key set: `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest::test_nine_denial_keys_are_unchanged`, `::test_decide_outcome_identical_across_both_gateway_bindings_for_every_feature` — outside `tests/Feature/Usage/` but the genuine, load-bearing proof (found by direct directory search; nothing under `tests/Feature/Usage/` covers this sub-item at all). Missing-wallet coarse behavior: `UsageWalletManagerMeterAuthorityTest::test_evaluate_coarse_capacity_unconditionally_authorizes_regardless_of_wallet_or_meter_state`. Missing/unknown/ambiguous currency: `BusinessCurrencyResolutionTest` (7 methods). Cap enforcement: `UsageWalletManagerSpendCapTest`, `AutoRechargeThresholdAndCapTest`. Concurrency: `UsageWalletManagerConcurrencyTest` (genuine `Symfony\Process` cross-process race). **COMPLETE.**

### 2.3 Payer consent, instruments, add-ons

**#10 — "Payer-owner authorization for every charge-causing action, including the narrowed platform-administrator posture — corrected and expanded this round — `PayerConsentForChargeActionsTest`: a Workspace owner/direct Business owner cannot cross-authorize as before; and, new this round, a platform administrator cannot originate a fresh top-up, auto-recharge enablement, or slot-agreement checkout under any `payer_type` — only resume an already-created attempt, asserted by attempting both and observing the origination attempt denied while the resume attempt... succeeds with a mandatory reason recorded."**

No file named `PayerConsentForChargeActionsTest` exists; coverage is genuine but scattered. Cross-authorization: `PayerConsentAuthorizationTest::test_workspace_owner_may_not_set_payer_to_business`, `::test_direct_business_owner_may_not_set_payer_to_workspace`. Top-up origination denial: `FundingAttemptPayerConsentTest::test_platform_administrator_cannot_originate_a_fresh_top_up_via_checkout_session`. Slot-agreement origination denial: `SlotAgreementAdminAuthorityTest::test_administrator_cannot_originate_a_fresh_checkout`. Resume-succeeds-with-mandatory-reason: `FundingAttemptRetryAsAdministratorAuthorityTest::test_a_successful_admin_retry_records_the_actor_and_the_normalized_reason_on_the_transition`, `::test_a_blank_reason_retry_is_denied_before_any_gateway_call`; `SlotAgreementAdminAuthorityTest::test_administrator_cancellation_requires_a_mandatory_reason`, `::test_administrator_cancellation_succeeds_with_a_mandatory_reason`. **PARTIAL.** The requirement names three origination actions an administrator must be denied: a fresh top-up, **auto-recharge enablement**, and a slot-agreement checkout. Only two of three are tested at the manager level. Every call site of `UsageWalletManager::configureAutoRecharge()` in the test suite (`AutoRechargeThresholdAndCapTest.php`, `AutoRechargeFailedPaymentRetryTest.php`, `FundingAttemptTerminalEventDispatchTest.php`, `SendLowBalanceNotificationTest.php`) passes a legitimate owner/customer actor id — none passes an admin actor id and asserts denial. The only admin-adjacent test, `AdminUsageBillingSurfaceBoundaryTest::test_the_admin_usage_billing_controller_never_calls_configure_auto_recharge`, is a pure string-grep boundary test proving no HTTP route exists — it never calls the manager method directly, so a regression inside `assertChargeCausingConsentForAutoRecharge()` itself would go undetected. **Missing assertion, one new method** (§3), placed in `FundingAttemptPayerConsentTest.php` beside its sibling top-up-denial test.

**#11 — "Workspace instrument isolation, historical billing-contact snapshot immutability, credit-type distinction, add-on idempotency — unchanged."**

Workspace instrument isolation: `CrossBusinessPaymentIsolationTest::test_a_business_owned_instrument_is_never_visible_from_a_different_businesss_dashboard`, `::test_funding_history_repository_lookup_is_business_scoped`. **COMPLETE.** Credit-type distinction: `UsageWalletManagerManualCreditTest::test_issuing_a_manual_credit_increases_available_balance_and_records_the_ledger_entry`, `::test_issuing_a_promotional_credit_records_the_correct_entry_type`, `::test_issuing_a_credit_with_a_disallowed_entry_type_is_rejected`. **COMPLETE.** Add-on idempotency: `AddonPurchaseTransitionAuditTest::test_a_duplicate_webhook_against_an_already_completed_purchase_is_idempotent`, `FundingConfirmationConcurrencyCorrectionTest::test_a_genuinely_simultaneous_double_confirmation_of_an_addon_purchase_produces_exactly_one_completion_transition`. **COMPLETE.** Historical billing-contact snapshot immutability: **MISSING entirely.** `business_funding_attempts.billing_contact_name_snapshot`/`billing_contact_email_snapshot` are populated once at attempt-creation time (`UsageBillingCheckoutManager.php:308–309`) and documented as required-frozen in `RFC-005-M3-CONTRACT.md` (lines 246, 583–584: "frozen, never re-derived") — but a repository-wide search for either column name inside `tests/` returns zero matches. `BillingProfileManagerBillingContactTest.php` and `PayerTransitionAuditTest::test_payer_change_never_rewrites_the_billing_contact` both test different, adjacent invariants (the mutable current contact row; a payer change not touching that row) — neither touches the funding-attempt snapshot columns. **Missing assertion, one new method** (§3).

**#12 — "Add-on purchase transition audit — new this round — `AddonPurchaseTransitionAuditTest`: every `business_usage_addon_purchases.status` change is recorded in `business_usage_addon_purchase_transitions` with the correct `source`."**

`AddonPurchaseTransitionAuditTest.php` (11 methods) genuinely proves the "recorded, exactly once" half — `test_a_crash_between_attempt_succeeded_and_purchase_completed_is_repaired_by_a_later_webhook` and `test_a_duplicate_webhook_against_an_already_completed_purchase_is_idempotent` both re-fetch `BusinessUsageAddonPurchaseTransitionRepository::forPurchase()` and assert exact counts. **PARTIAL.** The requirement's own text names "**with the correct `source`**" explicitly (`RFC-005-M4-CONTRACT.md` lines 2391–2404 confirm this exact phrase was the intended scope), but no method anywhere reads or asserts the `source` column's value — `completeAddonPurchaseUnderLock()` (`UsageBillingCheckoutManager.php:1123`) is driven from both `confirmAttemptFromReturn()` (`TransitionSource::SyncResponse`, line 484) and `confirmAttemptFromWebhook()` (`TransitionSource::WebhookEvent`, line 533), and neither existing test checks which one was recorded. `BusinessUsageAddonPurchaseTransitionSchemaTest` inserts a transition row with a hardcoded literal `'source' => 'sync_response'` purely to test the FK/append-only shape — it never calls production code, so it proves nothing about correctness. **Missing assertion, two existing methods strengthened** (§3) — no new file, no new method: `test_checkout_session_completed_webhook_confirms_an_addon_purchase` (webhook path, assert `WebhookEvent`) and `test_wallet_credit_addon_purchase_dispatches_exactly_one_send_receipt_notification` (sync-return path, assert `SyncResponse`).

### 2.4 Webhooks, claim/lease/exhaustion, provider identity

**#13 — "Webhook active lease vs. stale lease recovery, failed-event replay/resume, out-of-order/conflicting events, provider/local amount/currency/customer mismatch — unchanged, now asserted against the corrected algorithm."**

`WebhookStaleLeaseRecoveryTest` (4 methods: fresh claim, stale-processing reclaim, active-lease-not-reclaimed, failed-under-bound reclaim) — every method calls `EloquentPaymentProviderEventRepository::claim()` directly and its assertions match that method's current SQL exactly (`claim()` at `EloquentPaymentProviderEventRepository.php:68–87`, verified this pass — the three-branch `WHERE` clause `state='received' OR (state='failed' AND attempts<?) OR (state='processing' AND lease_expires_at<NOW() AND attempts<?)` is unchanged since these tests were written; only the separate `retryable()` batch-scanner method was reworked by Remediation #6, not `claim()`/`exhausted()`/`dispose()` themselves — **these tests are not superseded**). `WebhookDuplicateEventReplayTest::test_a_redelivered_event_id_is_absorbed_as_a_no_op` — a genuine second HTTP POST through the real webhook route, asserting exactly one row survives via `UNIQUE(provider, provider_event_id)`. `WebhookAmountCurrencyCustomerMismatchTest` (4 methods: amount/currency/customer mismatch each independently produce `failed` + zero wallet mutation via a real re-fetched wallet balance; a fully-matching webhook succeeds and credits exactly once). **COMPLETE.**

**#14 — "Max-attempt exhaustion, uniformly applied — corrected and expanded this round — `WebhookClaimExhaustionTest`: a payload that reliably crashes the worker on every claim is reclaimed at most 5 times... then becomes permanently unreclaimed and surfaces in the admin exhausted-events queue."**

`WebhookClaimExhaustionTest::test_attempts_5_reaches_the_bound_and_attempt_6_never_reclaims`, `::test_exhausted_query_surfaces_only_events_at_or_past_the_bound` — read in full, both match `claim()`/`exhausted()`'s current behavior exactly. **COMPLETE, not superseded** — same reasoning as #13.

**#15 — "Terminal-outcome exactness — new this round — `WebhookTerminalOutcomeTest`: `processed`/`ignored` both set `completed_at`, never overload `processed_at`'s prior ambiguous meaning; each terminal `UPDATE` clears `lease_expires_at`."**

**MISSING.** `applyTerminalState()` (`EloquentPaymentProviderEventRepository.php:99–121`, called by both `markProcessed()` and `markIgnored()`) sets `completed_at = NOW()` and `lease_expires_at = NULL` in its `UPDATE` — but a repository-wide search for `markProcessed`/`markIgnored` call sites in `tests/` finds exactly one (`PaymentProviderEventRetryReclaimTest.php:539`), and that method asserts only that the processed event no longer appears in `retryable()`'s candidate list — it never asserts `completed_at` is set or `lease_expires_at` is cleared, and never touches `markIgnored()` at all. **Missing assertion, two new methods** (§3), the natural home being `PaymentProviderEventSchemaTest.php` (already the file proving this model's other terminal-field schema behavior).

**#16 — "Terminal disposition and bounded retention for exhausted events — new this round — `WebhookEventDispositionTest`: an exhausted `failed`/stale-`processing` event... can be dispositioned to `disposed` only from an exhausted state... `WebhookExhaustedPayloadPurgeTest` — a `disposed` event's encrypted payload is purged once past the retention window... while... every timestamp/`disposed_by_user_id`/`disposition_note` remain permanently intact... a merely-exhausted-but-not-yet-`disposed` row is asserted not purged even past the same retention window."**

Both named test classes were merged into one file, `WebhookEventDispositionAndPurgeTest.php` (4 methods), read in full: `test_disposition_requires_an_exhausted_state`, `test_disposition_succeeds_for_an_exhausted_event_and_never_re_enters_processing`, `test_disposed_past_retention_payload_is_purged_while_audit_metadata_survives`, `test_a_merely_exhausted_undispositioned_event_is_never_purged`. Every clause of the requirement is mechanically present. **COMPLETE** (file name differs from §35's two-class naming; coverage is genuine).

**#17 — "Payload purge while preserving replay/idempotency state — unchanged for `processed`/`ignored` events, now also covering `disposed` events per the test above."**

`WebhookEventDispositionAndPurgeTest` (above) plus `PaymentProviderEventSchemaTest::test_payload_purged_at_and_disposition_fields_survive_payload_purge`. **COMPLETE.**

**#18 — "Provider-customer/payment-method uniqueness, including composite-FK enforcement — corrected and expanded this round — `ProviderIdentityUniquenessTest`: unchanged assertions, plus a new assertion that an attempted `business_payment_instruments` insert whose `provider` disagrees with its `provider_customer_id`'s actual `payment_provider_customers.provider` is rejected at the schema level by the composite FK."**

No file named `ProviderIdentityUniquenessTest` exists; coverage is genuine, split across two files. `PaymentProviderCustomerSchemaTest::test_provider_and_provider_customer_id_is_unique` (plus 3 more methods on generated-column/active-customer behavior). `BusinessPaymentInstrumentSchemaTest::test_provider_and_provider_payment_method_id_is_unique`, `::test_composite_fk_rejects_provider_mismatch` — the exact composite-FK rejection the requirement names, read in full and confirmed to expect `QueryException` on a genuine provider-mismatched insert. **COMPLETE.**

**#19 — "Provider-object resolution via untrusted metadata hint, never `event_type` — corrected this round — `ProviderObjectResolutionTest`: a `provider_session_or_intent_reference` value is asserted unique within each of `business_funding_attempts`/`additional_business_slot_agreements`/`additional_business_slot_renewal_charges` independently... resolution loads exactly the one local record named by the event's `metadata` hint and never queries a second table; two identical generic `event_type`s... resolve to their own correct, distinct local records via the hint, never via the shared `event_type` alone. `WebhookMetadataMismatchTest` — new this round — missing, malformed, unknown, ambiguous, or mismatched metadata... produces zero wallet/ledger/reservation/slot/agreement/accounting mutation, marks the event `failed`, and routes it to reconciliation."**

No file named `ProviderObjectResolutionTest` or `WebhookMetadataMismatchTest` exists; coverage is genuine but incomplete. **PARTIAL**, three distinct findings:

1. Per-table `provider_session_or_intent_reference` uniqueness — `BusinessFundingAttemptSchemaTest::test_provider_session_or_intent_reference_is_unique_when_populated` and `AdditionalBusinessSlotAgreementSchemaTest::test_provider_session_or_intent_reference_is_unique_when_populated` both exist and each inserts only into its own table (correctly proving per-table, not cross-table, uniqueness). **`AdditionalBusinessSlotRenewalChargeSchemaTest.php` has no such method**, despite the real migration (`database/migrations/2026_08_20_150003_create_additional_business_slot_renewal_charges_table.php:54`) defining a genuine `UNIQUE` constraint on that exact column (`absrc_provider_session_or_intent_reference_unique`) — confirmed directly by reading the migration. The production constraint is correct; only its test is missing. **Missing assertion, one new method** (§3).
2. Missing/malformed/unknown/ambiguous/mismatched metadata → zero mutation, `failed` state — `WebhookMetadataSpoofMismatchTest` (5 methods: missing metadata, unrecognized subject kind, nonexistent subject id, mismatched provider object id, mismatched operation id) — every method confirmed to assert `failed` state via a real HTTP webhook POST, and the amount/currency/customer-mismatch half is separately, thoroughly covered by `WebhookAmountCurrencyCustomerMismatchTest` (see #13). **COMPLETE** for this sub-clause.
3. Resolution via hint routes correctly per subject kind — `WebhookSlotAgreementSubjectRoutingTest` (5 methods) proves each of the three canonical `app_subject_kind` values routes correctly, including `slot_renewal_charge` reaching the real manager rather than a direct mutation. **However, no single test proves the requirement's own named scenario — two different records (a `funding_attempt` and a `slot_renewal_charge`) sharing the identical generic `event_type` (`payment_intent.succeeded`) resolve to their own correct, distinct records via the metadata hint alone.** Each subject kind is currently proven only in isolation; a regression that resolved by `event_type` instead of by hint (e.g., an accidental fallback) would not be caught by any existing test, since no test constructs both records under an ambiguous shared `event_type` in the same scenario. **Missing assertion, one new method** (§3).

### 2.5 Provider integration, slot agreements, business init, boundaries

**#20 — "`requires_action` auto-recharge behavior, Stripe minimum and maximum enforcement — unchanged."**

`StripeAmountMinMaxValidationTest` (4 methods, exact boundary values at 10,000 / 500,000 / 1,000,000,000,000 / 999,999,990,000 micro, driven through the real `initiateTopUp()` call). `AutoRechargeFailedPaymentRetryTest::test_a_requires_action_outcome_increments_the_failure_counter`, `::test_the_third_consecutive_requires_action_outcome_also_disables_auto_recharge`. **COMPLETE.**

**#21 — "Recurring renewal, `requires_action`, cancellation, proration, and recovery — substantially expanded this round"** (the five named test files).

- `AdditionalBusinessSlotAgreementCancellationTest` (3 methods: flags set without touching `next_renewal_at`; renewal correctly skips a `cancel_at_period_end` agreement even while due; finalization transitions to `canceled` and nulls `next_renewal_at` only once effective). **COMPLETE.**
- `AdditionalBusinessSlotAgreementProrationTest::test_mid_period_increase_freezes_its_exact_positive_allocation_delta_independent_of_amount` — **PARTIAL.** The requirement demands the snapshot "matches the exact-second `bcRoundHalfUp()` computation"; the existing assertion only checks `0 < amount_micro_snapshot < full_price` — a loose bound, not an independent exact-value recomputation, so a formula regression that still lands in-range would pass undetected. **Strengthening of the existing method** (§3) — add an independent `bcRoundHalfUp()` recomputation from the agreement's real stored period boundaries, asserted `assertSame` against the persisted snapshot.
- `AdditionalBusinessSlotAgreementRepeatedIncreaseTest::test_two_distinct_pending_increases_reserve_deltas_from_the_locked_target_never_the_stale_current` — **PARTIAL.** The requirement's own text names `local_idempotency_key` distinctness explicitly; the existing method asserts only `allocation_delta` values, never reading or comparing either charge's `local_idempotency_key`. **Strengthening of the existing method** (§3) — no new file, no new method.
- `AdditionalBusinessSlotAgreementFailedPeriodTest` — **PARTIAL** against the requirement's four sub-clauses: (a) "no `scheduled_renewal` row is created for a later period while retrying" — **not tested at all**, no method exercises a second renewal-initiation call while the current period's charge is still outstanding; (b) "exhausting retries sets `payment_lapsed`" — **COMPLETE** (`test_exactly_three_total_pre_lapse_attempts_produce_lapse`); (c) "no further automatic attempt" — **COMPLETE** (`test_no_automatic_fourth_attempt_ever_occurs`); (d) "recovery recomputes `next_renewal_at` forward from the recovery moment, never retroactively" — **weakly covered**: `test_ordinal_4_is_reachable_only_through_explicit_post_lapse_recovery` asserts only `assertNotNull($agreement->next_renewal_at)`, which cannot distinguish a correct forward-from-recovery computation from an incorrect retroactive one reusing the original `period_end`; (e) "no renewal charge is ever created for a skipped period" — **not directly tested**, no method exercises a multi-period lapse duration with a charge-count assertion. **Two new methods plus one strengthened method** (§3), all placed in this same existing file.
- `AdditionalBusinessSlotAgreementRenewalContactSnapshotTest::test_renewal_charge_email_snapshot_is_frozen_even_after_the_owners_email_changes` — **COMPLETE** for the `scheduled_renewal` charge kind. (The identical snapshot mechanism also applies, in production code, to `mid_period_increase` charges — not separately tested, but the underlying code path is identical and this is not classified as a hard gap.)

**#22 — "Slot and renewal transition audits, slot payment/allocation saga, slot authority blocker — unchanged."**

`SlotAgreementAllocationSagaTest` (9 methods, exact transition sequences through the real `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()` saga, crash-replay idempotency). `SlotAgreementAdminAuthorityTest` (7 methods). `SlotAgreementConcurrencyTest` — genuine `Symfony\Process` cross-process races for both allocation and retry (2 of its 4 methods are legitimately sequential single-process proofs for a different, non-racy invariant, per the file's own docblock — not a defect, see stale-test-smell note below). `AdditionalBusinessSlotAgreementTransitionSchemaTest` (append-only, FK-restricted). **COMPLETE.**

**#23 — "Post-rollout Business initialization... `NewBusinessWalletInitializationTest` (M1)... `NewBusinessPayerAssignmentInitializationTest` (M2, new)... a Business created between M1 and M2 deploy receives its payer assignment via the M2 backfill migration, asserted directly."**

`NewBusinessWalletInitializationTest` and `NewBusinessPayerAssignmentInitializationTest` both dispatch the real `BusinessCreated`/`BusinessAssignedToWorkspace` events and assert exactly-one-row, plus duplicate-redelivery idempotency. `BackfillBusinessPayerAssignmentsTest::test_backfill_creates_an_assignment_for_a_business_missing_one` creates a Business via the direct-repository path (no event dispatch, faithfully simulating pre-M2 creation per its own docblock), asserts the assignment is initially missing, runs the real M2 backfill migration directly, and asserts it now exists. **COMPLETE.**

**#24 — "Cross-table Business/wallet mismatch rejection, sensitive payload retention/redaction, provider-cost non-disclosure, invoice/receipt boundary, mechanical source-boundary test, webhook/provider fakes, database — unchanged."**

- Cross-table mismatch rejection: all four composite-FK-protected tables (`business_usage_ledger_entries`, `business_usage_reservations`, `business_usage_wallet_billing_status_transitions`, `business_funding_attempts`) each have a dedicated `QueryException`-expecting schema test. **COMPLETE.**
- Sensitive payload retention/redaction: `WebhookEventDispositionAndPurgeTest` (see #16/#17). **COMPLETE.**
- Provider-cost non-disclosure: **PARTIAL/MISSING.** `provider_cost_micro` is confirmed, by direct repository-wide grep, to appear only in admin-surface files (`UsageBillingController.php`, the ledger repository, the models) — never in the customer-facing `UsageBillingPresenter` or customer controller — so non-disclosure is structurally true today. But no test in `UsageBillingDashboardViewDataTest.php`/`UsageBillingDashboardAuthorizationTest.php` (the customer-facing dashboard tests) asserts a non-admin actor's view model never exposes `provider_cost_micro` or a margin figure — there is no regression test protecting this invariant if a future change wires the field into a customer surface by mistake. **Missing assertion, one new method** (§3).
- Invoice/receipt boundary: `ReceiptBoundaryTest` (18 methods — schema, migration DDL, slot-agreement non-eligibility, both Checkout/PaymentIntent evidence-resolution paths, existing-receipt no-op, recovery-after-failure, after-commit dispatch, notification opt-out, legacy-table absence, verbatim-URL-never-reconstructed, fake-gateway-only binding). **COMPLETE.**
- Mechanical source-boundary test: `EntitlementCatalogSourceBoundaryTest` (M4/RFC-004 boundary), `AdminUsageBillingSurfaceBoundaryTest` (admin-surface 30-path allow-list, count independently re-verified this pass). **COMPLETE.**
- Webhook/provider fakes: `FakePaymentProviderGatewayTest`, `StripePaymentProviderGatewayCompatibilityTest` (reflection-only, zero live network calls). **COMPLETE.**
- "Database" (generic, unnamed): too vague to mechanically audit as a discrete, checkable item — satisfied generically by the large population of `*SchemaTest.php` files across the tree. Not classified as a gap; flagged as imprecise §35 wording, no test authorized against it specifically.

**#25 — "Gate shape — unchanged, six commands."**

**SUPERSEDED — stale description, not a coverage gap.** Directly compared the `required_test_commands`-equivalent regression-gate section across four RFC-005 governance documents: `RFC-005-M1-CONTRACT.md` §14 (six explicit commands: Usage/Entitlement/Workspace/Business/Opportunity regressions plus one full-suite command), `RFC-005-M4-CONTRACT.md` §27 (a differently-composed six, collapsing two of M1's suites into one and adding two M4-specific categories), `RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md` §15 (three commands), `RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md` (two required commands plus a separate three-item pre-commit checklist). **The pattern is real and was correctly, mechanically derived once, in `RFC-005-M6-CONTRACT.md`'s own "Deriving RFC-005's own six-gate regression mechanically" section** (one regression command per shipped RFC's own targeted suite, plus one full-suite command — genuinely six only for RFC-005's own eventual M6 final release gate, not for every remediation's own narrower implementation-verification gate). §35's flat "six commands" line conflates the eventual M6 release-gate shape with the per-remediation implementation-verification gate shape, which has correctly and legitimately varied from two to nineteen commands across this RFC's own remediation history depending on scope. **No test file is missing or wrong; §35's own wording should be reworded** (not by this contract — see "Recommended wording corrections," below) to distinguish the two gate types explicitly.

---

### 2.6 Blocking-finding assessment

Design requirement #3 of this contract's own instructions requires recording, as a blocking finding, any genuine current product defect this audit discovers — and requires this contract to stop before authorizing a misleading test-only completion if one is found. **This audit found none.** The two candidates that could be mistaken for one are addressed here explicitly, so a human reviewer can independently check this judgment:

1. **No wallet-counter reconciliation job exists in `app/Jobs/Usage/`** (only `ReconcileProviderPendingState` and `ReconcileSlotAgreementAllocation` exist, neither touching `committed_spend_this_period_micro`/`recharged_this_period_micro`), despite §35's #4 text naming "the reconciliation job's independent recomputation... asserted to match the cached value at every step." **This is not classified as a product defect.** Nothing in the shipped system is broken: the underlying safety property — these two counters are formula-derived and never manually mutated — is independently, mechanically provable today without any such job, via (a) `FormulaDerivedCountersNeverManuallyMutatedM2Test`'s own source-grep proof that no method assigns to either column directly, and (b) the from-scratch ledger recomputation this contract strengthens in §3. No milestone or remediation contract from M1 through Remediation #6 ever authorized building a dedicated wallet-counter reconciliation job as required production scope; §35's own prose named a speculative verification mechanism at RFC-drafting time that no subsequent, separately-authorized implementation contract ever committed to building — the same category of drift as the "six commands" line in #25, not a bug. Building such a job now would itself be new product scope (a new Job class, a new schedule entry, a decision about what action to take on divergence) requiring its own contract with its own justification — explicitly forbidden by this contract's own production allow-list (empty) and governance section. **No correction contract is required for this.** A human governance decision may, separately and later, choose to authorize such a job as defense-in-depth (mirroring the `ReconcileProviderPendingState` precedent) — that decision is out of scope here and not recommended or opposed by this document.
2. **`monthly_recharge_cap_micro` changes do not route through `business_usage_limit_transitions`**, contradicting §35's #4 text. **Not a product defect** — confirmed this was never the shipped M2 design (`database/migrations/2026_08_16_130003_create_business_usage_limit_transitions_table.php`'s own docblock scopes that table to three specific limit types, never the recharge cap, matching M2 contract §11.3 exactly). §35's later-round text describes the recharge cap slightly imprecisely relative to the earlier, separately-authorized M2 design; the M2 design itself is not wrong.

**Conclusion: the production allow-list for this contract (§4) is empty.** Every finding above is a test-coverage gap, a weak/loose assertion needing strengthening, or a stale description in RFC-005's own §35 prose — never a defect in shipped, currently-executing behavior.

### 2.7 Recommended wording corrections (informational — not authorized or performed by this contract)

This contract does not modify `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` (out of its own allow-list, §4). For a future governance pass that may choose to correct §35's own text, this audit recommends, without authorizing:

- Reword the "deterministic tied-activation-timestamp lookup" clause (#1) to reflect the Amendment 1 Slice 3 direct-FK-pointer design, or remove it as moot.
- Reword the `monthly_recharge_cap_micro`-via-`business_usage_limit_transitions` clause (#4) to match the shipped M2 design (a direct wallet-column write, no transition row).
- Reword or remove the "reconciliation job" clause (#4) to describe the actual verification method in use (an independent test-side, from-scratch ledger recomputation), or explicitly defer a real reconciliation job to a future, separately-authorized remediation.
- Reword the "Gate shape — unchanged, six commands" clause (#25) to distinguish M6's own eventual six-suite release gate from each remediation's own scope-appropriate implementation-verification gate.

---

## 3. Locked test allow-list — exact paths, methods, and proposed bodies

**Every path below is an existing test file. No new test file is authorized. No production file is authorized (§4).** Every method listed as "NEW" is a proposed addition; every method listed as "STRENGTHEN" is an existing method whose body changes (fixture and/or assertions), with its current, insufficient assertion named so the difference is checkable at review time.

### 3.1 `tests/Feature/Usage/UsageCalendarMonthRolloverTest.php`

- **NEW** `test_dst_fall_back_boundary_in_business_timezone`: Business timezone `America/New_York`; wallet's `spend_period_key` established in October 2026; force `spend_period_end_utc` to the moment of the 2026-11-01 fall-back transition (clocks repeat 01:00–02:00 local); trigger rollover via a `reserve()` call; assert the new period's `spend_period_start_utc`/`spend_period_end_utc` correspond to genuine local calendar-month boundaries, computed via the same `Carbon`-timezone-aware construction the spring-forward test already uses, never a fixed-duration (`addDays(30)`-style) approximation that would silently misplace the boundary by an hour across the repeated local hour. **Fails against** a fixed-duration rollover implementation (would place the boundary an hour off across the repeated-hour ambiguity); **passes against** the current timezone-aware `Carbon` construction.

### 3.2 `tests/Feature/Usage/UsageWalletManagerCommittedSpendFormulaTest.php`

- **STRENGTHEN** `test_committed_spend_matches_from_scratch_ledger_recomputation` (current: available seeded at 20,000,000, overage always resolves entirely from available, `debt_delta_micro=0` throughout): reduce the seeded available balance before the overage commit so `$overageFromAvailable < $overageAmount`, producing a genuine `debt_delta_micro > 0` for that ledger row; keep the existing from-scratch recomputation loop unchanged, since it already sums both `-reserved_delta_micro` and `(-available_delta_micro)+debt_delta_micro` correctly — only the fixture's numbers change. **Fails against** a regression that recomputes only the available-delta half of the overage formula and drops the debt term; **passes against** the current, correct two-term formula.
- **STRENGTHEN** `test_cap_configuration_change_is_prospective_only` (current: mutates `monthly_spend_cap_micro` via a raw `DB::table('business_usage_wallets')->update(...)`, bypassing `setSpendCap()` entirely): replace the raw update with a real `UsageWalletManager::setSpendCap()` call, matching `UsageWalletManagerSpendCapTest`'s own established pattern, keeping the existing before/after assertions on `committed_spend_this_period_micro`. **Fails against** a regression inside `setSpendCap()` itself (e.g., one that incorrectly rewrites the committed-spend counter); the current raw-update version could never fail against such a regression since it never calls the method at all.
- **NEW** `test_committing_a_reservation_from_a_prior_rolled_over_period_does_not_inflate_the_new_periods_committed_spend`: reserve a unit against the wallet's current period; force the wallet's own calendar rollover forward (via the same rollover-forcing technique `UsageCalendarMonthRolloverTest`/`UsagePeriodMultiMonthDormancyTest`-equivalent methods use — advancing `spend_period_end_utc` into the past and triggering a subsequent wallet operation) so `business_usage_wallets.spend_period_key` now differs from the reservation's own `period_key`; commit the stale reservation; assert the **new** period's `committed_spend_this_period_micro` is unaffected by this commit (per `UsageWalletManager::commit()`'s `$isCurrentPeriod` guard) while the ledger entry and available/debt deltas are still applied correctly and a from-scratch recomputation for the *reservation's own original period* matches. **Fails against** a regression that increments the current period's counter regardless of the committed reservation's own stale `period_key`; **passes against** the current `$isCurrentPeriod`-guarded formula.

### 3.3 `tests/Feature/Usage/UsageWalletManagerReversalTest.php`

- **NEW** `test_a_provider_refund_never_decrements_committed_spend_this_period_micro`: seed a wallet with a known `committed_spend_this_period_micro`, apply a real `UsageWalletManager::applyProviderRefund()` reversal (reusing this file's own existing refund fixture), re-fetch the wallet row, assert `committed_spend_this_period_micro` is byte-for-byte unchanged from its pre-refund value. **Fails against** a regression that decrements committed spend on refund (reopening spend-cap headroom incorrectly); **passes against** the current formula, which never touches this counter on reversal.
- **NEW** `test_a_dispute_chargeback_never_decrements_committed_spend_this_period_micro`: identical structure, driven through a real `applyDisputeWithdrawal()` call (reusing this file's own existing chargeback fixture).
- **NEW** `test_recharge_cap_configuration_is_never_reopened_by_a_reversal_entry`: seed a wallet with `recharged_this_period_micro` at a known value; apply a real refund and a real dispute-chargeback reversal in sequence (reusing this file's existing fixtures); re-fetch the wallet row after each; assert `recharged_this_period_micro` is unchanged by either. **Fails against** a regression that treats a reversal as freeing recharge-cap headroom; **passes against** current behavior, which never writes this column from any reversal method.

### 3.4 `tests/Feature/Usage/AutoRechargeThresholdAndCapTest.php`

- **NEW** `test_a_real_reservation_below_threshold_triggers_auto_recharge_end_to_end`: attach a real payment instrument, enable auto-recharge with a real threshold via `configureAutoRecharge()`, seed the wallet's available balance just below that threshold, run with `QUEUE_CONNECTION=sync` (mirroring `NoAutoRechargeDispatchAtM1Test`'s own established technique for asserting inline dispatch), call the real `UsageWalletManager::reserve()` (not `EvaluateBusinessAutoRecharge::dispatch()` directly), and assert the wallet's available balance increases by the configured recharge amount as a direct, traceable result of that single `reserve()` call. **Fails against** a regression that breaks the inline dispatch wiring inside `reserve()`/`commit()` (e.g., an accidentally-removed `EvaluateBusinessAutoRecharge::dispatch()` call) while `EvaluateBusinessAutoRecharge::dispatch()` itself, called directly, would still succeed and mask the break; **passes against** the current, correctly-wired reservation-triggered path.

### 3.5 `tests/Feature/Usage/FundingAttemptPayerConsentTest.php`

- **NEW** `test_platform_administrator_cannot_enable_auto_recharge_under_any_payer_type`: build an entitled workspace/business fixture identical to this file's existing top-up-denial test; call `UsageWalletManager::configureAutoRecharge()` directly with an `is_admin=true` actor id, for both `PayerType::Workspace` and `PayerType::Business`; assert `UnauthorizedPayerAssignmentException` (or the manager's equivalent consent exception) is thrown in both cases, before any state mutation. **Fails against** a regression that adds (or already contains) an admin bypass inside `assertChargeCausingConsentForAutoRecharge()`; **passes against** current behavior, which the existing boundary/grep test cannot detect since it never calls the manager method directly.

### 3.6 `tests/Feature/Usage/PayerChangeDuringPendingAttemptTest.php`

- **NEW** `test_updating_the_billing_contact_never_rewrites_a_funding_attempts_frozen_snapshot`: create a funding attempt (capturing its `billing_contact_name_snapshot`/`billing_contact_email_snapshot` at creation time); call `BillingProfileManager::updateBillingContact()` with different name/email values; re-fetch the funding attempt from the database; assert both snapshot columns remain exactly their original, pre-update values. **Fails against** a regression that re-derives these columns from the current billing contact on read or on a later write; **passes against** current behavior, which populates them once, at creation, and never rewrites them.

### 3.7 `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php`

- **STRENGTHEN** `test_checkout_session_completed_webhook_confirms_an_addon_purchase` (current: asserts the purchase reaches `Completed`, never asserts `source`): add an assertion that the resulting `business_usage_addon_purchase_transitions` row's `source` equals `TransitionSource::WebhookEvent->value`.
- **STRENGTHEN** `test_wallet_credit_addon_purchase_dispatches_exactly_one_send_receipt_notification` (current: this method drives `confirmAttemptFromReturn()`, asserts the notification dispatch, never asserts `source`): add an assertion that the resulting transition row's `source` equals `TransitionSource::SyncResponse->value`. **Together these two fail against** a regression that records the wrong `source` value on either completion path (e.g. both hardcoded to the same value, or swapped); **pass against** current behavior, which correctly threads the calling method's own source through to `completeAddonPurchaseUnderLock()`.

### 3.8 `tests/Feature/Usage/AdditionalBusinessSlotAgreementProrationTest.php`

- **STRENGTHEN** `test_mid_period_increase_freezes_its_exact_positive_allocation_delta_independent_of_amount` (current: asserts only `0 < amount_micro_snapshot < full_price`): add an independent recomputation — from the agreement's real, stored `next_renewal_at` (period end) and a captured `now()` at the moment of the increase request, compute `$periodStart = $periodEnd->subMonthNoOverflow()`, `$remainingSeconds`/`$totalSeconds`, and `bcRoundHalfUp(bcmul($fullAmount, $remainingSeconds, 0), $totalSeconds)` independently in the test — and assert `assertSame` against the persisted `amount_micro_snapshot`. **Fails against** a formula regression that still lands the result strictly between 0 and the full price (e.g. an off-by-one-day approximation); **passes against** the current exact-second implementation.

### 3.9 `tests/Feature/Usage/AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php`

- **STRENGTHEN** `test_two_distinct_pending_increases_reserve_deltas_from_the_locked_target_never_the_stale_current` (current: asserts only `allocation_delta` values): additionally fetch both charges' `local_idempotency_key` values and assert `assertNotSame` between them. **Fails against** a regression that derives `local_idempotency_key` from a value shared between the two distinct `change_operation_id`s (e.g. omitting the operation id from the hash); **passes against** the current per-operation-id derivation.

### 3.10 `tests/Feature/Usage/AdditionalBusinessSlotAgreementFailedPeriodTest.php`

- **NEW** `test_no_scheduled_renewal_row_is_created_for_a_later_period_while_the_current_periods_renewal_is_still_being_retried`: create an agreement with a due `next_renewal_at`; call `createScheduledRenewalCharge()` once (producing a `created`/`failed`, pre-lapse charge); without advancing past lapse, invoke the renewal-initiation path (`InitiateSlotAgreementRenewal`'s own query, `findDueForRenewal()`) a second time; assert `DB::table('additional_business_slot_renewal_charges')->where('agreement_id', ...)->where('charge_kind', 'scheduled_renewal')->count()` remains `1`. **Fails against** a regression that creates a second concurrent scheduled-renewal charge for the same still-outstanding period; **passes against** current behavior.
- **NEW** `test_recovery_after_a_multi_period_lapse_creates_no_charge_for_any_skipped_period`: force a lapse (reusing `test_exactly_three_total_pre_lapse_attempts_produce_lapse`'s own fixture technique), let two full calendar periods elapse while lapsed, then trigger recovery; assert the total count of `additional_business_slot_renewal_charges` rows for the agreement reflects only the pre-lapse attempts plus exactly one post-recovery charge — never one row per skipped period. **Fails against** a regression that back-fills a charge for each skipped period on recovery; **passes against** current behavior.
- **STRENGTHEN** `test_ordinal_4_is_reachable_only_through_explicit_post_lapse_recovery` (current: asserts only `assertNotNull($agreement->next_renewal_at)`): additionally capture `now()` immediately before triggering recovery, and assert the recovered `next_renewal_at` falls within a tight tolerance (e.g. a few seconds) of `now()->addMonthNoOverflow()`, and explicitly assert it differs from the original, pre-lapse charge's own `period_end`. **Fails against** a regression that recomputes `next_renewal_at` retroactively from the original, stale `period_end` instead of forward from the recovery moment; **passes against** current behavior.

### 3.11 `tests/Feature/Usage/UsageBillingDashboardViewDataTest.php`

- **NEW** `test_the_customer_dashboard_view_model_never_exposes_provider_cost_or_margin_fields`: build the real customer-facing dashboard view model (via this file's own existing view-model-building fixture) for a business with known ledger activity including provider-cost-bearing entries; assert the resulting view model's array/object never contains a `provider_cost_micro` key or any margin-derived field, at any nesting level the presenter exposes. **Fails against** a regression that wires `provider_cost_micro` or a margin computation into the customer-facing presenter; **passes against** current behavior, which never does so.

### 3.12 `tests/Feature/Usage/AdditionalBusinessSlotRenewalChargeSchemaTest.php`

- **NEW** `test_provider_session_or_intent_reference_is_unique_when_populated`: mirroring `BusinessFundingAttemptSchemaTest`'s and `AdditionalBusinessSlotAgreementSchemaTest`'s own identically-named methods exactly — insert one `additional_business_slot_renewal_charges` row with a given `provider_session_or_intent_reference`, then attempt a second insert with the same value, expecting `QueryException` via the real `absrc_provider_session_or_intent_reference_unique` constraint. **Fails against** a migration regression that drops or weakens this constraint; **passes against** the current schema.

### 3.13 `tests/Feature/Usage/WebhookSlotAgreementSubjectRoutingTest.php`

- **NEW** `test_two_events_sharing_the_identical_generic_event_type_resolve_to_their_own_distinct_records_via_the_metadata_hint`: create both a pending funding attempt (auto-recharge) and a pending slot renewal charge in the same test, both awaiting a `payment_intent.succeeded` webhook; post two separate `payment_intent.succeeded` webhooks — one with `metadata.app_subject_kind=funding_attempt` naming the attempt, one with `metadata.app_subject_kind=slot_renewal_charge` naming the charge; assert each resolves to, and mutates, only its own named record (the funding attempt's wallet credit and the renewal charge's own state), and that neither event's processing touches the other record. **Fails against** a regression that resolves by `event_type` (or falls back to it under any condition) instead of exclusively by the metadata hint, which would risk cross-resolving the two identically-typed events; **passes against** current behavior, which always resolves via the hint.

### 3.14 `tests/Feature/Usage/PaymentProviderEventSchemaTest.php`

- **NEW** `test_marking_processed_sets_completed_at_never_processed_at_and_clears_the_lease`: create an event in `processing` state with a non-null `lease_expires_at`; call `EloquentPaymentProviderEventRepository::markProcessed()`; re-fetch the row; assert `completed_at` is set, `lease_expires_at` is `null`, and (via `DB::getSchemaBuilder()->getColumnListing(...)` or a direct raw-row check) that no `processed_at` column is written to or relied upon.
- **NEW** `test_marking_ignored_sets_completed_at_never_processed_at_and_clears_the_lease`: identical structure via `markIgnored()`. **Together these two fail against** a regression that reintroduces a separate, ambiguous `processed_at` write or fails to clear `lease_expires_at` on either terminal transition; **pass against** current behavior (`applyTerminalState()`, unchanged since Remediation #6).

### 3.15 Exact method-count summary

| File | New methods | Strengthened methods |
|---|---|---|
| `UsageCalendarMonthRolloverTest.php` | 1 | 0 |
| `UsageWalletManagerCommittedSpendFormulaTest.php` | 1 | 2 |
| `UsageWalletManagerReversalTest.php` | 3 | 0 |
| `AutoRechargeThresholdAndCapTest.php` | 1 | 0 |
| `FundingAttemptPayerConsentTest.php` | 1 | 0 |
| `PayerChangeDuringPendingAttemptTest.php` | 1 | 0 |
| `AddonPurchaseTransitionAuditTest.php` | 0 | 2 |
| `AdditionalBusinessSlotAgreementProrationTest.php` | 0 | 1 |
| `AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php` | 0 | 1 |
| `AdditionalBusinessSlotAgreementFailedPeriodTest.php` | 2 | 1 |
| `UsageBillingDashboardViewDataTest.php` | 1 | 0 |
| `AdditionalBusinessSlotRenewalChargeSchemaTest.php` | 1 | 0 |
| `WebhookSlotAgreementSubjectRoutingTest.php` | 1 | 0 |
| `PaymentProviderEventSchemaTest.php` | 2 | 0 |
| **Total (14 files)** | **15 new** | **7 strengthened** |

**Grand total: 22 new-or-modified methods across exactly 14 existing test files. Zero new files. Zero production files.**

---

## 4. Allow-lists

**Production allow-list: empty.** No genuine current product defect was independently discovered (§2.6); no product, schema, migration, config, route, controller, manager, repository, model, job, event, or notification file may be touched by any implementation of this contract.

**Test allow-list — exactly these 14 existing paths, all under `tests/Feature/Usage/`:**

1. `tests/Feature/Usage/UsageCalendarMonthRolloverTest.php`
2. `tests/Feature/Usage/UsageWalletManagerCommittedSpendFormulaTest.php`
3. `tests/Feature/Usage/UsageWalletManagerReversalTest.php`
4. `tests/Feature/Usage/AutoRechargeThresholdAndCapTest.php`
5. `tests/Feature/Usage/FundingAttemptPayerConsentTest.php`
6. `tests/Feature/Usage/PayerChangeDuringPendingAttemptTest.php`
7. `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php`
8. `tests/Feature/Usage/AdditionalBusinessSlotAgreementProrationTest.php`
9. `tests/Feature/Usage/AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php`
10. `tests/Feature/Usage/AdditionalBusinessSlotAgreementFailedPeriodTest.php`
11. `tests/Feature/Usage/UsageBillingDashboardViewDataTest.php`
12. `tests/Feature/Usage/AdditionalBusinessSlotRenewalChargeSchemaTest.php`
13. `tests/Feature/Usage/WebhookSlotAgreementSubjectRoutingTest.php`
14. `tests/Feature/Usage/PaymentProviderEventSchemaTest.php`

**Required new paths: none.** Every file above already exists; this remediation only modifies existing files.

No path outside this exact 14-entry list may be touched. Any additional path required during implementation is a stop-and-report condition, not a silent addition — identical to every prior RFC-005 remediation's own scope discipline.

---

## 5. Deduplication against Remediations #1–#6

No test proposed in §3 duplicates a scenario already fully covered by an earlier remediation:

- The refund-race/audit-ambiguity scenarios Remediation #6 authorized (`ProviderRefundDisputeConcurrencyTest`, `PaymentProviderEventDurableAuditTest`) are untouched and unreferenced by this contract's own new methods — §3.14's two new methods test a distinct, narrower claim (`markProcessed()`/`markIgnored()`'s own field-level terminal shape), never the refund/dispute outcome logic itself.
- The retry/reclaim rework Remediation #6 authorized (`PaymentProviderEventRetryReclaimTest`, 44 methods) is confirmed, this pass, to remain entirely orthogonal to `claim()`/`exhausted()`/`dispose()`'s own unchanged contract (§2.4, #13–#14) — no method in §3 touches `retryable()` or duplicates any of that file's 44 methods.
- The Funding Confirmation Concurrency Correction's own idempotency-under-lock proofs (`FundingConfirmationConcurrencyCorrectionTest`) are cited only as existing, sufficient coverage in §2.3 (#11) — no new method is proposed against that file.
- The Admin Usage Billing Surface's own boundary test (`AdminUsageBillingSurfaceBoundaryTest`) is cited as existing, correctly-scoped coverage for its own narrow claim (no HTTP route calls `configureAutoRecharge`) — §3.5's new method deliberately targets the *manager* boundary instead, a distinct claim that file was never designed to prove.

---

## 6. Stale-test-smell audit — full findings, actioned and non-actioned

Per this contract's own required audit scope, every category named in the task was checked across the full 132-file suite:

- **Assertions proving only part of a requirement:** `UsageWalletManagerCommittedSpendFormulaTest::test_committed_spend_matches_from_scratch_ledger_recomputation` (§2.1 #3, actioned §3.2); `AdditionalBusinessSlotAgreementProrationTest`'s loose-bound assertion (§2.5 #21, actioned §3.8); `AdditionalBusinessSlotAgreementFailedPeriodTest`'s weak recovery-timing assertion (§2.5 #21, actioned §3.10).
- **Mocked behavior bypassing the production boundary it claims to prove:** `UsageWalletManagerCommittedSpendFormulaTest::test_cap_configuration_change_is_prospective_only`'s raw-DB-update bypass of `setSpendCap()` (§2.1 #4, actioned §3.2); `BusinessUsageAddonPurchaseTransitionSchemaTest`'s hardcoded `source` literal, which proves only FK/append-only shape, never correctness (§2.3 #12 — not actioned; this file's own narrow schema-only purpose is legitimate, the correctness proof is added elsewhere, §3.7).
- **Sequential calls incorrectly relied upon as concurrency proof:** `AddonPurchaseTransitionAuditTest`'s and `FundingConfirmationConcurrencyCorrectionTest`'s "genuinely simultaneous" double-confirmation methods are in-process, sequential calls relying on the production row lock to serialize correctly — a legitimate technique for proving mutual-exclusion-under-lock, distinct from (and not mislabeled as a substitute for) genuine multi-process racing, which this same codebase correctly uses elsewhere (`PayerAssignmentConcurrencyTest`, `UsageWalletManagerConcurrencyTest`, `ProviderRefundDisputeConcurrencyTest`, `SlotAgreementConcurrencyTest`'s own two genuine-race methods) whenever the property under test is actually order-dependent. **Not actioned** — no genuine race exists for these specific scenarios to fail to prove; the lock-serialization technique is appropriate here. Two of `SlotAgreementConcurrencyTest`'s four methods share this same legitimate pattern for a different, non-racy invariant, per that file's own docblock.
- **`Queue::fake()`/`Event::fake()` hiding required after-commit behavior:** none found; every usage inspected across the audited files was narrowly scoped and did not mask an after-commit assertion this contract's scope depends on.
- **Model snapshot asserted without re-fetching persisted state:** none found in any file this audit read in full — the suite consistently re-fetches via repository `findById()`/`DB::table(...)->first()` calls rather than trusting an in-memory return value, including every method proposed for strengthening in §3.
- **Fixed IDs/provider references colliding with unique constraints:** none found; every fixture generating a provider reference uses `uniqid()` or an equivalent per-test-unique value.
- **Boundary/grep tests whose prohibited/allowed string set no longer matches current code:** `AdminUsageBillingSurfaceBoundaryTest::test_the_admin_usage_billing_controller_never_calls_configure_auto_recharge` remains accurate (the string genuinely does not appear in the admin controller) but was being implicitly over-relied-upon as the sole proof of a manager-level invariant it cannot prove — addressed by adding the manager-level test directly (§3.5), not by changing the boundary test itself, which remains correct for its own narrower claim.
- **Non-persisted fixture (informational only):** `SlotAgreementAdminAuthorityTest::test_a_non_admin_actor_directly_invoking_retry_as_administrator_is_denied` constructs a throwaway, never-persisted model instance for a case where the exception fires before any query executes — no collision risk, not actioned.

---

## 7. Required regression plan

Every command below must be run, in this order, against the disposable `ultimatesms_testing` database only, exactly as `AGENTS.md` requires. A command that exits zero but discovers zero tests is a failure.

**7.1 — Every newly created or modified test file, individually** (14 commands; each must report the exact positive method count named):

| Command | Expected method count |
|---|---|
| `php artisan test tests/Feature/Usage/UsageCalendarMonthRolloverTest.php` | 6 (5 existing + 1 new) |
| `php artisan test tests/Feature/Usage/UsageWalletManagerCommittedSpendFormulaTest.php` | file's existing count + 1 new (2 strengthened, same count) |
| `php artisan test tests/Feature/Usage/UsageWalletManagerReversalTest.php` | file's existing count + 3 new |
| `php artisan test tests/Feature/Usage/AutoRechargeThresholdAndCapTest.php` | file's existing count + 1 new |
| `php artisan test tests/Feature/Usage/FundingAttemptPayerConsentTest.php` | file's existing count + 1 new |
| `php artisan test tests/Feature/Usage/PayerChangeDuringPendingAttemptTest.php` | file's existing count + 1 new |
| `php artisan test tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` | 11 (unchanged — 2 strengthened, no new method) |
| `php artisan test tests/Feature/Usage/AdditionalBusinessSlotAgreementProrationTest.php` | 2 (unchanged — 1 strengthened, no new method) |
| `php artisan test tests/Feature/Usage/AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php` | 3 (unchanged — 1 strengthened, no new method) |
| `php artisan test tests/Feature/Usage/AdditionalBusinessSlotAgreementFailedPeriodTest.php` | file's existing count + 2 new (1 strengthened, same count contribution) |
| `php artisan test tests/Feature/Usage/UsageBillingDashboardViewDataTest.php` | file's existing count + 1 new |
| `php artisan test tests/Feature/Usage/AdditionalBusinessSlotRenewalChargeSchemaTest.php` | file's existing count + 1 new |
| `php artisan test tests/Feature/Usage/WebhookSlotAgreementSubjectRoutingTest.php` | 5 (unchanged count) + 1 new = 6 |
| `php artisan test tests/Feature/Usage/PaymentProviderEventSchemaTest.php` | 3 (unchanged count) + 2 new = 5 |

*(Exact pre-existing counts for the files whose current method count was not independently re-confirmed to the same mechanical certainty as `AddonPurchaseTransitionAuditTest.php` (11), `AdditionalBusinessSlotAgreementProrationTest.php` (2), `AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php` (3), `WebhookSlotAgreementSubjectRoutingTest.php` (5), and `PaymentProviderEventSchemaTest.php` (3) — all five confirmed directly via `grep -c "public function test_"` this pass — must be independently re-confirmed by the implementer via the same command immediately before adding any method, and the exact new total reported in the implementation's own completion evidence, exactly as `AGENTS.md` requires.)*

**7.2 — Complete `tests/Feature/Usage` + `tests/Unit/Usage` suite:**

```
php artisan test tests/Feature/Usage tests/Unit/Usage
```

Must report a positive count strictly greater than the pre-implementation baseline by exactly 15 (the net new-method count; strengthened methods do not change the total), 0 failed.

**7.3 — Full repository suite:**

```
php artisan test --stop-on-failure
```

Must report 0 failed.

**7.4 — Diff hygiene:**

```
git diff --check
```

Must exit clean.

**7.5 — Mechanical positive-count statement.** This contract locks the **net total new/modified method count at 22** (15 new + 7 strengthened) across the 14 files in §4. The implementer's own completion evidence must state the exact pre-implementation and post-implementation total method counts for `tests/Feature/Usage` + `tests/Unit/Usage` combined, and the arithmetic difference must equal exactly 15.

---

## 8. Forbidden scope

- No implementation is currently authorized by this document — a separate authorization pull request, pinning this contract's exact branch/starting head, is required before any test file is written, mirroring every prior RFC-005 milestone/remediation's own contract-then-authorization-then-implementation sequence.
- No path outside the exact 14-entry test allow-list (§4) may be touched; any additional path required during implementation is a stop-and-report condition, never a silent addition.
- No product, schema, migration, config, route, controller, manager, repository, model, job, event, or notification file may be modified under any circumstance by this remediation — the production allow-list is empty (§2.6, §4).
- No RFC-005 Milestone 6 work of any kind — no conformance document, no deployment guide, no release/tag work — is authorized by this contract or by its eventual implementation.
- No live Stripe action, no live refund, no live dispute simulation, no rate activation, no meter activation, no pilot activation, in any real (non-test) environment.
- No touching `docs/automation/AI-AUTONOMY-STATE.json`, `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`, `docs/automation/RFC-005-M6-CONTRACT.md`, or any of the eight already-merged, already-closed pre-M6 remediation contracts/closures, as part of this contract or its implementation.
- No re-opening, redesigning, or silently reinterpreting anything any of the eight already-merged pre-M6 remediations already resolved.
- Setting `advance_automatically` true, setting `start_automatically_after_contract_merge` true, changing `merge_policy` from `human_only`, or weakening `require_exact_scope` or the positive-test-count gate.
- Force-pushing, pushing directly to `main`, or tagging.
- Beginning any RFC-005 Milestone 6 work, drafting an M6 conformance document, or treating this contract's own eventual completion as authorizing M6 — it does not (§ Governance, above).

---

## 9. Completion criteria for a future implementation of this contract

An implementation of this contract is complete only when:

1. Exactly the 14 files in §4 are modified, containing exactly the 22 new/strengthened methods locked in §3 — no fewer, no more, no substitutions without a stop-and-report.
2. Every command in §7 passes with the exact positive counts §7 locks.
3. `git diff --name-only origin/main...HEAD` (against this contract's own eventual merge point) returns exactly the 14 paths in §4.
4. `docs/automation/AI-AUTONOMY-STATE.json`, `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`, and every existing `docs/automation/RFC-005-*` document are byte-identical to their pre-implementation state.
5. Independent review finds no blocking finding.
6. A human merges the implementation pull request — never automation.

Upon completion, §35's coverage gaps enumerated in §2 above have zero unexplained remainder: every requirement is either already-COMPLETE (cited with exact evidence, §2), SUPERSEDED with exact evidence and no test action required (§2), or closed by an exact, locked method in §3. **§35 has zero unexplained coverage gaps after this remediation, conditioned on the wording corrections recommended in §2.7 being understood as informational, not load-bearing — no §35 clause is left silently unaddressed.**

Remediation #7's own completion, once merged and closed, does **not** authorize M6. A fresh post-remediation audit or replacement of `docs/automation/RFC-005-M6-CONTRACT.md` remains a separate, required, explicitly-human-authorized prerequisite before any M6 work may begin (§ Governance, above).

---

## 10. Closure

**Status: CLOSED / COMPLETE.** Implementation PR [#159](https://github.com/os-creator1/os-ai/pull/159) (`agent/rfc-005-test-coverage-completion-v2`) applied this contract's §3 exactly — no substitution, no scope change. Final product head `d44f919814ddce9caed126f3e93081469d20ad5b`, human-merged into `main` as `c0f3f3a19fecb13e1a72390f26033c0c9873a4c3`. Final scope: exactly the 14 files in §4, exactly the 22 methods locked in §3 (15 new + 7 strengthened), focused-file total 87 → 102. Verification evidence — all 14 files together, the full Usage suite, the full repository suite, and `git diff --check` — is recorded in full, with the repository-verifiable/previously-recorded evidence split made explicit, in [`RFC-005-TEST-COVERAGE-COMPLETION-CLOSURE.md`](RFC-005-TEST-COVERAGE-COMPLETION-CLOSURE.md). That closure document also supersedes §7.1's own `UsageCalendarMonthRolloverTest.php` row, which understated the file's pre-implementation baseline and final count by one each (stated `6 (5 existing + 1 new)`; correct is 6 existing, 7 final) — the authorized `+1` change and the overall 87 → 102 arithmetic were never affected by this bookkeeping error. Independent review of the final head found no blocking finding. No production, schema, migration, config, or route file changed. M6 remains frozen; this remediation's completion does not authorize M6.
