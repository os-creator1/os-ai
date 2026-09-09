<?php

namespace App\Notifications\Usage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Customer Experience Slice 5 (brief §7) — sent to the opted-in billing
 * contact every time an automatic top-up attempt fails or needs the
 * payer's action, so a failed recharge is never silent. Carries no
 * amounts from the provider, no instrument identifiers and no internal
 * state names.
 */
class AutoRechargeFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $businessName,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Automatic top-up did not go through for ' . $this->businessName)
            ->line('We tried to top up the balance for ' . $this->businessName . ' automatically, but the payment did not go through.')
            ->line('Your balance was not changed. Paid activity continues until the available balance runs out.')
            ->line('To keep things running, check the payment method in Usage & Billing or add funds manually. After three failed attempts in a row, automatic top-up turns itself off until you switch it back on.');
    }
}
