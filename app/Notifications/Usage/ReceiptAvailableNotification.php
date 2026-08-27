<?php

namespace App\Notifications\Usage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Receipt Boundary Correction Contract §7 — sends only the Stripe-hosted
 * receipt link. No local invoice/receipt document is generated.
 */
class ReceiptAvailableNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $receiptUrl,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your receipt is available')
            ->line('Your payment was successful. Your receipt is available at the link below.')
            ->action('View Receipt', $this->receiptUrl);
    }
}
