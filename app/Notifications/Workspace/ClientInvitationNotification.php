<?php

namespace App\Notifications\Workspace;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Implementation Contract 07 §5/Notification — mirrors Laravel's own
 * password-reset notification pattern exactly: the plaintext token exists
 * only transiently, to build this one claim URL, and is never persisted.
 *
 * This class holds the plaintext token only for the lifetime of building
 * and queuing this notification — it is never written to the database
 * (ClientWorkspaceInvitation only ever stores token_hash), never logged,
 * and never appears in any exception message anywhere in this feature.
 */
class ClientInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $invitationUid,
        private readonly string $plaintextToken,
        private readonly string $agencyWorkspaceName,
        private readonly ?string $intendedBusinessName,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $businessPhrase = $this->intendedBusinessName !== null
            ? " for \"{$this->intendedBusinessName}\""
            : '';

        return (new MailMessage)
            ->subject("{$this->agencyWorkspaceName} invited you to a new workspace")
            ->line("{$this->agencyWorkspaceName} has invited you to set up a new workspace{$businessPhrase}.")
            ->action('Accept invitation', $this->claimUrl())
            ->line('If you did not expect this invitation, you can safely ignore this email.');
    }

    private function claimUrl(): string
    {
        return route('client-invitations.claim', [
            'uid' => $this->invitationUid,
            'token' => $this->plaintextToken,
        ]);
    }
}
