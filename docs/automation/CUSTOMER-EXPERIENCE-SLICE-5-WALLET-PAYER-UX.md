# CUSTOMER EXPERIENCE — SLICE 5: WALLET, PAYER, BALANCE AND SPENDING-CONTROL UX

## 1. Status and authority

**Status:** Implemented on branch `agent/customer-experience-slice-5-wallet-payer-ux`
(initial commit `7d6e902c9822754b23f6a2303001a9cbf323fbe0`), corrected by
**Correction Round 1** on the same branch (this revision). Correction Round 1
closes the four gaps the first implementation reported — the Workspace
automatic top-up ceiling not wired into the real job, the missing Agency payer
control on the account frame, ceilings without approved maxima, and generic
billing authority letting an agency-paid client alter financial controls — and
adds the owner-approved twice-per-rolling-24-hours protection.

**Parent contract:** `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`
— §12 (wallet, payer and spending-control model), §17.1/§17.2, §18 (S-2,
S-6, S-7, S-10), §19, §20 (C-4, C-9, C-10), §21/§21.1 row 5, §22.1 row 5
(as amended by Correction Round 1), §24/§24.1 (T-PAYER-1..4, T-WALLET-1..6,
T-CAP-1..5, T-TRIAL-1, T-COST-4/9/10), §27 C-3 and §28.1a/§28.9 (§28.9 is
now resolved by owner approval). Where the parent intentionally corrects
RFC-005, the parent wins; RFC-005 is amended only per §27 C-3 (its §41, items
1–11).

**Verified base:** `origin/main` at `d2b275ec9dd447c27276007636062815a1b8e957`
(`Merge pull request #218`), unchanged at the start of Correction Round 1.
Depends on merged Slice 1B only; independent of Lane C's Slice 1A.

**Owned tests (parent §24.1):** T-PAYER-1..4, T-WALLET-1..6, T-CAP-1..5,
T-TRIAL-1, T-COST-4, T-COST-9, T-COST-10 — T-WALLET-4 and T-WALLET-6 are no
longer gated (§13).

## 2. Existing RFC-005 primitives reused (nothing duplicated)

| Primitive | Reused as |
|---|---|
| `business_usage_wallets` + `UsageWalletManager::reserve()/commit()/release()/expireStaleReservations()` | the one wallet; every new spending control is evaluated inside `reserve()` under the existing wallet row lock |
| `business_usage_ledger_entries` (immutable) | untouched; the ledger renders with readable labels only |
| `business_usage_reservations` idempotency keys and TTL | untouched; the kill switch never settles or corrupts an open reservation |
| `business_payer_assignments` / `business_payer_transitions` / `BusinessPayerChanged` | the one payer system; `BillingProfileManager::assignPayer()` carries the no-op rule and the Agency authority; the account-frame control posts into it |
| `monthly_spend_cap_micro`, `business_feature_usage_limits`, `platform_feature_usage_safety_limits` | unchanged; surfaced in plain language |
| `monthly_recharge_cap_micro`, `recharged_this_period_micro`, `consecutive_recharge_failures`, `EvaluateBusinessAutoRecharge` | the Business ceiling is now required, bounded and enforced by the real job (§6A); the counter and the failure logic are unchanged |
| `business_funding_attempts` (+ transitions), `UsageBillingCheckoutManager::initiateCharge()`, outstanding-attempt rule, exactly-once credit | **the durable claim**: the attempt created under the wallet lock is the pending automatic top-up capacity; the same rows drive the rolling-window count — no second counter, no parallel ledger |
| `issueManualCredit(PromotionalCredit)` (platform administrator only, idempotent, audited) | the only way promotional credit exists |
| `FakePaymentProviderGateway` | the only provider in every test |

No second wallet, ledger, payer system, reservation mechanism, provider-specific balance, disconnected "daily recharge" counter or credit balance exists. All money is integer micro-units with bcmath.

## 3. Customer-visible behaviour — before / after

