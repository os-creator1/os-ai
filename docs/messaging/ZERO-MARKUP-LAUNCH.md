# Telnyx SMS launch — operator guide

Owner-approved pricing policy (USD, US-only managed messaging at first):

| Item | Customer price |
|---|---|
| Outbound SMS (local / toll-free) | $0.00747 per billable segment + actual Telnyx carrier surcharge |
| Inbound SMS (local / toll-free) | $0.00747 per billable segment + actual surcharge |
| Outbound MMS | $0.02200 per billable part + actual surcharge |
| Inbound MMS, local number | $0.01650 per part + actual surcharge |
| Inbound MMS, toll-free number | $0.02000 per part + actual surcharge |
| Numbers, rental, 10DLC and carrier registration | Telnyx's actual cost, 0% markup |
| Toll-free verification | $0 |

Everything is paid from the **owning Business's** usage wallet. Agency-owned numbers use the
Agency-owned Business wallet; Agency-funded client charges use the existing authorized AgencyRebill
mechanism and stay on the client Business's own ledger. **BYO Telnyx is billed by Telnyx directly** —
none of this billing applies to it. 

## What is dynamic (never configured by hand)
- **Number prices**: read per number from Telnyx search (`cost_information`), re-checked
  immediately before purchase, stored on the number so each renewal charges its own price.
  Telnyx's pricing page says SMS/MMS capability on a number adds **$0.10/month**. It is added to the
  number's monthly price as ONE combined amount (`MESSAGING_NUMBER_SMS_ADDON_MONTHLY_COST_MICRO`,
  default 100000) — never as a second line. **Verify on the pilot invoice**: if Telnyx's
  `cost_information.monthly_cost` already includes it, set that variable to `0`.
- **Message charges** (`messaging:reconcile-costs`, run hourly with `--apply`): the outbound send holds
  retail base × parts plus an estimated surcharge (`MESSAGING_ESTIMATED_CARRIER_SURCHARGE_MICRO`, per
  part, headroom only; a send is refused when the wallet cannot cover it). Once Telnyx finalises the
  message, it settles to **retail base × billable parts + the actual surcharge** by idempotent
  debits/credits. Inbound is billed by the same step. The retail base is never replaced by Telnyx's cost.
- **Carrier extras (e.g. a campaign review)**: billed only when actually incurred, with
  `messaging:charge-carrier-fee`.

## Verified Telnyx assumptions (GET /v2/messages/{id}, `data` envelope)
Read: `parts` (billable parts), `completed_at` (cost is trusted only once set), `cost.amount/currency`,
`cost_breakdown.carrier_fee.amount` (the surcharge), `cost_breakdown.rate.amount` (Telnyx's base rate),
`type` (SMS/MMS) and `direction`. If `carrier_fee` is absent the surcharge is `cost − rate`; if only a
total exists, or the figures contradict (`cost ≠ rate + carrier_fee`), the message becomes a visible
**incident** in `messaging_cost_reconciliations` (`status = 'incident'`, `incident_code`) — never a guess.
**Unverified until the first pilot messages are checked against the invoice**: that `carrier_fee` is the
message-level total (not per part), and that cost arrives asynchronously within hours.
Messages Telnyx did not charge for (cost 0) are not billed.

## Automatic reconciliation and exceptions
Safe to run unattended: settlements are idempotent (stable per-message revision keys), retries are
bounded (`MESSAGING_RECONCILIATION_MAX_ATTEMPTS`, default 12, backing off), and a single settlement above
`MESSAGING_RECONCILIATION_MAX_AUTO_ADJUSTMENT_MICRO` (default $1.00) becomes an incident. The command exits
non-zero while incidents exist and logs each one at `error`. Credits are recorded against
`MESSAGING_RECONCILIATION_ACTOR_USER_ID` (a platform administrator). A later Telnyx billing adjustment
is settled once by its own delta (`--recheck-days`, default schedule 3 days). Wallet shortfalls retry,
then become incidents.

## Real provisioning is pilot-restricted (default CLOSED)
Real number purchases and registrations are refused for every Business unless
`MESSAGING_PILOT_BUSINESS_IDS` lists it (comma-separated ids) or `MESSAGING_PROVISIONING_OPEN_TO_ALL=true`.
Other customers see that setup is not available yet. The existing switches
`MESSAGING_MANAGED_ENABLED` / provisioning-enabled remain required as well.

## Registration and campaign charges
A held fee is released only on a definite failure. If the carrier outcome is UNCONFIRMED (a number order
still pending, a brand/campaign accepted without an id) the charge is kept and the Business is blocked from
retrying until an operator resolves the incident, so the platform never absorbs a real carrier cost and a
customer is never charged twice. Credit it back by hand only if Telnyx confirms nothing was created.

## Funding fees (Stripe) — NOT implemented as a surcharge
Do not add an unverified payment surcharge: card-surcharging is restricted by card-network rules and by law
in some US states and for some card types. Compliant options for the owner to choose between, with counsel:
(1) price the wallet-funding fee into the retail rates above; (2) a minimum top-up amount so the fixed fee
is amortised; (3) a clearly disclosed, jurisdiction-checked surcharge only where Stripe and the card
networks permit it; (4) offer ACH / bank debit at lower processing cost. No funding fee is charged today.

## Order of operations
1. Deploy; `php artisan migrate --force`.
2. Set the environment variables (Forge), then `php artisan config:clear`.
3. `php artisan messaging:sync-provisioning-rates --dry-run`, then without `--dry-run`.
4. `php artisan usage:backfill-wallets --dry-run`, then without `--dry-run`.
5. Confirm `php artisan schedule:list` shows `messaging:sweep-number-renewals` and `messaging:reconcile-costs`.

## Unpaid numbers
Renewal warning → suspension (new outbound paused, inbound and port-out still work) → 14-day grace →
written release notice (must be confirmed delivered) → minimum notice period → an administrator's
audited release decision → authorized carrier release → reconciliation. Nothing is deleted at the
carrier automatically on day 14. While a number is held the carrier keeps billing MotionGrove for it; the
customer notices say so.
