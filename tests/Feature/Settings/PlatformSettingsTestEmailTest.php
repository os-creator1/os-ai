<?php

namespace Tests\Feature\Settings;

use App\Mail\PlatformSettingsTestEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * B3 Simplified Platform Settings §5/§18/§19 (Test email). Mail is faked
 * throughout -- no live network call is made.
 */
class PlatformSettingsTestEmailTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumeSuperAdminId();
        Mail::fake();
    }

    public function test_authorized_admin_can_send_a_test_email(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'system_email settings']);

        $this->post(route('admin.settings.email.test'), ['email' => 'destination@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status', 'success');

        Mail::assertSent(PlatformSettingsTestEmail::class, function (PlatformSettingsTestEmail $mail) {
            return $mail->hasTo('destination@example.test');
        });
    }

    public function test_invalid_destination_is_rejected(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'system_email settings']);

        $this->post(route('admin.settings.email.test'), ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_unauthorized_user_cannot_send_a_test_email(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $this->post(route('admin.settings.email.test'), ['email' => 'destination@example.test'])
            ->assertStatus(401);

        Mail::assertNothingSent();
    }

    public function test_demo_mode_blocks_the_test_email(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        config(['app.stage' => 'demo']);
        $this->actingAsAdmin(['access backend', 'general settings', 'system_email settings']);

        $this->post(route('admin.settings.email.test'), ['email' => 'destination@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status', 'error');

        Mail::assertNothingSent();
    }
}