| Area | Before | After |
|---|---|---|
| Balance | "Available / Reserved / Debt balance", "Committed spend this period", period key | **Available balance**, **Usage this month**, status (Active / Paid activity paused / Outstanding balance / Suspended), "Held for work in progress", "Amount owed", each with one-line help |
| Payer | "Payer" card with a `Workspace pays / Business pays` selector for everyone (E-13) | **Billing responsibility** statement only on the Business page. **Correction Round 1:** the Agency control lives at **Client accounts → [Business] → Billing responsibility** on the account frame — "Agency pays" / "Client pays" with plain explanations, the active option marked, a deliberate "Save billing responsibility" action (§4) |
| Manual funding | free-form amount, `min:1` micro (E-18) | **Add funds** with "Minimum USD 5.00 per top-up", existing-balance note, who is charged; $4.99 refused at the request and at the manager |
| Automatic top-up | free-form threshold/amount `min:1` micro (E-17); optional ceiling | **Automatic top-up**, off by default, four preset radios ($5/$10/$25/$50), no custom field; **Correction Round 1:** $5 visually preselected while off (nothing saved on GET), the **monthly limit is required** ("at least one top-up and at most USD 500.00 per month — a safety limit you set, not an amount we charge"), "Automatic top-up can run at most twice in any 24 hours." |
| Spending limits | "Monthly spend cap" + raw `Feature key` text box "e.g. crm" (E-14) | **Monthly spending limit**, **Pause paid activity**, **Limits by capability** from a curated select — **Correction Round 1:** rendered and accepted only for the payer side (§8A) |
| Agency | nothing Workspace-level (E-19/E-20) | **Agency-wide controls** for the Agency owner/admin only: aggregate monthly spending limit, aggregate monthly automatic top-up limit ("one shared limit across all client accounts whose usage your agency pays for, at most USD 500.00"), agency-wide pause |
| Activity | raw `entry_type`, raw feature key, raw funding `state`/`purpose` | "Paid activity", "Funds added", "Automatic top-up", "Completed", "Did not go through", capability labels |
| Messages | "Payer updated." on a no-op | "No change — this client account is already billed to your agency." / "No change — this client already pays for this account." / "No change — you already pay for this business."; refusals name the limit and say "No automatic charge was made — you can add funds manually" |
| Vocabulary | "Back to Workspace" | "Back to account" / "Back to agency account" from the Slice 1B context; no "Workspace" on Core/Growth pages |

## 4. Payer visibility and authorization (contract §12.4, §18 S-7; Correction Round 1 §8, §10)

