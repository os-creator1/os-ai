# Staging Acceptance Tracker

Evidence-based status of every issue raised in the "Remaining Staging Acceptance Issues"
assignment, plus PRs #443–#446. **A passing local test is not "fixed on staging".**
Nothing below is marked *Verified on staging* because no one has exercised these changes
on `staging.getmotiongrove.com` yet.

Status vocabulary: Not started · In progress · Implemented, untested · Tests passed ·
Awaiting merge · Merged into staging · Verified on staging · Blocked by owner configuration ·
Deferred by product decision.

Last updated against `origin/staging` @ `347b4b9a`.

## A. Already merged into staging (needs staging verification)

| Item | PR | Status | Remaining action |
| --- | --- | --- | --- |
| Resend HTTPS transport for transactional email | #443 | Merged into staging | Owner: Resend sender authentication + `RESEND_API_KEY` (see §D) then send one real test email |
| Standardized transactional email design, per-business branding | #444 | Merged into staging | Visually confirm one email per family on staging |
| Platform-user date crash, invitation + Stripe OAuth diagnostics | #445 | Merged into staging | Re-open the previously crashing Platform Owner page; trigger invitation and Stripe Connect error paths |
| Business-branded signed confirmation; blueprint packages kept out of live catalogs | #446 | Merged into staging | Sign a document on staging; confirm no auto-created packages in live catalog |
| 28-day billing + Private Offers (flag OFF) | #440 | Merged into staging | Needs staging migrate + 28-day Prices (see §D) |

## B. This assignment — implemented on isolated branches (no PR opened, not merged)

| # | Area | Branch | SHA | Status | Tests run (local, disposable DB) | Remaining action |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Agency Outreach V2: dynamic ordered sequences, per-step timing/advance/pause, preview, link not tied to message 3, legacy 3-message flow as optional template, versioned edits, custom FAQ with niche/campaign scope + configurable unanswered behavior | `feature/agency-outreach-v2` | `562aa51a` | Tests passed (locally) · Awaiting merge | AgencyOutreach + AgencyProspecting: 364 tests OK; new `OutreachSequenceV2Test` (15) | Open PR, review, merge; run additive migration `2026_11_12_100001` on staging; verify with a sandbox campaign. Branch is 8 commits behind staging; merges cleanly (dry run), not re-run on the new base |
| 2 | Onboarding V2: Agency not forced through Business setup, goals no longer capped at 2 (+ optional priority), services optional/skippable, "set up later", Finish redirect/idempotency | `feature/onboarding-v2` | `66c40a54` | Tests passed (locally; only pre-existing baseline failures remain) · Awaiting merge | Onboarding + V1 signup suites; baseline failures proven pre-existing on clean staging | Open PR, review, merge; verify first-time Business and Agency sign-ups on staging. Merges cleanly (dry run) |
| 3 | Calendar Notifications V2: unlimited reminders, custom timing/units/reorder/disable, per-reminder channel, editable confirmation/reminder wording with variables + preview | `feature/calendar-notifications-v2` | `c15e524b` | Tests passed (locally) · Awaiting merge | Calendar folder: 489 tests OK after merging staging (conflict with #444 resolved: custom wording uses `BrandedMailMessage`); new `BookingNotificationsV2Test` (12) | Open PR, review, merge; run migration `2026_11_12_110001`; send one reminder on staging |
| 4 | Client invitation return-to-link (safe destination, no token leakage, no loops) | `feature/invitation-return-to-link` | `c1bfac61` | Tests passed (locally) · Awaiting merge | `AgencyClientProvisioningTest` 30 OK (3 new) | Merge after #445 (already in staging; dry-run merge clean); fresh invitation acceptance on staging. New-user registration still requires a plan (V1 signup) — return-to-link covers sign-in and verification only |
| 5 | Outbound email safety | `feature/outbound-email-identity` | `97434f94` | Tests passed (locally) · Awaiting merge | `BusinessEmailSenderTest` 55 OK | **No code change**: policy was already enforced (Business sales email only via the Business's own authenticated mailbox, no platform fallback, no caller-supplied From). Test-only pin. Transactional mail keeps platform identity |
| 6 | Private Offers / billing re-inspection | — | — | No code change | — | See §C |

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

No PRs opened, nothing merged into `staging`/`main`, no deployments, no real AI/Stripe/email/SMS
calls, no production or staging database/credential access, no automation locks or gates edited.
