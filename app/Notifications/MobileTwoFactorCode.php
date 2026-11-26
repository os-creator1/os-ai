<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * The web's two-factor code notification (same channels: mail and SMS), carrying a mobile challenge's code.
 *
 * The code is passed in instead of read from `users.two_factor_code` (see MobileTwoFactorChallenge), and the mail has
 * no "Verify Here" link: the code is typed into the app, not into the web verify page.
 */
class MobileTwoFactorCode extends TwoFactorCode
{
    public function __construct(private readonly string $code)
    {
        parent::__construct('');
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your MotionGrove sign-in code')
            ->line('Your two factor code is ' . $this->code)
            ->line('Enter it in the MotionGrove app. The code will expire in 10 minutes.')
            ->line('If you have not tried to sign in on a phone, ignore this message and consider changing your password.');
    }

    public function toSms($notifiable)
    {
        // Reuse the web's SMS path on an unsaved copy, so the code is never written to the users row.
        $copy = clone $notifiable;
        $copy->two_factor_code = $this->code;

        return parent::toSms($copy);
    }

    public function code(): string
    {
        return $this->code;
    }
}
