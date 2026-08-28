<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Notifications\Usage\AutoRechargeDisabledNotification;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * RFC-005 Job/Event Dispatch Completion Correction Contract §5 —
 * orchestration only. UsageWalletManager::recordAutoRechargeFailure() owns
 * the true->false auto_recharge_enabled transition (the durable dispatch-
 * dedup edge) and the dispatch decision; this job never writes
 * business_usage_wallets, it only resolves the recipient and sends the
 * notification. Recipient resolution mirrors SendReceiptNotification's own
 * already-proven algorithm exactly.
 *
 * Dispatched from inside UsageWalletManager's own wallet-locked
 * transaction, after commit. Inherits Base's $tries = 1 /
 * $maxExceptions = 1 unchanged — this correction claims only "at most one
 * automatic dispatch decision per disable episode," never exact-once
 * external delivery (contract §5).
 */
class SendAutoRechargeDisabledNotification extends Base implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly int $businessId,
    ) {
    }

    public function handle(
        BusinessBillingContactRepository $billingContactRepository,
    ): void {
        $contact = $billingContactRepository->findByBusinessId($this->businessId);

        if ($contact === null) {
            Log::info('Auto-recharge-disabled notification skipped: no billing contact configured.', ['business_id' => $this->businessId]);

            return;
        }

        if (! $contact->notification_opt_in) {
            Log::info('Auto-recharge-disabled notification skipped: billing contact opted out.', ['business_id' => $this->businessId]);

            return;
        }

        if ($contact->contact_user_id === null) {
            $email = $contact->contact_email;
        } else {
            $email = $contact->contactUser?->email;
        }

        if (blank($email)) {
            Log::warning('Auto-recharge-disabled notification skipped: no usable recipient email.', ['business_id' => $this->businessId]);

            return;
        }

        Notification::route('mail', $email)->notify(new AutoRechargeDisabledNotification());
    }
}
