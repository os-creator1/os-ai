<?php

namespace App\Notifications\Documents;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Implementation Contract 17 §8.4 / §11.3 — the payment and offer-expiry
 * reminder, emailed to the document's own frozen `recipient_email_snapshot`.
 *
 * NO SECURE LINK, AND THAT IS DELIBERATE. The plaintext access token exists
 * for exactly as long as it takes DocumentIssuedNotification to build one URL;
 * the durable record is a bcrypt hash, which is not reversible (§6.3). A
 * reminder therefore CANNOT rebuild the recipient's link, and the alternative
 * — rotating the token to mint a new one — would invalidate the link the
 * recipient already has, from a scheduled sweep they did not ask for. So a
 * reminder points back at the original email instead.
 *
 * EMAIL ONLY (§11.3): no SMS, no messaging door, no wallet, no metering.
 *
 * Deliberately NOT ShouldQueue: SendDocumentReminderEmail already provides the
 * queueing, exactly as DocumentIssuedNotification is sent synchronously from
 * inside its own job.
 */
class DocumentReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $businessName,
        private readonly string $documentTitle,
        private readonly ?string $amount,
        private readonly ?string $dueDescription,
        private readonly bool $isExpiryWarning,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->isExpiryWarning) {
            return (new MailMessage)
                ->subject("Expiring soon — {$this->documentTitle}")
                ->line("\"{$this->documentTitle}\" from {$this->businessName} expires {$this->dueDescription}.")
                ->line('You can still review it using the link in the original email.');
        }

        $message = (new MailMessage)
            ->subject("Payment reminder — {$this->documentTitle}")
            ->line("{$this->businessName} is expecting a payment of {$this->amount} for \"{$this->documentTitle}\".");

        if ($this->dueDescription !== null) {
            $message->line("It is due {$this->dueDescription}.");
        }

        return $message->line('You can pay using the link in the original email.');
    }
}
