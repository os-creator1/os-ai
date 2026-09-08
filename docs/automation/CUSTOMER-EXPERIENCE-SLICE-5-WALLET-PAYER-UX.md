# CUSTOMER EXPERIENCE — SLICE 5: WALLET, PAYER, BALANCE AND SPENDING-CONTROL UX

## 1. Status and authority

**Status:** Implemented on branch `agent/customer-experience-slice-5-wallet-payer-ux`.

**Parent contract:** `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`
— §12 (wallet, payer and spending-control model), §17.1/§17.2, §18 (S-2,
S-6, S-7, S-10), §19, §20 (C-4, C-9, C-10), §21/§21.1 row 5, §22.1 row 5,
§24/§24.1 (T-PAYER-1..4, T-WALLET-1..6, T-CAP-1..5, T-TRIAL-1, T-COST-4/9/10),
§27 C-3 and §28.1a/§28.9. Where the parent intentionally corrects
RFC-005, the parent wins; RFC-005 is amended only per §27 C-3 (its new §41).

**Verified base:** `origin/main` at `d2b275ec9dd447c27276007636062815a1b8e957`
(`Merge pull request #218`), with `81cdc921bc455fe18c41d9362501344338fd3314`
as an ancestor. Depends on merged Slice 1B only; independent of Lane C's
Slice 1A.

**Owned tests (parent §24.1):** T-PAYER-1..4, T-WALLET-1..6, T-CAP-1..5,
T-TRIAL-1, T-COST-4, T-COST-9, T-COST-10.

## 2. Existing RFC-005 primitives reused (nothing duplicated)

| Primitive | Reused as |
|---|---|
| `business_usage_wallets` + `UsageWalletManager::reserve()/commit()/release()/expireStaleReservations()` | the one wallet; every new control is evaluated inside `reserve()` under the existing wallet row lock |
| `business_usage_ledger_entries` (immutable) | untouched; the ledger now renders with readable labels only |
| `business_usage_reservations` idempotency keys and TTL | untouched; the kill switch never settles or corrupts an open reservation |
| `business_payer_assignments` / `business_payer_transitions` / `BusinessPayerChanged` | the one payer system; `BillingProfileManager::assignPayer()` adds the no-op rule and the Agency authority |
| `monthly_spend_cap_micro`, `business_feature_usage_limits`, `platform_feature_usage_safety_limits` | unchanged; surfaced in plain language |
| `monthly_recharge_cap_micro`, `recharged_this_period_micro`, `consecutive_recharge_failures`, `EvaluateBusinessAutoRecharge` | unchanged; presets and consent added at `configureAutoRecharge()` |
| `UsageBillingCheckoutManager` (top-up, auto-recharge attempts, webhook/return confirmation, exactly-once credit) | untouched; the $5 floor sits in front of it |
| `issueManualCredit(PromotionalCredit)` (platform administrator only, idempotent, audited) | the only way promotional credit exists |
| `FakePaymentProviderGateway` | the only provider in every test |

No second wallet, ledger, payer system, reservation mechanism, provider-specific balance or disconnected credit balance exists. All money is integer micro-units with bcmath.

## 3. Customer-visible behaviour — before / after

| Area | Before | After |
|---|---|---|
| Balance | "Available / Reserved / Debt balance", "Committed spend this period", period key | **Available balance**, **Usage this month**, status (Active / Paid activity paused / Outstanding balance / Suspended), "Held for work in progress", "Amount owed", each with one-line help |
| Payer | "Payer" card with a `Workspace pays / Business pays` selector for everyone (E-13) | **Billing responsibility** statement only: Core/Growth "You pay for this business's usage."; Agency-paid client "**Billing managed by your agency**" and no funding controls; the Agency owner sees who pays and where to change it (Client accounts → business → Billing responsibility). No selector anywhere on the Business page |
| Manual funding | free-form amount, `min:1` micro (E-18) | **Add funds** with "Minimum USD 5.00 per top-up", existing-balance note, who is charged; $4.99 refused at the request and at the manager; decimal entry converted with bcmath |
| Automatic top-up | free-form threshold/amount `min:1` micro (E-17) | **Automatic top-up**, off by default, four preset radios ($5/$10/$25/$50), no custom field; consent recorded (who/when) only by the payer's explicit save; failed attempts shown and mailed |
| Spending limits | "Monthly spend cap" + raw `Feature key` text box "e.g. crm" (E-14) | **Monthly spending limit** with explanation; **Pause paid activity** (confirmed emergency stop); **Limits by capability** from a curated select (Contacts & CRM, Conversations, Automations, Website, Google Business Profile) |
| Agency | nothing Workspace-level (E-19/E-20) | **Agency-wide controls** for the Agency owner/admin only: aggregate monthly spending limit, aggregate monthly automatic top-up limit, agency-wide pause |
| Activity | raw `entry_type`, raw feature key, raw funding `state`/`purpose` | "Paid activity", "Funds added", "Automatic top-up", "Completed", "Did not go through", capability labels |
| Messages | "Payer updated." on a no-op; "Spend cap updated." | "No change — this business is already billed to your agency."; task-oriented refusals naming the limit and "nothing was charged" |
| Vocabulary | "Back to Workspace" | "Back to account" / "Back to agency account" from the Slice 1B context; no "Workspace" on Core/Growth pages |

