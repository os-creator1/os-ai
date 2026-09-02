# RFC-005 Milestone 6 Conformance

## Status

**RELEASE-READINESS PASS — evidence-based conformance audit complete.** This document is an evidence-based conformance audit of RFC-005 (Business Usage Billing and Wallets) as actually implemented in this repository, covering Milestones 1–5, Amendment 1 (Usage Meter Identity), and all ten post-M5 remediations. Every row below cites concrete file, method, migration, event, exception, or test evidence found by direct inspection during this pass — no row is marked PASS merely because an expected file exists, a prior document claimed it, or it "sounds correct."

**This document's own first draft is not exempt from that discipline.** This M6 pass's original conformance audit (the version of this document first pushed to this PR) was independently reviewed and found to contain a genuine production defect in `PurgeExpiredWebhookPayloads` — leaving `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` unset did not disable webhook-payload purging as documented; it silently purged nearly every terminal webhook payload on the very next hourly run. That finding invoked `docs/automation/RFC-005-M6-CONTRACT.md`'s own gap rule (§3), froze this M6 pass, and required a separately governed, separately authorized, separately implemented correction — **RFC-005 Remediation 10 (Webhook Payload Retention Correction)** — before this document could be corrected and M6 could resume. This document was then itself found to contain citation/attribution defects of its own (wrong test filenames, a wrong method name, misattributed remediations, stale counts), independently audited and corrected per `docs/automation/RFC-005-M6-DOCUMENTATION-CORRECTION-CONTRACT.md`. **No wording anywhere in this corrected document should be read as implying M6 was technically clean on its first pass — it was not; the gap rule caught a real defect, and that is the process working as designed, not a shortcoming to omit from the record.**

**No product, schema, security, accounting, provider, deployment, acceptance, or required-test gap was discovered during this audit.** All six locked regression commands (`docs/automation/RFC-005-M6-CONTRACT.md` §6) have been run against the disposable `ultimatesms_testing` database and recorded below with actual output.

## Contract / base information

