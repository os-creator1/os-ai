<?php

namespace App\Jobs\Messaging;

use App\Jobs\Base;
use App\Models\BusinessMessagingNumber;
use App\Notifications\Messaging\NumberRenewalWarningNotification;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2's advance-warning
 * notice. Orchestration only: NumberLifecycleManager owns the durable
 * renewal_warning_sent_at marker and the dispatch decision; this job never
 * writes business_messaging_numbers, it only resolves the recipient and
 * sends the notification. Recipient resolution mirrors
 * SendLowBalanceNotification's own already-proven algorithm exactly.
 *
 * Dispatched from inside NumberLifecycleManager's own transaction, after
 * commit.
 */
class SendNumberRenewalWarning extends Base implements ShouldQueueAfterCommit
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
            Log::info('Number renewal warning skipped: number no longer exists.', ['business_messaging_number_id' => $this->numberId]);

            return;
        }

        $contact = $billingContactRepository->findByBusinessId($this->businessId);

        if ($contact === null) {
            Log::info('Number renewal warning skipped: no billing contact configured.', ['business_id' => $this->businessId]);

            return;
        }

        if (! $contact->notification_opt_in) {
            Log::info('Number renewal warning skipped: billing contact opted out.', ['business_id' => $this->businessId]);

            return;
        }

        $email = $contact->contact_user_id === null ? $contact->contact_email : $contact->contactUser?->email;

        if (blank($email)) {
            Log::warning('Number renewal warning skipped: no usable recipient email.', ['business_id' => $this->businessId]);

            return;
        }

        Notification::route('mail', $email)->notify(new NumberRenewalWarningNotification($number->phone_number, $number->next_renewal_at));
    }
}
