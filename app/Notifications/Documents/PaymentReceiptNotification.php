<?php

namespace App\Notifications\Documents;

use App\Library\Mail\MailBrand;
use Illuminate\Bus\Queueable;
use App\Library\Mail\BrandedMailMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Implementation Contract 17 §8.4 / §11.3 — the payment receipt, emailed to
 * the document's own frozen `recipient_email_snapshot`.
 *
 * Carries no secure link, no client secret and no provider reference: a
 * receipt is a confirmation, not a second way into the document.
 */
class PaymentReceiptNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $businessName,
        private readonly string $documentTitle,
        private readonly string $amount,
        private readonly ?MailBrand $brand = null,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new BrandedMailMessage)
            ->subject("Payment received — {$this->documentTitle}")
            ->greeting('Payment received')
            ->viewData([
                'brand' => $this->brand,
                'icon' => 'check',
                'details' => ['Amount' => $this->amount, 'For' => $this->documentTitle, 'Paid to' => $this->businessName],
            ])
            ->line("{$this->businessName} has received your payment of {$this->amount}.")
            ->line('Thank you.');
    }
}
