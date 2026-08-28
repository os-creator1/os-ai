<?php

namespace App\Notifications\Usage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * RFC-005 Job/Event Dispatch Completion Correction Contract §4 — sent when
 * a wallet's available balance drops to or below its configured
 * auto-recharge threshold. Applies only to wallets with auto-recharge
 * configured (contract §4 item 2); the amounts are formatted by the caller
 * before construction, matching ReceiptAvailableNotification's own
 * already-formatted-value convention.
 */
class LowBalanceNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $availableBalanceMicro,
        public readonly string $thresholdMicro,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your account balance is low')
            ->line('Your available balance has dropped to or below your configured auto-recharge threshold.')
            ->line('This is a notice only — no action is required if auto-recharge is able to complete.');
    }
}
