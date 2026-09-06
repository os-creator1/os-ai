<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * B3 Simplified Platform Settings §5/§18 — the minimal "Send test email"
 * message (SettingsController::testEmail()). Deliberately carries no
 * mail-configuration detail (host/username/password) in its body: it
 * only confirms delivery is working.
 */
class PlatformSettingsTestEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function build(): self
    {
        return $this->subject('AI Business OS test email')
            ->text('emails.platform-settings-test');
    }
}
