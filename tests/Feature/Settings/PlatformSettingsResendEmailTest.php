<?php

namespace Tests\Feature\Settings;

use App\Mail\PlatformSettingsTestEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * System Email screen supports the Resend API mailer without touching the
 * SMTP fields or ever handling RESEND_API_KEY. No live network call is made.
 */
class PlatformSettingsResendEmailTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    private const PERMS = ['access backend', 'general settings', 'system_email settings'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumeSuperAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(self::PERMS);
    }

    public function test_form_offers_resend_and_shows_it_selected_without_the_api_key(): void
    {
        config([
            'mail.default' => 'resend',
            'services.resend.key' => 're_fake_secret_value',
        ]);

        $html = $this->get('/admin/settings')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option[^>]*value="resend"[^>]*selected/i', $html);
        $this->assertStringNotContainsString('re_fake_secret_value', $html);
        $this->assertStringNotContainsString('RESEND_API_KEY', $html);
    }

    public function test_saving_resend_needs_no_smtp_fields_and_preserves_smtp_credentials(): void
    {
        $this->post('/admin/settings/email', $this->baseSystemEmailPayload(['password' => 'keep-this-secret']))
            ->assertRedirect();

        $this->post('/admin/settings/email', [
            'driver' => 'resend',
            'from_email' => 'notifications@notify.getmotiongrove.com',
            'from_name' => 'MotionGrove',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('resend', $this->readEnvValue('MAIL_MAILER'));
        $this->assertSame('notifications@notify.getmotiongrove.com', $this->readEnvValue('MAIL_FROM_ADDRESS'));
        $this->assertSame('MotionGrove', $this->readEnvValue('MAIL_FROM_NAME'));
        $this->assertSame('keep-this-secret', $this->readEnvValue('MAIL_PASSWORD'));
        $this->assertSame('smtp.example.test', $this->readEnvValue('MAIL_HOST'));
        $this->assertNull($this->readEnvValue('RESEND_API_KEY'));
    }

    public function test_resend_submission_cannot_write_an_api_key(): void
    {
        $this->post('/admin/settings/email', [
            'driver' => 'resend',
            'from_email' => 'notifications@notify.getmotiongrove.com',
            'from_name' => 'MotionGrove',
            'api_key' => 're_should_be_ignored',
            'RESEND_API_KEY' => 're_should_be_ignored',
        ])->assertRedirect();

        $this->assertNull($this->readEnvValue('RESEND_API_KEY'));
    }

    public function test_smtp_and_sendmail_can_still_be_selected_and_unknown_drivers_are_rejected(): void
    {
        $this->post('/admin/settings/email', $this->baseSystemEmailPayload())->assertRedirect();
        $this->assertSame('smtp', $this->readEnvValue('MAIL_MAILER'));

        $this->post('/admin/settings/email', $this->baseSystemEmailPayload(['driver' => 'sendmail']))->assertRedirect();
        $this->assertSame('sendmail', $this->readEnvValue('MAIL_MAILER'));

        $this->post('/admin/settings/email', $this->baseSystemEmailPayload(['driver' => 'bogus']))
            ->assertSessionHasErrors('driver');
    }

    public function test_smtp_still_requires_its_fields(): void
    {
        $this->post('/admin/settings/email', ['driver' => 'smtp', 'from_email' => 'a@example.test', 'from_name' => 'A'])
            ->assertSessionHasErrors(['host', 'port', 'username']);
    }

    public function test_test_email_uses_the_configured_resend_mailer(): void
    {
        config(['mail.default' => 'resend']);
        Mail::fake();

        $this->post(route('admin.settings.email.test'), ['email' => 'destination@example.test'])
            ->assertSessionHas('status', 'success');

        Mail::assertSent(PlatformSettingsTestEmail::class, fn ($m) => $m->hasTo('destination@example.test'));
    }
}
