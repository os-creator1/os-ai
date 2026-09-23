<?php

namespace App\Notifications\Documents;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Implementation Contract 17 §11.3 — the emailed secure link. Email is the
 * canonical V1 delivery path; there is no SMS, no messaging door and no
 * wallet side effect anywhere in this slice.
 *
 * Mirrors ClientInvitationNotification exactly: the plaintext token exists
 * only for the lifetime of building this one URL. It is never persisted
 * (business_documents stores only access_token_hash), never logged, and
 * never appears in an exception message.
 *
 * Deliberately NOT ShouldQueue. SendDocumentLinkEmail — a Base-extending,
 * ShouldQueueAfterCommit, ShouldBeEncrypted job — already provides the
 * queueing, and its payload is the only place the plaintext token is ever
 * persisted, encrypted. Making this notification queueable too would
 * serialize the token a SECOND time, into an ordinary unencrypted
 * notification payload, which is exactly what §6.3 forbids. It is therefore
 * sent synchronously from inside that job.
 *
 * The copy makes NO legal claim. It does not describe the signature as
 * qualified, advanced, identity-verified or legally sufficient (§6.5).
 */
class DocumentIssuedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $documentUid,
        private readonly string $plaintextToken,
        private readonly string $businessName,
        private readonly string $documentTitle,
        private readonly bool $requiresSignature,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $action = $this->requiresSignature ? 'Review and sign' : 'View document';

        return (new MailMessage)
            ->subject("{$this->businessName} sent you \"{$this->documentTitle}\"")
            ->line("{$this->businessName} has sent you a document to review.")
            ->action($action, $this->documentUrl())
            ->line('This link is personal to you. Please do not forward it.');
    }

    private function documentUrl(): string
    {
        return route('public.documents.show', [
            'uid' => $this->documentUid,
            'token' => $this->plaintextToken,
        ]);
    }
}
