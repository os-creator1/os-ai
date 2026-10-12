<?php

namespace App\Notifications\Workspace;

use App\Library\Mail\MailBrandResolver;
use Illuminate\Bus\Queueable;
use App\Library\Mail\BrandedMailMessage;
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
        private readonly ?int $agencyWorkspaceId = null,
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

        // The inviting Agency is always named; its own look applies only when its white-label brand is enabled and entitled.
        $details = ['Invited by' => $this->agencyWorkspaceName];

        if ($this->intendedBusinessName !== null) {
            $details['Workspace'] = $this->intendedBusinessName;
        }

        return (new BrandedMailMessage)
            ->subject("{$this->agencyWorkspaceName} invited you to a new workspace")
            ->greeting("You're invited to a new workspace")
            ->viewData([
                'brand' => app(MailBrandResolver::class)->forAgencyWorkspace($this->agencyWorkspaceId),
                'icon' => 'mail',
                'details' => $details,
            ])
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
