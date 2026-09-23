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
| A — Platform SaaS subscription | §1 below — **every step is browser-operable today** | after the Lane-A branch merges |
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

All of this is done in the browser at **`/<admin path>/platform-billing`**. No
database edit is required at any point.

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.3.1 | Sign in as the Platform Owner and open **Platform Billing**. | Core / Growth / Agency each shown with an "On sale / Not on sale" badge and, where not on sale, the exact reasons. | |
| 1.3.2 | For each tier set price, currency and billing cycle to match §1.1.2, paste that tier's `price_...` id, and enter a reason. Save. | Saved; a `workspace_plan_catalog_pricing_changes` row records each price change with your reason. | |
| 1.3.3 | Try pasting a Product id (`prod_…`) or a secret into the Stripe Price field. | Rejected with a validation error; nothing is stored. | |
| 1.3.3a | Paste a **syntactically valid but nonexistent** `price_…` id. | Rejected: "could not be found on this platform's own Stripe account." The catalog and the pricing history are **unchanged**. | |
| 1.3.3b | Create a Price in Stripe whose amount, currency or interval does NOT match what you are entering, and paste it. | Rejected, naming the exact disagreement (amount / currency / interval). Nothing is written. | |
| 1.3.3c | Create a Price with `interval_count` 3, or an inactive Price, or a one-time Price, and paste each. | Each rejected. Nothing is written. | |
| 1.3.3d | If you have a connected account (a Business's or an Agency's), create a Price there and paste its id. | Rejected as not retrievable — a connected-account Price is not visible to the platform key, which is the lane boundary doing its job. | |
| 1.3.4 | Enable a trial on one tier with a duration; leave another tier with no trial. Try enabling a trial with the length blank. | The blank length is rejected. | |
| 1.3.5 | Tick "Available to new signups" for the tiers you intend to sell. | Each of those tiers now reads **On sale**. | |
| 1.3.6 | Check the Stripe panel. | Shows API key Configured/Missing, mode, webhook secret Configured/Missing, the endpoint URL, and the exact event list to subscribe. **No key, no key prefix, no key length.** | |
| 1.3.6a | Temporarily blank `STRIPE_SECRET` and reload the panel. | Mode reads **Not configured** — never "test". Restore the key afterwards. | |
| 1.3.7 | View page source of the whole page. | No secret anywhere in the HTML, and no input field that could store one. | |

### 1.4 Brand-new signup, with trial

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.4.1 | In a clean browser profile, open **`/register`**. | The V1 signup page. It lists ONLY the tiers marked on sale in §1.3, each with its price, cycle and configured trial. | |
| 1.4.2 | Confirm signup does **not** ask for A2P registration, a Google connection, calendar integration, a Business Stripe Connect account or Telnyx configuration. | None requested. | |
| 1.4.3 | Confirm there is no payment-method dropdown. | **Stripe only** — no Braintree / Cash / NowPayments / Authorize.Net / EasyPay / FedaPay / Vodacom. | |
| 1.4.4 | Fill in name / email / password, business name, niche, country and time zone, select the tier that has a trial, and submit. | Redirected to Stripe's hosted Checkout. | |
| 1.4.5 | Complete checkout with test card `4242 4242 4242 4242`, any future expiry, any CVC. | Redirected back into the product, landing on Home. | |
| 1.4.6 | **Stripe Dashboard:** the Customer exists, the Subscription exists with status `trialing`, and its trial end matches the configured duration. | Matches. | |
| 1.4.7 | **Database:** `platform_subscriptions` has one row for this Workspace with `status = trialing`, a `provider_customer_id`, a `provider_subscription_id`, and `trial_days_snapshot` equal to what was configured at signup time. | Matches. | |
| 1.4.8 | **Database:** `workspace_plan_assignments` has exactly one row for this Workspace, `is_complimentary = 0`, on the tier that was selected. | Matches. | |
| 1.4.9 | **Database:** zero new rows in `subscriptions`, `subscription_transactions`, `invoices`, `payment_methods`. | Zero. | |
| 1.4.10 | **Database:** exactly one Business and exactly one Primary Location for the new Workspace, and the Business's `industry` is the niche you chose. | Matches. | |
| 1.4.11 | In the product: the account is usable; **Settings → Plan & subscription** shows the tier, Trial status, the price you configured, and the trial end. | Matches. | |
| 1.4.12 | **Owner surface:** the new account appears under Subscriptions, and the trialing count has gone up by one. | Matches. | |
| 1.4.13 | Change that tier's configured trial duration in the owner surface. | The existing subscriber's trial end is **unchanged**. A *new* signup on that tier gets the new duration. | |
| 1.4.14 | **Webhook-only completion.** Repeat 1.4.1–1.4.5 with a second customer, but **close the tab on Stripe's success page before it redirects back**. | The account still completes: the Workspace receives its non-complimentary assignment on the tier chosen, and the customer can sign in to a usable account. This is the step that proves the webhook alone is sufficient. | |

