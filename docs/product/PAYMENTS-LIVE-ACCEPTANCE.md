# Payments — Live Acceptance Checklist

**Purpose:** the manual, operator-executed acceptance procedure for the four
money lanes, run in Stripe **test mode** against a real deployment.

**This document is the only place a payment lane may be called "live
tested".** A green automated suite proves the code does what we designed; it
does not prove that a real Stripe account, a real webhook endpoint, a real
card and a real browser agree with us. `FakeStripe` tests passing is **never**
evidence for anything in this file.

**Status**

| Lane | Section | Ready to execute |
|---|---|---|
| A — Platform SaaS subscription | §1 below | after the Lane-A branch merges |
| C — Agency SaaS revenue | to be appended | not built yet |
| B — Business revenue | to be appended | built (Contract 17), not yet live-tested |
| D — Usage funding | to be appended | built (RFC-005), not yet live-tested |

Sections for C, B and D are appended to **this same document** as those phases
complete. Do not start a second checklist.

---

## 0. Rules for whoever executes this

1. **Stripe TEST MODE only.** Every key is `sk_test_` / `pk_test_`. If any key
   in the environment begins `sk_live_`, stop.
2. **No real card.** Use Stripe's documented test cards only.
3. **Never paste a secret into a browser field, a ticket, a chat message or
   this document.** Secrets live in the deployment's environment
   configuration and nowhere else.
4. Record the **actual observed** result against each step. "Presumed fine" is
   a failure.
5. A step that cannot be executed is **blocked**, not passed. Say which.

---

## 1. Lane A — Platform SaaS subscriptions

The Workspace owner pays the **Platform Owner**. Money lands in the Platform
Owner's own Stripe account as a direct charge. No connected account is
involved.

### 1.1 Stripe test-mode setup

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.1.1 | In the Stripe Dashboard (test mode), create a **Product** for each tier you intend to sell: Core, Growth, Agency. | Three products exist. | |
| 1.1.2 | For each product create a **recurring Price** with the amount, currency and interval you intend to charge. Record each `price_...` id. | Three price ids. | |
| 1.1.3 | Confirm the deployment's environment has `STRIPE_SECRET` (`sk_test_…`) and `STRIPE_KEY` (`pk_test_…`) set. | Set, and never displayed anywhere in the app. | |

### 1.2 Webhook endpoint

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.2.1 | In Stripe → Developers → Webhooks, add an endpoint pointing at `https://<your-host>/stripe/webhook/platform-subscriptions`. | Endpoint created. | |
| 1.2.2 | Subscribe it to exactly these events: `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed`. | Six events, no more. | |
| 1.2.3 | Copy the endpoint's signing secret into `STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET` in the deployment environment. **Do not** put it in the database. | Set. | |
| 1.2.4 | Confirm this secret is **different** from `STRIPE_CONNECT_WEBHOOK_SECRET` (lane B) and `STRIPE_WEBHOOK_SECRET` (lane D). | Three distinct secrets. | |
| 1.2.5 | Send a test `customer.subscription.updated` from the Dashboard. | HTTP 200. A `platform_subscription_events` row exists. Its `state` is `failed` with `last_error = no_matching_local_record` — correct, because no local subscription matches a Dashboard-invented id. **Fail-closed is the pass condition here.** | |
| 1.2.6 | Tamper with the signature (resend with a wrong secret configured, or curl the endpoint with a bogus `Stripe-Signature`). | HTTP 400, and **no new row** in `platform_subscription_events`. | |

### 1.3 Platform Owner commercial configuration

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.3.1 | Sign in as the Platform Owner and open the plan/commercial configuration surface. | Core / Growth / Agency listed. | |
| 1.3.2 | Set price, currency and billing cycle per tier, matching §1.1.2, and paste each tier's `price_...` id. | Saved; a `workspace_plan_catalog_pricing_changes` row records each price change with the reason you gave. | |
| 1.3.3 | Enable a trial on one tier and set its duration; leave another tier with no trial. | Saved. | |
| 1.3.4 | Mark all three tiers available for signup. | Saved. | |
| 1.3.5 | Check the Stripe status panel. | Shows configured / test mode / webhook configured. **Shows no key, no key prefix and no key length.** | |
| 1.3.6 | View page source of that panel. | No secret anywhere in the HTML. | |

### 1.4 Brand-new signup, with trial

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.4.1 | In a clean browser profile, sign up as a brand-new customer: name/email/password → business name → niche → basic info. | Account created. | |
| 1.4.2 | Confirm signup does **not** ask for A2P registration, a Google connection, calendar integration or a Business Stripe Connect account. | None requested. | |
| 1.4.3 | Select the tier that has a trial. | Payment step offers **Stripe only** — no Braintree / Cash / NowPayments / Authorize.Net / EasyPay / FedaPay / Vodacom. | |
| 1.4.4 | Complete checkout with test card `4242 4242 4242 4242`, any future expiry, any CVC. | Redirected back into the product. | |
| 1.4.5 | **Stripe Dashboard:** the Customer exists, the Subscription exists with status `trialing`, and its trial end matches the configured duration. | Matches. | |
| 1.4.6 | **Database:** `platform_subscriptions` has one row for this Workspace with `status = trialing`, a `provider_customer_id`, a `provider_subscription_id`, and `trial_days_snapshot` equal to what was configured at signup time. | Matches. | |
| 1.4.7 | **Database:** `workspace_plan_assignments` has exactly one row for this Workspace, `is_complimentary = 0`, on the tier that was selected. | Matches. | |
| 1.4.8 | **Database:** zero new rows in `subscriptions`, `subscription_transactions`, `invoices`, `payment_methods`. | Zero. | |
| 1.4.9 | **Database:** exactly one Business and exactly one Primary Location for the new Workspace. | Matches. | |
| 1.4.10 | In the product: the account is usable, and the Plan & Subscription page shows the tier, trial status and trial end. | Matches. | |
| 1.4.11 | Change the tier's configured trial duration in the owner surface. | The existing subscriber's trial end is **unchanged**. | |

