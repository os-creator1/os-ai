<?php

namespace App\Library\PlatformOwner;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use RuntimeException;

/**
 * Platform Owner V1 final — the legitimate customer-support actions on one
 * customer account. Every action re-checks the actor against the database
 * (PlatformOwnerAuthority::assertAdministrator), refuses administrator
 * accounts (those are managed on the Administrators page), and writes one
 * audit row. There is no way to read, set or reveal a password: a reset is
 * only ever the standard emailed link, and the link itself is never stored,
 * returned or logged.
 */
class PlatformUserActions
{
    public function __construct(
        private readonly PlatformOwnerAuthority $authority,
        private readonly PlatformAdminAuditLog $audit,
    ) {
    }

    public function sendPasswordReset(int $actorId, User $target): void
    {
        $this->guard($actorId, $target);

        $status = Password::broker()->sendResetLink(['email' => $target->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw new RuntimeException(__('The reset link could not be sent right now (:s). Try again shortly.', ['s' => $status]));
        }

        $this->audit->record($actorId, 'user.password_reset_sent', 'user', $target->uid, 'Sent a password reset link to ' . $target->email);
    }

    public function resendVerification(int $actorId, User $target): void
    {
        $this->guard($actorId, $target);

        if ($target->hasVerifiedEmail()) {
            throw new RuntimeException(__('This email address is already verified.'));
        }

        $target->sendEmailVerificationNotification();

        $this->audit->record($actorId, 'user.verification_resent', 'user', $target->uid, 'Resent the verification email to ' . $target->email);
    }

    public function suspend(int $actorId, User $target, string $reason): void
    {
        $this->guard($actorId, $target);

        if (! $target->status) {
            throw new RuntimeException(__('This account is already suspended.'));
        }

        $target->forceFill(['status' => false, 'password_changed_at' => now()])->save();

        $this->audit->record($actorId, 'user.suspended', 'user', $target->uid, 'Suspended ' . $target->email . ' and signed out all sessions', $reason);
    }

    public function reactivate(int $actorId, User $target, string $reason): void
    {
        $this->guard($actorId, $target);

        if ($target->status) {
            throw new RuntimeException(__('This account is already active.'));
        }

        $target->forceFill(['status' => true])->save();

        $this->audit->record($actorId, 'user.reactivated', 'user', $target->uid, 'Reactivated ' . $target->email, $reason);
    }

    /**
     * Sign the user out everywhere. Uses the existing CheckPasswordChanged
     * mechanism: a session whose login predates users.password_changed_at is
     * ended on its next request. The password itself is not touched.
     */
    public function revokeSessions(int $actorId, User $target): void
    {
        $this->guard($actorId, $target);

        $target->forceFill(['password_changed_at' => now()])->save();

        $this->audit->record($actorId, 'user.sessions_revoked', 'user', $target->uid, 'Signed ' . $target->email . ' out of all sessions');
    }

    private function guard(int $actorId, User $target): void
    {
        $this->authority->assertAdministrator($actorId);

        if ($target->is_admin || $target->id === $actorId) {
            throw new RuntimeException(__('Administrator accounts are managed under Administrators.'));
        }
    }
}
