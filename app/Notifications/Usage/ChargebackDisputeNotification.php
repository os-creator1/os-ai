<?php

namespace App\Notifications\Usage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * RFC-005 Remediation #6 §11 — sent when a provider-confirmed dispute
 * withdraws funds, at most once per genuinely new DisputeChargeback
 * outcome (never for a reinstatement, never for a refund). Best-effort
 * external delivery under this codebase's existing one-attempt
 * notification convention — never claims exact-once delivery.
 */
class ChargebackDisputeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $providerDisputeId,
        public readonly string $amountMicro,
        public readonly string $currencyCode,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A payment dispute has been filed against your account')
            ->line("Dispute {$this->providerDisputeId} has withdrawn funds from your account.")
            ->line("Amount: {$this->amountMicro} micro-units ({$this->currencyCode}).")
            ->line('Billing has been suspended pending resolution of this dispute.');
    }
}
