<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Notifications\Usage\LowBalanceNotification;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * RFC-005 Job/Event Dispatch Completion Correction Contract §4 —
 * orchestration only. UsageWalletManager owns the durable
 * low_balance_notified_at marker and the dispatch decision; this job never
 * writes business_usage_wallets, it only resolves the recipient and sends
 * the notification. Recipient resolution mirrors SendReceiptNotification's
 * own already-proven algorithm exactly.
 *
 * Dispatched from inside UsageWalletManager's own wallet-locked
 * transaction, after commit. Inherits Base's $tries = 1 /
 * $maxExceptions = 1 unchanged — this correction claims only "at most one
 * automatic dispatch decision per episode," never exact-once external
 * delivery (contract §4).
 */
class SendLowBalanceNotification extends Base implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly int $businessId,
    ) {
    }

    public function handle(
        BusinessUsageWalletRepository $walletRepository,
        BusinessBillingContactRepository $billingContactRepository,
    ): void {
        $wallet = $walletRepository->findByBusinessId($this->businessId);

        if ($wallet === null) {
            throw new \RuntimeException("Business usage wallet for business {$this->businessId} not found.");
        }

        $contact = $billingContactRepository->findByBusinessId($this->businessId);

        if ($contact === null) {
            Log::info('Low-balance notification skipped: no billing contact configured.', ['business_id' => $this->businessId]);

            return;
        }

        if (! $contact->notification_opt_in) {
            Log::info('Low-balance notification skipped: billing contact opted out.', ['business_id' => $this->businessId]);

            return;
        }

        if ($contact->contact_user_id === null) {
            $email = $contact->contact_email;
        } else {
            $email = $contact->contactUser?->email;
        }

        if (blank($email)) {
            Log::warning('Low-balance notification skipped: no usable recipient email.', ['business_id' => $this->businessId]);

            return;
        }

        Notification::route('mail', $email)->notify(new LowBalanceNotification(
            (string) $wallet->available_balance_micro,
            (string) $wallet->auto_recharge_threshold_micro,
        ));
    }
}