* `BillingProfileManager::billingResponsibilityFor()` computes, server-side: tier, payer, agency-paid, whether the actor is the payer, whether the actor manages the payer-side financial controls (`actor_manages_limits`, §8A), whether the actor may edit the billing contact (`actor_manages_billing_contact`, M2 authority, unchanged), and whether the actor manages responsibility (Agency owner or Agency-wide active Admin on the Agency tier). Views render from those facts; hiding a form is never the authorization.
* **Where the control lives (Correction Round 1):** `WorkspaceController::show()` adds `billingResponsibility` view data — uid, name and the customer-facing responsibility of every client account — only for an Agency-tier Workspace and only when the actor manages responsibility for every listed account (the account frame already admits only the owner and scope-all active Admins; Staff never receive it; the Agency's own directly owned Business is not listed). `resources/views/customer/workspaces/show.blade.php` renders, per client account, a form with `billing_responsibility` = `agency` | `client`, the current option marked, the two explanations, and "Save billing responsibility". It posts to the existing `POST …/businesses/{business}/usage-billing/payer` route with `return_to=account`; `UpdateBusinessPayerRequest` maps the customer vocabulary to `PayerType` server-side (the legacy `payer_type` field is accepted for existing authorized callers only — no customer form renders it, and no `payer_type`, enum word, assignment id or user id appears in customer HTML).
* `assignPayer()` authority is unchanged: a **real change** requires the Agency owner or an Agency-wide active Admin inside an Agency-tier Workspace; a Business user, scoped Admin, Staff, stranger or any Core/Growth actor is refused; cross-Workspace and cross-Business paths are 404. Core/Growth never see the control on any page; the Business's own Usage & Billing page still has no selector.
* **True no-op, through the real form:** submitting the active choice performs no update (the row's `updated_at` is byte-for-byte unchanged), writes no transition, dispatches no `BusinessPayerChanged`, sends nothing, credits nothing, creates no attempt, calls no provider, and answers "No change — …" in customer language, never "updated". A genuine change is row-locked, audited once and evented exactly once; repeating it is the no-op.
* Funding controls (Add funds, Automatic top-up) render only for the payer; RFC-005 §16's charge-causing consent enforces it on every POST.

## 5. $5 manual top-up (T-WALLET-1)

`UsageWalletManager::MINIMUM_MANUAL_TOP_UP_MICRO = 5_000_000`. `InitiateTopUpRequest` accepts `amount` ("5.00", converted with `bcmul`, at most two decimals) or `amount_micro`, and validates `min:5000000`; `UsageBillingTopUpController::initiate()` re-checks through `manualTopUpDenialReason()` before the unchanged `initiateTopUp()`. $4.99 → refused at both boundaries; $5.00 → accepted. A top-up is credited only by the existing return/webhook confirmation and exactly once. Manual top-ups never count towards any automatic top-up ceiling or the rolling window (§6A).

## 6. Automatic top-up — the owner-approved policy (T-WALLET-2/4/5/6; Correction Round 1 §2, §3, §6, §7)

**Single authoritative source — `UsageWalletManager` constants:**

| Constant | Value | Meaning |
|---|---|---|
| `MINIMUM_MANUAL_TOP_UP_MICRO` | 5 000 000 | $5 minimum manual top-up |
| `AUTO_RECHARGE_PRESETS_MICRO` | 5 / 10 / 25 / 50 × 10⁶ | the only automatic top-up amounts |
| `AUTO_RECHARGE_SUGGESTED_PRESET_MICRO` | 5 000 000 | visual suggestion only |
| `BUSINESS_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO` | 500 000 000 | $500 per Business per month (safety maximum) |
| `WORKSPACE_MONTHLY_AUTO_RECHARGE_MAXIMUM_MICRO` | 500 000 000 | $500 across one Agency Workspace per month (safety maximum) |
| `AUTO_RECHARGE_MAX_PER_ROLLING_WINDOW` | 2 | automatic top-ups per Business per window |
| `AUTO_RECHARGE_ROLLING_WINDOW_HOURS` | 24 | the rolling window |

* **Default and consent:** off by default (schema default + `auto_recharge_consented_at/by` null); only the payer's explicit save enables it and records who/when; a saved card, a manual top-up, an unrelated form, a GET, or a migration never enables it or infers consent.
* **Suggested amount:** while disabled and no valid preset is saved, the page preselects $5 and says "Nothing is saved or charged until you turn automatic top-up on and save." Nothing is written on GET. A submission with automatic top-up off keeps it off. A saved valid preset stays selected; a legacy non-preset amount is never offered (visual fallback to $5, no write).
* **Presets only:** `ConfigureAutoRechargeRequest` (`Rule::in`) and `configureAutoRecharge()` (`autoRechargeConfigurationProblem()`) each refuse every other amount; there is no custom field and the former $5–$500 custom range is withdrawn.
* **Business ceiling — required and bounded:** enabling requires `monthly_recharge_cap_micro` ≥ the preset and ≤ $500 (both boundaries; `$500.00` succeeds, `$500.01` fails, `500_000_000` succeeds, `500_010_000` fails, negative/zero/blank fail while enabling). While disabled a stored ceiling is still bounded by the maximum but is never permission to charge.
* **Agency aggregate ceiling — bounded and required for agency-paid Businesses:** `setWorkspaceAggregateRechargeCap()` and `UpdateBusinessSpendCapRequest` refuse anything above $500; an agency-paid Business cannot be charged automatically until the Agency has set one (fails closed). Core/Growth accounts need none; a client-paid Business neither counts towards nor is limited by it.
* **Frequency:** at most two automatic top-ups per Business in any rolling 24 hours (§6A).
* Failed recharge: `recordAutoRechargeFailure()` still mails `AutoRechargeFailedNotification` on every payment failure; a policy refusal (§6A) is not a failure.

## 6A. Real execution flow, lock order and accounting (Correction Round 1 §5, §7)

1. `EvaluateBusinessAutoRecharge::handle()` — enabled? below threshold? then the **read-only pre-check** `UsageWalletManager::autoRechargeCeilingAdmission()`; a refusal exits with `notifyAutoRechargeRefusal()` (one opted-in billing-contact alert per rolling window, `auto_recharge_refusal_notified_at`), creating no attempt and touching no balance or failure counter. Then the outstanding-attempt rule, then `initiateAutoRecharge()`.
2. `UsageBillingCheckoutManager::initiateCharge()` (AutoRecharge branch) — inside one transaction: **lock 1** `business_usage_wallets` row (`FOR UPDATE`); outstanding-attempt check (locking read); `claimAutoRechargeAdmissionUnderLock()` → **lock 2** `workspace_usage_controls` row (`FOR UPDATE`, only while the Workspace pays); period rollover; the full evaluation below; then the attempt row is created **in the same transaction** — it is the durable claim. Only after commit is the provider called. The order wallet → Workspace is the same as `reserve()`, so two Businesses of one Workspace serialize on the Workspace row and two evaluations of one Business on its wallet row; because both locks are locking reads taken before any consistent read, the transaction's snapshot includes every claim committed by the previous lock holders.
3. **Evaluation order** (`evaluateAutoRechargeAdmission()`), every step before any provider call: (a) Business ceiling — missing fails closed; effective limit = min(stored, $500); consumed = `recharged_this_period_micro` (incremented only by AutoRecharge credits) + `expected_amount_micro` of the Business's outstanding AutoRecharge attempts; exact integer headroom; (b) rolling window — `countAutoRechargeAttemptsCreatedAfter(now − 24h)` over outstanding + charged states; ≥ 2 refuses; (c) Workspace aggregate (Workspace-paid only) — missing on the Agency tier fails closed, absent on Core/Growth is skipped; effective limit = min(stored, $500); consumed = Σ `recharged_this_period_micro` over the Workspace-paid wallets in the same period + Σ outstanding AutoRecharge amounts with `payer_type_snapshot = workspace`.
4. **Accounting invariants:** a pending attempt counts by amount until it settles or fails; on settlement the same amount moves into `recharged_this_period_micro` (credit and state finalization commit in one transaction — never both, never neither); a definitively failed or canceled attempt released its claim and its window slot by leaving the outstanding states; the platform-administrator retry and a replayed webhook confirm the **same** attempt (one row, one ledger entry, one slot, one amount); manual top-ups, promotional credit, refunds, unrelated adjustments, BYO transport and client-paid Businesses never count.
5. **Boundary rule:** an attempt counts while `created_at > now() − 24 hours`; exactly 24 hours old no longer counts (precise timestamps, never a calendar-date comparison).
6. **Customer message and alert:** refusals map through `customerMessageForDenial()` to `usage_billing.denials.*` — "Automatic top-up has reached its daily safety limit — it can run at most twice in any 24 hours. No automatic charge was made. You can add funds manually at any time." (and the ceiling equivalents) — never a funding-attempt state or identifier; the actual payer's opted-in billing contact receives `SpendingLimitReachedNotification` with that sentence at most once per window.
7. Existing protections stay: one outstanding auto-recharge attempt per Business, the failed-payment counter and the 3-strike system disable, `AutoRechargeFailedNotification`, exactly-once credit, no provider call inside a transaction.

## 7. Spending controls (T-CAP-1..5)

`reserve()` evaluation order is: wallet lock → period rollover → **Business emergency stop** → **Workspace controls row lock + Workspace emergency stop** → meter/rate integrity → `billing_status` → outstanding debt → per-capability limit → Business monthly limit → **Workspace aggregate monthly limit** (Workspace-paid Businesses only) → platform safety limit → available balance. Every check is exact integer headroom (`evaluateHeadroom()`) before any provider work. Refusals return a reason code; `customerMessageForDenial()` turns it into one task-oriented sentence. A refusal by limit or empty balance mails `SpendingLimitReachedNotification` once per period; `usage:spending-threshold-alerts` announces once per period at 80% of the Business limit. BYO transport is untouched (contract §11.5).

## 8. Emergency kill switch (T-CAP-5)

Two stops, both customer-authorized, no platform-global customer control: **Business** (`pausePaidActivity()/resumePaidActivity()`, payer side only since Correction Round 1 — §8A) sets `business_usage_wallets.paid_activity_paused_at/_by_user_id`; **Workspace** (`pauseWorkspacePaidActivity()/resumeWorkspacePaidActivity()`, Workspace owner or Agency-wide active Admin) sets `workspace_usage_controls.paid_activity_paused_at`. Both audited in `usage_control_transitions`. While paused, `reserve()` refuses immediately before any meter or provider work; open reservations keep their lifecycle; the ledger is never modified.

## 8A. Financial-control authority matrix (Correction Round 1 §9)

One matrix, `BillingProfileManager::actorManagesPayerControls()`, enforced by `UsageWalletManager::setSpendCap()/setFeatureLimit()/pausePaidActivity()/resumePaidActivity()` and rendered through `billingResponsibilityFor()['actor_manages_limits']`:

| Scenario | Adds funds / enables automatic top-up (RFC-005 §16 consent) | Business spending limit, capability limits, pause/resume | Agency-wide controls | Billing responsibility | Billing contact (M2, unchanged) |
|---|---|---|---|---|---|
| Core / Growth owner | owner | owner | — (no selector, no Agency controls) | — | owner + covering Admin |
| Core / Growth other users | no | no | — | — | as before |
| Agency, **agency-paid** client: Agency owner | yes | yes | yes | yes | yes |
| Agency, agency-paid client: Agency-wide active Admin | no (charge-causing consent stays with the payer of record) | yes | yes | yes | yes |
| Agency, agency-paid client: **the client** | no | **no** | no | no | yes |
| Agency, **client-paid**: the client (payer) | yes | yes | — | no | yes |
| Agency, client-paid: Agency owner / Agency-wide Admin | no | **no** (changes responsibility, views the summary) | yes (agency-paid accounts only) | yes | yes |
| Staff, selected-scope Admin, inactive member, stranger | no | no | no | no | no (stranger 404) |

The agency-paid client's page shows **"Billing managed by your agency"** and no financial mutation form; every crafted POST from that client (add funds, automatic top-up, spending limit, capability limit, pause/resume, billing responsibility, Agency-wide controls) is refused server-side with every row, event, notification, attempt and provider untouched. Generic permission to edit the billing contact never implies any financial control.

## 9. Raw feature keys removed (E-14)

`customerCapabilityCatalog()` = every registry feature that is Available and Business-scoped; `capabilityLabel()/capabilityHelp()` read `locale.usage_billing.capabilities.*`. The page offers a `<select>` of those only; the route parameter is checked against the catalogue (`isCustomerLimitableCapability()`) and anything else is 404. Saved limits and ledger rows render labels; no provider cost, meter key, state name, payer enum or identifier renders.

## 10. Trials and promotional credit (T-TRIAL-1, T-COST-4)

No tier, Business creation, plan change or Agency Business count credits a wallet. Promotional credit exists only through `issueManualCredit(PromotionalCredit)` by a platform administrator: bounded, idempotent by operation id, audited, a distinct ledger entry type, consumed before paid balance, never withdrawable as cash, and never counted towards any automatic top-up ceiling. **Gap recorded honestly:** the ledger has no expiry column; automatic lapse is not modelled.

## 11. Schema and migrations

Additive only; no merged migration edited; the three original Slice 5 migrations are left exactly as verified:

1. `2026_09_11_120001_add_spending_controls_to_business_usage_wallets_table` — five nullable columns.
2. `2026_09_11_120002_create_workspace_usage_controls_tables` — `workspace_usage_controls` and append-only `usage_control_transitions`.
3. `2026_09_11_120003_backfill_auto_recharge_consent_on_business_usage_wallets` — data-only, idempotent consent backfill.
4. **Correction Round 1:** `2026_09_11_120004_require_deliberate_ceiling_for_enabled_auto_recharge_on_business_usage_wallets` — adds nullable `business_usage_wallets.auto_recharge_refusal_notified_at` (the once-per-window refusal-alert marker) and, idempotently (`apply()`), switches **off** every enabled row whose ceiling is missing, zero, below its preset or above $500 — values preserved for a one-click re-enable, consent neither fabricated nor erased, no balance/ledger/payer/attempt touched. Rationale: the maxima are safety maxima, not defaults, so an enabled row without a deliberately chosen compliant ceiling must not keep charging; a new additive migration was chosen over editing the verified backfill. `down()` drops the marker column only and never re-enables anything.

Forward, `migrate:rollback --step=4` (exactly the Slice 5 set), forward replay: §14.

## 12. Changed paths (all inside §22.1 row 5 as amended by Correction Round 1)

Original Slice 5: `app/Http/Requests/Customer/Business/{InitiateTopUpRequest,ConfigureAutoRechargeRequest,UpdateBusinessSpendCapRequest,UpdateBusinessFeatureLimitRequest,UpdateBusinessPayerRequest}.php`; `app/Library/Usage/{BillingProfileManager,UsageWalletManager}.php`; `app/Http/Controllers/Customer/Business/{UsageBillingController,UsageBillingTopUpController,UsageBillingAutoRechargeController}.php`; `app/Models/BusinessUsageWallet.php`; `app/Notifications/Usage/{AutoRechargeFailedNotification,SpendingLimitReachedNotification}.php`; `app/Console/Commands/SendUsageSpendingThresholdAlerts.php`; `resources/views/customer/business/usage-billing/show.blade.php`; `resources/lang/en/locale.php`; `database/migrations/2026_09_11_12000{1,2,3,4}_*.php`; `tests/Feature/Usage/Slice5/**` and the amended `tests/Feature/Usage/**` files; `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` (§41); this file.

**Correction Round 1 narrow allowlist additions (parent §22.1 row 5), each mechanically required:**

| Path | Why it was required |
|---|---|
| `app/Jobs/Usage/EvaluateBusinessAutoRecharge.php` | the real automatic-recharge job must consult every ceiling and the rolling window before initiating, and treat a refusal as a policy outcome |
| `app/Library/Usage/UsageBillingCheckoutManager.php` (`initiateCharge()` AutoRecharge branch only) | the admission and durable claim must happen under the wallet lock, in the transaction that creates the attempt, before the provider call |
| `app/Repositories/Contracts/BusinessFundingAttemptRepository.php`, `app/Repositories/Eloquent/EloquentBusinessFundingAttemptRepository.php` | two additive read methods deriving pending capacity and the window count from the authoritative funding attempts (the surface-boundary tests forbid raw billing-table queries elsewhere) |
| `app/Http/Controllers/Customer/Workspace/WorkspaceController.php` (`show()` view data) and `resources/views/customer/workspaces/show.blade.php` | the contracted Agency control at Client accounts → [Business] → Billing responsibility |
| `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md` | §12.2, §21, §22.1, §24.1 and §28.9 record the approved policy and this allowlist |

Not needed and not touched: `routes/customer.php` (the account-frame form posts to the existing payer route), any other Workspace controller/view, any other job or Usage library, `app/Models/**` beyond the wallet model, and any test directory outside `tests/Feature/Usage/**`.

## 13. Still-gated decisions (recorded honestly)

| Decision | State |
|---|---|
| §28.1a telecom retail rate card | no telecom rate activated; every amount in tests is an explicit fixture rate |
| T-COST-9 in real retail currency | proven with fixture rates only |
| Provider / Telnyx production implementation, BYO provider implementation | untouched (Slices 3/4/9) |
| Promotional credit automatic expiry | not modelled by the ledger (§10) |
| Custom automatic top-up amounts | withdrawn by owner decision; presets only |

Resolved by Correction Round 1 (no longer gaps): the Workspace ceiling is wired into the real job and checkout path; the Agency payer UI exists on the account frame; the monthly ceiling hard maxima are approved ($500 / $500) and enforced; the rolling-window limit is enforced.

## 14. Evidence

All runs on the disposable `ultimatesms_testing` database, one `artisan test <path>` per
invocation, strictly serial (the runner waits for an idle PHP before each path),
`QUEUE_CONNECTION=sync`, fake provider gateway only — no live Stripe or other provider
call anywhere. Correction Round 1 evidence; the original Slice 5 evidence is superseded.

### 14.1 Migrations (exactly the four Slice 5 migrations)

| Step | Result |
|---|---|
| `migrate:fresh --force` (forward from empty) | `…120001`, `…120002`, `…120003`, `…120004` all DONE |
| `migrate:rollback --step=4 --force` | exactly the four Slice 5 migrations rolled back in reverse order; the previous migration `2026_09_10_140001_create_view_as_sessions_table` still `Ran` |
| `migrate --force` (forward replay) | all four `Ran` again in batch 2 |
| `120004::apply()` twice on the replayed schema | `{"disabled":0}` both times (idempotent) |
| `DeliberateCeilingBackfillTest` | 1 passed (33 assertions): four non-compliant enabled rows switched off, one compliant row kept, one disabled row untouched; every balance, stored value, consent record, ledger row and payer assignment byte-for-byte preserved; second `apply()` a no-op; re-enabling requires a compliant ceiling |

### 14.2 Test counts

| Command (one path per invocation) | Result |
|---|---|
| Focused correction files (amended existing tests, 19 files) | all green after the fixture amendments; see the per-file counts in the branch report |
| `php artisan test tests/Feature/Usage/Slice5` | 88 passed (1019 assertions) — plus `DeliberateCeilingBackfillTest` run on its own: 1 passed (33 assertions), written after that directory scan |
| `php artisan test tests/Feature/Usage` | **994 passed (5128 assertions), 0 failed** — every multi-process concurrency test included |
| `php artisan test tests/Unit/Usage` | 21 passed (46 assertions) |
| `php artisan test tests/Feature/Workspace` | 774 passed (2279 assertions) |
| `php artisan test tests/Feature/Security` | 140 passed (1129 assertions) |
| `php artisan test tests/Feature/Business` | 3 failed, 540 passed (5021 assertions) — the three `BusinessKnowledgeProfile*` failures reproduced on baseline (§14.3) |
| `php artisan test tests/Feature/Entitlement` | 326 passed (869 assertions) |
| `php artisan test tests` (full suite) | 13 failed, 5070 passed (25928 assertions): the nine pre-existing baseline failures (§14.3) plus four `tests/Feature/DesignSystem/**` failures caused by this round's first account-frame markup and fixed before commit (§14.5); zero failures under `tests/Feature/Usage`, `tests/Unit/Usage`, `tests/Feature/Workspace`, `tests/Feature/Security` |
| `WorkspaceBusinessComponentAdoptionTest`, `WorkspaceBusinessDesignSystemContentTest` after the fix | 13 passed (136 assertions), 8 passed (101 assertions) |
| Final reruns after the account-frame markup fix (`AgencyBillingResponsibilityTest`, `WorkspaceOverviewHttpTest`, the two DesignSystem files) | 7 passed (110 assertions); 31 passed (85 assertions); 13 passed (136 assertions); 8 passed (101 assertions) |

### 14.3 Baseline reproductions (exact `origin/main` = `d2b275ec9dd447c27276007636062815a1b8e957`, unchanged during this round)

| Test | Correction branch | Baseline `d2b275e` (this round, detached worktree) | Verdict |
|---|---|---|---|
| `BrandingAdminFooterRenderTest::test_admin_footer_renders_the_company_name_exactly_once` | failed (full suite) | 1 failed, 1 passed (9 assertions) — same test | deterministic baseline failure |
| `BusinessKnowledgeProfileControllerTest::test_missing_stale_and_present_fields_are_all_displayed` | failed | 1 failed, 28 passed (68 assertions) — same test | deterministic baseline failure |
| `BusinessKnowledgeProfileHoursTest::test_no_change_row_for_a_true_hours_no_op` | failed | 1 failed, 17 passed (35 assertions) — same test | deterministic baseline failure |
| `BusinessKnowledgeProfileSeamTest::test_only_the_manager_writes_the_tracked_tables` | failed | 1 failed, 2 passed (3707 assertions) — same test | deterministic baseline failure |
| `OpportunityManagerBeginRunTest` heartbeat test (`RunAlreadyActiveException`) | failed | 1 failed, 11 passed (48 assertions) — same test | deterministic baseline failure |
| `WebsiteIndexingTest::test_robots_txt_is_untouched_by_this_feature_branch` | failed | 1 failed, 4 passed (15 assertions) — same test | deterministic baseline failure |
| `WebsiteDraftPageServiceSeamTest` store/update "persists every draft field" (2 tests) | failed | 2 failed, 8 passed (56 assertions) — same two tests | deterministic baseline failure |
| `WebsiteDraftPublishTest::test_rollback_repoints_the_website_without_mutating_any_revision…` | failed | 1 failed, 6 passed (35 assertions) — same test | deterministic baseline failure |
| Multi-process concurrency tests (`AutoRechargeFailedPaymentRetryTest` race, `UsageWalletManagerConcurrencyTest`, `UsageWalletManagerSetActiveRateConcurrencyTest`, new `AutoRechargeRollingWindowConcurrencyTest`) | all green in this round's `tests/Feature/Usage` and full-suite runs | (the original round proved the first three timing-sensitive on the baseline itself) | not a branch regression; the new rolling-window race is deterministic by construction (the frequency limit closes the window the older race test leaves open) |

No test required the canonical database name beyond the runner's standard `DB_DATABASE=ultimatesms_testing`; the shared-schema hazard (baseline runs leave the origin/main schema) was handled by re-migrating from this worktree before any non-`RefreshDatabase` file ran.

### 14.4 Static checks

- `git diff --check` on every changed path: exit 0.
- Secret-shaped-string sweep on every added line (Stripe `sk_`/`pk_`/`whsec_`, AWS keys, private-key blocks, `key/secret/password/token = "…"`): no match.
- Changed-path allowlist audit: every path inside §22.1 row 5 as amended (§12).

### 14.5 Fixes made during verification

- The provider-flow tests that call `initiateAutoRecharge()` directly (7 files, 14 call sites) and the tests that enabled automatic top-up without a ceiling (6 files) now carry the deliberately chosen ceiling the policy requires; `AutoRechargeCeilingsTest` gives every Business its own ceiling before the Workspace ceiling is exercised.
- The account-frame form first used `<x-button>` inside the region `tests/Feature/DesignSystem/**` pins as free of Design System markers with exact counts, and closed with `@endisset`, which truncated that test's region extraction; it now uses the region's plain `<button class="btn btn-sm btn-outline-secondary">` and `@if (isset(...)) … @endif`. No DesignSystem test was edited.
- The rolling-window tests fake notifications for the whole class (every success and refusal mails the opted-in contact) and space the two seed attempts an hour apart so the exact-24-hour boundary opens exactly one slot.
