<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Notifications\Usage\ChargebackDisputeNotification;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * RFC-005 Remediation #6 §11/§26 — orchestration only, mirroring
 * SendReceiptNotification's/SendLowBalanceNotification's own established
 * recipient-resolution algorithm exactly. Dispatched from
 * ProcessPaymentProviderEvent at most once per genuinely new
 * DisputeChargeback outcome, after the owning transaction commits, never
 * for a reinstatement, never for a refund. Inherits Base's $tries = 1 /
 * $maxExceptions = 1 unchanged — this correction claims only "at most one
 * automatic dispatch decision," never exact-once external delivery.
 */
class SendChargebackDisputeNotification extends Base implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly int $ledgerEntryId,
    ) {
    }

    public function handle(
        BusinessUsageLedgerEntryRepository $ledgerRepository,
        BusinessBillingContactRepository $billingContactRepository,
    ): void {
        $ledgerEntry = $ledgerRepository->findById($this->ledgerEntryId);

        if ($ledgerEntry === null) {
            throw new \RuntimeException("Ledger entry {$this->ledgerEntryId} not found.");
        }

        $contact = $billingContactRepository->findByBusinessId((int) $ledgerEntry->business_id);

        if ($contact === null) {
            Log::info('Chargeback dispute notification skipped: no billing contact configured.', ['business_id' => $ledgerEntry->business_id]);

            return;
        }

        if (! $contact->notification_opt_in) {
            Log::info('Chargeback dispute notification skipped: billing contact opted out.', ['business_id' => $ledgerEntry->business_id]);

            return;
        }

        if ($contact->contact_user_id === null) {
            $email = $contact->contact_email;
        } else {
            $email = $contact->contactUser?->email;
        }

        if (blank($email)) {
            Log::warning('Chargeback dispute notification skipped: no usable recipient email.', ['business_id' => $ledgerEntry->business_id]);

            return;
        }

        $currencyCode = (string) DB::table('currencies')->where('id', $ledgerEntry->currency_id)->value('code');

        Notification::route('mail', $email)->notify(new ChargebackDisputeNotification(
            (string) $ledgerEntry->provider_reference,
            (string) $ledgerEntry->gross_amount_micro,
            $currencyCode,
        ));
    }
}
