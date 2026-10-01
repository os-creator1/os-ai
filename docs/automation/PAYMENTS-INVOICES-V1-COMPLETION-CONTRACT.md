# Payments & Invoices V1 — Completion Contract

**Lane:** `agent/payments-invoices-v1-completion` (route 3, human-authorized manual lane).
**Base:** `origin/main` @ `3692660f`.
**Scope:** customer-facing **business** invoices and payments — money lane B of
[Contract 21](../product/implementation-contracts/21-COMMERCIAL-PAYMENTS-COMPLETION.md)
(an end customer pays *the Business*, on the Business's own connected Stripe
account). Platform SaaS billing (lane A), Agency SaaS (lane C) and usage
funding (lane D) are untouched.

This is a **completion** lane. The invoice domain, the secure link, the
PaymentIntent flow, the verified webhook and the refund flow already exist
(Contract 17, sub-slices A–G). This document records what was audited, the
defects found against the lane's acceptance list, the corrections made, and the
exact lifecycle / provider / webhook / Location rules that now hold. It does not
reopen Contract 17; it amends four statements in it (see §9).

---

## 1. The canonical invoice domain (unchanged — audited)

An **invoice** is `business_documents.kind = 'invoice'`: one versioned document
with stages (Contract 17 §5.1), *not* a separate table. There is no second
invoice model. (`invoices` / `Invoices` is the legacy platform-billing table
and is never used here.)

| Concern | Where it lives |
|---|---|
| Business scope | `business_documents.business_id` |
| Exactly one operational Location | `business_documents.business_location_id` (NOT NULL, `restrictOnDelete`) |
| Customer | `business_documents.contact_id` (NOT NULL); delivery identity frozen in `recipient_*_snapshot` at send |
| Currency | `business_documents.currency_code`; every version, line and schedule item repeats it and `send()` proves they agree |
| Money | integer minor units only (`*_minor` `BIGINT`); no float anywhere in the path |
| Historical stability | `business_document_versions` — `draft` → `issued` → `superseded`; an issued version's lines, schedule terms and `content_hash` are frozen; Catalog items enter only as `package_snapshots` (Contract 16), never a live price |
| Payment ledger | `business_document_payments` (one row per attempt) with `business_id`, `business_document_id`, `schedule_item_id`, `business_stripe_connection_id`, provider intent / charge ids, `amount_minor`, `currency_code`, `status`, `succeeded_at`, `local_idempotency_key`; Location and Contact are the **document's** (a payment is reachable only through its document, and a document's Location and Contact are immutable after creation) |
| Refunds | `business_document_refunds` (already present, preserved; no partial-payment or new refund workflow was added) |

**No migration was added by this lane.** Denormalising Location/Contact onto the
payment row would create a second copy that could drift from the immutable
document; the join is authoritative.

### Authoring (unchanged — proven)

Create → choose Contact → choose Location → add Catalog-backed lines (immutable
`package_snapshots`) and permitted custom lines → quantity → totals → schedule
(`full`, or `deposit` + `balance`) → save draft → edit only while an open draft
version exists. Foreign Contact / Location / Catalog item / Opportunity are
refused by `DocumentManager::assertIdentity()` and the controller's Business
scoping; `create()` re-reads Business, Location and Contact **fresh** inside its
transaction, so a stale in-memory model cannot create a document on an archived
Location or with a Contact that has moved.

## 2. The exact lifecycle

### Document (`DocumentStatus`)

```
draft ──send──▶ sent ──sign──▶ signed ──final payment──▶ paid   (terminal)
  │               │ │               │
  │               │ └─final payment (invoice, no signature)──▶ paid
  │               ├──expiry sweep (unsigned, zero payments)──▶ expired   (terminal)
  │               └──revise──▶ sent (new version N+1, old one superseded on next send)
  └─────────void──┴──────void───────┴──▶ void                   (terminal)
```

* `send` freezes the open draft into an `issued` version (hash, schedule, link
  rotation) in one transaction under the document lock; delivery is a separate
  **after-commit, encrypted** queued job, so a mail failure can never un-send or
  half-send a document and a rolled-back send emails nothing.
* `resend` *(new, §5)* rotates the link of the **current issued version** and
  queues the same delivery job; no version, content or schedule changes.
* `paid`, `void`, `expired` accept **no** inbound transition and no mutation of
  any kind — every authoring method refuses (`draft()` requires `draft|sent` and
  an open draft version).
* A document becomes `paid` only inside `PaymentFinalizer`, from the **current
  version's** schedule, when no `pending` item remains. A deposit never marks it
  paid.

### Payment attempt (`BusinessDocumentPaymentStatus`)

```
created ─▶ requires_action ─▶ processing ─▶ succeeded            (final at the provider)
   │              │                │
   └──────────────┴────────────────┴──▶ failed ──(provider-confirmed success on the SAME intent)──▶ succeeded
                                      └─▶ canceled                (final at the provider)
```

`failed` is **not** final at the provider: `payment_intent.payment_failed`
leaves the PaymentIntent alive and the Payment Element lets the customer retry
it. A `succeeded` observation on a `failed` row is therefore applied. Nothing
else ever moves a settled row.