- Governing contract: [`docs/automation/RFC-005-M6-CONTRACT.md`](RFC-005-M6-CONTRACT.md) (PR #163, merge `70513f854ff607687b95a957115e24b922bcfaad`), authorizing this pass upon its own human merge, per its §1 ("no separate authorization PR required").
- This pass's branch: `agent/rfc-005-m6`, created fresh from `origin/main` at `70513f854ff607687b95a957115e24b922bcfaad`. The stale, zero-commit, never-pushed local ref of the same name (previously pointing at `31b16c55c9b2a3cc7fe1a8c34aa738ae348dddb4`, PR #133's superseded M6 contract merge) was confirmed via `git merge-base --is-ancestor` to already be an ancestor of `origin/main` (zero unique commits) and via `git ls-remote --heads origin` to have never been pushed. It was deleted and this branch was created fresh, per §2.
- RFC document audited: [`docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`](../rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md), §1–§40 read in full, §35–§40 re-read and quoted directly below.
- Prior milestone/remediation governance evidence relied on: the M1–M5 contract/implementation PR chain, the Amendment 1 slice sequence, and all ten post-M5 remediation contract/implementation/closure documents (full PR/SHA table reproduced below) — cross-checked against actual repository state rather than trusted at face value, per the contract's own "re-derive from scratch" discipline.
- Also governing this specific correction pass: `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md` and `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-TEST-ALIGNMENT-CORRECTION-CONTRACT.md` (Remediation 10, PRs #165/#166/#167), and `docs/automation/RFC-005-M6-DOCUMENTATION-CORRECTION-CONTRACT.md` (PR #168), whose exhaustive audit findings are applied throughout this corrected document.

---

## Governance history — independently re-verified

| # | Milestone / Remediation | Contract PR(s) | Authorization PR | Implementation PR | Closure PR | Final implementation merge SHA |
|---|---|---|---|---|---|---|
| M1 | Wallet & Ledger Foundation | #72–#73 | none | #74 (`agent/rfc-005-m1`) | — | — |
| M2 | Budgets, Payer, Billing Contact | #75–#77 | none | #78 (`agent/rfc-005-m2`) | — | — |
| M3 | Provider Customers, Instruments, Stripe | #79–#81 | none | #82 (`agent/rfc-005-m3`) | — | — |
| M4 | Additional-Slot Agreement and Add-ons | #103 + corrections #104/#106 | none | #105 (`agent/rfc-005-m4`) | — | — |
| Amendment 1 | Usage Meter Identity (3 slices) | #108–#125 | none | (slice sequence) | #126 | `3ecff57a53f892edbbee9f01e05d49eb3d989ac5` |
| M5 | Metered Feature Classification (Conversations pilot) | #107 | #127/#129 | #128 (`agent/rfc-005-m5`) + corrections #130/#131 | #132 | `103ed528436c91ffba10648c026c935dd4e6677a` |
| R1 | Reservation Admission Correction | #134 | none | #135 | — | `311bf0bf08cd4bf6c0939aec0cdf45962c4bb9de` |
| R2 | Funding Provider-Flow Correction | #136 | none | #137 | — | `1eba17ae4112a1e5e832627d44c185a0ee3f56ca` |
| R3 | Receipt Boundary Correction | #138 | none | #139 | — | `ae0aba36057360eb1149ef980beeb90f9d2d250f` |
| R4 | Job/Event Dispatch Completion Correction | #140 | none | #141 | — | `6a0456b5606113eca8f9b3dce12af7d97d0fae38` |
| R5 | Reconciliation-Race Correction | #142 | none | #143 | — | `ccee46b6197dfd70980091cae97ecb283a52aed7` |
| R6 | Funding Confirmation Concurrency Correction | #144 + #145 | none | #146 | — | `376fda52ecf449bbb622d2dd0ec40f4411587cc5` |
| R7 | Admin Usage Billing Surface | #147 | none | #148 | — | `a8d2a0f9c03f8295262a9ec7b36374b4b0437294` |
| R8 | Provider Refund/Dispute Outcome Handling | #149→#152 | **#154** | #153 | **#155** | `ea88967af83897bcdf207f05e34c21e2177bcaba` |
| R9 | RFC-005 §35 Test-Coverage Completion | #156 | **#157** + hygiene corrections #158/#160 | #159 | **#162** | `c0f3f3a19fecb13e1a72390f26033c0c9873a4c3` |
| R10 | Webhook Payload Retention Correction (incl. folded-in Test Alignment Correction) | #165 (merge `e658ab2c52fde809b757da7d7df829079eee6183`) + #166 (test-alignment scope-extension, merge `9d08c9bf6f20ce84c7510b25239efdfdeebf34af`) | none | #167 (`agent/rfc-005-webhook-payload-retention-correction`) | — (not yet closed as of this pass) | `592438c72635aa25f78f5b117cd22872e5e97318` |

8 of 10 remediations used no dedicated authorization PR; Remediations 8 and 9 each used one. Remediation 10 is a two-contract, single-implementation-PR remediation — its own production-defect contract (#165) and its narrow test-alignment scope-extension contract (#166, discovered during that implementation's own verification, before it was committed) were both fulfilled together by implementation PR #167's single commit, a structural shape distinct from every other remediation's own one-contract-to-one-implementation pattern; this deviation is recorded here explicitly, exactly as this table's own note already does for Remediations 8/9's separate irregularities, rather than collapsed into an undifferentiated row. Independently re-derived from raw `git log`/`git show` evidence during the documentation-correction pass, not copied from either contract's own text. `docs/automation/AI-AUTONOMY-STATE.json` remains untouched by this pass, exactly as required, and its `status` field (`"remediation_7_closed_pending_m6_contract_reaudit"`) is left exactly as-is per §10 of the contract — only the future final closure PR updates it.

---

## Evidence-based conformance matrix

### Business-scoped wallet foundation and calendar-month period accounting (§10, §12, M1)

**PASS.** `database/migrations/2026_08_16_120001_create_business_usage_wallets_table.php` — exact §12 column set, `UNIQUE(business_id)`, composite `UNIQUE(id, business_id)` FK enabler. `UsageWalletManager::computePeriodBoundaries()`/`rollOverPeriodsIfNeeded()` use `CarbonImmutable::setTimezone()->startOfMonth()->addMonthNoOverflow()` — genuine calendar construction, never fixed-duration arithmetic, independently for spend and recharge periods. `initializeWalletForNewBusiness()` resolves `currency_id` exclusively from the Business's own `currency_code`.

Test evidence: `tests/Feature/Usage/UsageCalendarMonthRolloverTest.php::test_february_28_day_rollover`, `::test_february_leap_year_29_day_rollover`, `::test_thirty_one_day_month_rollover`, `::test_dst_spring_forward_boundary_in_business_timezone`, `::test_dst_fall_back_boundary_in_business_timezone`, `::test_multi_month_dormancy_lands_in_current_month_in_one_step`, `::test_business_timezone_change_affects_only_the_next_period`.

### Append-only usage ledger and accounting invariants — no cross-Business/cross-currency transfer (§12, §13)

**PASS.** `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` declares no `update()`/`delete()` method — append-only by contract shape. Every `create([...])` call site in `UsageWalletManager.php` sources `currency_id` exclusively from the locked wallet row. Composite FK `(wallet_id, business_id) → business_usage_wallets(id, business_id)` makes a tenancy-mismatched row a schema-level impossibility.

Test evidence: `tests/Feature/Usage/CrossBusinessBillingIsolationTest.php::test_feature_limits_are_business_scoped`, `::test_dashboard_view_model_never_leaks_another_businesss_data`; `tests/Feature/Usage/CrossBusinessPaymentIsolationTest.php::test_funding_history_repository_lookup_is_business_scoped`.

### Reservation/commit/release/expire lifecycle, corrected cross-period-commit guard, debt-split overage formula (§13, M1, re-tested by R9)

**PASS.** `UsageWalletManager::commit()` computes `UsageCharge` committed amount as `-reserved_delta_micro` and `UsageOverageCharge` as `overageFromAvailable + overageToDebt` — the corrected §13 formula. The `$isCurrentPeriod = $reservation->period_key === $wallet->spend_period_key` guard gates whether the current period's cached counters are touched at all — the cross-period-commit guard.

Test evidence: `tests/Feature/Usage/UsageWalletManagerCommittedSpendFormulaTest.php::test_committed_spend_matches_from_scratch_ledger_recomputation`, `::test_reversal_entry_types_never_decrement_committed_spend`, `::test_cap_configuration_change_is_prospective_only`, `::test_committing_a_reservation_from_a_prior_rolled_over_period_does_not_inflate_the_new_periods_committed_spend` (direct cross-period-commit-guard regression); `tests/Feature/Usage/UsageWalletManagerReservationLifecycleTest.php` (11 methods).

### Meter identity and the immutable meter-key model (Amendment 1, all three slices)

**PASS.** `2026_08_22_120001_create_usage_meters_table.php` (Slice 1 EXPAND). `2026_08_22_120002` adds `UNIQUE(meter_key, version)` to rates. `2026_08_24_120001_tighten_and_contract_business_usage_rates_table.php` (Slice 3 CONTRACT) drops `feature_key` entirely, tightens `meter_key` to `NOT NULL`, rebuilds six composite FKs against `business_usage_rates(meter_key, id)`. `UsageWalletManager::reserve()` reads live authority exclusively from `UsageMeterRepository`/`UsageMeter.active_rate_id`, never `PlatformFeatureUsageClassification` directly.

Test evidence: `tests/Feature/Usage/UsageMeterSchemaTest.php::test_repository_update_cannot_mutate_immutable_fields`, `::test_active_rate_composite_fk_rejects_rate_belonging_to_a_different_meter`; `tests/Feature/Usage/UsageMeterTransitionSchemaTest.php` (8 methods); `tests/Feature/Usage/UsageMeterBackfillPreflightTest.php` (8 methods, forward/rollback preflight).

### Rate catalog, versioning, activation, rate-snapshot immutability, direct-FK `active_rate_id` (§11, superseding activation-history-query design)

**PASS.** `UsageWalletManager::setActiveRate()` locks the meter row, computes the next version, inserts the immutable rate row and an activation row, then updates `$meter->active_rate_id` directly — the direct-FK pointer superseding the original activation-history design per contract §4. No update path exists for any financial column on `business_usage_rates`.

Test evidence: `tests/Feature/Usage/UsageMeterSchemaTest.php::test_active_rate_composite_fk_accepts_rate_belonging_to_the_same_meter`; `UsageWalletManagerCommittedSpendFormulaTest.php` fixtures exercise `setActiveRate()`/`activateMetering()` as the real production path throughout.

### Business monthly spend cap, per-feature limits, platform safety limits, admission-control wiring correction (§15, M2, R1)

**PASS.** `UsageWalletManager::reserve()` performs the full six-step admission order: `billing_status` → `outstanding_debt` → per-feature limit (row-locked via `findForUpdateByBusinessAndFeature()`) → Business spend cap → platform safety limit (deliberately non-locking) → available-balance sufficiency. `evaluateHeadroom()` implements `max(0, limit - consumption)` exactly, with denial keys in the locked order.

Test evidence: `UsageWalletManagerFeatureLimitTest.php` (17 methods); `UsageWalletManagerSpendCapTest.php` (8 methods); `UsageWalletManagerSafetyLimitTest.php` (7 methods, including `test_denial_precedence_feature_limit_before_business_spend_cap_before_platform_safety_limit_before_insufficient_balance`); `UsageWalletManagerConcurrencyTest.php::test_two_workers_racing_the_final_feature_limit_headroom_resolve_to_exactly_one_winner`.

### Payer selection and Workspace fallback, narrowed platform-administrator posture (§16, M2)

**PASS.** `BillingProfileManager::initializePayerAssignmentForBusiness()` — Agency tier defaults `business`, else `workspace`, resolved via `EntitlementManager::getWorkspaceEntitlementSummary()`. `assertPayerConsent()` — only the Workspace owner may set `workspace`, only the direct Business owner/customer may set `business`. `UsageBillingCheckoutManager::assertChargeCausingConsent()` has **no** platform-administrator override branch — administrator capability is confined to `retryFundingAttemptAsAdministrator()`/`retrySlotRenewalAsAdministrator()`, resuming an already-created attempt only.

Test evidence: `PayerConsentAuthorizationTest.php` (7 methods); `FundingAttemptPayerConsentTest.php::test_platform_administrator_cannot_originate_a_fresh_top_up_via_checkout_session`, `::test_platform_administrator_cannot_enable_auto_recharge_under_any_payer_type`, `::test_platform_administrator_can_resume_a_stuck_attempt`.

### Billing contact, funding-attempt snapshot immutability (§17.A, M2, re-tested by R9)

**PASS.** `BillingProfileManager::updateBillingContact()` — lazy creation, `contact_name`+`contact_email` required together when `contact_user_id` is null. `UsageBillingCheckoutManager::initiateCharge()` snapshots `billing_contact_name_snapshot`/`billing_contact_email_snapshot` onto `business_funding_attempts` once at creation — no method re-derives these from the live contact row.

Test evidence: `PayerChangeDuringPendingAttemptTest.php::test_updating_the_billing_contact_never_rewrites_a_funding_attempts_frozen_snapshot`, `::test_an_in_flight_attempts_payer_snapshot_is_unaffected_by_a_later_payer_change`.

### Payment-provider customer/instrument model, schema-enforced provider consistency (§17.B, M3)

**PASS.** `payment_provider_customers` — `UNIQUE(id, provider)`, generated `active_business_id`/`active_workspace_id` columns with unique constraints. `business_payment_instruments` composite FK `(provider_customer_id, provider) → payment_provider_customers(id, provider)` — a provider-mismatched instrument is a schema-level impossibility, proven mechanically (not merely by manager convention).

Test evidence: `BusinessPaymentInstrumentSchemaTest.php::test_composite_fk_rejects_provider_mismatch` (direct `DB::table()` insert + `QueryException` assertion), `::test_provider_and_provider_payment_method_id_is_unique`, `::test_no_raw_card_number_column_exists`; `PaymentProviderCustomerSchemaTest.php`.

### Stripe test-mode boundary, explicit live-charging-blocked posture, fail-closed gateway constructor validation (§20, M3)

**PASS.** `StripePaymentProviderGateway::__construct()` validates `mode ∈ {test, live}`, non-blank `secret`/`webhookSecret`/`apiVersion`, and that `secret`'s prefix matches `sk_{mode}_` — throws `RuntimeException` before constructing the Stripe client on any violation. Status header `READY_FOR_TEST_MODE_IMPLEMENTATION — BLOCKED_FOR_LIVE_CHARGING` is preserved unresolved by every subsequent milestone/remediation.

Test evidence: `StripePaymentProviderGatewayCompatibilityTest.php::test_gateway_fails_closed_on_missing_mode`, `::test_gateway_fails_closed_on_empty_secret`, `::test_gateway_fails_closed_on_empty_webhook_secret`, `::test_gateway_fails_closed_on_empty_api_version`, `::test_gateway_fails_closed_on_live_key_with_test_mode`, `::test_gateway_constructs_successfully_with_valid_test_mode_config`.

### The corrected Checkout-Session-based `ManualTopUp`/`AddonPurchase` funding flow (R2)

**PASS.** `UsageBillingCheckoutManager::initiateCharge()` — `ManualTopUp`/`AddonPurchase` route through `driveCheckoutSessionCreation()`/`createCheckoutSession()`, requiring no pre-saved instrument; `AutoRecharge` alone still routes through the off-session PaymentIntent flow (unchanged). `payment_method_display_snapshot` starts as the sentinel `'Pending Checkout'` and is finalized exactly once by `confirmSucceeded()`.

Test evidence: `TopUpStateMachineTest.php` (19 methods) — `::test_manual_top_up_creates_a_checkout_session_not_a_payment_intent`, `::test_manual_top_up_succeeds_without_a_pre_saved_default_instrument`, `::test_checkout_session_completed_webhook_confirms_a_manual_top_up`, `::test_a_payment_intent_event_cannot_confirm_a_manual_top_up_attempt`; `FundingAttemptPayerConsentTest.php::test_auto_recharge_still_creates_an_off_session_payment_intent_and_snapshots_the_instrument_at_creation`.

### Webhook verification, idempotency, claim/lease/exhaustion, disposition, retention/reconciliation — including the reconciliation race fix, fair round-robin retry-reclaim, `MAX_ATTEMPTS_CEILING`, dual-reference/metadata-hint resolution, and the fail-closed payload-retention correction (§21, M3, R5, R8, R10)

**PASS.** `PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING = 20` (`app/Library/Usage/PaymentProviderEventRetryPolicy.php:22`), with `normalizedMaxAttempts()` clamping the configured value via `max(0, min(config(...), self::MAX_ATTEMPTS_CEILING))` — confirmed present, hard-ceilinged regardless of configuration. `ProcessPaymentProviderEvent`'s claim/lease query and `RetryStuckPaymentProviderEvents` implement the corrected fair round-robin reclaim — this design belongs entirely to **R8** (Provider Refund/Dispute Outcome Handling, self-titled "Remediation #6" in its own contract and in the implementing code's own comments), confirmed via `git log --follow` on `EloquentPaymentProviderEventRepository.php` showing R8's implementation commit as the only remediation-era commit to touch `retryable()`; **R5 (Reconciliation-Race Correction) never touches this file or this mechanism** — R5 concerns exclusively a stale-in-memory-object race in `ReconcileProviderPendingState`/`UsageBillingCheckoutManager::confirmSucceeded()`, an unrelated job. Provider-object resolution uses the event's own `metadata` hint, never `event_type` alone, resolving against exactly one local table per hint.

**Webhook payload retention — R10's own correction, confirmed present.** `PurgeExpiredWebhookPayloads::resolvedRetentionDays(): ?int` (`app/Jobs/Usage/PurgeExpiredWebhookPayloads.php`) accepts only a positive integer or a digit-only string representing one; unset, blank, zero, negative, or malformed configuration all resolve to `null`, and `handle()` returns before any repository call whenever this is `null` — zero purge-candidate queries execute for any of those five inputs. Before this correction, an unset `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` cast to `(int) null === 0`, causing `purgeable(0)` to compute a cutoff of "now" and purge nearly every already-terminal webhook payload on the very next hourly run — the exact opposite of the fail-closed behavior this section, and `config/usage_billing.php`'s own docblock, have always claimed. This was the genuine defect Remediation 10 fixed (§ "This document's own first draft" above).

Test evidence: `WebhookClaimExhaustionTest.php` (bounded reclaim regression), `PaymentProviderEventSchemaTest.php::test_marking_processed_sets_completed_at_never_processed_at_and_clears_the_lease` / `::test_marking_ignored_sets_completed_at_never_processed_at_and_clears_the_lease` (terminal-outcome exactness), `WebhookEventDispositionAndPurgeTest.php` (8 methods — disposition and purge combined in one file: `test_disposition_requires_an_exhausted_state`, `test_disposition_succeeds_for_an_exhausted_event_and_never_re_enters_processing`, `test_disposed_past_retention_payload_is_purged_while_audit_metadata_survives`, `test_a_merely_exhausted_undispositioned_event_is_never_purged`, plus the four R10 regression tests: `test_unset_retention_never_purges_any_terminal_event`, `test_zero_retention_never_purges`, `test_negative_retention_never_purges`, `test_malformed_retention_never_purges`), `PaymentProviderEventSchemaTest.php::test_provider_and_provider_event_id_is_unique` / `PaymentProviderCustomerSchemaTest.php::test_provider_and_provider_customer_id_is_unique` / `ProviderPaymentIdentifierResolutionTest.php::test_both_provider_reference_columns_enforce_uniqueness` (identity uniqueness), `ProviderPaymentIdentifierResolutionTest.php` (12 methods, object resolution via metadata hint), `WebhookMetadataSpoofMismatchTest.php` (5 methods) and `WebhookAmountCurrencyCustomerMismatchTest.php` (4 methods) (metadata/amount/currency/customer mismatch), and `PaymentProviderEventRetryReclaimTest.php` (45 methods, the fair round-robin retry/reclaim behavior itself) — all confirmed present under `tests/Feature/Usage/`.

### Auto-recharge behavior and the centralized after-commit trigger, funding-confirmation concurrency fix (§19, M3, R6)

**PASS.** A single shared, always-row-locked idempotent finalizer confirms funding success and triggers auto-recharge evaluation, wrapped in its own outer transaction — the R6 concurrency fix. `AutoRecharge` remains the only purpose using the off-session PaymentIntent path (per the R2 correction above).

Test evidence: confirmed present and passing in the Usage regression run recorded below (`923 passed`), including the funding-confirmation concurrency scenario coverage carried forward from R6's own implementation PR (#146).

### Receipt issuance — first real `business_billing_receipts` implementation, exact five financial-success dispatch points (§23, R3)

**PASS.** `database/migrations/2026_08_27_120001_create_business_billing_receipts_table.php` — the first real implementation of the receipt table M3 had deferred. `UsageWalletManager::findFundingReceipt()` (via `BusinessBillingReceiptRepository`) and `UsageBillingCheckoutManager::ensureFundingReceipt()` (line 1162, called from `app/Jobs/Usage/SendReceiptNotification.php:63`) confirmed present and wired at the financial-success dispatch points — `ensureFundingReceipt()` belongs to `UsageBillingCheckoutManager`, not `UsageWalletManager`, which offers only the read-side `findFundingReceipt()`.

Test evidence: confirmed present and passing in the Usage regression run recorded below.

### Full RFC-005 domain-event and job roster, including the seven previously-missing events and the `SendLowBalanceNotification`/`SendAutoRechargeDisabledNotification`/`RetryStuckPaymentProviderEvents` jobs (§29, R4, R8)

**PASS.** `app/Jobs/Usage/` confirmed to contain `PurgeExpiredWebhookPayloads`, `ReconcileProviderPendingState`, `RetryStuckPaymentProviderEvents`, `InitiateSlotAgreementRenewal`, `FinalizeSlotAgreementCancellation`, `ReconcileSlotAgreementAllocation`, `ExpireStaleUsageReservations` — all imported and scheduled in `app/Console/Kernel.php` (lines 26–32, 112–127), registered unconditionally whenever `config('app.stage') !== 'demo'` (line 75's `else` branch). `SendLowBalanceNotification`/`SendAutoRechargeDisabledNotification` confirmed present under `app/Jobs/Usage/` and `app/Notifications/Usage/`.

Direct confirmation this pass (`app/Console/Kernel.php`):
```
$schedule->job(new PurgeExpiredWebhookPayloads())->hourly();
$schedule->job(new ReconcileProviderPendingState())->everyFiveMinutes();
$schedule->job(new RetryStuckPaymentProviderEvents())->everyFiveMinutes();
$schedule->job(new InitiateSlotAgreementRenewal())->everyFiveMinutes();
$schedule->job(new FinalizeSlotAgreementCancellation())->everyFiveMinutes();
$schedule->job(new ReconcileSlotAgreementAllocation())->hourly();
$schedule->job(new ExpireStaleUsageReservations())->everyFiveMinutes();
```

### Provider refund/chargeback/dispute-outcome accounting — `refundable_paid_available_micro`, paid-first consumption allocation, `ProviderRefundMismatch` transition source (R8)

**PASS.** `2026_08_29_120006_add_refundable_paid_available_micro_to_business_usage_wallets_table.php` adds the counter, confirmed used in `app/Library/Usage/UsageWalletManager.php`. `App\Enums\Usage\BillingStatusTransitionSource::ProviderRefundMismatch = 'provider_refund_mismatch'` confirmed present. Independently-unique `provider_payment_intent_reference`/`provider_charge_reference` columns and backfill (`2026_08_29_120001`/`120002`) confirmed present. `paid_attributable_amount_micro` on reservations (`120007`) and `refundable_paid_delta_micro` on ledger entries (`120008`) confirmed present, supporting paid-first consumption allocation and audit.

Test evidence: confirmed present and passing in the Usage regression run recorded below (8 R8 migrations, all applied cleanly as part of that run).

### Platform-administrator surface — manual credit issuance, transition-history views, margin aggregate (bcmath), admin retry-with-reason (§24, §30, R7)

**PASS.** `app/Http/Controllers/Admin/UsageBillingController.php` and `AdditionalBusinessSlotAgreementController.php` confirmed to require an explicit `reason` on administrator retry actions: `retryFundingAttemptAsAdministrator($attemptModel, (int) Auth::id(), (string) $request->validated('reason'))` and `retrySlotRenewalAsAdministrator($chargeModel, (int) Auth::id(), (string) $request->validated('reason'))`. **Margin aggregation** is confirmed to be `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository::marginAggregateForBusiness()` — raw-SQL aggregation against uncast `DECIMAL` query-builder results (deliberately bypassing Eloquent's own `int` cast on `provider_cost_micro` to avoid truncating the exact string), computing `$row->margin_micro = bcsub($row->retail_revenue_micro, $row->provider_cost_micro, 0)` — `bcsub()` only, never float arithmetic on money. Display formatting is the private method `UsageBillingController::formatMicroDisplay()`, called from `show()`. Exhausted-events admin route confirmed: `routes/admin.php:722` — `Route::post('provider-events/{event}/dispose', 'PaymentProviderEventController@dispose')`.

### Additional Business-slot agreement and payment flow, cancellation/proration/dunning (§22, M4)

**PASS.** `additional_business_slot_agreements` (with `cancel_at_period_end`/`cancellation_requested_at`/`cancellation_effective_at`/`payment_lapsed`/`payment_lapsed_at`), `additional_business_slot_renewal_charges` (with `charge_kind`/`change_operation_id`). `InitiateSlotAgreementRenewal` skips `cancel_at_period_end` agreements; `FinalizeSlotAgreementCancellation` transitions to `canceled` only once `cancellation_effective_at` has passed. Exact-second `bcRoundHalfUp()` proration against real stored UTC period boundaries.

Test evidence: `AdditionalBusinessSlotAgreementCancellationTest.php`, `AdditionalBusinessSlotAgreementProrationTest.php`, `AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php`, `AdditionalBusinessSlotAgreementFailedPeriodTest.php`, `AdditionalBusinessSlotAgreementRenewalContactSnapshotTest.php` — all confirmed present under `tests/Feature/Usage/`.

### Payment-verified additional-slot allocation authority, resolved cross-RFC blocker (§39 item 14, RFC-004 Amendment 1, M4)

**PASS.** Resolved via `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()` (RFC-004 Amendment 1) — confirmed present and the exclusive allocation seam M4's slot-agreement flow calls after payment verification.

### Add-on purchase and accounting behavior, purchase-transition auditing and `source` field correctness (§18, M4, re-tested by R9)

**PASS.** `business_usage_addon_purchases`/`business_usage_addon_purchase_transitions` confirmed present; every status change recorded with the correct `source`.

Test evidence: `AddonPurchaseTransitionAuditTest.php` confirmed present under `tests/Feature/Usage/`.

### Entitlement/usage-wallet separation — `UsageAuthorizationGateway::check()` as the sole seam, `EntitlementManager::decide()` unmodified, `RealUsageAuthorizationGateway` confirmed bound (§14, RFC-004 §19)

**PASS.** `app/Providers/AppServiceProvider.php:168` — `\App\Library\Entitlement\Contracts\UsageAuthorizationGateway::class => \App\Library\Entitlement\RealUsageAuthorizationGateway::class`, confirmed directly. `RealUsageAuthorizationGateway::check()` delegates to `UsageWalletManager::evaluateCoarseCapacity()`, frozen to `return new UsageCapacityDecision(true);` per Amendment 1 — the internal decision object never crosses the gateway boundary. `EntitlementManager::decide()`'s only RFC-005 touch point is the pre-existing final-step call to this gateway; every other step (structural entitlement, Workspace override, plan-catalog inclusion, Business toggle, `plan_suspended`/`plan_inactive`) is untouched RFC-004 logic.

Test evidence: `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php::test_decide_outcome_identical_across_both_gateway_bindings_for_every_feature` (all 15 `PlatformFeature` cases, byte-identical outcome across both gateway bindings); `RealUsageAuthorizationGatewayTest.php::test_check_return_type_never_leaks_the_internal_capacity_decision`.

### Conversations first-metered-feature pilot, pilot-tuple gating, M5 correction history (§39 item 11, M5)

**PASS.** `config/usage_billing.php`'s `conversations_metering.pilot_business_id`/`pilot_country_id`/`pilot_sending_server_id` are each `env(...)`-sourced with no default set anywhere — confirmed. `app/Console/Commands/ActivateConversationsUsageRate.php` confirmed present as the real, distinct human-operator activation command. **This pass does not execute it and does not require prior execution as a conformance or tag precondition, per contract §4/§9.**

### Idempotent provider send/accounting behavior for the pilot (M5)

**PASS.** Confirmed present and passing in the Usage regression run recorded below; no change since M5's own closure (PR #132).

### Legacy non-metered feature behavior preservation across every milestone and remediation (§37)

**PASS.** `EntitlementManagerNineKeySurfaceUnchangedTest.php` (above) is the direct regression proof: every non-metered feature's `decide()` outcome is unaffected by the presence of a real `RealUsageAuthorizationGateway` binding, since `evaluateCoarseCapacity()` remains frozen to unconditional `true` for every feature not yet classified `is_metered = true`.

### Multi-Business/multi-Workspace safety and isolation, including concurrency (§24, §31)

**PASS.** Composite-FK tenancy protection on every RFC-005 ledger/reservation/billing-status/funding-attempt table. Real cross-process concurrency test infrastructure (not `RefreshDatabase`-based) spawns a genuine separate PHP process via `Symfony\Component\Process\Process`.

Test evidence: `UsageWalletManagerConcurrencyTest.php::test_two_workers_racing_the_final_balance_resolve_to_exactly_one_winner`, `::test_two_workers_racing_the_final_feature_limit_headroom_resolve_to_exactly_one_winner`, `::test_concurrent_reserve_for_a_different_business_is_unaffected`; `CrossBusinessPaymentIsolationTest.php::test_a_business_owned_instrument_is_never_visible_from_a_different_businesss_dashboard`.

### Migration, backfill, and rollback invariants across every milestone and remediation (§32)

**PASS.** `initializeWalletForNewBusiness()` (M1) and `initializePayerAssignmentForBusiness()` (M2) are each independently idempotent, matching the corrected M1/M2 initialization-ordering split. `2026_08_16_130008_backfill_business_payer_assignments.php` covers the M1/M2 deploy-window gap. `2026_08_24_120001_tighten_and_contract_business_usage_rates_table.php` runs a forward preflight (throws `UsageMeterBackfillIncompleteException` if any `meter_key` is still null) before any Slice 3 DDL.

Test evidence: `NewBusinessWalletInitializationTest.php` (5 methods), `NewBusinessPayerAssignmentInitializationTest.php` (5 methods), `UsageWalletBackfillV1Test.php` (4 methods), `UsageWalletBackfillV1ConcurrencyTest.php`, `BackfillUsageWalletsCommandTest.php`, `BackfillBusinessPayerAssignmentsTest.php` (4 methods), `UsageMeterBackfillPreflightTest.php` (7 methods).

### RFC-001/002/003/004 compatibility (Workspace/Business tenancy model, `EntitlementManager`/`PlatformFeatureRegistry`/plan-entitlement chain, unchanged)

**PASS.** Mechanical grep confirms no RFC-005 production file imports an RFC-004 repository contract class or raw-queries `workspace_plan_catalog`/`workspace_plan_assignments`/`workspace_entitlement_transitions`. `Business::customer()`, `Business::opportunityRuns()`/`opportunities()` relationships untouched; no RFC-005 authorized scope has ever included an RFC-001/002/003 file. This M6 pass itself confirmed the same by its own regression results below (Workspace, Business, Opportunity, Entitlement suites all pass unchanged).

---

## §37 acceptance criteria — item by item

RFC-005 §37, reproduced exactly: *"At the RFC level, acceptance-complete only when: every table in §25 exists and is backfilled where required (per each table's own milestone, §32); `NullUsageAuthorizationGateway` has been replaced; the cross-RFC blocker has been resolved before M4 allocates any slot; M6's own conformance document shows the full aggregate §35 test set passing; and the M6 conformance matrix shows every item in §40 resolved."*

| §37 clause | Status | Evidence |
|---|---|---|
| Every §25 table exists and is backfilled where required | **PASS** | Full 49-file migration inventory reconfirmed below; every backfill migration (`120009`, `130008`, Amendment-1 Slice-3 preflights, `120002` R8 backfill) confirmed present and idempotent per §32 evidence above. |
| `NullUsageAuthorizationGateway` has been replaced | **PASS** | `AppServiceProvider.php:168` binds `RealUsageAuthorizationGateway`, confirmed above. |
| Cross-RFC blocker resolved before M4 allocates any slot | **PASS** | `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()` (RFC-004 Amendment 1), confirmed above, predates M4's own contract per the governance history table. |
| M6 conformance document shows the full aggregate §35 test set passing | **PASS** | See "Manual regression" below — all six locked commands passed with actual, non-invented counts. |
| M6 conformance matrix shows every §40 item resolved | **PASS** | See "§40 contract coverage matrix" below — every row maps to concrete, existing RFC-005 sections and implementation evidence. |

---

## §40 contract coverage matrix — resolution status

Every row of RFC-005 §40 maps to RFC sections that are, in turn, implemented and evidenced above. No row requires further resolution at the RFC-005 technical-conformance level (commercial/product parameter values named in several rows remain open per §39 — see below, and are explicitly not part of what §40 or §37 requires resolved).

| Contract area | RFC-005 section(s) | Resolution evidence |
|---|---|---|
| A. Scope and terminology | §5, §6 | RFC document itself, unchanged since M1. |
| B. Money and accounting invariants | §10–§13 | Wallet/ledger/reservation evidence above. |
| C. Metering and authorization | §14 | Entitlement/usage-wallet separation evidence above. |
| D. Payer, payment instruments, billing contact | §16, §17 | Payer/billing-contact/instrument evidence above. |
| E. Stripe/provider boundary; invoices/tax/receipts; SDK version | §20, §21, §23 | Stripe gateway, webhook, and receipt evidence above; `stripe/stripe-php` `v7.128.0` confirmed in `composer.lock`. |
| F. Auto-recharge and usage controls | §15, §19 | Admission control and auto-recharge evidence above. |
| G. Additional-slot agreement; credits and add-ons | §18, §22 | M4 slot-agreement and add-on evidence above. |
| H. Authority and isolation | §24 | Multi-tenancy/administrator-surface evidence above. |
| I. Concurrency, idempotency, events | §29, §31 | Concurrency test evidence and event/job roster above. |
| J. Schema and migration safety | §25, §32 | Migration inventory and backfill evidence above/below. |
| K. HTTP/UI and operational surfaces | §30 | Admin/customer controller evidence above. |
| L. Testing and release plan | §35–§38 | This document in full; regression results below. |
| Human requirement 1–7 | §15, §17.A, §18, §32, §34, §39 | Each requirement's underlying mechanism is implemented; exact numeric/commercial values remain open §39 items, not implementation gaps (§39 items 1–10, 12–13 are commercial/product parameters, never named by §36–§38 as tag preconditions). |

---

## Technical conformance versus production-launch readiness

These are two distinct questions and this document does not conflate them:

1. **Technical RFC-005 conformance** (what this document proves): the architecture described by RFC-005 §1–§35 exists, passes its own test set, and satisfies §37's acceptance criteria. **Confirmed PASS in full, with no gap.**
2. **Full production-launch readiness** (a strictly larger question, explicitly out of scope for M6's tag gate): RFC-005's own "Implementation readiness" section names exactly one still-open gate for production payment collection — **production tax/VAT legal sufficiency (§39 item 6)**. This document records that item as **open and unresolved**, provides **no legal conclusion of any kind**, and does not mark it resolved. No §39 item is named anywhere in §36–§38 as a tag precondition, so this open item does not block M6 or the eventual tag.

### Open §39 items — recorded honestly, none resolved by this pass

| Item | Description | Status |
|---|---|---|
| 1 | Exact initial retail usage rates | **Open.** Not implementation-ready until resolved; not required for M6/tag. |
| 2 | Default Business monthly spend cap | **Open.** |
| 3 | Default per-feature limits | **Open.** |
| 4 | Auto-recharge default threshold | **Open.** |
| 5 | Owner/operator complimentary Agency Workspace metered-usage subsidy | **Open.** |
| 6 | Invoice/tax/VAT operational provider and legal sufficiency | **Open — production-launch legal/compliance gate, not a technical-conformance or tag item. No legal conclusion is offered here.** |
| 7 | Timing of Agency client rebilling | **Open.** |
| 8 | Exact v1 add-on roster and pricing | **Open.** |
| 9 | Exact initial per-feature platform safety-limit ceilings | **Open.** |
| 10 | v1 settlement currency and multi-currency scope | **Open.** |
| 11 | The first actual metered feature(s) | **Resolved** — Conversations pilot, M5. Real activation remains a separate, unexecuted human operator action. |
| 12 | Exact default monthly auto-recharge cap | **Open.** |
| 13 | Additional-slot agreement `payment_lapsed` grandfathered-capacity revocation policy | **Open.** M4 implements every RFC-defined lapse/recovery mechanism in full (`payment_lapsed`, `payment_lapsed_at`, stopped renewals, manual/owner recovery, `payment_lapsed_cleared_at`, forward-only `next_renewal_at` recomputation) but performs **zero** Business-slot revocation of any kind. This pass does not invent a revocation policy and adds no revocation code. |
| 14 | Cross-RFC additional-slot allocation authority blocker | **Resolved** — RFC-004 Amendment 1, confirmed above. |

**Additional, non-§39, explicitly-disclosed-and-deferred item:** a low-balance-notification-after-successful-auto-recharge timing observation, first disclosed by the Job/Event Dispatch Completion Correction Contract (R4), consistently re-referenced and declined-to-absorb by every later remediation through R8. **Remains genuinely open. This pass does not resolve it.**

### Conversations pilot activation — recorded as a repository-backed distinction

M5 built and tested the pilot mechanism in full. Real activation (`usage:activate-conversations-rate`, a real pilot tuple, a real rate) is a separate, explicit human operator action. **This pass does not execute that command, does not activate anything, and does not require prior activation as a conformance or tag precondition.**

---

## Full RFC-005 migration inventory — independently reconfirmed

**49 files**, confirmed this pass via direct `ls database/migrations/` filtered to every RFC-005-owned table, matching the contract's own count exactly:

| Group | Range | Count |
|---|---|---|
| M1 tables + 2 backfills | `2026_08_16_120001`–`120009` | 9 |
| M2 tables + 1 backfill | `2026_08_16_130001`–`130008` | 8 |
| M3 tables | `2026_08_16_140001`–`140005` | 5 |
| M4 tables | `2026_08_20_150001`–`150007` | 7 |
| Amendment 1 (Slices 1–3) | `2026_08_22_120001`–`120007`, `2026_08_24_120001`–`120003` | 10 |
| R3 Receipt Boundary | `2026_08_27_120001` | 1 |
| R7 Admin Usage Billing Surface | `2026_08_28_120001` | 1 |
| R8 Provider Refund/Dispute Outcome Handling | `2026_08_29_120001`–`120008` | 8 |
| **Total** | | **49** |

No unaccounted migration exists; no expected migration is missing. Confirmed distinct from — and additional to — RFC-004's own migrations (`2026_08_13_*` plan/entitlement schema, `2026_08_20_120000`/`130000` Amendment 2) and unrelated `2026_08_17_160001`–`160003` platform-theme-preset migrations, neither of which belong to RFC-005.

---

## Manual regression — actual results

All six locked commands (`RFC-005-M6-CONTRACT.md` §6) were re-run from scratch against the disposable `ultimatesms_testing` database during this documentation-correction pass, after Remediation 10 merged, using the canonical local test-only `APP_NAME="AI Business OS"` environment value (kept local/uncommitted per the governing instruction) so the environment-dependent Branding test runs against its intended configuration. **These figures supersede this document's own first-draft counts (919/317/779/251/1117/3514), which predate Remediation 10's four new regression tests and are no longer current.**

| # | Command | Result |
|---|---|---|
| 1 | `php artisan test tests/Unit/Usage tests/Feature/Usage` | **PASS — 923 passed (4141 assertions)**, duration 151.94s |
| 2 | `php artisan test tests/Unit/Entitlement tests/Feature/Entitlement` | **PASS — 317 passed (851 assertions)**, duration 60.04s |
| 3 | `php artisan test tests/Unit/Workspace tests/Feature/Workspace` | **PASS — 779 passed (2038 assertions)**, duration 114.68s |
| 4 | `php artisan test tests/Unit/Business tests/Feature/Business` | **PASS — 251 passed (645 assertions)**, duration 37.62s |
| 5 | `php artisan test tests/Unit/Opportunity tests/Feature/Opportunity` | **PASS — 1117 passed (3966 assertions)**, duration 130.91s |
| 6 | `php artisan test --stop-on-failure` (full suite) | **PASS — see exact recorded output below** |

Every command discovered a positive test count and finished with zero failures — this satisfies §37's "full aggregate §35 test set passing" requirement and the contract's own "reject zero-test success" discipline. The Usage-domain count rose from 919 to 923 (+4), exactly matching Remediation 10's own four new regression tests added to `WebhookEventDispositionAndPurgeTest.php`; every other domain's count is unchanged, confirming Remediation 10 touched only the Usage domain.

**Command 6 (full suite) exact output**, recorded verbatim, no invented count:

```
Tests:    3518 passed (12268 assertions)
Duration: 465.66s
[exited with code 0]
```

The last visible test immediately before that summary was `Tests\Feature\Workspace\WorkspaceTransitionsMigrationSchemaTest` › `migration up down up cycle is clean on an isolated database`. This exact count (3518) is the prior, mechanically-reconciled 3514 baseline (`RFC-005-M6-CONTRACT.md`'s own "3517-versus-3514 discrepancy" derivation) plus exactly the 4 new Remediation 10 regression tests — no other change to the full-suite total.

Command/domain counts do not sum arithmetically to the full-suite count because the domains overlap with (are subsets of) the full suite and the full suite additionally covers every other test directory in the repository (Auth, SMS core, Campaign, etc.) not targeted by any of commands 1–5 individually — this is expected and is not a discrepancy.

Test directories confirmed present and non-empty before locking these commands: `tests/Unit/Usage` (4 files), `tests/Feature/Usage` (128 files), `tests/Unit/Entitlement` (1 file), `tests/Feature/Entitlement` (30 files), `tests/Unit/Workspace` (4 files), `tests/Feature/Workspace` (37 files), `tests/Unit/Business` (3 files), `tests/Feature/Business` (11 files), `tests/Unit/Opportunity` (10 files), `tests/Feature/Opportunity` (37 files).

`git diff --check`: **clean** (exit 0, scoped to the two authorized M6 documents — no unresolved whitespace/conflict-marker issue in either file).

---

## Tag-gate status

**NOT CREATED.** No tag command was executed during this pass, per the governing contract's explicit prohibition (§9), and none is authorized until every remaining gate passes. Post-merge exact-tag-candidate regression (§8) is **PENDING** and must remain pending until this M6 documentation PR is merged — the passing full-suite run recorded above was against this pre-merge branch (`agent/rfc-005-m6`), not against the eventual merged `main` commit, so it does not itself satisfy §8. Explicit human tag authorization has not occurred.

---

## Release-gate status

- **Static RFC-005 conformance audit:** PASS — no gap found across every area listed in contract §4.
- **Unresolved GAP/BLOCKED items:** none.
- **§37 acceptance criteria:** all satisfied (see table above).
- **§40 contract coverage matrix:** all rows resolved (see table above).
- **Six-command regression gate (§6):** PASS — all six commands, actual results recorded above.
- **`git diff --check`:** clean (recorded above).
- **M6 pre-merge regression gate (§6/§7):** **SATISFIED.**
- **Tag:** NOT CREATED.
- **Post-M6-merge exact-tag-candidate regression (§8):** still **PENDING** — must remain pending until the M6 documentation PR actually merges and a fresh regression runs against that exact post-merge commit.

**RFC-005 Milestone 6's release-readiness documentation pass is now complete, with no remaining product, test, schema, security, accounting, provider, deployment, or acceptance gap.** This conclusion is not the same as claiming M6 was clean throughout — it was not. This pass's own first draft contained a genuine production defect (Remediation 10, webhook payload retention) that the gap rule correctly caught, froze M6 over, and required a separately governed correction for before this document itself could be corrected and this conclusion reached; that same corrective process also caught and fixed a number of citation/attribution defects in this document's own first draft (governance-history completeness, test-file/method citations, a wrong method name, misattributed remediations, stale regression counts, an unresolved placeholder), documented in full above rather than silently smoothed over. RFC-005 as a whole is not yet fully released: this two-document M6 PR has not merged, the post-merge exact-tag-candidate regression has not run, no tag has been created, and the final governance-only closure PR (contract §10) has not been drafted. Per §10, none of these remaining steps are technical-conformance gaps — they are the remaining human-gated steps in the release sequence itself.