## 4. Payer visibility and authorization (contract §12.4, §18 S-7)

* `BillingProfileManager::billingResponsibilityFor()` computes, server-side: tier, payer, agency-paid, whether the actor is the payer, whether the actor manages limits, whether the actor manages responsibility (Agency owner or Agency-wide active Admin on the Agency tier). The view renders from those facts; hiding a form is never the authorization.
* `assignPayer()` authority: a **real change** requires the Agency owner or an Agency-wide active Admin inside an Agency-tier Workspace, for either target; a Business user, scoped Admin, Staff, stranger, or any Core/Growth actor is refused (`UnauthorizedPayerAssignmentException` → 404 for strangers, an error message for viewers). `agency_rebill` is never a target.
* **True no-op:** submitting the currently-assigned payer performs no update (the row's `updated_at` is byte-for-byte unchanged), writes no `business_payer_transitions` row, dispatches no `BusinessPayerChanged`, sends nothing, calls no provider, and returns `changed=false`; the controller answers "No change — this business is already billed to [payer]." A reaffirmation is accepted from the Agency manager or the current payer themself and refused for everyone else (so the endpoint is never a payer oracle for unrelated actors).
* Funding controls (Add funds, Automatic top-up) render only for the payer; the manager's charge-causing consent rule (RFC-005 §16, unchanged) enforces it on every POST.

**UI-path allowlist gap (reported, not silently expanded):** the contract places the Agency's selector at *Client Accounts → [Business] → Billing responsibility*, i.e. the account frame (`resources/views/customer/workspaces/show.blade.php`, `WorkspaceController`, `routes/customer.php`). None of those paths is in the Slice 5 allowlist, so this slice ships the secure backend (the existing `POST …/usage-billing/payer` endpoint with the new authority and no-op rule) and removes the selector from the Business page; the account-frame control is the narrowest amendment for a follow-up. The Agency owner currently changes responsibility through that endpoint only.

## 5. $5 manual top-up (T-WALLET-1)

`UsageWalletManager::MINIMUM_MANUAL_TOP_UP_MICRO = 5_000_000` — five units of the wallet's currency in the repository's micro convention. `InitiateTopUpRequest` accepts `amount` ("5.00", converted with `bcmul`, at most two decimals) or `amount_micro`, and validates `min:5000000`; `UsageBillingTopUpController::initiate()` re-checks through `manualTopUpDenialReason()` before calling the unchanged `UsageBillingCheckoutManager::initiateTopUp()`. $4.99 → refused at both boundaries; $5.00 → accepted. A top-up is credited only by the existing return/webhook confirmation and exactly once (repeat confirmation proven idempotent). Existing balance is shown ("You already have … available"). Currency: the floor is 5.00 of the wallet currency; the wallet currency is resolved by RFC-005 from the Business.

## 6. Automatic top-up (T-WALLET-2/4/5/6)

* Presets `AUTO_RECHARGE_PRESETS_MICRO = [5, 10, 25, 50] × 1 000 000`; `ConfigureAutoRechargeRequest` validates `Rule::in(presets)`; `configureAutoRecharge()` refuses anything else (`InvalidArgumentException`) regardless of the request layer. There is no custom field; **the recommended $5–$500 custom range is not implemented (§28.9 gate)** and an unbounded amount can never ship.
* Off by default (schema default + `auto_recharge_consented_at/by` null); enabling records who consented and when; disabling clears it; a saved card or a manual top-up never enables it; the evaluation job creates no attempt and calls no provider while it is off.
* Concurrency: unchanged — one outstanding auto-recharge attempt per Business (RFC-005 §19); the existing real-two-process test still passes.
* Failed recharge: `recordAutoRechargeFailure()` now also mails `AutoRechargeFailedNotification` to the opted-in billing contact on every failure; the page shows the failure count and "Did not go through"; the balance is never touched.
* Ceilings: the Business monthly ceiling (existing) admits the exact boundary and refuses one unit over (proven through the job). The **Workspace aggregate monthly ceiling** is stored (`workspace_usage_controls.monthly_aggregate_recharge_cap_micro`), audited, and decided by `autoRechargeCeilingAdmission()` (exact boundary proven). **Wiring gap:** the evaluation job `app/Jobs/Usage/EvaluateBusinessAutoRecharge.php` (and `UsageBillingCheckoutManager::initiateAutoRecharge()`) are outside this slice's allowlist, so the Workspace ceiling is not yet consulted by the job itself — narrowest amendment: add that job to the allowlist and call `autoRechargeCeilingAdmission()` before `initiateAutoRecharge()`. The platform hard maxima (§28.9 c/d) are not invented.

## 7. Spending controls (T-CAP-1..5)

`reserve()` evaluation order is now: wallet lock → period rollover → **Business emergency stop** → **Workspace controls row lock + Workspace emergency stop** → meter/rate integrity → `billing_status` → outstanding debt → per-capability limit → Business monthly limit → **Workspace aggregate monthly limit** (Workspace-paid Businesses only; sum of committed + reserved spend this period across the Workspace-paid wallets in the same period) → platform safety limit → available balance. Every check is exact integer headroom (`evaluateHeadroom()`): exactly the remaining allowance succeeds, one micro-unit more fails, before any provider work. Lock order is fixed (wallet, then Workspace controls), so two Businesses of one Workspace serialize on the shared row and cannot both take the final aggregate unit.

Refusals return a reason code; `customerMessageForDenial()` turns it into one task-oriented sentence ("… nothing was charged"). A refusal by limit or empty balance mails `SpendingLimitReachedNotification` once per period; `usage:spending-threshold-alerts` (console, threshold alerts only) announces once per period when 80% of the Business limit is consumed.

BYO transport is untouched: nothing here assumes a usage event implies a debit; BYO transport takes no reservation (contract §11.5) and therefore consumes neither limit; paid non-transport meters reserve normally.

## 8. Emergency kill switch (T-CAP-5)

Two stops, both customer-authorized, no platform-global customer control:

* **Business:** `pausePaidActivity()/resumePaidActivity()` (Workspace owner, covering active Admin, or direct owner) sets `business_usage_wallets.paid_activity_paused_at/_by_user_id`; audited in `usage_control_transitions`.
* **Workspace:** `pauseWorkspacePaidActivity()/resumeWorkspacePaidActivity()` (Workspace owner or Agency-wide active Admin) sets `workspace_usage_controls.paid_activity_paused_at`.

While paused, `reserve()` refuses immediately (`paid_activity_paused` / `workspace_paid_activity_paused`) before any meter or provider work; open reservations keep their normal commit/release/expiry lifecycle; no ledger row is erased or settled; inbound handling takes no reservation and is unaffected. The UI shows "Paid activity paused" and a confirmed "Pause paid activity" / "Resume paid activity" control; the POST is refused server-side for Staff, Business users (Workspace stop) and strangers (404).

## 9. Raw feature keys removed (E-14)

`customerCapabilityCatalog()` = every registry feature that is Available and Business-scoped; `capabilityLabel()/capabilityHelp()` read `locale.usage_billing.capabilities.*` (readable fallback for a historical key). The page offers a `<select>` of those only; the route parameter is checked against the catalogue (`isCustomerLimitableCapability()`) and anything else — unknown, Planned, Workspace-scoped, path tricks — is 404 (never an oracle). Saved limits and ledger rows render labels; no provider cost, meter key or state name renders.

## 10. Trials and promotional credit (T-TRIAL-1, T-COST-4)

No tier, Business creation, plan change or Agency Business count credits a wallet (every new wallet is 0, no ledger row). Promotional credit exists only through `issueManualCredit(PromotionalCredit)` by a platform administrator: bounded to the granted amount, idempotent by operation id, audited (actor, reason), a distinct ledger entry type, consumed before paid balance (RFC-005 paid-first), and never part of `refundable_paid_available_micro` (not withdrawable as cash). **Gap recorded honestly:** the ledger has no expiry column; time-bounded promotions are recorded through the mandatory reason and their automatic lapse is not modelled.

## 11. Schema and migrations

Additive only; no merged migration edited:

1. `2026_09_11_120001_add_spending_controls_to_business_usage_wallets_table` — five nullable columns; `down()` drops exactly them.
2. `2026_09_11_120002_create_workspace_usage_controls_tables` — `workspace_usage_controls` (unique per Workspace) and append-only `usage_control_transitions`; `down()` drops both.
3. `2026_09_11_120003_backfill_auto_recharge_consent_on_business_usage_wallets` — data-only, idempotent (`apply()`): enabled rows with a threshold and a preset amount get `auto_recharge_consented_at = updated_at` (user unknown → null, never invented); every other enabled row is switched **off** (values kept for a one-click re-enable); nothing else is touched; `down()` is a documented no-op.

Forward, `migrate:rollback --step=3`, forward replay: see the branch report.

## 12. Changed paths (all inside §22.1 row 5)

`app/Http/Requests/Customer/Business/{InitiateTopUpRequest,ConfigureAutoRechargeRequest,UpdateBusinessSpendCapRequest,UpdateBusinessFeatureLimitRequest}.php`;
`app/Library/Usage/{BillingProfileManager,UsageWalletManager}.php`;
`app/Http/Controllers/Customer/Business/{UsageBillingController,UsageBillingTopUpController,UsageBillingAutoRechargeController}.php`;
`app/Models/BusinessUsageWallet.php`; `app/Notifications/Usage/{AutoRechargeFailedNotification,SpendingLimitReachedNotification}.php`;
`app/Console/Commands/SendUsageSpendingThresholdAlerts.php` (threshold alerts only);
`resources/views/customer/business/usage-billing/show.blade.php`; `resources/lang/en/locale.php` (`usage_billing.*`);
`database/migrations/2026_09_11_12000{1,2,3}_*.php`; `tests/Feature/Usage/Slice5/**` (new) and the amended
`tests/Feature/Usage/**` files listed in the report; `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` (§41); this file.

`app/Models/BusinessPayerAssignment.php` needed no change. The new `workspace_usage_controls` table is read/written through `DB::table()` inside `UsageWalletManager` because `app/Models/**` beyond the two named models is outside the allowlist.

## 13. Still-gated financial decisions (recorded honestly)

| Decision | State |
|---|---|
| §28.9 (a)/(b) custom auto-recharge range | not implemented; presets only, custom prohibited server-side |
| §28.9 (c)/(d) platform hard maxima for Business/Workspace monthly ceilings | not invented; any operator value accepted |
| §28.1a telecom retail rate card | no telecom rate activated; every amount in tests is an explicit fixture rate |
| T-COST-9 in real retail currency | proven with fixture rates only |
| Workspace aggregate recharge ceiling wiring into `EvaluateBusinessAutoRecharge` | decided in the manager; job outside the allowlist (§6) |
| Account-frame "Billing responsibility" control | backend shipped; UI path outside the allowlist (§4) |
| Promotional credit expiry | not modelled by the ledger (§10) |

## 14. Evidence

All runs on the disposable `ultimatesms_testing` database, one `artisan test <path>` per
invocation, strictly serial, `QUEUE_CONNECTION=sync`, fake provider gateway only (no live
Stripe call anywhere in the suite).

### 14.1 Migrations (this slice's exact three migrations)

| Step | Result |
|---|---|
| `migrate:fresh --force` (forward from empty) | `…120001` DONE, `…120002` DONE, `…120003` DONE |
| `migrate:rollback --step=3 --force` | exactly the three Slice 5 migrations rolled back (reverse order); previous migration `2026_09_10_140001_create_view_as_sessions_table` still `Ran` |
| `migrate --force` (forward replay) | all three `Ran` again in batch 2 |

### 14.2 Test counts

| Command (one path per invocation) | Result |
|---|---|
| `php artisan test tests/Feature/Usage/Slice5` (new Slice 5 tests) | 52 passed (496 assertions) |
| `php artisan test tests/Feature/Usage` (after the boundary/fixture fixes) | first run (before the two fixes in §14.5): 5 failed, 953 passed (4578 assertions); rerun after the fixes: 3 failed, 955 passed (4597 assertions); third run: 1 failed, 957 passed (4597 assertions). Every failure after the fixes is a multi-process holder/race test that passes in isolation with the Slice 5 schema (§14.3); no non-concurrency failure remains |
| `php artisan test tests/Unit/Usage` | 21 passed (46 assertions) |
| `php artisan test tests/Feature/Security` | 140 passed (1129 assertions) |
| `php artisan test tests/Feature/Workspace` | 774 passed (2279 assertions) |
| `php artisan test tests/Feature/Business` | 3 failed, 540 passed (5021 assertions) — the three `BusinessKnowledgeProfile*` failures reproduced on baseline (§14.3) |
| `php artisan test tests/Feature/Entitlement` | 326 passed (869 assertions) |
| `php artisan test tests` (full suite, with the Slice 5 code) | 9 failed, 5037 passed (25440 assertions) — all nine reproduced on baseline (§14.3); zero failures under `tests/Feature/Usage` or `tests/Unit/Usage` |

### 14.3 Baseline reproductions (exact `origin/main` = `d2b275ec9dd447c27276007636062815a1b8e957`)

| Test | Slice 5 branch | Baseline `d2b275e` | Verdict |
|---|---|---|---|
| `AutoRechargeFailedPaymentRetryTest::test_two_concurrent_evaluations_for_the_same_business_never_create_two_attempts` | 4 passed / 1 failed in 5 isolated runs | 4 passed / 2 failed in 6 isolated runs | pre-existing timing sensitivity of the forced two-process race (the second process occasionally reaches the wallet lock only after the first attempt has already completed against the fake gateway); not introduced by this slice |
| `BrandingAdminFooterRenderTest::test_admin_footer_renders_the_company_name_exactly_once` | failed (full suite) | 1 failed, 1 passed (9 assertions) — same test | pre-existing |
| `BusinessKnowledgeProfileControllerTest::test_missing_stale_and_present_fields_are_all_displayed` | failed (Business, full suite) | 1 failed, 28 passed (68 assertions) — same test | pre-existing |
| `BusinessKnowledgeProfileHoursTest::test_no_change_row_for_a_true_hours_no_op` | failed (Business, full suite) | 1 failed, 17 passed (35 assertions) — same test, same "2 is identical to 1" | pre-existing |
| `BusinessKnowledgeProfileSeamTest::test_only_the_manager_writes_the_tracked_tables` | failed (Business, full suite) | 1 failed, 2 passed (3707 assertions) — same test | pre-existing |
| `OpportunityManagerBeginRunTest` heartbeat test (`RunAlreadyActiveException`) | failed (full suite) | 1 failed, 11 passed (48 assertions) — same test, same exception | pre-existing |
| `WebsiteIndexingTest::test_robots_txt_is_untouched_by_this_feature_branch` | failed (full suite) | 1 failed, 4 passed (15 assertions) — same test | pre-existing |
| `WebsiteDraftPageServiceSeamTest` store/update "persists every draft field" (2 tests) | failed (full suite) | 2 failed, 8 passed (56 assertions) — same two tests | pre-existing |
| `WebsiteDraftPublishTest::test_rollback_repoints_the_website_without_mutating_any_revision…` | failed (full suite) | 1 failed, 6 passed (35 assertions) — same test | pre-existing |
| `UsageWalletManagerConcurrencyTest`, `UsageWalletManagerSetActiveRateConcurrencyTest` (multi-process "holder" tests) | all green in the full-suite run; in back-to-back `tests/Feature/Usage` reruns one to three of them failed with "Holder process never confirmed its lock" | isolated, three runs per file on both worktrees with the Slice 5 schema in place: Slice 5 worktree 6 of 6 runs green; baseline `UsageWalletManagerConcurrencyTest` 1 failed / 2 passed in 2 of its 3 runs, `SetActiveRate` 3 of 3 green | environment-sensitive multi-process tests on this Windows host (subprocess bootstrap versus the lock-confirmation window); not a code-path change of this slice |

### 14.4 Static checks

- `git diff --cached --check` on the 45 staged paths: exit 0.
- Secret-shaped-string sweep on every added line of the staged diff (Stripe `sk_`/`pk_`/`whsec_`, AWS keys, private-key blocks, `key/secret/password/token = "…"`): no match.
- Changed-path allowlist audit: all 45 paths inside contract §22.1 row 5 (see §12).

### 14.5 Fixes made during verification

- `AdminUsageBillingSurfaceBoundaryTest` and `ProviderRefundDisputeSurfaceBoundaryTest` forbid any raw `DB::table('business_usage_…')` query outside the Eloquent repositories. The three Slice 5 sites in `UsageWalletManager` (Workspace aggregate spend, Workspace aggregate recharge, threshold-alert marker) now go through the `BusinessUsageWallet` / `BusinessPayerAssignment` / `Business` models and the wallet repository, with identical semantics.
- `CrossBusinessPaymentIsolationTest` and `ProviderCustomerOwnershipTest` set "Business pays" through `changePayer()` as the Business owner, which §4 now denies; those fixtures write the assignment row directly, like the other amended tests.
- Shared-database hazard recorded for future rounds: the baseline worktree's `RefreshDatabase` runs leave `ultimatesms_testing` at the origin/main schema, so Slice 5 files that deliberately skip `RefreshDatabase` must be preceded by `migrate:fresh` from this worktree (done before §14.3's isolated runs; the database is left at the Slice 5 schema).
