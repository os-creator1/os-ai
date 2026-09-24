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
| A — Platform SaaS subscription | §1 | built and merged (PR #366); **not yet live-tested** |
| C — Agency SaaS revenue | §2 | built and merged (PR #367); **not yet live-tested** |
| B — Business revenue | §3 | built and merged (Contract 17); **not yet live-tested** |
| D — Usage funding | §4 | built and merged (RFC-005, Contract 09); **not yet live-tested** |
| Four-lane coexistence | §5 | automated fake-gateway conformance merged with the four-lane conformance branch; **not yet live-tested** |

Every section lives in **this same document**. Do not start a second
checklist. Real Stripe test-mode execution of §§1–5 is a separate, later
milestone: until a human records an Observed result against a row, that row is
**not executed**, whatever the automated suites say.

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
| 1.7.10 | From the LOCKED screen, follow **Continue to billing** to Plan & subscription, then click **Update your payment method**. | Both pages open; you reach Stripe's hosted portal. Neither bounces you back to the locked screen — the recovery the locked screen promises is actually reachable. | |
| 1.7.10a | Still locked, try an ordinary operational page for that Workspace (Team, Settings, the Workspace overview) and the billing **mutations** (change plan / cancel / resume). | All still redirect to the locked screen. Only the recovery set is open. | |
| 1.7.10b | Still locked, sign in as a **Staff** member of that Workspace and open the payment-method route. | 404. Being reachable is not being authorized. | |
| 1.7.10c | Add a working card in the portal and pay the open invoice. | Access is restored immediately. | |
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
| 1.9a.8 | **Re-subscribe onto a tier that has a trial configured.** Take a locked, cancelled account and restart it on a trial plan. | Stripe confirms `trialing`. The product shows **Trial**, not Locked: `workspace_plan_assignments.locked_at` and `grace_started_at` are null and `trial_ends_at` is exactly Stripe's `trial_end` for the NEW subscription. | |
| 1.9a.9 | Replay that subscription event several times. | `trial_ends_at` does not move, and no second `access_restored` transition row appears. | |
| 1.9a.10 | Replay the OLD subscription's events again. | The new trial is untouched and the account stays usable. | |

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

**What is being proved:** a client pays **the Agency**, on the **Agency's own
connected Stripe account**, and that payment drives the **client's own**
Workspace plan and lifecycle. If at the end of this section the money is in the
platform's balance instead of the Agency's, lane C has failed however green the
test suite is.

**The rules in §0 apply unchanged.** Stripe TEST MODE only. No real charge. A
passing fake-gateway suite is **not** acceptance — every row below is executed
against real Stripe test mode, in a browser, by a human.

### 2.1 Prerequisites

| # | Step | Expected | Observed |
|---|---|---|---|
| 2.1.1 | Confirm the deployment has `STRIPE_SECRET` (`sk_test_…`) set and lane C's own signing secret `STRIPE_AGENCY_SUBSCRIPTION_WEBHOOK_SECRET` configured. | Both set, neither displayed anywhere in the app. | |
| 2.1.2 | Add a **Connect** webhook endpoint in the Stripe Dashboard pointing at `POST /stripe/webhook/agency-subscriptions`, subscribed to `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed`. | Created. Its signing secret is the one in 2.1.1, and it is **not** the lane A, B or D secret. | |
| 2.1.3 | Confirm the Platform Owner's own catalog has **Core and Growth priced** (Platform Owner → plan catalog). | Priced. This is a real prerequisite: an agency cannot enroll a client onto an unpriced canonical tier, and the product says so rather than assigning something half-configured. | |
| 2.1.4 | Sign in as an **Agency-tier** Workspace owner with at least one actively managed Client Workspace. | Clients list shows the client. | |

### 2.2 Agency connects the account that receives its revenue

| # | Step | Expected | Observed |
|---|---|---|---|
| 2.2.1 | Open **SaaS → Stripe account**. | Reads "Not connected", and explains the money is the agency's own. | |
| 2.2.2 | Connect, choosing a country. | Redirected to **Stripe's own hosted onboarding**. No bank detail or identity document is typed into our application at any point. | |
| 2.2.3 | Complete onboarding as the Stripe test flow allows, return, and refresh status. | Reads "Ready to take payments". The account id is shown **masked**; the full id appears nowhere on the page source. | |
| 2.2.4 | **Stripe Dashboard:** confirm a connected account now exists under the platform. | It does, and its metadata names the agency workspace. | |
| 2.2.5 | Sign in as an Agency **Admin** and then an Agency **Staff** member and open the same page. | Both can read it; neither sees connect, disconnect or refresh controls. Posting to those routes directly is refused. | |

### 2.3 Agency publishes a resale plan

| # | Step | Expected | Observed |
|---|---|---|---|
| 2.3.1 | Open **SaaS → Plans** and create a plan: a customer-facing name, a capability tier, a price, a currency, a cycle, and optionally a trial. | Created, and **not** published — it has no verified Stripe price yet. | |
| 2.3.2 | Confirm the tier choices offered. | **Core and Growth only.** Agency is not offered: reselling it would let a client manage its own clients on somebody else's subscription. | |
| 2.3.3 | Click **Create or connect price**, leaving the id blank. | A recurring Price is created **on the agency's own connected account** and verified against the typed terms. | |
| 2.3.4 | **Stripe Dashboard → that connected account → Products.** | The Price exists there, with the exact amount, currency and interval, and does **not** exist on the platform account. | |
| 2.3.5 | Paste a Price id belonging to the **platform** account (or another agency's) and try to connect it. | Refused: "could not be found on this agency's own Stripe account". Not a permission error — it is genuinely invisible to that account. | |
| 2.3.6 | Publish the plan. | Published, and the parity check ran again at that moment. | |
| 2.3.7 | Edit the published plan's price. | It **unpublishes** until a matching price is connected, the change is recorded in pricing history, and the price is re-verified before it can be sold again. | |

### 2.4 Client enrollment — the consent boundary

| # | Step | Expected | Observed |
|---|---|---|---|
| 2.4.1 | On a managed client, offer the published plan. | Recorded as **offered**. **Stripe Dashboard: nothing was created** — no customer, no session, no subscription. Nothing is owed. | |
| 2.4.2 | As the **agency owner**, try to reach the client's own checkout route directly. | 404. The agency cannot consent on its client's behalf. | |
| 2.4.3 | Start **View As** on that client and try the same. | Refused. A support session can never fabricate financial consent. | |
| 2.4.4 | Sign in as the **client owner** and open their plan page. | It names **the agency** as the party charging them, and shows the exact price, currency, cycle and trial they are being asked to agree to. | |
| 2.4.5 | Confirm and continue to payment. | Stripe **hosted Checkout** opens. Confirm in the URL/session that it is on the **agency's connected account**. | |
| 2.4.6 | Pay with `4242 4242 4242 4242`. | Payment succeeds. | |
| 2.4.7 | **Stripe Dashboard → the AGENCY's connected account.** | The customer, subscription and invoice are **there**. | |
| 2.4.8 | **Stripe Dashboard → the PLATFORM account.** | **Nothing** from this transaction. No charge, no application fee, no transfer. This is the single most important row in this section. | |
| 2.4.9 | Back in the product as the client. | Status Active (or Trial), the plan is theirs, and the account is usable. | |
| 2.4.10 | Confirm the client's own Workspace, Business and Locations. | Unchanged — enrollment reused the account, it did not provision a second one. | |

### 2.5 Webhook-only completion

| # | Step | Expected | Observed |
|---|---|---|---|
| 2.5.1 | Repeat 2.4.4–2.4.6 for a second client, but **close the tab** instead of returning. | The webhook alone finishes it: the subscription activates and the client's plan is assigned without the browser ever coming back. | |
| 2.5.2 | Replay that event from the Dashboard several times. | 200 each time. No duplicate assignment, no duplicate subscription, no second transition. | |
| 2.5.3 | Send a body with a bad signature. | 400, and nothing is stored. | |

### 2.6 Trial, renewal, failure, recovery

| # | Step | Expected | Observed |
|---|---|---|---|
| 2.6.1 | Enroll a client on a plan **with a trial**, using a Stripe **test clock**. | Status Trial, and the client's canonical trial end equals Stripe's `trial_end` for that subscription. | |
| 2.6.2 | Advance the clock past the trial and let the first invoice pay. | Renews to Active; the account stays usable throughout. | |
| 2.6.3 | Switch the card to `4000 0000 0000 0341` and advance to the next renewal. | `invoice.payment_failed` delivered; **one** 3-day grace window opens; the account is **still usable** with a billing prompt naming the agency. | |
| 2.6.4 | Let a second failure arrive. | The grace window does **not** slide. | |
| 2.6.5 | Click **Update your payment method** as the client. | Opens the **agency's** hosted Billing Portal, on the agency's account. No card is typed into our application. | |
| 2.6.6 | Instead of recovering, advance past the grace window and let `workspaces:advance-account-lifecycle` run. | Locked. Data intact. | |
| 2.6.7 | While still **Locked**, as the client owner: open the agency plan page, then click **Update your payment method**. | Both open. Neither bounces back to the locked screen — the recovery the locked screen promises is actually reachable, and the portal is the **agency's**. | |
| 2.6.7a | Still locked, try an ordinary operational page (Team, Settings, the Workspace overview) and the billing **mutations** (change plan / cancel / resume). | All still redirect to the locked screen. Only the recovery set is open. | |
| 2.6.7b | Still locked, sign in as a **Staff** member of that client, then as an unrelated user, then as the **agency owner**, and open the payment-method route. | 404 for all three. Being reachable is not being authorized. | |
| 2.6.7c | Pay the open invoice from the agency's Dashboard. | Access restored immediately, on the same tier, with nothing deleted. | |
| 2.6.8 | Lock the **agency's own** account and open the client's agency plan page. | Reads **Unavailable** and points at the agency's account status. It does **not** say the client's payment failed, and offers no CTA implying the client can fix it. | |
| 2.6.9 | With the agency still locked, have the client pay again. | The client stays locked with an agency-caused reason, and their own lifecycle timestamps are unchanged. A client cannot buy their way out of their agency's delinquency. | |

### 2.7 Plan change, cancellation, coming back

| # | Step | Expected | Observed |
|---|---|---|---|
| 2.7.1 | As the client, upgrade to a higher plan. | Immediate: the agency bills the difference now and the entitlement widens now. | |
| 2.7.2 | As the client, downgrade. | Nothing changes today; the page shows the scheduled change and its date. | |
| 2.7.3 | Have the **agency raise that plan's price**, then advance past the boundary and let `agency-subscriptions:apply-due-plan-changes` run. | The client is charged the amount **they agreed to when they requested the change**, not the new one. | |
| 2.7.4 | Cancel as the client. | Access continues to the end of the paid period; the end date is shown. | |
| 2.7.5 | Advance past it. | `customer.subscription.deleted` delivered; the client is locked; data intact. | |
| 2.7.6 | As the client, use **Start again** to re-subscribe. | A new subscription on the agency's account; the same Workspace, Business and Locations; access restored. | |
| 2.7.7 | Replay the OLD subscription's `deleted` event from the Dashboard. | 200, and the new subscription is untouched. | |
| 2.7.8 | Take another client to **ended/locked**, then have the agency offer them a **fresh** plan, and pay it as the client. | The offer is payable while still locked, the payment confirms, and the client is activated on the new plan. (Before this correction the stale provider subscription id made the new purchase unconfirmable — the client paid and was never activated.) | |

### 2.7a A disconnected account still owns the subscriptions it created

| # | Step | Expected | Observed |
|---|---|---|---|
| 2.7a.1 | With a client actively subscribed, **disconnect** the agency's Stripe account in the product. | The connection reads Disconnected. **Stripe Dashboard:** the client's subscription is still live — we do not cancel an agency's billing relationships on its behalf. | |
| 2.7a.2 | Let that still-live subscription fail a renewal (failing card + test clock), or cancel it from the agency's Dashboard. | The event is **processed**, not rejected: the client's own lifecycle keeps updating. Before this correction it failed as "unknown account" and the client's lifecycle silently stopped. | |
| 2.7a.3 | Check the connection again. | Still Disconnected, still not chargeable. Resolving ownership answers a question; it does not change an answer. | |
| 2.7a.4 | Try to offer a plan, bind a price or publish for that agency. | All refused. A disconnected account can never sell again. | |
| 2.7a.5 | Replay an event naming a **different** agency's account, and one naming an account nobody ever connected. | Both refused and recorded failed. | |
| 2.7a.6 | In the Stripe Dashboard, **revoke** the platform's access to that connected account, then deliver another event for it. | The event is visibly **Failed** with a safe reason code. No renewal, cancellation or Active state is taken from the payload, and the client's subscription is unchanged. | |

### 2.8 Account and lane isolation

| # | Step | Expected | Observed |
|---|---|---|---|
| 2.8.1 | With **two** agencies connected, replay one agency's subscription event but re-point it at the other agency's account. | Refused and recorded failed. One agency's account can never speak for another's client. | |
| 2.8.2 | Confirm one client's non-payment while another client of the same agency is active. | Only the defaulting client is affected. The other client and the **agency's own** account are untouched. | |
| 2.8.3 | Lock the **agency's own** lane-A account and check a managed client. | The client loses effective access with an agency-caused reason — and their own `locked_at`/`grace_started_at` are **unchanged**. Restore the agency; the client resumes on its own state. | |
| 2.8.4 | Check `platform_subscriptions`, `platform_subscription_events`. | Untouched by anything in this section. | |
| 2.8.5 | Check `business_documents`, `business_document_payments`, `business_stripe_connections`, `business_payment_events`. | Untouched. | |
| 2.8.6 | Check `business_usage_wallets`, `business_usage_ledger_entries`, `payment_provider_events`. | Untouched. AgencyRebill is lane D and is not agency SaaS revenue. | |
| 2.8.7 | Check the Platform Owner's revenue view. | Lane-A revenue only. The agency's resale revenue is **not** presented as ours. | |
| 2.8.8 | Check the agency's own revenue page. | Labelled as the agency's own revenue, grouped by currency, never summed across currencies. | |

### 2.9 Sign-off

| Field | Value |
|---|---|
| Executed by | |
| Date | |
| Deployment / commit | |
| Stripe account (test) | |
| Agency connected account (test) | |
| Result | pass / fail / blocked |
| Blocked or failed steps | |

## 3. Lane B — Business revenue

**What is being proved:** an end customer pays **a Business** for that
Business's own proposal, contract or invoice, on the **Business's own connected
Stripe account**, as a direct charge with **no** platform fee (Contract 17
§11.2). If at the end of this section the money is in the platform's balance,
lane B has failed however green the test suite is.

**The rules in §0 apply unchanged.** Stripe TEST MODE only; test cards only;
every row executed in a real browser against a real deployment by a human.

Automated evidence that already exists (fake gateway — **not** acceptance):
`tests/Feature/Payments/*` (onboarding, payment execution, webhook replay and
races, refunds, stale-payment reconciliation) and
`tests/Feature/PaymentsConformance/FourLaneCoexistenceTest.php`.

### 3.1 Prerequisites

| # | Step | Expected | Observed |
|---|---|---|---|
| 3.1.1 | Confirm `STRIPE_SECRET` is `sk_test_…` and lane B's own signing secret `STRIPE_CONNECT_WEBHOOK_SECRET` is configured. | Both set; neither displayed anywhere in the app. | |
| 3.1.2 | In the Stripe Dashboard add a **Connect** webhook endpoint at `POST /stripe/webhook/business-payments`, subscribed to `payment_intent.succeeded`, `payment_intent.processing`, `payment_intent.payment_failed`, `payment_intent.requires_action`, `payment_intent.canceled`, `refund.created`, `refund.updated`, `refund.failed`, `charge.refund.updated`. | Created. Its signing secret is the one in 3.1.1 and is **not** the lane A, C or D secret. | |
| 3.1.3 | Confirm `documents:reconcile-stale-payments` is scheduled (every five minutes) and the queue worker is running. | Both running. | |
| 3.1.4 | Sign in as the owner of a Business on a plan that includes Payments & Contracts. | The Business's Payments & Contracts module is reachable. | |

### 3.2 The Business connects the account that receives its revenue

| # | Step | Expected | Observed |
|---|---|---|---|
| 3.2.1 | Open **Payments → Connect Stripe** (`/workspaces/{workspace}/businesses/{business}/payments/connect`). | Reads not connected, and says the money is the Business's own. | |
| 3.2.2 | Start the connection. | Redirected to **Stripe's hosted onboarding**. No bank detail or identity document is typed into our application. | |
| 3.2.3 | Finish the test onboarding, return (`…/payments/connect/resume`) and use **Refresh**. | Charges enabled. The account id is shown masked, never in full in the page source. | |
| 3.2.4 | **Stripe Dashboard:** confirm a connected account now exists under the platform. | It does. | |
| 3.2.5 | Sign in as an Admin and then a Staff member of that Workspace and try the connect, refresh and disconnect actions. | Each mutating action answers 404; the read-only status page stays readable. Only the Workspace owner decides where the Business's money goes. | |

### 3.3 A customer pays a document

| # | Step | Expected | Observed |
|---|---|---|---|
| 3.3.1 | Create a proposal with a deposit + balance schedule, send it to a test mailbox you control. | The customer receives a link; nothing is charged. | |
| 3.3.2 | Open the link as the customer, sign it, and choose **Pay** (`POST /documents/{uid}/{token}/pay`). | Stripe's payment element loads for the **Business's connected account**; there is no card field of our own. | |
| 3.3.3 | Pay the deposit with `4242 4242 4242 4242`. | Payment succeeds; the document shows the deposit paid and the balance due. | |
| 3.3.4 | **Stripe Dashboard → the BUSINESS's connected account → Payments.** | The PaymentIntent is **there**, for the exact amount and currency. | |
| 3.3.5 | **Stripe Dashboard → the PLATFORM account.** | **Nothing** from this payment: no charge, no application fee, no transfer. The single most important row in this section. | |
| 3.3.6 | Check the customer's receipt email. | One receipt, naming the Business as the payee. | |
| 3.3.7 | Pay the balance with an authentication-required test card (`4000 0025 0000 3155`) and complete the challenge. | Succeeds after authentication; the document reads fully paid. | |
| 3.3.8 | Start a payment with a declining card (`4000 0000 0000 0002`). | Declined; the schedule item stays due and payable again; nothing is marked paid. | |

### 3.4 Webhook-only completion, replay and signature

| # | Step | Expected | Observed |
|---|---|---|---|
| 3.4.1 | Pay a document and **close the tab** before returning. | The webhook alone marks the item paid. | |
| 3.4.2 | Replay that `payment_intent.succeeded` from the Dashboard several times. | 200 each time. One payment row, one receipt, no second transition. | |
| 3.4.3 | Send a body with a bad signature to `/stripe/webhook/business-payments`. | 400, and nothing is stored. | |
| 3.4.4 | Stop the queue worker, pay, restart it after the webhook arrived. | The stored event is processed exactly once when the worker returns. | |

### 3.5 Refunds

| # | Step | Expected | Observed |
|---|---|---|---|
| 3.5.1 | As the Business owner, refund part of a captured payment from the document. | Refund created **on the Business's connected account**; the document shows the partial refund. | |
| 3.5.2 | **Stripe Dashboard → connected account.** | The refund is there, against the original payment. No application-fee refund exists because no fee was taken. | |
| 3.5.3 | Try to refund more than the remaining captured amount. | Refused; nothing reaches Stripe. | |

### 3.6 Lane isolation

| # | Step | Expected | Observed |
|---|---|---|---|
| 3.6.1 | Check `platform_subscriptions`, `platform_subscription_events`. | Untouched by anything in this section. | |
| 3.6.2 | Check `agency_stripe_connections`, `agency_client_subscriptions`, `agency_client_subscription_events`. | Untouched. | |
| 3.6.3 | Check `business_usage_wallets`, `business_usage_ledger_entries`, `payment_provider_customers`, `payment_provider_events`. | Untouched. A customer's payment to a Business never funds a usage wallet. | |
| 3.6.4 | Check the Business owner's Plan & subscription page and the Workspace's access state. | Unchanged. Business revenue is not a SaaS payment and neither unlocks nor extends anything. | |
| 3.6.5 | Check the Platform Owner's **Billing & Revenue** view. | Lane-A only. This Business's revenue is not shown as platform revenue. | |

### 3.7 Sign-off

| Field | Value |
|---|---|
| Executed by | |
| Date | |
| Deployment / commit | |
| Stripe account (test) | |
| Business connected account (test) | |
| Result | pass / fail / blocked |
| Blocked or failed steps | |

---

## 4. Lane D — Usage funding

**What is being proved:** a Business, Workspace or AgencyRebill payer funds the
**platform's** usage wallet for a Business, on the **platform's own** Stripe
account, as **prepaid usage funding** — not as SaaS subscription revenue. A
wallet credit never unlocks a Workspace, and a SaaS payment never credits a
wallet (Contract 21 §2).

**The rules in §0 apply unchanged.**

Automated evidence that already exists (fake gateway — **not** acceptance):
`tests/Feature/Usage/*` (funding attempts, webhook replay/claim/lease recovery,
refund and dispute outcomes, AgencyRebill authority and effect) and
`tests/Feature/PaymentsConformance/FourLaneCoexistenceTest.php`.

### 4.1 Prerequisites

| # | Step | Expected | Observed |
|---|---|---|---|
| 4.1.1 | Confirm `STRIPE_SECRET` is `sk_test_…`, `STRIPE_MODE=test`, `STRIPE_API_VERSION` is set, and lane D's own signing secret `STRIPE_WEBHOOK_SECRET` is configured. | All set. The gateway refuses to start if the key does not match the mode. | |
| 4.1.2 | In the Stripe Dashboard add a **platform** (not Connect) webhook endpoint at `POST /stripe/webhook/usage-billing`, subscribed to `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`, `charge.dispute.created`, `charge.dispute.updated`, `charge.dispute.closed`, `charge.dispute.funds_withdrawn`, `charge.dispute.funds_reinstated`, `refund.created`, `refund.updated`, `refund.failed`, `charge.refund.updated`. | Created. Its signing secret is **not** the lane A, B or C secret. | |
| 4.1.3 | Confirm the scheduled lane-D jobs run (`ReconcileProviderPendingState`, `RetryStuckPaymentProviderEvents`, `ExpireStaleUsageReservations`) and the queue worker is running. | Running. | |

### 4.2 Payer, payment method and a manual top-up

| # | Step | Expected | Observed |
|---|---|---|---|
| 4.2.1 | As a Workspace owner open **Settings → Usage & billing** (`/workspaces/{workspace}/businesses/{business}/usage-billing`). | Shows the wallet balance, the current payer and no card field of our own. | |
| 4.2.2 | Add a payment method (`…/usage-billing/payment-method/setup-intent` → Stripe element → `…/confirm`) with `4242 4242 4242 4242`. | Saved; only brand and last four are shown. | |
| 4.2.3 | Start a top-up (`…/usage-billing/top-up`). | Redirected to Stripe **hosted Checkout** on the **platform** account. | |
| 4.2.4 | Pay it and return (`…/top-up/{attempt}/confirm`). | The wallet balance rises by exactly the top-up amount; one ledger entry; one receipt. | |
| 4.2.5 | **Stripe Dashboard → the PLATFORM account.** | The payment is there, as a one-off payment — **not** a subscription and not on any connected account. | |
| 4.2.6 | Replay the `checkout.session.completed` event several times. | 200 each time; the balance does not change again. | |
| 4.2.7 | Start a top-up and close the tab before returning. | The webhook alone credits the wallet, exactly once. | |
| 4.2.8 | Start a top-up and abandon it until the Checkout session expires. | Nothing is credited; the attempt reads failed/expired. | |

### 4.3 Auto-recharge, failure, refund and dispute

| # | Step | Expected | Observed |
|---|---|---|---|
| 4.3.1 | Enable auto-recharge (`…/usage-billing/auto-recharge`) with a threshold, then consume usage below it. | One off-session charge on the platform account; one credit. | |
| 4.3.2 | Replace the card with `4000 0000 0000 0341` and trigger auto-recharge again. | The charge fails; no credit; the failure is recorded and the owner is notified; auto-recharge does not loop. | |
| 4.3.3 | Refund a top-up from the Stripe Dashboard. | `charge.refunded` / `refund.*` processed; the wallet is debited by the refunded amount once. | |
| 4.3.4 | Open a dispute with a dispute test card. | The dispute lifecycle is recorded; funds withdrawn/reinstated adjust the wallet once each. | |

### 4.4 AgencyRebill is lane D, not lane C

| # | Step | Expected | Observed |
|---|---|---|---|
| 4.4.1 | As an Agency owner, make the Agency the **AgencyRebill** payer for a managed client's Business, and top that wallet up. | The charge is on the **platform** account against the Agency's own payment method; the **client's** Business wallet is credited. | |
| 4.4.2 | Check `agency_client_subscriptions`, `agency_client_subscription_events`, `agency_stripe_connections`. | Untouched. AgencyRebill creates no Agency SaaS subscription and no lane-C record. | |
| 4.4.3 | Check the Agency's **SaaS → Revenue** page. | The rebill top-up does **not** appear as agency revenue. | |

### 4.5 A wallet credit never unlocks a Workspace

| # | Step | Expected | Observed |
|---|---|---|---|
| 4.5.1 | Take a Workspace to **Locked** (lane A §1.7 or lane C §2.6), then complete a top-up for one of its Businesses (a top-up started before the lock may confirm after it). | The wallet is credited; the Workspace **stays Locked**; its `locked_at` is unchanged. | |
| 4.5.2 | Check the Workspace's Plan & subscription page. | Still asks for the SaaS payment. Usage funding is not a subscription payment. | |

### 4.6 Lane isolation

| # | Step | Expected | Observed |
|---|---|---|---|
| 4.6.1 | Check `platform_subscriptions`, `platform_subscription_events`, `workspace_plan_assignments`. | Untouched by any top-up in this section. | |
| 4.6.2 | Check `business_document_payments`, `business_payment_events`, `business_stripe_connections`. | Untouched. | |
| 4.6.3 | Check the Platform Owner's **Billing & Revenue** view. | Lane-A subscription figures unchanged. Usage funding is not counted as SaaS revenue there. | |

### 4.7 Sign-off

| Field | Value |
|---|---|
| Executed by | |
| Date | |
| Deployment / commit | |
| Stripe account (test) | |
| Result | pass / fail / blocked |
| Blocked or failed steps | |

---

## 5. Four-lane conformance

**What is being proved:** all four lanes run **at the same time, in one
deployment, on one platform Stripe account**, and still keep their money,
their records, their customer identities, their webhook authority and their
entitlement effects apart. The hardest legitimate case is included: an Agency
that connects **the same underlying Stripe account** for its own Business
revenue (lane B) and for its resold SaaS subscriptions (lane C). Sharing an
account is the merchant's business; it must never become sharing a record.

The automated counterpart is
`tests/Feature/PaymentsConformance/FourLaneCoexistenceTest.php`. It runs every
provider as a **fake gateway** (except the signature matrix, which uses the
real gateways' local HMAC verification with placeholder secrets and makes no
network call). **It is not evidence for any row below.**

**The shared-account case is proven at the record level only.** Both connect
flows as implemented create a **new** connected account (lane B:
`StripeApiConnectGateway::createAccount`; lane C:
`StripeApiAgencyGateway::createAccount`); neither offers "use an account I
already connected". So a live deployment cannot today make G's lane-B and
lane-C connections point at one physical account through the product, and this
section runs them on two accounts, **X** (lane C) and **Y** (lane B). The
automated suite binds both records to one account id to prove that sharing
would still keep the records apart. Adding an "existing account" path is a
product decision, not a conformance defect: Lane C §C1 permits sharing, it does
not require it.

**Prerequisite:** §§1.1–1.3, 2.1–2.2, 3.1–3.2 and 4.1 completed on the same
deployment, with all four webhook endpoints registered and four **distinct**
signing secrets.

### 5.1 One world, four lanes

| # | Step | Expected | Observed |
|---|---|---|---|
| 5.1.1 | Workspace **P** subscribes to the platform directly (lane A, §1.4). | Active; Platform Owner's Stripe account holds the subscription. | |
| 5.1.2 | Agency **G** connects Stripe account **X** for lane C (§2.2) and enrolls client **K** on a resale plan (§2.4). | K is Active on the Agency's plan; the subscription is on **X**. | |
| 5.1.3 | Agency **G**'s own Business connects Stripe for lane B (§3.2), creating account **Y**, and a customer pays a G document (§3.3). | The payment is on **Y**. The app holds **one** `agency_stripe_connections` row (X, the Agency Workspace) and **one** `business_stripe_connections` row (Y, the Agency's Business) — each owned by its own commercial identity. | |
| 5.1.4 | K's Workspace owner tops up K's Business wallet (lane D, §4.2). | Charged on the **platform** account; K's wallet credited once. | |

### 5.2 Financial ownership

| # | Step | Expected | Observed |
|---|---|---|---|
| 5.2.1 | **Stripe Dashboard → platform account.** | Exactly P's subscription payment (lane A) and K's top-up (lane D). **No** charge, fee or transfer from G's document payment or K's agency subscription. | |
| 5.2.2 | **Stripe Dashboard → accounts X and Y.** | X holds exactly K's agency subscription invoice (lane C); Y holds exactly G's document payment (lane B). Nothing from P, nothing from the top-up, on either. | |
| 5.2.3 | Search every Dashboard object above for an application fee or transfer. | None anywhere. | |

### 5.3 Identity and webhook isolation

| # | Step | Expected | Observed |
|---|---|---|---|
| 5.3.1 | Compare the Stripe customer ids behind P's subscription, K's agency subscription and K's usage-billing customer (`payment_provider_customers`). | Three different customers. No lane reuses another lane's customer. | |
| 5.3.2 | A Connect endpoint receives its subscribed event types for **every** connected account. Paying K's agency invoice on X also emits a `payment_intent.succeeded` on X, which lane B's endpoint is subscribed to. In the Dashboard, find that delivery to `/stripe/webhook/business-payments`. | 200, and **nothing changed**: no document payment was created or confirmed, and K's agency subscription is untouched. Lane B may record the event as acknowledged/ignored in its own table; it never resolves it to a lane-C record. | |
| 5.3.3 | Replay each lane's confirming event (P's `invoice.paid`, G's `payment_intent.succeeded`, K's `invoice.paid`, K's `checkout.session.completed` top-up) three times. | 200 each time; one stored event per lane; no second transition, receipt or credit. | |
| 5.3.4 | Send one lane's correctly signed body to each of the other three endpoints (resend from the Dashboard to a mis-configured endpoint URL, or re-sign with the other lane's secret in a local tool — never paste a secret into the app). | 400 at every wrong endpoint; nothing stored anywhere. | |
| 5.3.5 | Cancel K's agency subscription, let K start again (§2.7.6), then replay the OLD subscription's `customer.subscription.deleted`. | K stays active on the new subscription; P, G and the top-up are unaffected. | |

### 5.4 Canonical entitlements

| # | Step | Expected | Observed |
|---|---|---|---|
| 5.4.1 | Fail K's agency renewal (§2.6.3). | K alone enters grace. P, G's own Workspace and G's Business are unchanged. | |
| 5.4.2 | Fail P's platform renewal (§1.7). | P alone enters grace. K is unchanged. | |
| 5.4.3 | Let K reach **Locked**, then complete a K top-up and a G document payment. | K stays Locked. Neither usage funding nor Business revenue unlocks a Workspace. | |
| 5.4.4 | Pay K's open agency invoice. | K is restored on its own plan. | |
| 5.4.5 | Use a client whose own platform subscription **ended** before its agency started billing it (e.g. run §1.8 on a Workspace, then §2.4 on it as a managed client). Open its Plan & subscription page, then POST the platform re-subscribe form directly. | The page offers no **Start subscription again**; the direct POST is refused ("That change cannot be made from the current plan state.") and no platform Checkout Session is created. One paid authority per Workspace. | |
| 5.4.6 | As G's owner, offer a plan to a managed client whose platform subscription is `unpaid` or `paused`. | Refused: "client has a platform subscription". | |

### 5.5 Recovery and authority

| # | Step | Expected | Observed |
|---|---|---|---|
| 5.5.1 | With K Locked, open K's recovery routes (agency plan page and payment-method portal) and an ordinary page. | Recovery opens the **agency's** portal on X; ordinary pages still redirect to the locked screen (§2.6.7–2.6.7a). | |
| 5.5.2 | With P Locked, open P's payment-method recovery. | The **platform's** portal opens; nothing of X is reachable from P. | |
| 5.5.3 | Start **View As** on K as G's owner and try: K's agency-plan checkout, K's platform plan actions, the signup re-entry (`/signup/plan`, `/signup/complete`) and K's usage-billing top-up. | Every one refused; no Stripe object is created in any account. | |

### 5.6 Reporting

| # | Step | Expected | Observed |
|---|---|---|---|
| 5.6.1 | Platform Owner → **Billing & Revenue**. | Active = P only. Grace/Locked count only platform subscribers — K's agency delinquency (5.4.1) is **not** counted. No row names G or K. | |
| 5.6.2 | G → **SaaS → Revenue**. | K's agency subscription only, labelled as the agency's own revenue, grouped by currency. No lane-A, lane-B or lane-D money. | |
| 5.6.3 | G's Business → the paid document. | G's document payment only, on Y. | |
| 5.6.4 | K's Business → **Usage & billing**. | The top-up only, as usage funding. | |

### 5.7 Sign-off

| Field | Value |
|---|---|
| Executed by | |
| Date | |
| Deployment / commit | |
| Stripe platform account (test) | |
| Agency connected account X (test) | |
| Agency Business connected account Y (test) | |
| Result | pass / fail / blocked |
| Blocked or failed steps | |
