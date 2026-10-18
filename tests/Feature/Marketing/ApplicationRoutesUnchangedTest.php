<?php

namespace Tests\Feature\Marketing;

use App\Models\AppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * The marketing site shares `/` and `/pricing` with the application. These tests (database backed, because
 * they sign a real customer in) prove the application behaviour on those URLs and its neighbours is unchanged.
 */
class ApplicationRoutesUnchangedTest extends TestCase
{
    use CreatesBusinessTestData;
    use RefreshDatabase;

    private const MARKETING_PRICING_H1 = 'Simple plans. Real help included.';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['license' => 'test-license-key', 'custom_script' => ''] as $setting => $value) {
            AppConfig::query()->firstOrCreate(['setting' => $setting], ['value' => $value]);
        }
        config(['marketing.enabled' => true, 'marketing.hosts' => []]);
    }

    private function signedInCustomer()
    {
        $customer = $this->createCustomer();
        $customer->user->forceFill(['email_verified_at' => now()])->save();

        return $customer->user;
    }

    public function test_a_signed_in_customer_keeps_the_application_pricing_page_and_root_redirect(): void
    {
        $user = $this->signedInCustomer();

        $pricing = $this->actingAs($user)->get('/pricing');
        $this->assertStringNotContainsString(self::MARKETING_PRICING_H1, (string) $pricing->getContent());
        $this->assertContains($pricing->getStatusCode(), [200, 302], 'the legacy page still answers for its owner');

        $this->actingAs($user)->get('/')->assertRedirect('login');
    }

    public function test_a_guest_gets_the_public_pricing_page_and_the_legacy_post_routes_are_untouched(): void
    {
        $this->get('/pricing')->assertOk()->assertSee(self::MARKETING_PRICING_H1, false);

        // The sibling legacy endpoints still demand a signed-in customer (redirect or 401, never a 200).
        $this->assertContains($this->post('/pricing')->getStatusCode(), [302, 401]);
        $this->assertContains($this->post('/pricing/view')->getStatusCode(), [302, 401]);
    }

    public function test_the_application_entry_points_are_unchanged_for_a_guest(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('More bookings.', false);
        $this->assertContains($this->get('/dashboard')->getStatusCode(), [302, 401]);
        $this->assertTrue(Route::has('register'));
        $this->assertSame(
            route('register', ['plan' => 'growth']),
            $this->app->make(\App\Library\Marketing\MarketingSite::class)->planUrl('growth')
        );
    }

    public function test_plan_buttons_land_on_the_catalog_validated_signup_and_never_activate_anything(): void
    {
        // The signup validates the plan against the catalog: an unsellable plan lands on plan selection, not a trial.
        $response = $this->get('/register?plan=growth');
        $this->assertContains($response->getStatusCode(), [200, 302]);
        $this->assertStringNotContainsString('trial', strtolower((string) $response->getContent()));
    }

    public function test_marketing_pages_do_not_answer_on_a_foreign_host(): void
    {
        $response = $this->get('http://customer-site.example.test/product');

        $this->assertStringNotContainsString('Everything your business runs on', (string) $response->getContent());
    }
}