### Schedule item

`pending → paid → refunded` (full cumulative refund) and `pending → void`.

## 3. Provider idempotency strategy

* One live attempt per schedule item is a **database** guarantee:
  `unique(active_schedule_item_id)`, a stored generated column that holds the
  item id only while the payment is `created | requires_action | processing`.
* The Stripe idempotency key is `document-payment:{payment_uid}`, derived from
  the **durable local row**, never a guessed ordinal. The same string, persisted
  as `local_idempotency_key`, is sent as `metadata.app_operation_id` and
  cross-checked on every inbound observation.
* Two re-drive shapes, never a second intent (§7.2.1): **A** the row knows its
  intent id → *retrieve* that intent on the row's recorded connection; **B** an
  earlier create returned uncertainly → repeat the create under the **same**
  key, so Stripe returns the original.
* No provider call happens inside a transaction or under a lock; the fake
  gateway (and the real-concurrency runner's) throws if one does.
* Amount and currency come exclusively from the persisted schedule item; the
  pay endpoint's request schema is empty and refuses card-shaped or
  account-naming input.
* **Intent metadata** *(new)*: `app_business_uid`, `app_location_uid`,
  `app_document_uid` (our uids — no numeric ids, no PII) are stamped on the
  PaymentIntent so it is traceable from the connected account's dashboard. They
  are a pure function of the durable rows, built in one place, so a re-drive
  sends byte-identical parameters. **They are informational and never
  authoritative** — see §4.
* Retries are bounded: a genuine failure frees the item for **exactly one** new
  attempt (one new row, one new key); the pay route is throttled; reconciliation
  is bounded and decides nothing on its own.

## 4. Webhook truth model

1. The signature is verified over the **exact raw body** before a single row is
   written (`400`, zero side effects otherwise).
2. The event is stored (`unique(stripe_account_id, provider_event_id)` makes a
   duplicate delivery a `200` no-op) and a job claims it with one atomic
   conditional `UPDATE` (received / retryable-failed / expired-lease); a losing
   worker returns without counting an attempt.
3. The local payment is **re-derived from the persisted
   `provider_payment_intent_id`** — never from the event naming a row — and the
   shared `PaymentFinalizer` cross-checks, fail-closed with a reason code: the
   event's connected account equals **the connection recorded on the row**;
   `metadata.app_operation_id` equals the row's `local_idempotency_key`; the
   intent id, amount and currency match. The intent metadata added in §3 is
   deliberately **not** consulted.
4. Locks are taken in one canonical order — document → schedule item →
   payment — and everything is re-read under them.
5. Browser redirects carry no authority: the public page renders persisted
   state only, and "paid" appears only after a webhook (or a verified
   server-side retrieval through the same finalizer) wrote it.

### Two truths, kept apart *(corrected by this lane)*

* **The payment row is the ledger of what the provider did with the attempt.**
  It records the provider-confirmed outcome whatever state the document is in.
  Previously a capture that landed after the document was voided / expired /
  paid was *ignored* (`ignored_document_terminal`): the customer was charged,
  the row stayed `created`, and because a refund needs a `succeeded` payment the
  Business could neither see nor refund it. It is now **recorded** (event state
  `processed`, reason `recorded_against_terminal_document`) and refundable.
* **The document and its schedule move only while the document is live.** A late
  capture against a terminal document never reopens, pays or alters it and never
  emits `DocumentFullyPaid`. A second capture against an already-paid invoice
  (e.g. an old attempt's still-open intent) is recorded, refundable, and pays
  the invoice exactly **once**.

Idempotence: a replayed event, a duplicate delivery, two concurrent workers and
two different success events for one intent all produce **one** `succeeded`
transition, one `paid` transition and one event each (proven with real
multi-process races).

## 5. Business-facing surface

* **List** — title, kind, status, Location, total, paid date (Location-filtered
  by `LocationAccessGuard`).
* **Show** — for an issued document: the **frozen issued version** (what the
  customer holds, never the live Catalog or a later draft), schedule progress,
  paid / void / expired notices, **payments and receipts** (status, amount,
  received-at, receipt emailed, refunds, remaining refundable) and the
  existing refund action behind its explicit confirmation.
* **Re-send payment link** *(new — `DocumentManager::resendLink`, route
  `…documents.{uid}.resend`)* — Contract 17 §7.1 resolves a lost email by
  "re-sending, which rotates the token", but `send()` consumes a draft, so the
  only way to re-send was to revise into a content-identical version 2. Resend
  rotates the link and queues the same after-commit encrypted job to the
  recipient **frozen at first send**. It is refused for draft / paid / void /
  expired / lapsed documents, re-checks status under the document lock, and
  emits no `DocumentSent`.
* **Revise** — surfaced in the UI for a sent, unsigned, draft-less document
  (the route already existed).
* **Void** — draft / sent / signed only, as before; refused while a succeeded
  payment is unrefunded.
* **Customer page** — frozen invoice, schedule with per-instalment *Paid*
  status, *Payment received — thank you* only for a persisted `paid` document,
  *being confirmed* only while the provider reports `processing`. It shows the
  Business's name and nothing else about the tenant (no Location, Contact,
  account id, uids, column names or idempotency keys — pinned by test).

## 6. Location / ACL / View As

* Every authenticated route runs the §6.1 chain in order: Workspace/Business
  tenancy → `payments_contracts` → the Payments & Contracts entitlement →
  `LocationAccessGuard::assertUserCanAccessLocation()` for the document's
  **fresh** `business_location_id`; a refusal is the same `404` a missing
  document gets. The list is filtered by `accessibleLocationIdsForBusiness`.
* Proven for staff granted Location A only: they cannot list, open, edit,
  add lines to, schedule, send, **re-send**, revise, void or refund a Location B
  invoice, nor create a draft at B or pair A with B's Contact; a refusal changes
  nothing.
* **Agency View As follows the existing rule — unchanged and pinned.** View As
  is true impersonation (PR #301): the Agency actor may do what the viewed
  client could, bounded by tenancy, capability, entitlement and Location
  authority, and by the closed prohibited list (which does not include the
  documents routes). It cannot start/disconnect Stripe (owner-only, Contract 17
  §6.2) and cannot reach a sibling client's Business. **Product-owner
  attention:** under this rule an Agency actor in View As can *refund* a
  client's captured payment (same single capability + explicit confirmation as
  the client). That is the current behavior and is pinned, not endorsed; adding
  the refund route to `ViewAsProhibitedActions` is a one-line change if the
  product owner wants it.

## 7. Events (for later central Automations — `WorkflowTriggerType` untouched)

All are Laravel events dispatched **after commit**, at most once per
transition, and carry numeric ids only (no amount, no PII, no provider
reference). The tenant identity is trailing and optional so every earlier
two-argument caller keeps working.

| Event | Meaning | Carries |
|---|---|---|
| `DocumentPaymentSucceeded` | "payment received" — the one transition into `succeeded` for a payment row | `documentId, paymentId, businessId, businessLocationId, contactId` |
| `DocumentFullyPaid` | "invoice paid" — the one transition into `paid` | `documentId, businessId, businessLocationId, contactId` |
| `DocumentPaymentFailed` *(new)* | "payment failed" — the one transition into `failed`, for a payment against a still-live document | `documentId, paymentId, businessId, businessLocationId, contactId` |
| `DocumentSent`, `DocumentSigned`, `DocumentVoided`, `DocumentExpired`, `DocumentRefunded` | unchanged | unchanged |

`DocumentPaymentFailed` has no listener yet. A rolled-back finalization emits
nothing (proven).

## 8. Known limits and deliberate non-goals

* **No partial-payment or refund workflow was invented.** Deposit + balance and
  per-payment refunds are the existing Contract 17 behavior and are preserved.
* **A duplicate capture is recorded and refundable, not prevented.** If attempt
  A fails, the customer starts attempt B and pays, and A's still-open intent is
  then also completed, both captures are real money: both are recorded, the
  invoice is paid once, and the Business refunds the surplus. Cancelling A's
  intent at the provider when B starts would narrow the window further but needs
  a new provider call and is out of this lane's scope.
* **A void does not cancel the in-flight intent at the provider.** A customer
  who completes it after the void is charged; that capture is now recorded and
  refundable (§4). Cancelling the intent on void would need a provider call from
  `void()` (after commit) and is a recommended follow-up.
* No tax or compliance engine, no PDF rendering, no per-Location Stripe account.
* Reconciliation now orders oldest-`updated_at`-first and touches a still-in-
  flight attempt, so a batch rotates through every stale attempt instead of
  re-reading the first rows forever (an attempt on a void document can stay
  `created` indefinitely).

## 9. Amendments to Contract 17

1. **§7.1 Send / §11.3** — adds `resendLink` as the delivery-failure recovery
   path (§5 above).
2. **§8.3 "Move a terminal document backward"** — unchanged for the *document*;
   the *payment row* of a late capture is now recorded rather than ignored (§4).
3. **§8.3 "Duplicate a state transition"** — `failed → succeeded` is permitted
   on the same provider intent, because `failed` is not final at the provider.
4. **§10 Events** — adds `DocumentPaymentFailed`; adds tenant identity to
   `DocumentPaymentSucceeded` and `DocumentFullyPaid`.

## 10. Tests

New: `tests/Feature/Payments/InvoicePaymentCompletionTest`,
`InvoiceLifecycleAndResendTest`, `BusinessInvoiceSurfaceTest`,
`PaymentConcurrencyTest` (+ `Support/concurrent_payment_runner.php`),
`tests/Feature/Documents/DocumentLocationAclTest`,
`DocumentViewAsAuthorityTest`. Two Contract 17 tests were corrected because they
asserted the stranding behavior fixed in §4
(`PaymentWebhookAndRaceTest::test_a_terminal_document_accepts_no_inbound_transition`,
`::test_payment_success_versus_document_void`).
