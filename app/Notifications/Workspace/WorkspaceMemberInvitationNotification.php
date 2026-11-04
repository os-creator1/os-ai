<?php

namespace App\Notifications\Workspace;

use App\Library\Mail\BrandedMailMessage;
use App\Library\Mail\MailBrand;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The Settings → Team invitation email, sent to a person with no account yet.
 * Same shape as ClientInvitationNotification: the plaintext token exists only
 * to build this one acceptance link and is never stored, logged or returned.
 * Always in the MotionGrove brand — the inviting account is named in the body,
 * not re-skinned.
 */
class WorkspaceMemberInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $invitationUid,
        private readonly string $plaintextToken,
        private readonly string $accountName,
        private readonly string $roleLabel,
        private readonly CarbonInterface $expiresAt,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new BrandedMailMessage)
            ->subject("You're invited to join {$this->accountName} on MotionGrove")
            ->greeting("You're invited to join {$this->accountName}")
            ->viewData([
                'brand' => MailBrand::platform(),
                'icon' => 'mail',
                'details' => [
                    'Account' => $this->accountName,
                    'Role' => $this->roleLabel,
                    'Expires' => $this->expiresAt->format('F j, Y'),
                ],
            ])
            ->line("{$this->accountName} has invited you to join their team on MotionGrove as {$this->roleLabel}. Accept the invitation to set up your sign-in.")
            ->action('Accept invitation', route('workspace-invitations.claim', ['uid' => $this->invitationUid, 'token' => $this->plaintextToken]))
            ->line('This invitation expires on ' . $this->expiresAt->format('F j, Y') . '. If you did not expect it, you can safely ignore this email.');
    }
}
