# Staging Acceptance Tracker

Evidence-based status of every issue raised in the "Remaining Staging Acceptance Issues"
assignment, plus PRs #443–#446. **A passing local test is not "fixed on staging".**
Nothing below is marked *Verified on staging* because no one has exercised these changes
on `staging.getmotiongrove.com` yet.

Status vocabulary: Not started · In progress · Implemented, untested · Tests passed ·
Awaiting merge · Merged into staging · Verified on staging · Blocked by owner configuration ·
Deferred by product decision.

Updated against `staging` @ `08a3b276` (merged PRs #443–#452 and #454). Staging/Forge deployment and browser acceptance are not yet verified.

**Legend (every cell below uses exactly one of these for each stage):**
*Code implemented* · *Tests passed* (disposable MySQL 8 in GitHub Actions; run linked) · *Awaiting PR* ·
*PR open* · *Merged into staging* · *Deployed* · *Verified in the browser* · *Blocked by owner configuration*.
Nothing is marked *Deployed* or *Verified in the browser*: no one has exercised these on
`staging.getmotiongrove.com`, and no evidence exists for it.

## A. Merged into staging (not deployed or browser-verified unless stated)

| Item | PR | Code | Tests | Merged | Deployed | Browser-verified | Remaining action |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Resend HTTPS transport | #443 | yes | passed | yes | not verified | no | Owner: Resend sender auth + `RESEND_API_KEY` (§D), then one real test email |
| Standardized transactional email design | #444 | yes | passed | yes | not verified | no | Visually confirm one email per family |
| Platform-user date crash, invitation + Stripe OAuth diagnostics | #445 | yes | passed | yes | not verified | no | Re-open the crashing Platform Owner page; trigger error paths |
| Business-branded signed confirmation; no auto-created catalog packages | #446 | yes | passed | yes | not verified | no | Sign a document on staging |
| 28-day billing + Private Offers (flag OFF) | #440 | yes | passed | yes | not verified | no | Staging migrate + 28-day Prices (§D) |
| Growth email verification for signed-out customers (login / 2FA / session-loss continuation, works with registration closed) | #447 | yes | passed | yes | not verified | no | Click a real verification link while logged out on staging |

## B. Final integration wave (now merged into staging; deployment/browser acceptance still unverified)

Test runs are throw-away workflows on `verify/*` branches (not part of any feature branch).

| Lane | Branch | Final SHA | PR | Tests (disposable MySQL 8, CI) | Result |
| --- | --- | --- | --- | --- | --- |
| Agency Outreach V2 | `feature/agency-outreach-v2` | `bd031721` | **#448 merged** | [run 38260388607](https://github.com/os-creator1/os-ai/actions/runs/38260388607): `tests/Feature/AgencyOutreach` 187 tests, 1097 assertions, 0 failures | Code implemented · Tests passed · Merged into staging · deployment/browser acceptance unverified |
| Onboarding V2 | `feature/onboarding-v2` | `74cc234f` (includes staging and #454) | **#449 merged** | [final CI 38281167265](https://github.com/os-creator1/os-ai/actions/runs/38281167265): **247 tests, 1,757 assertions, 0 failures**, 1 nonfatal PHPUnit deprecation, including validated completion fixture and event checks | Code implemented · Tests passed · Merged into staging · deployment/browser acceptance unverified |
| Invitation return-to-link | `feature/invitation-return-to-link` | `68510fb8` | **#450 merged** | [run 38271166962](https://github.com/os-creator1/os-ai/actions/runs/38271166962): AgencyClientProvisioningTest, ClientInvitationManagerTest, AgencyClientsHttpTest + all `tests/Feature/Auth`: **172 tests, 917 assertions, 0 failures** | Code implemented · Tests passed · Merged into staging · deployment/browser acceptance unverified |
| Calendar Notifications V2 | `feature/calendar-notifications-v2` | `9d9650b1` | **#451 merged** | [run 38264074032](https://github.com/os-creator1/os-ai/actions/runs/38264074032): `tests/Feature/Calendar` + `tests/Feature/Mail` 494 tests, 2502 assertions, 0 failures | Code implemented · Tests passed · Merged into staging · deployment/browser acceptance unverified |
| Outbound email identity (tests only) | `feature/outbound-email-identity` | `46cac88f` | **#452 merged** | [run 38264108143](https://github.com/os-creator1/os-ai/actions/runs/38264108143): `tests/Feature/BusinessEmail` 179 tests, 723 assertions, 0 failures (includes `BusinessEmailSenderTest`) | Tests passed · Merged into staging · deployment/browser acceptance unverified. No production code changed |

### Corrections made in this wave
- **Invitation return-to-link** (merge conflict with #447 in `LoginController`): both fixes kept. After login the order is
  pending email verification, then pending client invitation, then private offer, then home. The invitation
  memory was also added to the 2FA success redirect and the already-verified verification redirect, which
  would otherwise have dropped it. New regression
  `test_a_verification_link_clicked_after_opening_the_invitation_still_returns_to_the_invitation`.
  The invitation claim/accept checks, new-user registration and plan requirements are unchanged.
- No code correction was needed for Onboarding V2, Calendar V2 or Outbound email identity.

### Known failing tests (reproduced on unchanged staging `e82ce4fd`, not caused by any lane)
| Test | Symptom |
| --- | --- |
| `V1SignupHttpTest::test_replaying_the_payment_submit_never_duplicates_the_account_or_tenant` | Expected redirect to `/signup/plan`, got `/dashboard` (signup payment replay) |
| `V1SignupFinalAcceptanceTest::test_password_reset_works_and_the_new_password_logs_in_to_the_same_account` | Login assertion fails (login not completing in CI) |
| `V1SignupFinalAcceptanceTest::test_a_suspended_user_cannot_sign_in` | `assertGuest` fails: user is authenticated |
| `BusinessOnboardingHttpTest::test_capacity_denial_business_slot_allocation_required_redirects_with_the_safe_message` | Fixture creates 3 Businesses in one Workspace; unique `businesses.workspace_id` rejects it |

**Baseline corrections merged and reverified:** [PR #454](https://github.com/os-creator1/os-ai/pull/454) was merged into `staging` before the final Onboarding V2 integration. Its four-file test-only correction passed [CI #38272848862](https://github.com/os-creator1/os-ai/actions/runs/38272848862) (175 tests, 1,401 assertions, 0 failures). Onboarding V2 includes that staging baseline and passed [final CI #38281167265](https://github.com/os-creator1/os-ai/actions/runs/38281167265) (247 tests, 1,757 assertions, 0 failures). The earlier four failures are historical and no longer block PR #449; code is merged into staging; browser acceptance remains outstanding.
Also: a full `tests/Feature/Business` directory run in CI hits DB-creation privilege errors (the CI user cannot
`CREATE DATABASE` for the historical-schema tests); run those narrowly.

### Migrations (additive; staging database application not verified)
`2026_11_12_100001_add_dynamic_sequences_to_agency_outreach` (Outreach, nullable/defaulted columns) and
`2026_11_12_110001_add_configurable_notifications_to_booking_types` (Calendar, nullable columns) both sort after
every migration currently on staging and touch different tables. Onboarding V2, Invitation and Outbound email add none.

### Follow-up discovered during review
- **Onboarding test quality resolved:** PR #449 @ `74cc234f` removes the broad exception catch/unconditional true assertion; the fixture now asserts zero active services and zero primary services (empty synchronization deactivates rows) and the test checks Completed status, persistence and exactly one completion event. [CI #38281167265](https://github.com/os-creator1/os-ai/actions/runs/38281167265): **247 tests, 1,757 assertions, zero failures**.
- **Invitation 2FA backup-code path resolved:** `TwoFactorController::updateBackUpCode` now respects pending signed verification, invitation, Private Offer and home redirect precedence; ordinary 2FA also retains Private Offer return. [CI #38271166962](https://github.com/os-creator1/os-ai/actions/runs/38271166962) passed 172 tests and 917 assertions. **PR #450 is merged into staging**; deployment remains unverified.
- **Baseline suspended-account test corrected in PR #454:** CI now verifies that a genuinely signed-out suspended account cannot sign in and an existing authenticated session is terminated after suspension. No production auth code was changed. **#454 is merged, #449 was rerun and passed** ([CI #38279727586](https://github.com/os-creator1/os-ai/actions/runs/38279727586)); PR #449 is merged into staging. The old CI was not green.
- **PR tracking:** #448 Outreach, #449 Onboarding, #450 Invitation, #451 Calendar, #452 Outbound email and #454 test corrections are all merged into staging. The five feature PRs passed pre-merge focused CI; integration smoke tests and staging browser acceptance remain.

## C. Billing / Private Offers findings (report only)

- No new concrete, reproducible defect was confirmed in this pass, so no billing code was
  changed. Private Offers stay OFF (`platform_billing.private_offers_enabled`).
- Two P2 items from PR #440 Codex review rounds remain *Deferred* (not reproduced as a
  failing regression): catch-up price snapshot via `observe()`, and trial reservation not
  keyed on provider customer id. Both are reachable only with Private Offers enabled.
- **"28-day free trial" marketing vs. signup configuration:** signup does **not** offer a
  public trial. `platform_billing.allow_public_trials` defaults to `false`
  (`PLATFORM_ALLOW_PUBLIC_TRIALS`); the plan presenter returns `trial_days = null` while
  it is false and the three signup plan views render the badge only when `trial_days`
  is non-null. So any "28-day free trial" wording on a marketing page is **not** backed
  by signup. Either remove that wording or take a product decision to enable public
  trials — status: **Deferred by product decision**. Billing cycle itself is 28 days
  (a 28-day *billing period*, not a trial); marketing copy must not conflate them.

## D. Operational readiness — owner steps (not verified; no secrets were read)

| Item | Owner step | Expected confirmation | Status |
| --- | --- | --- | --- |
| Stripe Agency OAuth | Create/locate the Stripe Connect platform OAuth client in Stripe Dashboard → Connect → settings; set `STRIPE_AGENCY_CONNECT_CLIENT_ID` (`ca_…`) in Forge env | `/agency/…` "Connect existing Stripe account" starts OAuth instead of showing the not-configured message | Blocked by owner configuration |
| Connect callback | Add redirect URI `https://staging.getmotiongrove.com/agency/stripe/connect-existing/callback` to that OAuth client (exact match, https) | Stripe returns to the callback with no `redirect_uri` mismatch error | Blocked by owner configuration |
| Resend sender auth | In Resend add the sending domain, publish SPF/DKIM DNS records, wait for "Verified"; set `RESEND_API_KEY`, `MAIL_MAILER=resend`, `MAIL_FROM_ADDRESS` on that domain | Test email lands in inbox with DKIM pass and the platform From | Blocked by owner configuration |
| 28-day Stripe Prices | In Stripe (test mode first) create recurring Prices with a 28-day interval (`interval=day`, `interval_count=28`) for Core, Growth, Agency; record the `price_…` ids in the platform plan catalog (Platform Owner → Plans) | Plan catalog shows each tier mapped; a test-mode checkout creates a subscription with a 28-day period | Blocked by owner configuration |
| Staging deploy/migrations | Per `docs/product/FORGE-STAGING.md`: deploy the target SHA, `migrate --force` (additive only), confirm `php artisan migrate:status` shows all Ran | Deployed SHA equals the intended `staging` head; no pending migrations | Not verified |
| Fresh client invitation acceptance | After #445 (+ branch 4) is deployed: Agency invites a new client email; open the emailed link in a private window; sign in/register | Link lands on the claim page, then returns to it after sign-in and completes without a loop | Not verified on staging |

## E. Rules observed

Claude implementation lanes did not open PRs, merge or deploy. ChatGPT opened PRs #448–#452 and #454 and subsequently merged #448–#452 into staging at the owner's instruction. No direct deployment, staging or production DB/credential access or external service calls were performed. Forge auto-deploy behavior has not been verified.
