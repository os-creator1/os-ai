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

## 3. Automatic, self-service reconciliation (ON by default)
`messaging:reconcile-pending-orders` runs every 5 minutes on the scheduler, so it works with no browser open and for every
future purchase. For each open **local** incident it runs the same guarded recovery, with backoff 2, 4, 8, 16, 30 minutes.
Overlapping runs cannot double-attempt (atomic lease), and recovery itself is idempotent, so nothing can be bought, charged,
attached or campaign-linked twice. Hold it off with `MESSAGING_AUTO_RECONCILE_ORDERS=false`; `--dry-run` always works.
**Deploying it with incident 6 still open will recover that incident on the first run** (it is verified the same way as the
manual command). Run the dry run first if you want to look before it does.

| Outcome | What happens |
|---|---|
| Carrier confirms | Number attached, approved registration linked to its campaign, voice assigned, incident resolved. The customer's page switches by itself. |
| Still pending | Retried with backoff. After `MESSAGING_RECONCILE_MAX_ATTEMPTS` (12) or `MESSAGING_RECONCILE_ESCALATE_HOURS` (6) it is **escalated**: error logged, every platform administrator is emailed + notified once, the customer is told it is being handled. |
| Carrier says failed | Recorded on the first sighting; on a second consecutive sighting the original charge is credited back **once** (idempotent ledger entry, resolved incident `[auto-refunded]`) only if: automatic refunds are on, `MESSAGING_RECONCILIATION_ACTOR_USER_ID` names a platform administrator, Telnyx lists no such number on the account, no local rows exist, exactly one committed purchase reservation matches, and the amount is within `MESSAGING_MAX_AUTO_REFUND_MICRO`. Otherwise it is escalated and nothing is credited. |

## What the customer sees (nothing to do, nothing to contact)
* Buy: the button turns into "Activating your number…" at once and cannot be double-submitted; the purchase finishes on the
  server even if they close the tab.
* Phone & messaging shows **Activating your number…** (persisted: same after refresh, new login or another device), says they
  can leave, and updates itself when the number is ready. No fake progress, no blocking spinner. A second purchase is refused
  while one is pending.
* Taking longer than expected: "our team has been alerted". Carrier failed: "payment is being returned automatically", then
  after the credit the purchase page says the payment was returned and offers a new number. A genuinely rejected order (no
  charge) keeps the existing "You have not been charged" message.

## Not automated
The "attach failed after provider success" incident stage, and a failed order whose refund safeguards do not all hold (those
are escalated to operators).
