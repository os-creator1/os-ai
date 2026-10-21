<?php

namespace App\Notifications\Auth;

use App\Library\Mail\BrandedMailMessage;
use App\Library\Mail\MailBrandResolver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Lang;

/**
 * Password-reset mail. A person who is a customer of an Agency is addressed in the Agency's name, colour and logo,
 * with the Agency's support address as Reply-To; everyone else gets exactly the stock message.
 *
 * The From ADDRESS is always the platform's authenticated sender — an Agency's own domain is never claimed.
 */
class BrandedResetPassword extends ResetPassword
{
    public function toMail($notifiable)
    {
        $brand = app(MailBrandResolver::class)->forUser($notifiable);

        if ($brand->kind !== 'agency') {
            return parent::toMail($notifiable);
        }

        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        $message = (new BrandedMailMessage)
            ->from((string) config('mail.from.address'), $brand->name)
            ->subject(Lang::get('Reset your :name password', ['name' => $brand->name]))
            ->viewData(['brand' => $brand, 'icon' => 'mail'])
            ->line(Lang::get('You are receiving this email because we received a password reset request for your account.'))
            ->action(Lang::get('Reset Password'), $this->resetUrl($notifiable))
            ->line(Lang::get('This password reset link will expire in :count minutes.', ['count' => $minutes]))
            ->line(Lang::get('If you did not request a password reset, no further action is required.'));

        if ($brand->replyTo !== null) {
            $message->replyTo($brand->replyTo, $brand->name);
        }

        return $message;
    }
}