### 1.5 Brand-new signup, no trial

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.5.1 | Repeat §1.4.1–1.4.4 with the no-trial tier. | Charged immediately. | |
| 1.5.2 | **Stripe Dashboard:** subscription status `active`, and a paid invoice for the configured amount and currency. | Matches. | |
| 1.5.3 | **Database:** `platform_subscriptions.status = active`, `current_period_start` / `current_period_end` populated. | Matches. | |
| 1.5.4 | Start a checkout and click Stripe's **back/cancel** link. | You land on the resumable "Choose a plan" screen. The Workspace exists, there is **no** plan assignment, and `platform_subscriptions.status = pending`. No paid access, and the account is not deleted. | |
| 1.5.5 | From that screen pick a plan and complete checkout. | It finishes normally, and there is still exactly **one** Workspace, one Business and one Primary Location — retrying created no duplicates. | |
| 1.5.6 | **Resume must not rewrite identity.** Sign up with a NON-US country and a distinctive niche and time zone, note the Business and Primary Location rows, cancel checkout, then resume — first with the same tier, then with a different one. | Every Business and Location field is unchanged: country, time zone and niche are still the ones chosen at signup. (Before this correction, resuming rewrote them to US / config default / Other.) | |
| 1.5.7 | **Only one payable checkout.** Start checkout on one tier, leave the Stripe tab open, go back and resume on a DIFFERENT tier, then return to the first tab and try to pay. | The first session shows as expired and cannot be completed. Stripe's Dashboard shows one open session for this customer, carrying the NEW tier's Price. | |
| 1.5.8 | Complete a checkout, then go back and try to resume. | Refused — "that checkout has already been paid". No second Checkout Session and no second subscription is created. | |
| 1.5.9 | While signed in, open `/register` directly and try to submit it with a different email. | You are redirected to the plan screen or Home; no second user account is created and you are not switched out of your own account. | |
| 1.5.10 | **Two tabs at once.** Open the resumable plan screen in two browser tabs and submit a DIFFERENT tier in each, as close together as you can manage. | The Stripe Dashboard shows exactly **one** open Checkout Session for this customer when both requests have finished. Every other session for them is `expired`, and `platform_subscriptions.provider_checkout_session_id` is the one still open. | |

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
| 1.7.6 | As the customer, open **Plan & subscription**. | It shows "We could not take your latest payment", the Grace deadline, and an **Update your payment method** link. It does not link to legacy Ultimate SMS billing, and there is no card field anywhere on the page. | |
| 1.7.7 | Click **Update your payment method**. | Redirected to Stripe's hosted Billing Portal, straight into the add-a-payment-method flow, and returned to Plan & subscription afterwards. **You never type a card into our application.** | |
| 1.7.8 | Add a working card there (`4242 4242 4242 4242`) and let Stripe retry, or pay the open invoice from the Dashboard. | `invoice.paid` delivered; `grace_started_at` and `locked_at` both cleared; access restored immediately. | |
| 1.7.9 | Instead of recovering, advance past the 3-day grace window and let `workspaces:advance-account-lifecycle` run. | `locked_at` set. The product shows the locked screen; data is intact. | |
| 1.7.10 | From the locked account, recover via the payment-method route and pay. | Access is restored immediately. | |
| 1.7.11 | **Owner surface:** check Needs attention during 1.7.3–1.7.9. | The account is listed with its Grace start, and the past-due / grace / locked counts reflect reality. | |

### 1.8 Cancellation

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.8.1 | On **Plan & subscription**, tick the cancellation confirmation and cancel. | Stripe shows `cancel_at_period_end = true`; the local row agrees; the page now shows "Ending at period end" and the exact **Access ends** date. | |
| 1.8.2 | Confirm access. | **Still usable** — the paid period is never taken away. | |
| 1.8.3 | Submit the cancellation a second time. | Harmless; nothing changes. | |
| 1.8.4 | Click **Keep my subscription**. | `cancel_at_period_end` back to false on both sides, and the cancel form returns. | |
| 1.8.4 | Cancel again and advance the clock past the period end. | `customer.subscription.deleted` delivered; the Workspace is locked; data intact. | |
| 1.8.5 | Replay that same event from the Dashboard. | 200, no double transition, `locked_at` unchanged. | |

