<?php

namespace Tests\Unit\Mail;

use App\Notifications\LoginConfirmation;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResendMailerTest extends TestCase
{
    public function test_resend_mailer_uses_the_https_api_transport(): void
    {
        $this->assertSame('resend', config('mail.mailers.resend.transport'));
        $this->assertArrayHasKey('key', config('services.resend'));

        config(['services.resend.key' => 'fake-key-not-a-credential']);
        Mail::purge('resend');

        $this->assertInstanceOf(ResendTransport::class, Mail::mailer('resend')->getSymfonyTransport());
    }

    public function test_default_mailer_stays_environment_configurable(): void
    {
        $this->assertSame(env('MAIL_MAILER', 'smtp'), config('mail.default'));
    }

    public function test_default_sender_is_the_verified_notify_domain(): void
    {
        $from = require base_path('config/mail.php');

        $this->assertSame('notifications@notify.getmotiongrove.com', $from['from']['address']);
        $this->assertSame('MotionGrove', $from['from']['name']);
    }

    public function test_no_other_mailer_is_forced_for_platform_notifications(): void
    {
        // Existing notifications use the default mailer and the global from address.
        $src = file_get_contents(app_path('Notifications/LoginConfirmation.php'));

        $this->assertStringContainsString("config('mail.from.address')", $src);
        $this->assertStringNotContainsString('mailer(', $src);
        $this->assertTrue(class_exists(LoginConfirmation::class));
    }
}
