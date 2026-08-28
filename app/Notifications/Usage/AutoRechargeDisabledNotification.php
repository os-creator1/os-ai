<?php

namespace App\Notifications\Usage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * RFC-005 Job/Event Dispatch Completion Correction Contract §5 — sent
 * exactly once per system-disable episode, when three consecutive
 * auto-recharge failures (Failed or RequiresAction outcomes) disable
 * auto-recharge automatically. Never sent for a deliberate owner/admin
 * disable via configureAutoRecharge(enabled: false, ...).
 */
class AutoRechargeDisabledNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Auto-recharge has been disabled')
            ->line('Auto-recharge was automatically disabled after three consecutive failed recharge attempts.')
            ->line('Your configured threshold, amount, and monthly cap have been preserved. Re-enable auto-recharge at any time to resume automatic top-ups.');
    }
}