### 1.9 Upgrade and downgrade

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.9.1 | On **Plan & subscription**, read the change-plan options. | Each option states what it will do before you confirm: upgrade "takes effect immediately, and you are billed the difference now"; downgrade "takes effect at the end of your current billing period. Nothing is deleted." | |
| 1.9.2 | From Core, confirm an upgrade to Growth. | Takes effect **immediately**: Stripe bills the difference, and the Workspace's entitlements widen right away. | |
| 1.9.3 | From Growth, confirm a downgrade to Core. | **Nothing changes today.** The customer keeps Growth, and the page shows the scheduled change and its effective date. | |
| 1.9.4 | Advance the clock past the period end and let `platform-subscriptions:apply-due-plan-changes` run. | The tier becomes Core, the Stripe price changes with no proration, and **no customer data is deleted** — components are merely no longer active. | |
| 1.9.5 | Change a catalog price in the owner surface. | Existing subscribers' amounts are unchanged, and **Plan & subscription still shows what they actually pay**; a **new** signup is charged the new amount. | |
| 1.9.6 | Sign in as a **Staff** member of a subscribed Workspace and try to reach the change-plan, cancel, resume, payment-method and re-subscribe routes. | All 404. No financial action is possible. | |
| 1.9.7 | Schedule a downgrade to Core, then **reprice Core onto a new Stripe Price** in the owner surface, then let the boundary arrive. | The customer is charged the amount they agreed to when they scheduled the change, on the Price they agreed to — **not** the new one. | |
| 1.9.8 | During an upgrade, use the Stripe Dashboard to confirm the Price changed, then interrupt the app before it finishes (stop the worker / close the request). | The entitlement has **not** widened yet, and the subscription row still shows a pending operation. | |
| 1.9.9 | Replay `customer.subscription.updated` from the Dashboard. | The same operation finishes: the tier, the snapshot amount and the entitlement all reach the target, exactly once, and the pending operation clears. | |

### 1.9a Re-subscribing after cancellation

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.9a.1 | Take an account all the way to `canceled` (1.8.4) and open **Plan & subscription**. | Status reads **Ended**. There is a **Start subscription again** form and **no** change-plan or cancel form. | |
| 1.9a.2 | Choose a **different** tier and confirm. | A new Stripe Checkout Session opens. Before paying, the account is still ended and still locked — nothing has been granted. | |
| 1.9a.3 | Complete payment and return. | A **new** Stripe subscription exists; access is restored; the plan is the tier just purchased; the amount shown is what was just paid. | |
| 1.9a.4 | Check the database and the Stripe Dashboard. | Exactly **one** Workspace, one Business, its Locations and one subscription row — no second account was provisioned. The previous provider subscription id is retained for audit. | |
| 1.9a.5 | Replay the OLD subscription's `customer.subscription.deleted` from the Dashboard. | 200, and the account stays active. A dead subscription cannot lock the one that replaced it. | |
| 1.9a.6 | Repeat 1.9a.2–1.9a.3 but close the tab instead of returning. | The webhook alone finishes it: access is restored and the tier is correct without the browser ever coming back. | |
| 1.9a.7 | Reload the return URL and replay the webhook several times. | No duplicate assignment, no duplicate subscription, no change in outcome. | |

### 1.10 Complimentary account

| # | Step | Expected | Observed |
|---|---|---|---|
| 1.10.1 | As Platform Owner, grant a Workspace complimentary status. | `is_complimentary = 1`, with a reason and a grantor recorded. | |
| 1.10.2 | Confirm no Stripe subscription was created for it. | None in the Dashboard. | |
| 1.10.3 | Confirm it reads as complimentary, not as a paid active subscription, everywhere it is shown. | The customer's Plan & subscription page says "Complimentary account" and offers no cancel action; the owner surface counts it separately. | |
| 1.10.4 | Confirm no administrator anywhere can enter a customer's card or create a charge on their behalf. | There is no such field or action. Card entry happens only on Stripe's own pages, initiated by the customer. | |

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
