<?php

namespace App\Jobs\Messaging;

use App\Jobs\Base;
use App\Models\BusinessMessagingNumber;
use App\Notifications\Messaging\NumberSuspendedNotification;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Phone Numbers + A2P lane — messaging contract §13.3's suspension notice.
 * Orchestration only, mirroring SendNumberRenewalWarning /
 * SendLowBalanceNotification exactly.
 */
class SendNumberSuspendedNotice extends Base implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly int $businessId,
        private readonly int $numberId,
    ) {
    }

    public function handle(BusinessBillingContactRepository $billingContactRepository): void
    {
        $number = BusinessMessagingNumber::find($this->numberId);

        if ($number === null) {
            Log::info('Number suspended notice skipped: number no longer exists.', ['business_messaging_number_id' => $this->numberId]);

            return;
        }

        $contact = $billingContactRepository->findByBusinessId($this->businessId);

        if ($contact === null) {
            Log::info('Number suspended notice skipped: no billing contact configured.', ['business_id' => $this->businessId]);

            return;
        }

        if (! $contact->notification_opt_in) {
            Log::info('Number suspended notice skipped: billing contact opted out.', ['business_id' => $this->businessId]);

            return;
        }

        $email = $contact->contact_user_id === null ? $contact->contact_email : $contact->contactUser?->email;

        if (blank($email)) {
            Log::warning('Number suspended notice skipped: no usable recipient email.', ['business_id' => $this->businessId]);

            return;
        }

        Notification::route('mail', $email)->notify(new NumberSuspendedNotification($number->phone_number, $number->grace_expires_at));
    }
}
