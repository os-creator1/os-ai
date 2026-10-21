<?php

namespace App\Notifications\Auth;

use App\Library\Mail\BrandedMailMessage;
use App\Library\Mail\MailBrandResolver;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Lang;

/** Email-verification mail, branded for an Agency's customer exactly as BrandedResetPassword is. */
class BrandedVerifyEmail extends VerifyEmail
{
    public function toMail($notifiable)
    {
        $brand = app(MailBrandResolver::class)->forUser($notifiable);

        if ($brand->kind !== 'agency') {
            return parent::toMail($notifiable);
        }

        $message = (new BrandedMailMessage)
            ->from((string) config('mail.from.address'), $brand->name)
            ->subject(Lang::get('Verify your :name email address', ['name' => $brand->name]))
            ->viewData(['brand' => $brand, 'icon' => 'mail'])
            ->line(Lang::get('Please click the button below to verify your email address.'))
            ->action(Lang::get('Verify Email Address'), $this->verificationUrl($notifiable))
            ->line(Lang::get('If you did not create an account, no further action is required.'));

        if ($brand->replyTo !== null) {
            $message->replyTo($brand->replyTo, $brand->name);
        }

        return $message;
    }
}
