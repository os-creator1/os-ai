<?php

namespace App\Jobs\Messaging;

use App\Jobs\Base;
use App\Library\Messaging\NumberLifecycleManager;
use App\Models\BusinessMessagingNumber;
use App\Notifications\Messaging\NumberReleaseNoticeNotification;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Phone Numbers + A2P lane — messaging contract §13.3: "the transition
 * from suspended to released must be ... preceded by notification." This
 * is that notification. Orchestration only, mirroring
 * SendNumberRenewalWarning / SendLowBalanceNotification exactly.
 *
 * Review correction: this job is now the ONLY writer of confirmed
 * delivery/failure — NumberLifecycleManager::sendReleaseNotice() merely
 * dispatches it. Every exit path calls back into the manager with a
 * definitive delivered/failed outcome, so a release decision can never
 * be made on the strength of a notice that was only ever queued, never
 * actually delivered.
 */
class SendNumberReleaseNotice extends Base implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly int $businessId,
        private readonly int $numberId,
    ) {
    }

    public function handle(BusinessBillingContactRepository $billingContactRepository, NumberLifecycleManager $lifecycle): void
    {
        $number = BusinessMessagingNumber::find($this->numberId);

        if ($number === null) {
            Log::info('Number release notice skipped: number no longer exists.', ['business_messaging_number_id' => $this->numberId]);

            return;
        }

        $contact = $billingContactRepository->findByBusinessId($this->businessId);

        if ($contact === null) {
            Log::info('Number release notice failed: no billing contact configured.', ['business_id' => $this->businessId]);
            $lifecycle->recordReleaseNoticeDeliveryFailure($number, 'No billing contact configured.');

            return;
        }

        if (! $contact->notification_opt_in) {
            Log::info('Number release notice failed: billing contact opted out.', ['business_id' => $this->businessId]);
            $lifecycle->recordReleaseNoticeDeliveryFailure($number, 'Billing contact opted out of notifications.');

            return;
        }

        $email = $contact->contact_user_id === null ? $contact->contact_email : $contact->contactUser?->email;

        if (blank($email)) {
            Log::warning('Number release notice failed: no usable recipient email.', ['business_id' => $this->businessId]);
            $lifecycle->recordReleaseNoticeDeliveryFailure($number, 'No usable recipient email address.');

            return;
        }

        try {
            Notification::route('mail', $email)->notify(new NumberReleaseNoticeNotification($number->phone_number));
        } catch (\Throwable $e) {
            Log::error('Number release notice delivery failed.', ['business_id' => $this->businessId, 'exception' => $e->getMessage()]);
            $lifecycle->recordReleaseNoticeDeliveryFailure($number, 'Delivery threw: ' . $e->getMessage());

            throw $e;
        }

        $lifecycle->recordReleaseNoticeDelivered($number);
    }
}
