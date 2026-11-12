<?php

namespace App\Notifications\Messaging;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to platform administrators when an unconfirmed number purchase could not be settled automatically (carrier
 * still pending after the retry window, a verification mismatch, or a failed order that could not be refunded safely).
 * The customer has been told it is being handled and does not need to do anything; this is the operators' prompt.
 */
class NumberOrderEscalatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $incidentId,
        public readonly int $businessId,
        public readonly string $phoneNumber,
        public readonly string $outcome,
        public readonly string $detail,
        public readonly int $attempts,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Number order needs attention: incident #{$this->incidentId}")
            ->line("A customer's local number purchase could not be settled automatically after {$this->attempts} attempt(s).")
            ->line("Incident #{$this->incidentId}, Business #{$this->businessId}, number {$this->phoneNumber}. Last finding ({$this->outcome}): {$this->detail}")
            ->line('The customer was told it is being handled and does not need to contact support. Nothing was bought again and no further charge was made.')
            ->line("Inspect: php artisan messaging:recover-pending-number --business={$this->businessId} --incident={$this->incidentId}");
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'number_order_escalated',
            'incident_id' => $this->incidentId,
            'business_id' => $this->businessId,
            'phone_number' => $this->phoneNumber,
            'outcome' => $this->outcome,
            'detail' => $this->detail,
            'attempts' => $this->attempts,
        ];
    }
}