### 1.5 Brand-new signup, no trial

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.5.1 | Repeat §1.4.1–1.4.4 with the no-trial tier. | Charged immediately. | |
| 1.5.2 | **Stripe Dashboard:** subscription status `active`, and a paid invoice for the configured amount and currency. | Matches. | |
| 1.5.3 | **Database:** `platform_subscriptions.status = active`, `current_period_start` / `current_period_end` populated. | Matches. | |
| 1.5.4 | Abandon a checkout (start it, close the tab). | Workspace exists, **no** plan assignment, `platform_subscriptions.status = pending`. No paid access. | |

### 1.6 Renewal

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.6.1 | Create a Stripe **test clock**, attach a new test customer to it, and run §1.5 against that customer. | Subscription created on the clock. | |
| 1.6.2 | Advance the clock past the period end. | Stripe issues and pays the renewal invoice. | |
| 1.6.3 | Confirm `invoice.paid` was delivered and processed. | `platform_subscription_events` row `processed`. `current_period_end` has moved forward. Account still usable. | |

### 1.7 Payment failure → Grace → Locked → recovery

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.7.1 | Put the customer's default payment method onto a failing test card (e.g. `4000 0000 0000 0341`, which attaches but fails on charge). | Set. | |
| 1.7.2 | Advance the test clock to the next renewal. | Renewal fails. `invoice.payment_failed` delivered. | |
| 1.7.3 | **Database:** `workspace_plan_assignments.grace_started_at` is set **once**; `locked_at` is null. | Matches. | |
| 1.7.4 | In the product: the account **still works**, and shows a billing warning. | Matches (Blueprint §27). | |
| 1.7.5 | Let Stripe retry (or advance the clock again) so a second failure arrives. | `grace_started_at` is **unchanged** — the window does not slide. | |
| 1.7.6 | Advance past the 3-day grace window and let `workspaces:advance-account-lifecycle` run. | `locked_at` set. The product shows the locked screen; data is intact. | |
| 1.7.7 | Update to a working card and pay the outstanding invoice from the Stripe Dashboard. | `invoice.paid` delivered; `grace_started_at` and `locked_at` both cleared; access restored immediately. | |

### 1.8 Cancellation

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.8.1 | Cancel from the customer's Plan & Subscription page. | Stripe shows `cancel_at_period_end = true`. Local row agrees. | |
| 1.8.2 | Confirm access. | **Still usable** — the paid period is never taken away. | |
| 1.8.3 | Resume/undo the cancellation. | `cancel_at_period_end` back to false, both sides. | |
| 1.8.4 | Cancel again and advance the clock past the period end. | `customer.subscription.deleted` delivered; the Workspace is locked; data intact. | |
| 1.8.5 | Replay that same event from the Dashboard. | 200, no double transition, `locked_at` unchanged. | |

### 1.9 Upgrade and downgrade

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.9.1 | From Core, upgrade to Growth. | Takes effect **immediately**: Stripe bills the difference, and the Workspace's entitlements widen right away. | |
| 1.9.2 | From Growth, downgrade to Core. | **Nothing changes today.** The customer keeps Growth; the change is scheduled for the period end. | |
| 1.9.3 | Advance the clock past the period end and let `platform-subscriptions:apply-due-plan-changes` run. | The tier becomes Core, the Stripe price changes with no proration, and **no customer data is deleted** — components are merely no longer active. | |
| 1.9.4 | Change a catalog price in the owner surface. | Existing subscribers' amounts are unchanged; a **new** signup is charged the new amount. | |

### 1.10 Complimentary account

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.10.1 | As Platform Owner, grant a Workspace complimentary status. | `is_complimentary = 1`, with a reason and a grantor recorded. | |
| 1.10.2 | Confirm no Stripe subscription was created for it. | None in the Dashboard. | |
| 1.10.3 | Confirm it reads as complimentary, not as a paid active subscription, everywhere it is shown. | Matches. | |

### 1.11 Lane isolation

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.11.1 | After all of the above, check `business_documents`, `business_document_payments`, `business_document_refunds`, `business_stripe_connections`, `business_payment_events`. | Untouched by anything in this section. | |
| 1.11.2 | Check `business_usage_wallets`, `business_usage_ledger_entries`, `payment_provider_customers`, `payment_provider_events`. | Untouched. | |
| 1.11.3 | Check the owner's revenue view. | Lane-A subscription revenue only. Lane B/C/D money is **not** presented as platform SaaS revenue. | |

### 1.12 Sign-off

| Field | Value |
|---|---|
| Executed by | |
| Date | |
| Deployment / commit | |
| Stripe account (test) | |
| Result | pass / fail / blocked |
| Blocked or failed steps | |

---

## 2. Lane C — Agency SaaS revenue

_To be appended when lane C is built._

## 3. Lane B — Business revenue

_To be appended. Contract 17 is implemented but has not been live-tested._

## 4. Lane D — Usage funding

_To be appended. RFC-005 is implemented but has not been live-tested._

## 5. Four-lane conformance

_To be appended after lanes A and C merge: one pass proving all four lanes
coexist on one Stripe account without sharing a commercial identity._
