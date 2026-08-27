# RFC-005 Receipt Boundary Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction that gives RFC-005 §23's `business_billing_receipts` table, §28's `UsageWalletManager` receipt write authority, and §29's `App\Jobs\Usage\SendReceiptNotification` job their first real implementation contract. Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch named in §0, exactly as every RFC-005 milestone and correction contract before it (most recently the Funding Provider-Flow correction, [`RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md`](./RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md), merged PR [#137](https://github.com/os-creator1/os-ai/pull/137)) has required.

This correction exists because the M6 static conformance audit found Receipt Boundary to be a real blocking gap: `business_billing_receipts` has no migration, model, or repository; no `SendReceiptNotification` job exists; and neither `PaymentIntentResult` nor `CheckoutSessionResult` exposes any receipt evidence. This contract is remediation #3 of 7; it does not by itself unblock M6.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-receipt-boundary-correction-contract`, in an isolated linked worktree (`../rfc-005-receipt-boundary-contract-worktree`), based on `origin/main` at `1eba17ae4112a1e5e832627d44c185a0ee3f56ca` — the Funding Provider-Flow correction's own merge commit (PR [#137](https://github.com/os-creator1/os-ai/pull/137)), confirmed via `git fetch origin main && git rev-parse origin/main` immediately before branching, and confirmed to be the merge of `agent/rfc-005-funding-provider-flow-correction` via `git log -1 --format='%H %s'`.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-receipt-boundary-correction`.**
- Confirmed before drafting: no `docs/automation/RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md` exists anywhere in `origin/main`'s history, and no `agent/rfc-005-receipt-boundary-correction` branch exists on `origin`.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - `contract_drafting_consumes: 0/2` — drafting this document consumes no correction round; only a post-merge independent-review failure requiring a redraft would consume one.
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - `M6 remains frozen` — this contract does not resume M6, does not touch any M6 conformance document, and does not narrow M6's own blocking-gap list beyond noting that Receipt Boundary's *contract* (not its implementation) now exists.
  - `AI-AUTONOMY-STATE.json` is untouched by this branch.
  - **Implementation requires a separate, explicit human instruction issued after this contract itself is human-merged** — mirroring every prior RFC-005 correction contract's own governance clause.
