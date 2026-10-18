<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/**
 * A transactional / product-notice email raised by a Platform automation or
 * announcement. Plain by design: the body is operator-written text already stripped
 * of unknown merge tokens, rendered escaped.
 */
class PlatformAutomationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $mailBody,
        public readonly string $purpose = 'transactional',
    ) {
    }

    public function build(): self
    {
        return $this->subject($this->mailSubject)->view('emails.platform-automation', [
            'body' => $this->mailBody,
            'purpose' => $this->purpose,
        ]);
    }
}
