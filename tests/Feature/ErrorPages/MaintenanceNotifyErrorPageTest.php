<?php

namespace Tests\Feature\ErrorPages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The compact 503 page keeps the maintenance "notify me" form: POST + CSRF to
 * maintenance.notify, the existing confirmation, and no form where the
 * endpoint cannot accept it.
 */
class MaintenanceNotifyErrorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Artisan::call('up');
        parent::tearDown();
    }

    public function test_the_prerendered_maintenance_page_offers_the_csrf_protected_post_form(): void
    {
        $html = (string) view('errors.503', ['retryAfter' => 60])->render();

        $this->assertStringContainsString('data-role="maintenance-notify-form"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('action="' . route('maintenance.notify') . '"', $html);
        $this->assertMatchesRegularExpression('/name="_token" value="[^"]+"/', $html);
        $this->assertStringContainsString('type="email" name="email"', $html);
        $this->assertStringContainsString('Temporarily unavailable', $html);
        $this->assertStringNotContainsString('misc-wrapper', $html);
        $this->assertStringNotContainsString('under-maintenance', $html);
        $this->assertSame(0, preg_match('/<img[^>]+(error|illustration|maintenance)/i', $html));
    }

    public function test_no_form_is_shown_when_the_application_is_not_in_maintenance(): void
    {
        $html = (string) view('errors.503')->render();

        $this->assertStringContainsString('Temporarily unavailable', $html);
        $this->assertStringNotContainsString('maintenance-notify-form', $html);
        $this->assertStringNotContainsString('maintenance/notify', $html);
    }

    public function test_the_form_is_shown_while_maintenance_is_active_and_the_confirmation_keeps_working(): void
    {
        Artisan::call('down');

        $html = (string) view('errors.503')->render();
        $this->assertStringContainsString('data-role="maintenance-notify-form"', $html);

    }

    public function test_the_confirmation_message_renders_with_the_notified_flag(): void
    {
        $this->app['request']->merge(['notified' => '1']);

        $html = (string) view('errors.503', ['retryAfter' => 60])->render();

        $this->assertStringContainsString('data-role="maintenance-notified"', $html);
        $this->assertStringContainsString('You will be notified when we are back online.', $html);
    }

    public function test_the_notify_endpoint_still_refuses_outside_maintenance_and_stores_inside_it(): void
    {
        $this->post(route('maintenance.notify'), ['email' => 'a@example.com'])->assertNotFound();
        $this->assertSame(0, DB::table('maintenance_notifications')->count());

        Artisan::call('down');

        $this->post(route('maintenance.notify'), ['email' => 'a@example.com'])->assertRedirect('/?notified=1');
        $this->assertSame(1, DB::table('maintenance_notifications')->where('email', 'a@example.com')->count());
    }
}