- This is a new, independently bounded pre-M6 correction contract — not a correction round against M1–M6's own contracts, and not a correction round against the already-merged Reservation Admission or Funding Provider-Flow contracts. Its `maximum_correction_rounds: 2` budget is its own; no counter is borrowed or altered on any other contract.
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md`.

---

## 1. Re-confirmed governing design facts (direct source audit, not trusted from the assigning prompt)

All read directly from `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` on this exact base commit.

**§23 — `business_billing_receipts`, confirmed verbatim:**

| Column | Type | Nullable | Default |
|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — |
| `ledger_entry_id` | `unsignedBigInteger`, FK `business_usage_ledger_entries.id`, `restrictOnDelete()` | No | — |
| `provider_receipt_url` | `string(2048)` | No | — |
| `provider_reference` | `string(191)` | No | — |
| `created_at` | `timestamp` | No | `now()` |

No `updated_at`. No `UNIQUE` index declared on any column beyond the implicit PK. Confirmed: §25's table inventory lists `business_billing_receipts` as `unchanged`, sole write authority `UsageWalletManager` — consistent with §28's authority table, which lists `receipts` as one of `UsageWalletManager`'s method-level responsibilities.

**§28 — confirmed:** `UsageWalletManager` is the sole write authority for wallets, ledger, reservations, rates/activations, classifications, limits, billing-status, **and receipts**. "No controller, job, or event listener ever writes to a table in §25 directly."

**§29 — confirmed:** `SendReceiptNotification` is one of thirteen named `App\Jobs\Usage\*` jobs, `ShouldQueue` + `ShouldQueueAfterCommit` where dispatched from within a request-handling transaction.

**§20/§21 — confirmed:** Stripe-only v1, behind `PaymentProviderGateway`; every outbound provider call normalized to a DTO; "no outbound Stripe call ever occurs while a database row lock is held" (unchanged, load-bearing for this correction's design, §4 below).

**Current-implementation gap, re-confirmed by direct repository search on this exact base commit (zero matches unless noted):**

```
grep -rn "business_billing_receipts" app database   → zero matches
grep -rln "SendReceiptNotification" app             → zero matches
grep -rln "receipt_url|receiptUrl|ReceiptUrl" app    → one unrelated match (App\Models\SendCampaignSMS.php — a pre-existing, unrelated legacy SMS-campaign field, not this domain)
grep -rn "latest_charge" app                        → zero matches
```

`PaymentIntentResult` (`app/Library/Usage/PaymentIntentResult.php`) fields: `providerPaymentIntentId, status, clientSecret, amountMinorUnits, currencyCode` — no receipt field.
`CheckoutSessionResult` (`app/Library/Usage/CheckoutSessionResult.php`) fields: `providerCheckoutSessionId, status, paymentStatus, redirectUrl, amountMinorUnits, currencyCode, providerCustomerId, providerPaymentIntentId, providerPaymentMethodId` — no receipt field.
`StripePaymentProviderGateway::retrievePaymentIntent()` calls `paymentIntents->retrieve($id)` with **no `expand` parameter at all**. `retrieveCheckoutSession()` expands only `payment_intent.payment_method`. Neither reaches a `Charge` object, which is where Stripe's `receipt_url` actually lives.

All five gap claims in the assigning prompt are confirmed true on this exact base commit. No claim required correction.

**One additional fact discovered by this audit, not stated in the assigning prompt, that is load-bearing for §5 below:** `UsageBillingCheckoutManager::confirmSucceeded()` calls `UsageWalletManager::creditFromFunding()` for exactly three cases — `ManualTopUp`, `AutoRecharge`, and `AddonPurchase` with `fulfillment_mode: wallet_credit` (confirmed by direct grep: `creditFromFunding(` appears at exactly three call sites in `UsageBillingCheckoutManager.php`, lines 651 and 691, the latter guarded by `$catalogRow->fulfillment_mode->value === 'wallet_credit'`). **No additional-slot-agreement code path (initial Checkout, `scheduled_renewal`, or `mid_period_increase`) ever calls `creditFromFunding()` or creates any `business_usage_ledger_entries` row** — slot agreements are Workspace-scoped capacity purchases, not Business wallet credits, and RFC-004's `workspace_plan_assignments.additional_business_slots` remains their sole authoritative entitlement value (§22). This is mechanically decisive for §5.

---

## 2. Exact provider receipt evidence source (§4 of the assigning prompt)

**Stripe object family:** the hosted receipt URL (`receipt_url`) exists only on a Stripe **Charge** object. Neither `PaymentIntent` nor `Checkout Session` carries it directly; both reach it only via expansion to their own `latest_charge`.

**Confirmed SDK support:** `composer.json` pins `stripe/stripe-php: ^7.76` (confirmed installed via `composer.lock`). `latest_charge` and multi-level dot-path `expand` (Stripe's API supports up to 4 levels of expansion per call) have been stable in the Stripe API for years; both this SDK version's method signatures already accept an arbitrary `expand` array in the params hash exactly as `retrieveCheckoutSession()` already demonstrates today (`'expand' => ['payment_intent.payment_method']`).

**Exact retrieval mechanism, locked per purpose:**

- **`AutoRecharge` (PaymentIntent-backed):** `$this->client->paymentIntents->retrieve($id, ['expand' => ['latest_charge']])`. Receipt evidence: `$paymentIntent->latest_charge->receipt_url` / `$paymentIntent->latest_charge->id`. If `latest_charge` is null (should not happen for a `succeeded` PaymentIntent, but is not assumed impossible), treat as evidence-unavailable (§3).
- **`ManualTopUp` / `AddonPurchase` (Checkout-Session-backed):** `$this->client->checkout->sessions->retrieve($id, ['expand' => ['payment_intent.latest_charge']])` — a single two-level nested expansion, well within Stripe's documented 4-level cap. Receipt evidence: `$session->payment_intent->latest_charge->receipt_url` / `->id`.

**Exact `provider_reference` rule, locked:** `provider_reference` persists the **Stripe Charge id** (`ch_...`), never the PaymentIntent id and never the Checkout Session id. Rationale: `receipt_url` is intrinsically a Charge-scoped fact in Stripe's own data model — Stripe has no separate "Receipt" API object; the Charge *is* the receipt's identity. The PaymentIntent/Checkout Session id is already durably captured elsewhere (`business_funding_attempts.provider_session_or_intent_reference`), so re-storing it as `provider_reference` would be redundant and would misname the column's own purpose. `provider_reference` uniquely and correctly identifies *which specific Charge* this receipt describes.

**Exact accounting-vs-receipt failure rule, locked (the assigning prompt's strong safety requirement):** a successfully verified payment/accounting mutation (the `creditFromFunding()` ledger write and the funding attempt's `Succeeded` transition) **never** depends on, is gated by, or can be rolled back because of receipt-evidence retrieval failure or unavailability. The receipt re-fetch call (above) is always performed **before** `confirmSucceeded()`'s locked transaction begins (identical ordering discipline to the existing Checkout-verification re-fetch, preserving §20's "no outbound Stripe call while a row lock is held" rule) and is wrapped so that **any** exception from it (`ProviderApiUnavailableException`, a malformed/missing `latest_charge`, or any other `Provider*Exception`) is caught, logged, and treated as "no receipt evidence this pass" — `confirmSucceeded()` always proceeds to credit and transition regardless.

---

## 3. Recoverability of missing receipt evidence — the exact, narrow retry mechanism (no new job)

**Mechanical fact, confirmed by direct re-read of `UsageBillingCheckoutManager::confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`/`retryFundingAttemptAsAdministrator()` (current post-Funding-Provider-Flow-correction code):** each already opens with `if ($attempt->state === Succeeded) { …; return; }` before ever reaching `confirmSucceeded()` again — the funding attempt's own state machine, not a try/catch pattern, is what prevents a double credit. This early-return branch already exists and already fires on every subsequent natural touch of an already-succeeded attempt (a browser return arriving after the webhook already confirmed it, or vice versa; `AddonPurchase`'s own idempotent `finalizeAddonPurchaseIfPending()` re-check already lives here).

**Locked design:** this correction extends that exact existing early-return branch, for every purpose, with one additional check: *if the funding attempt's ledger entry has no `business_billing_receipts` row yet, attempt the §2 receipt re-fetch once more and attach it if it now succeeds.* This requires zero new job, zero new schedule, and zero new provider-call path beyond what already exists for `ManualTopUp`/`AddonPurchase` — it is the same re-fetch, on the same call, just also consulted on a second or third natural touch.

**One genuinely new provider call, explicitly flagged (assigning-prompt §9's own required disclosure):** `AutoRecharge`'s webhook confirmation path (`confirmAttemptFromWebhook()`'s final `else` branch) currently calls `confirmSucceeded()` directly, with **no** provider re-fetch of any kind — a deliberate M3-era design choice to keep the trusted webhook path to zero additional Stripe calls for this purpose. Attaching receipt evidence for `AutoRecharge` requires **one new `retrievePaymentIntent(..., expand: ['latest_charge'])` call on this specific path**, executed before `confirmSucceeded()`, outside any lock, exception-guarded per §2. This is the one path where Receipt Boundary is a genuine, disclosed extension beyond the Funding Provider-Flow contract's own "no new provider call" description of that branch.

**Open human-review point, narrowest recommendation given (assigning-prompt §5's escape hatch, used here honestly):** `AutoRecharge` has no browser-return leg at all — it is initiated by a background job (`EvaluateBusinessAutoRecharge`) and confirmed only by webhook (or, for a *stuck, not-yet-succeeded* attempt, `retryFundingAttemptAsAdministrator()`, whose own guard explicitly rejects an already-`Succeeded` attempt and is therefore not a retry path for a missing receipt). If the one new webhook-path fetch above fails, **no further automated retry exists for that specific `AutoRecharge` charge within this correction's scope** — inventing a dedicated backfill/reconciliation job for this narrow case is explicitly out of scope (§12). **Recommendation: accept this as a bounded v1 gap.** It affects only `AutoRecharge` charges where the one additional fetch fails at the exact moment of webhook confirmation (expected rare — the PaymentIntent/Charge already exists at Stripe by this point; only a transient Stripe read-side failure would trigger it), and Stripe's own separately-configured `receipt_email` (if ever set at PaymentIntent-creation time — **not currently set anywhere in this codebase**, confirmed by grep) would remain an independent channel outside this system's control. A human reviewer may instead require a bounded reconciliation job in a future correction; this contract does not preclude that, and does not build it.

---

## 4. Receipt-producing flow matrix (mechanically derived, not assumed)

Derived entirely from §1's `creditFromFunding()` call-site audit — the schema's own `ledger_entry_id NOT NULL` FK is used mechanically, not a fabricated ledger entry.

| # | Flow | Provider object family | Creates a `business_usage_ledger_entries` row? | Ledger entry type | Receipt row? | `SendReceiptNotification`? | Governing source | Exact trigger point |
|---|---|---|---|---|---|---|---|---|
| 1 | `ManualTopUp` | Checkout Session → Charge | Yes | `PaidTopUp` | **YES** | **YES** | §1 audit; §20 (Checkout-backed) | `UsageBillingCheckoutManager::confirmSucceeded()`, immediately after `creditFromFunding()` succeeds, for this purpose |
| 2 | `AutoRecharge` | PaymentIntent → Charge | Yes | `AutoRecharge` | **YES** | **YES** | §1 audit; §20 (PaymentIntent-backed) | Same, for this purpose (§3's one new webhook-path fetch applies here) |
| 3 | `AddonPurchase` — `wallet_credit` | Checkout Session → Charge | Yes (via `creditFromFunding()`, entry type `PaidTopUp`, reused) | `PaidTopUp` | **YES** | **YES** | §1 audit (`finalizeAddonPurchaseIfPending()` line 691) | Same call site, inside `finalizeAddonPurchaseIfPending()` |
| 4 | `AddonPurchase` — `direct_deliverable` | Checkout Session → Charge | **No** — pure state-machine completion, no wallet mutation (§18, confirmed by direct code read: the `fulfillment_mode !== 'wallet_credit'` branch skips `creditFromFunding()` entirely) | — | **NO — mechanically impossible**, no `ledger_entry_id` exists to reference | **NO** | §1 audit; RFC §18 | N/A |
| 5 | Initial additional-slot agreement Checkout | Checkout Session → Charge | **No** — Workspace-scoped capacity purchase, never a Business wallet credit (§22; confirmed zero `creditFromFunding()` call sites in any slot-agreement code path) | — | **NO — mechanically impossible** | **NO** | §1 audit; RFC §22 | N/A |
| 6 | Scheduled additional-slot renewal (`charge_kind: scheduled_renewal`) | PaymentIntent → Charge | **No**, same reason as #5 | — | **NO — mechanically impossible** | **NO** | §1 audit; RFC §22 | N/A |
| 7 | Mid-period additional-slot increase (`charge_kind: mid_period_increase`) | PaymentIntent → Charge | **No**, same reason as #5 | — | **NO — mechanically impossible** | **NO** | §1 audit; RFC §22 | N/A |
| 8 | `Refund` | Charge refund (no new Checkout/PaymentIntent) | Yes (`Refund` ledger entry, an existing, unrelated mechanism) | `Refund` | **NO — human-review, narrowest recommendation given below** | **NO** in this correction's scope | RFC §23 ("Stripe-hosted receipts authoritative"); §39 does not list this as an open item, but this correction does not implement refund flows at all (§12) | N/A this correction |
| 9 | `DisputeChargeback` | Stripe-initiated clawback | Yes (`DisputeChargeback` ledger entry) | `DisputeChargeback` | **NO — same reasoning as #8** | **NO** | Same | N/A this correction |
| 10 | Any other RFC-005-defined provider-backed path | — | — | — | **NONE FOUND** — the audit found no tenth provider-backed charge path in RFC-005 beyond rows 1–9 | — | §1 audit (exhaustive `creditFromFunding()`/slot-agreement grep) | — |

**Human-review flag for rows 8–9 (narrowest interpretation applied, per the assigning prompt's own instruction):** a Stripe *refund* does not itself carry a new "payment receipt" in Stripe's data model — Stripe's hosted receipt describes the original charge, and any refund notice is a structurally distinct communication this RFC does not yet design (§23 lists refunds/disputes/receipts as four *structurally distinct* entry types, never conflated). Building a `business_billing_receipts` row for a `Refund`/`DisputeChargeback` ledger entry would require designing what "the receipt for a refund" even means — explicitly out of scope for this correction (§12: "executable provider refund/dispute handling beyond what is strictly needed to define that those flows do or do not create receipts"). **Locked recommendation: rows 8–9 do NOT create a `business_billing_receipts` row and do NOT dispatch `SendReceiptNotification` in this correction.** A human may authorize a separate future correction to design refund-receipt semantics; this contract neither builds nor precludes it.

---

## 5. Receipt cardinality / idempotency — no new `UNIQUE` constraint required

**Mechanical analysis (assigning prompt §6):** the RFC's own `business_billing_receipts` schema (§1 above) declares no `UNIQUE` index beyond the implicit PK. This contract does **not** invent one. Instead, it reuses an already-real, already-unique anchor: `business_usage_ledger_entries.id` — a genuine PK-backed row that, for every receipt-eligible flow (§4 rows 1–3), is created deterministically at most once per funding attempt (guarded by `business_usage_ledger_entries.correlation_key UNIQUE`, already schema-enforced, confirmed in `database/migrations/2026_08_16_120007_create_business_usage_ledger_entries_table.php:39`).

**Locked mechanism:** `UsageWalletManager` gains a new method (exact signature §7) that, inside one DB transaction, issues `SELECT * FROM business_usage_ledger_entries WHERE id = ? FOR UPDATE` against the **already-existing** ledger entry row (a real row lock on a real PK — not a lock on a not-yet-existing row, and not a new index), then checks `business_billing_receipts WHERE ledger_entry_id = ?` for an existing row before inserting. Every caller — the first successful confirmation (§2), a later natural retry (§3), a genuinely concurrent double-webhook race — serializes on this exact same row lock, because they all name the same `ledger_entry_id`. This gives real mutual exclusion without a new `UNIQUE` constraint:

- **Return + webhook replay converge:** both call sites resolve to the same `ledger_entry_id` (via `correlation_key` lookup, §7) and lock the same row; whichever commits first wins the "no existing receipt" check, the second sees the row and no-ops.
- **Duplicate jobs converge:** `SendReceiptNotification` (§8) is dispatched only from inside the same transaction that just created (or confirmed pre-existing) the receipt row, keyed by the receipt's own `id` — a duplicate dispatch for the same receipt id is itself idempotent at the notification layer (§8's own dedup rule), independent of this section.
- **Concurrent execution converges:** MySQL's row lock serializes concurrent transactions naming the same `ledger_entry_id`; there is no window where two transactions both observe "no receipt exists" for the same ledger entry.

**Defense-in-depth recommendation (not locked as blocking, explicitly flagged per the assigning prompt's own instruction not to sneak in a schema decision):** adding `UNIQUE (ledger_entry_id)` to `business_billing_receipts` as a schema-level backstop is a narrow, low-risk, mechanically obvious hardening that a human may choose to authorize alongside the migration in §10 — but it is **not required for correctness** under the row-lock design above, and this contract does not assume it will be added. If a human declines it, no other part of this design changes.

---

## 6. `UsageWalletManager` write-authority design (§28, assigning-prompt §7)

**Exact new method — receipt persistence (write, sole authority):**

```php
public function attachFundingReceipt(
    int $ledgerEntryId,
    int $businessId,
    string $providerReceiptUrl,
    string $providerReference,
): ?BusinessBillingReceipt
```

- Opens one `DB::transaction()`. Locks `business_usage_ledger_entries` `WHERE id = $ledgerEntryId FOR UPDATE` (§5). If no such row exists, throws (a programmer error — the caller must only ever pass a `ledger_entry_id` it already knows exists, per §7's lookup).
- Checks `BusinessBillingReceiptRepository::findByLedgerEntryId($ledgerEntryId)`. If found, returns the existing row unchanged (idempotent no-op — this is the sole convergence point for §5).
- Otherwise inserts one row: `business_id` (the caller-supplied value, validated equal to the ledger entry's own `business_id` — a defensive cross-check, throws on mismatch, never silently reassigns), `ledger_entry_id`, `provider_receipt_url`, `provider_reference`, `created_at: now()`. Returns the newly-created row.
- **Never called with an open outbound-provider call in flight** — the caller (`UsageBillingCheckoutManager`) always completes its Stripe re-fetch (§2) before invoking this method, matching §20's existing "no provider call under a lock" discipline exactly.

**Exact new method — existence check (read, for the caller to decide whether a re-fetch is worth attempting, §3):**

```php
public function hasFundingReceipt(int $ledgerEntryId): bool
```

Thin wrapper over `BusinessBillingReceiptRepository::findByLedgerEntryId()`, no lock — used only to avoid a wasted Stripe call when a receipt is already known to exist; the authoritative check remains `attachFundingReceipt()`'s own locked re-check.

**Exact widened method — resolving the ledger entry id (§7's own caller needs this; no `creditFromFunding()` behavior change):**

```php
public function findLedgerEntryIdByCorrelationKey(string $correlationKey): ?int
```

Thin wrapper over a new `BusinessUsageLedgerEntryRepository::findByCorrelationKey()` read method (§9). `creditFromFunding()`'s own signature, body, and `void` return type are **byte-for-byte unchanged** — this correction does not touch the credit path itself at all, preserving the Funding Provider-Flow contract's own just-shipped behavior exactly. `UsageBillingCheckoutManager` calls `creditFromFunding()` first (unchanged), then separately calls `findLedgerEntryIdByCorrelationKey($attempt->local_idempotency_key.':credit')` to resolve the id it needs for `attachFundingReceipt()` — the same deterministic `correlationKey` string `confirmSucceeded()`/`finalizeAddonPurchaseIfPending()` already construct today, reused verbatim, not re-derived.

**No controller, job, listener, or `UsageBillingCheckoutManager` method ever calls `BusinessBillingReceiptRepository`'s create/update methods directly** — only `UsageWalletManager::attachFundingReceipt()` does. `UsageBillingCheckoutManager` is granted **read-only** injection of `BusinessBillingReceiptRepository` (for a display/lookup need only, e.g. surfacing `hasFundingReceipt`-equivalent state) if implementation finds it needed, but never its own write call — mirroring the already-established precedent that `UsageBillingCheckoutManager` already directly injects `BusinessUsageWalletRepository` for reads today (confirmed, constructor line 127) while every wallet **write** still routes exclusively through `UsageWalletManager`.

---

## 7. `SendReceiptNotification` design (§29, assigning-prompt §8)

**Exact constructor — IDs/scalars only, per RFC §29's own convention:**

```php
public function __construct(
    private readonly int $receiptId,
) {}
```

**Queue/after-commit behavior:** `extends App\Jobs\Base`, `implements ShouldQueue`; dispatched via `dispatch(new SendReceiptNotification($receipt->id))->afterCommit()` from inside `UsageBillingCheckoutManager`'s own confirmation transaction, mirroring every other `App\Jobs\Usage\*` dispatch convention already in this codebase (`ShouldQueueAfterCommit` where dispatched from within a request-handling transaction, per §29).

**`handle()` algorithm, locked:**

1. Load `BusinessBillingReceipt::find($this->receiptId)` (via its repository). If null (should not happen — a dispatch always follows a real insert — but handled defensively), log and return; never throws into the queue's retry machinery for a receipt that no longer exists.
2. Load the receipt's own `Business` (`business_id`), then `BusinessBillingContactRepository::findByBusinessId()`.
3. **Missing billing contact:** if none exists, log at `info` level and return — no notification is sent, no error is raised. A `Business` with no configured billing contact is a valid, non-error state (§17.A: `business_billing_contacts` is created only when a billing contact is explicitly configured; it is not auto-created).
4. **`notification_opt_in` handling:** if the contact exists but `notification_opt_in === false`, log at `info` level and return — no send. This is a hard gate, checked before any recipient resolution.
5. **Recipient selection:** if `contact_email` is non-null, use it directly (the schema's own "required together with `contact_name` if `contact_user_id` is null" rule guarantees at least one addressable identity whenever a contact row exists and `contact_user_id` is null; when `contact_user_id` is set, resolve that `User`'s own email as the recipient, since `contact_email` may be null in that branch per §17.A's own nullability).
6. **Missing/unresolvable email** (a `contact_user_id` pointing at a user with no valid email — not expected under normal data, but not assumed impossible): log at `warning` level and return — never throws.
7. Send via Laravel's `Notification` facade, ad-hoc/anonymous routing: `Notification::route('mail', $email)->notify(new ReceiptAvailableNotification($receipt->provider_receipt_url))` — this works identically whether or not `contact_user_id` is set, and is the correct mechanism for a billing contact with no local `User` account at all (§17.A explicitly allows `contact_user_id: NULL`).
8. **Idempotency / duplicate-send protection:** the job is dispatched exactly once, from exactly one call site (`attachFundingReceipt()`'s own "newly created" branch in §6 — **never** from the "already existed" branch, which returns the existing row without dispatching anything). Combined with §5's row-lock convergence (only one caller ever observes "newly created" for a given `ledger_entry_id`), this makes a duplicate dispatch for the same receipt structurally impossible under normal operation; Laravel's own queue-level `ShouldBeUnique` is **not** added, since the dispatch-site guarantee already provides the real protection and an additional unique-job lock would be redundant.
9. **Send failure does not affect accounting or receipt persistence** — by construction, this job runs strictly after both have already committed (dispatched `afterCommit()`); a mail-delivery exception here can only fail the notification itself, never unwind the receipt row or the funding attempt's `Succeeded` state.
10. **Retry behavior:** standard Laravel queue retry (the app's existing default job-retry configuration, unchanged by this correction) — a transient mail-transport failure retries; a permanent failure (e.g., an invalid address) exhausts retries and lands in the existing failed-jobs mechanism, unchanged from every other job in this codebase.
11. **Content:** the notification sends **only** the Stripe-hosted `provider_receipt_url` as a link — no local invoice/receipt document is generated or attached, per RFC §23's "Stripe-hosted receipts authoritative for v1" and this correction's own explicit exclusion (§12).

**One new, narrow production path beyond the assigning prompt's own candidate list, disclosed per its own instruction not to widen scope silently:** `app/Notifications/Usage/ReceiptAvailableNotification.php` — a plain Laravel `Notification` (`use Queueable`, `via(): ['mail']`, `toMail(): MailMessage`), the concrete class `SendReceiptNotification`'s `handle()` sends. This codebase's existing Laravel notification/mail infrastructure (`app/Notifications/*`, e.g. `TopupNotification.php`, `WelcomeEmailNotification.php`) is fully functional and configured — confirmed by direct read — but **no `App\Jobs\Usage\*` job has ever sent through it yet**; the only prior attempt at a Usage-domain notification, `SendSlotAgreementPriceChangeNotice.php`, explicitly documents in its own docblock that it stops at a `Log::info()` call because "no notification-delivery infrastructure exists in this codebase yet" for this domain. That statement is correct for the *Usage domain's own wiring*, not for Laravel's underlying notification/mail system itself, which this correction is the first to actually use. Added to §11's allowlist.

---

## 8. DTO / gateway boundary — exact additions (§9 of the assigning prompt)

All additions are new, trailing, nullable/optional constructor parameters — confirmed backward-compatible by direct grep of every construction site of both DTOs (`app/Library/Usage/FakePaymentProviderGateway.php`, `app/Library/Usage/StripePaymentProviderGateway.php`, `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php`, `tests/Feature/Usage/TopUpStateMachineTest.php` — exactly four sites, none broken by an appended optional parameter).

- **`PaymentIntentResult`** — add `public ?string $receiptUrl = null, public ?string $receiptChargeId = null` (trailing, both nullable).
- **`CheckoutSessionResult`** — add the same two trailing nullable fields.
- **`PaymentProviderGateway`** — no interface method signature changes; `retrievePaymentIntent()`/`retrieveCheckoutSession()` keep their existing signatures, now internally requesting the wider `expand` (§2) and populating the two new DTO fields. `createOffSessionPaymentIntent()`/`createCheckoutSession()` are **unchanged** — receipt evidence is never available at creation time (a Charge does not exist until the payment actually completes), so no new field is ever populated on the *create* path, only on *retrieve*.
- **`StripePaymentProviderGateway`** — `retrievePaymentIntent()` gains `['expand' => ['latest_charge']]`; `retrieveCheckoutSession()`'s existing expand array gains `'payment_intent.latest_charge'` alongside its current `'payment_intent.payment_method'` (both expansions requested in the same call — Stripe's API permits multiple expand paths per request). Both map `latest_charge->receipt_url`/`->id` into the two new DTO fields when present, `null` when `latest_charge` is absent or the object is not yet in a state where a Charge exists.
- **`FakePaymentProviderGateway`** — gains a deterministic way for a test to register receipt evidence for a given fake PaymentIntent/Checkout Session (exact shape: extend the existing `paymentIntentOutcomes`/`checkoutSessionOutcomes` registration arrays, already used for other per-call outcome overrides, with two new optional keys, `receiptUrl`/`receiptChargeId`; absent keys default to a deterministic fake value, e.g. `'https://fake.stripe.test/receipts/...'` / `'ch_fake_...'`, so an existing test that does not care about receipts continues to pass unchanged with zero new setup).

**Provider-flow correction invariants explicitly preserved, re-confirmed unaffected by every change above:**

- `ManualTopUp`/`AddonPurchase` remain Checkout-Session-backed; `AutoRecharge` remains PaymentIntent-backed — this correction changes only what is *read* from the already-retrieved object, never which object type is created for which purpose.
- The additional-slot agreement's initial Checkout Session still requests `setup_future_usage: 'off_session'` exactly as before — untouched by this correction, and never receipt-relevant (§4 row 5).
- No silent payment-instrument persistence and no silent auto-recharge enablement — this correction touches no instrument or auto-recharge-enablement code path at all.
- The one new provider call this correction introduces (§3, `AutoRecharge`'s webhook path) is exhaustively disclosed there; no other path gains an undisclosed new call.

---

## 9. Model / repository / migration — exact derivation (§10 of the assigning prompt)

**Required, following the exact `business_usage_addon_purchases` precedent (`app/Models/BusinessUsageAddonPurchase.php`, its repository pair, and `database/migrations/2026_08_20_150006_create_business_usage_addon_purchases_table.php`) column-for-column:**

- **Migration** `database/migrations/<next-date>_create_business_billing_receipts_table.php` — creates exactly the six columns in §1's table, `$table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete()`, `$table->foreign('ledger_entry_id')->references('id')->on('business_usage_ledger_entries')->restrictOnDelete()`, `$table->timestamp('created_at')->useCurrent()`, an index on `business_id` (read-path convenience, matching the addon-purchases precedent), and — **only if separately human-authorized per §5's defense-in-depth note** — `$table->unique('ledger_entry_id')`. No other column, index, or constraint.
- **Model** `app/Models/BusinessBillingReceipt.php` — `protected $table = 'business_billing_receipts'; public $timestamps = false;` (no `updated_at`, matching `BusinessUsageAddonPurchase`'s own pattern exactly), `$fillable = ['business_id', 'ledger_entry_id', 'provider_receipt_url', 'provider_reference', 'created_at']`, `belongsTo(Business::class)` and `belongsTo(BusinessUsageLedgerEntry::class, 'ledger_entry_id')` relations.
- **Repository contract** `app/Repositories/Contracts/BusinessBillingReceiptRepository.php extends BaseRepository` — `findById(int $id): ?BusinessBillingReceipt`, `findByLedgerEntryId(int $ledgerEntryId): ?BusinessBillingReceipt`, `create(array $attributes): BusinessBillingReceipt`. No `update()` method — the table is create-only, matching its own lack of `updated_at`.
- **Eloquent implementation** `app/Repositories/Eloquent/EloquentBusinessBillingReceiptRepository.php` — direct implementation, no deviation from the established per-table pattern.
- **`AppServiceProvider` binding** — one new line in the existing repository-binding array: `\App\Repositories\Contracts\BusinessBillingReceiptRepository::class => \App\Repositories\Eloquent\EloquentBusinessBillingReceiptRepository::class,` alongside the existing `BusinessUsageAddonPurchaseRepository` binding.
- **One narrow addition to an existing contract**, mechanically required by §6/§7 and not part of the assigning prompt's own candidate list — disclosed per its own instruction: `BusinessUsageLedgerEntryRepository` (existing file, `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php`, and its Eloquent implementation) gains one new **read-only** method, `findByCorrelationKey(string $correlationKey): ?BusinessUsageLedgerEntry` — a straightforward `WHERE correlation_key = ?` lookup against the column already confirmed `UNIQUE` (§5). This does not touch the "append-only, no update()/delete()" contract's own existing shape; it adds one more read method alongside `create()`/`sumCommittedAmountForFeature()`.

No column, index, or table beyond what is listed above is authorized. No unrelated schema modification.

---

## 10. Test ownership — exact paths (§11 of the assigning prompt)

**Reused existing files (new test methods added to each, no new file):**

- `tests/Feature/Usage/TopUpStateMachineTest.php` — successful `ManualTopUp` receipt: `attachFundingReceipt()` is called with the correct `ledger_entry_id`/`business_id`/`provider_reference` (Charge id, never the Checkout Session id)/`provider_receipt_url`; `SendReceiptNotification` is dispatched exactly once, `afterCommit()`, with the correct receipt id.
- `tests/Feature/Usage/WebhookAmountCurrencyCustomerMismatchTest.php` or a sibling `AutoRecharge`-scoped file (implementation-time: reuse whichever existing file already owns a successful `AutoRecharge` webhook confirmation, per the Funding Provider-Flow contract's own already-established re-scoping of these fixtures to `AutoRecharge`) — successful `AutoRecharge` receipt, including the one new webhook-path provider call (§3), asserted via the Fake gateway's own call-recording.
- `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` — `wallet_credit` add-on purchase produces a receipt; `direct_deliverable` add-on purchase produces **no** receipt row and **no** `SendReceiptNotification` dispatch (the mechanically-impossible-by-schema case, §4 row 4, asserted directly as a negative).
- `tests/Feature/Usage/FundingAttemptExactlyOnceWalletCreditTest.php` — extended: a duplicate webhook/return replay for an already-succeeded attempt never creates a second `business_billing_receipts` row and never dispatches a second `SendReceiptNotification`, exercising §5's row-lock convergence directly alongside its existing exactly-once-credit proof.
- `tests/Feature/Usage/RedirectBeforeWebhookConfirmationTest.php` — extended: if the browser-return confirmation's own receipt fetch fails (simulated via the Fake gateway), accounting still succeeds (existing assertion, unaffected) **and** the subsequent webhook confirmation's own early-return branch (§3) successfully attaches the receipt that the first pass missed — the direct proof of §3's retry mechanism.
- `tests/Feature/Usage/FakePaymentProviderGatewayTest.php` — the new receipt-evidence registration keys (§8) behave deterministically: registered values are returned verbatim; an unregistered outcome still returns the fake's own default receipt fields (never `null` unless the test explicitly registers a "no evidence" outcome for the missing-evidence proof below).
- `tests/Feature/Usage/CrossBusinessPaymentIsolationTest.php` — extended: a receipt row's `business_id` always matches its ledger entry's own `business_id`; `attachFundingReceipt()` rejects (throws) a mismatched `business_id` argument (§6's defensive cross-check), asserted directly.

**New test file required — no existing file honestly owns this behavior:**

- `tests/Feature/Usage/ReceiptBoundaryTest.php` — the receipt-boundary-specific proofs that do not naturally fit any existing file's own scope:
  - exact `business_billing_receipts` schema (columns, types, nullability, the two FKs' `restrictOnDelete()`, absence of `updated_at`) — a schema test mirroring `BusinessUsageAddonPurchaseSchemaTest.php`'s own established pattern.
  - receipt write authority boundary: a mechanical source-scan test (mirroring §24's existing "no raw query outside its owning manager/repository" boundary test) asserting no file under `app/Http`, `app/Jobs`, `app/Listeners`, or `UsageBillingCheckoutManager.php` itself calls `BusinessBillingReceiptRepository::create()`/`update()` directly — only `UsageWalletManager.php` does.
  - accounting succeeds even when receipt evidence retrieval is temporarily unavailable — for all three receipt-eligible purposes, simulated via a Fake-gateway-thrown `ProviderApiUnavailableException` on the receipt-evidence expand call specifically, asserting the funding attempt still reaches `Succeeded` and the wallet is still credited, with zero receipt row created that pass.
  - notification opt-out: `notification_opt_in = false` on the billing contact — receipt row is still created (accounting/evidence persistence is independent of notification preference), but `SendReceiptNotification` sends nothing (asserted via a mail/notification fake, zero mail sent).
  - missing billing contact: no `business_billing_contacts` row exists for the Business at all — receipt row still created, `SendReceiptNotification` handles it as a clean no-op (no exception, no mail sent).
  - duplicate job/send idempotency: `SendReceiptNotification` dispatched twice for the same receipt id (simulated directly, bypassing the dispatch-site guarantee, to prove the job itself is also safe if ever double-dispatched by an operational mistake) sends at most one mail.
  - tenant/Business isolation: a receipt for Business A is never visible/attachable via a lookup scoped to Business B (extends the `CrossBusinessPaymentIsolationTest.php` proof above with a receipt-specific angle where a dedicated new-file assertion reads more naturally than an addition to that file).
  - `FakePaymentProviderGateway` deterministic receipt behavior beyond what `FakePaymentProviderGatewayTest.php` itself owns — an end-to-end proof that a real `UsageBillingCheckoutManager` confirmation call, using the Fake, produces a receipt row with exactly the Fake's registered `receiptUrl`/`receiptChargeId`.
  - no legacy `invoices` table reuse: a direct assertion (or source-scan) that no code path in this correction ever references the legacy `invoices` table.
  - no local receipt/invoice document generation: a direct assertion that `provider_receipt_url` is always the verbatim Stripe-returned URL, never a locally-constructed one, and that no local PDF/HTML receipt-rendering code is introduced anywhere in this correction's diff.
  - no live Stripe network call in automated tests: the entire file, like every other `tests/Feature/Usage/*` file, binds `FakePaymentProviderGateway` via `app()->instance(PaymentProviderGateway::class, ...)` and never `StripePaymentProviderGateway` — mechanically enforced by the same convention every existing Usage test already follows.

No test file beyond the eight reused files and the one new file above is authorized. Implementation must not create a second new file "for convenience" without a further human-authorized correction.

---

## 11. Explicit exclusions (§12 of the assigning prompt, restated as a lock)

Out of scope for this correction, confirmed by direct source audit that none of the following is required to make Receipt Boundary itself correct:

- Job/Event Dispatch Completion work except `SendReceiptNotification` itself.
- `BusinessFundingAttemptSucceeded` / `BusinessFundingAttemptFailed` event dispatch.
- `SendLowBalanceNotification` / `SendAutoRechargeDisabledNotification`.
- `ExpireStaleUsageReservations` scheduling.
- Admin Usage Billing Surface.
- `ManualCredit` / `PromotionalCredit` / `UsageChargeReversal` / `CorrectionReversal` admin surface.
- Executable provider refund/dispute handling beyond §4 rows 8–9's own narrow "does this create a receipt" determination (locked: no).
- Add-on HTTP routes/controllers/views.
- M6 conformance docs.
- Deployment docs.
- Tag work.
- Conversations pilot activation.
- Tax/VAT implementation.
- Legacy `invoices` table changes.
- A dedicated receipt-evidence backfill/reconciliation job (§3's disclosed, human-reviewable gap).
- `UNIQUE (ledger_entry_id)` schema hardening (§5's disclosed, human-reviewable optional addition — not assumed, not built unless separately authorized).

---

## 12. Future implementation allowlist — mechanically derived (§13 of the assigning prompt)

**Production paths (11 — every candidate in the assigning prompt's own list evaluated; two genuinely necessary paths added beyond it, both disclosed above with rationale; none of the prompt's candidates found unnecessary):**

1. `database/migrations/<next-date>_create_business_billing_receipts_table.php` — new.
2. `app/Models/BusinessBillingReceipt.php` — new.
3. `app/Repositories/Contracts/BusinessBillingReceiptRepository.php` — new.
4. `app/Repositories/Eloquent/EloquentBusinessBillingReceiptRepository.php` — new.
5. `app/Providers/AppServiceProvider.php` — one new binding line.
6. `app/Library/Usage/UsageWalletManager.php` — `attachFundingReceipt()`, `hasFundingReceipt()`, `findLedgerEntryIdByCorrelationKey()` added; `creditFromFunding()` unchanged.
7. `app/Library/Usage/UsageBillingCheckoutManager.php` — the three purpose-aware confirmation methods gain the §2/§3 receipt-fetch-and-attach sequence; constructor gains read-only `BusinessBillingReceiptRepository` injection if implementation finds a display need (§6).
8. `app/Library/Usage/Contracts/PaymentProviderGateway.php` — no signature change (§8); doc-comment update only, if any.
9. `app/Library/Usage/StripePaymentProviderGateway.php` — `retrievePaymentIntent()`/`retrieveCheckoutSession()` expand widened; both DTO-mapping call sites populate the two new fields.
10. `app/Library/Usage/FakePaymentProviderGateway.php` — deterministic receipt-evidence registration (§8).
11. `app/Library/Usage/PaymentIntentResult.php` and `app/Library/Usage/CheckoutSessionResult.php` — two new trailing nullable fields each.
12. `app/Jobs/Usage/SendReceiptNotification.php` — new.
13. `app/Notifications/Usage/ReceiptAvailableNotification.php` — new, disclosed in §7, not in the assigning prompt's own candidate list.
14. `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` and `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php` — one new read-only method each, disclosed in §9, not in the assigning prompt's own candidate list.

*(Numbered for reference; several are file **pairs**, giving 17 actual file paths — the exact count is re-verified mechanically at implementation-contract time via a grep-based diff-scope audit, following every prior RFC-005 correction's own established discipline.)*

**Test/support paths — 9, per §10 above:** the eight reused existing files plus `tests/Feature/Usage/ReceiptBoundaryTest.php`.

No production or test path beyond these two lists is authorized without a further human-authorized correction round.

---

## 13. Future implementation gates (§14 of the assigning prompt, locked verbatim)

```
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan migrate:fresh --env=testing
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Unit/Usage tests/Feature/Usage
git diff --check
```

Only `ultimatesms_testing` may be used (confirmed via `.env.testing`'s `DB_DATABASE`, per every prior RFC-005 correction's own established safety check). No real Stripe credentials. No live Stripe network request — every test binds `FakePaymentProviderGateway` (§10's own "no live Stripe network call" test proves this mechanically, not just by convention).

---

## 14. Contract-branch validation

Run immediately before commit, on this docs-only branch:

```
git status --short
git diff --check
git diff --name-only origin/main...HEAD
```

The only changed tracked path must be `docs/automation/RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md`. Confirmed below in the final report.

---

## 15. Unresolved human decisions carried forward from this contract

1. **§3 — `AutoRecharge`'s one new webhook-path provider call**, and the narrow, undisclosed-in-RFC gap where its own single receipt-fetch attempt failing leaves no further automated retry in this correction's scope. Recommendation: accept as a bounded v1 gap; no job invented.
2. **§4 rows 8–9 — `Refund`/`DisputeChargeback` never produce a receipt in this correction.** Recommendation: correct as the narrowest interpretation; a future correction may design refund-receipt semantics separately if a human requires it.
3. **§5/§9 — whether to additionally authorize `UNIQUE (ledger_entry_id)`** on `business_billing_receipts` as schema-level defense-in-depth. Recommendation: not required for correctness (the row-lock design already provides it); a human may add it at implementation-contract time with zero design impact elsewhere.

No other open item was found; every other question the assigning prompt raised was resolved directly against RFC-005's own text or this repository's own current, directly-read implementation.

---

*This contract authorizes drafting review only. Implementation on `agent/rfc-005-receipt-boundary-correction` requires a separate, explicit human instruction issued after this document is human-merged.*
