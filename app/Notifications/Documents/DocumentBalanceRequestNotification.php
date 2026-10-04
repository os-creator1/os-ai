<?php

namespace App\Notifications\Documents;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Contract 17B §3/§7 — the automatic balance payment request: "a payment of
 * {amount} for {title} is due", with the freshly minted secure link and the
 * pay-section anchor.
 *
 * Like DocumentIssuedNotification, the plaintext token exists only to build
 * this one URL and this is deliberately NOT ShouldQueue: the encrypted
 * SendDocumentBalanceRequestEmail job already provides the queueing, and a
 * queueable notification would serialize the token a second time, unencrypted.
 *
 * The amount and the due date are the frozen schedule row, already formatted
 * by the job (due date in the Business timezone). No card data, no account
 * data and no legal claim; every interpolated value is escaped by the mail
 * template.
 */
class DocumentBalanceRequestNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $documentUid,
        private readonly string $plaintextToken,
        private readonly string $businessName,
        private readonly string $documentTitle,
        private readonly string $amount,
        private readonly string $dueDate,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("A payment of {$this->amount} for “{$this->documentTitle}” is due")
            ->line("{$this->businessName} is requesting your payment of {$this->amount} for “{$this->documentTitle}”.")
            ->line("Due date: {$this->dueDate}.")
            ->action('Pay now', $this->payUrl())
            ->line('This link is personal to you. Please do not forward it.');
    }

    private function payUrl(): string
    {
        return route('public.documents.show', [
            'uid' => $this->documentUid,
            'token' => $this->plaintextToken,
        ]) . '#pay';
    }
}
