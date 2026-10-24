<?php

namespace Tests\Feature\BusinessEmail\Support;

use App\Library\BusinessEmail\Imap\ImapSettings;
use App\Library\BusinessEmail\Imap\SmtpMailer;
use Symfony\Component\Mime\Email;

final class FakeSmtpMailer implements SmtpMailer
{
    /** @var list<Email> */
    public array $sent = [];

    public ?\Throwable $failure = null;

    public ?\Throwable $testFailure = null;

    public ?string $lastIp = null;

    public int $tests = 0;

    public function send(ImapSettings $settings, string $ip, Email $email): void
    {
        $this->lastIp = $ip;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->sent[] = $email;
    }

    public function test(ImapSettings $settings, string $ip): void
    {
        $this->tests++;
        $this->lastIp = $ip;

        if ($this->testFailure !== null) {
            throw $this->testFailure;
        }
    }
}
