# Zero-markup Telnyx SMS launch — operator guide

MotionGrove passes Telnyx's costs through at cost (0% markup). Every managed
number and related expense is paid from the **owning Business's** usage wallet.
Agency-owned numbers use the Agency-owned Business wallet; Agency-funded client
charges use the existing authorized AgencyRebill mechanism and stay on the
client Business's own ledger. BYO Telnyx is billed by Telnyx directly — none
of this billing applies to it. USD and US numbers only, at first.

## What is dynamic (never configured by hand)
- **Number prices**: read per number from Telnyx search (`cost_information.upfront_cost`,
  `monthly_cost`, `currency`), re-checked immediately before purchase, and stored
  on the number so each rental renewal charges that number's own price.
- **Message costs**: billed at an estimate per segment, then reconciled to Telnyx's final
  per-message cost (`messaging:reconcile-costs`). Inbound is billed only by that
  reconciliation.
- **Carrier extras (e.g. a $15 campaign review)**: billed only when actually incurred, with
  `messaging:charge-carrier-fee`.

## What is configured (environment variables, applied by `messaging:sync-provisioning-rates`)
Pass-through fees are a **multiplier** (1000000 = exactly the carrier's price). The command
refuses anything below 1000000 or above 2000000. Fixed fees are the carrier's published at-cost amounts in
micro-USD (1,000,000 = 1.00).

See `config/messaging.php` for every setting and the final launch report for the exact block.

## Order of operations
1. Deploy the branch; run migrations: `php artisan migrate --force`.
2. Set the environment variables (Forge → Environment), then reload config: `php artisan config:clear`.
3. Dry run: `php artisan messaging:sync-provisioning-rates --dry-run`.
4. Apply: `php artisan messaging:sync-provisioning-rates`.
5. Backfill wallets: `php artisan usage:backfill-wallets --dry-run`, then without `--dry-run`.
6. Confirm the scheduler runs (`php artisan schedule:list` shows `messaging:sweep-number-renewals`
   and `messaging:reconcile-costs`).
7. Reconcile weekly: `php artisan messaging:reconcile-costs --days=14` (report), then
   `--apply --actor-user-id=<admin id>` to settle differences.

## Unpaid numbers
Renewal warning → suspension (new outbound paused, inbound and port-out still work) → 14-day grace →
written release notice (must be confirmed delivered) → minimum notice period → an administrator's
audited release decision → authorized carrier release → reconciliation. Nothing is deleted at the
carrier automatically on day 14. While a number is held the carrier keeps billing MotionGrove for it; the
customer notices say so.
