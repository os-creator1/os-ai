# Local number order recovery

**What happens.** Telnyx sometimes accepts a number order but answers "still pending". MotionGrove cannot tell that from a
lost order, so it records a `number_order_outcome_unknown` incident, keeps the wallet charge (committed, never refunded
automatically) and blocks a second purchase. The number then becomes Active at Telnyx a little later, but MotionGrove never
learns of it. Production incident 6 (Business 2, +17206640969, "order accepted but still pending") is this case.

**Nothing in this flow buys a number, creates a charge or refunds.** Every step reads Telnyx (GET) and the local wallet.

## 1. Inspect incident 6 (read-only)
```bash
php artisan messaging:recover-pending-number --business=2 --incident=6          # dry-run is the default
php artisan messaging:reconcile-pending-orders --dry-run                         # all open local incidents
```
The dry run prints the verified target (number, Telnyx resource id, Messaging Profile, parent order, wallet reservation)
and the price check, or the exact reason it cannot verify yet: `[pending]` (Telnyx still provisioning), `[failed]` (Telnyx
says it did not happen) or an untagged mismatch (billing, profile, account, reservation).

Checks before anything is written: Telnyx order is `success` and contains exactly this number; the number resource is
`active` on the expected Messaging Profile and Telnyx account; the Business has no identity/number yet; exactly one
committed `messaging_number_purchase` reservation exists; the price is proven from Telnyx billing records and that
reservation (one-time fee + prorated monthly rental must equal what the wallet was charged). Any doubt aborts.

## 2. Recover it (needs the owner's go-ahead)
```bash
php artisan messaging:recover-pending-number --business=2 --incident=6 --execute
```
One transaction creates the identity and number (renewal one month after the original purchase) and resolves the incident
last. Afterwards, outside the transaction: the number's Messaging Profile is linked to the Business's already-approved
10DLC campaign (the registration is only read) and voice assignment runs (both idempotent, best effort). Re-running is a
no-op ("Already reconciled").

## 3. Automatic reconciliation (off by default)
`messaging:reconcile-pending-orders` runs every 5 minutes. When `MESSAGING_AUTO_RECONCILE_ORDERS=true` it runs the same
guarded recovery for each open **local** incident, with backoff 2, 4, 8, 16, 30 minutes, and escalates (operator alerted,
`Log::error`, shown in Admin > provisioning incidents, customer told) after `MESSAGING_RECONCILE_MAX_ATTEMPTS` (12) attempts,
`MESSAGING_RECONCILE_ESCALATE_HOURS` (6) hours, or immediately when Telnyx reports the order failed. Overlapping runs cannot
double-attempt (atomic lease). Toll-free incidents stay manual.

## What the customer sees
"Confirming your number" (page refreshes itself) while pending; once resolved the number appears on the same page. If
unresolved after the escalation window: "taking longer than usual, our team has been alerted". If Telnyx failed the order:
"the carrier could not complete the order, our team is reviewing the charge". A genuinely rejected order (no charge) still
shows the existing "You have not been charged" error.

## Not automated
Refunding the charge of an order the carrier failed (needs a billing decision), and the "attach failed after provider
success" incident stage.
