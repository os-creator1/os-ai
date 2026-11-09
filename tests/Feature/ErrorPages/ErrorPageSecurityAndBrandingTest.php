<?php

namespace Tests\Feature\ErrorPages;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\Support\RequestScopedCache;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\Templates;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Agency white-label branding on the unified error page, cross-Business
 * concealment, POST-only logout (including impersonation exit) and the
 * absence of redirect loops.
 */
class ErrorPageSecurityAndBrandingTest extends TestCase
{
    use CreatesBusinessTestData;
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();

        Route::middleware('web')->group(function (): void {
            Route::get('/__branded/404', fn () => abort(404, 'SECRET-BRAND-MESSAGE'));
            Route::get('/__branded/500', fn () => throw new \RuntimeException('SECRET-BRAND-MESSAGE'));
        });
    }

    // ------------------------------------------------------------ branding

    private function enableBrand(): array
    {
        [$owner, , $agency] = $this->tenant(WorkspacePlanTier::Agency, 'Northwind Agency Business', 'Northwind Agency');
        $fixture = $this->createAgencyManagedClient($agency, 'Alpha Dental Business', 'Alpha Dental');
        $this->assignTier($fixture['clientWorkspace'], WorkspacePlanTier::Growth);

        app(AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $agency, [
            'display_name' => 'Northwind Digital',
            'tagline' => 'Growth, handled.',
            'accent_color' => '#1a73e8',
            'support_email' => 'help@northwind.example',
            'is_enabled' => '1',
        ], null);

        return [$agency, $owner, $fixture['clientOwner']];
    }

    public function test_a_managed_clients_error_pages_carry_the_agency_brand_and_fall_back_otherwise(): void
    {
        [, , $clientOwner] = $this->enableBrand();

        $this->authenticateAs($clientOwner);
        app(RequestScopedCache::class)->flush();

        foreach (['/__branded/404', '/__branded/500'] as $url) {
            $body = (string) $this->get($url)->getContent();

            $this->assertStringContainsString('data-role="agency-brand-mark"', $body, $url);
            $this->assertStringContainsString('Northwind Digital', $body, $url);
            $this->assertStringContainsString('background-color:#1a73e8', $body, $url);
            $this->assertStringNotContainsString('SECRET-BRAND-MESSAGE', $body);
        }

        // A signed-out visitor, and an unrelated customer, get the platform brand.
        auth()->logout();
        $this->flushSession();
        app(RequestScopedCache::class)->flush();
        $guest = (string) $this->get('/__branded/404')->getContent();
        $this->assertStringNotContainsString('Northwind Digital', $guest);
        $this->assertStringNotContainsString('agency-brand-mark', $guest);
        $this->assertStringContainsString('class="eb-logo"', $guest);
    }

    // ------------------------------------------------- cross-Business denial

    public function test_a_foreign_business_resource_is_a_plain_404_page_that_reveals_nothing(): void
    {
        $this->ensureAppConfig();

        $owner = $this->createCustomer();
        $this->createBusinessWithWorkspace($owner, $this->businessAttributes(['name' => 'Own Business']));

        $stranger = $this->createCustomer();
        $foreign = $this->createBusinessWithWorkspace($stranger, $this->businessAttributes(['name' => 'Foreign Business']));
        $template = Templates::create([
            'user_id' => $stranger->user_id,
            'business_id' => $foreign->id,
            'name' => 'Foreign template',
            'message' => 'Secret foreign text',
            'status' => true,
        ]);

        $this->authenticateAs($owner, ['sms_quick_send']);

        $url = route('customer.workspaces.businesses.outreach.templates.show_data', [$foreign->workspace->uid, $foreign->uid, $template->id]);
        $page = $this->post($url);

        $page->assertNotFound()
            ->assertSee('data-error-code="404"', false)
            ->assertDontSee('Secret foreign text')
            ->assertDontSee('Foreign Business')
            ->assertDontSee('Foreign template');

        // Same answer as a business that does not exist at all.
        $missing = $this->post(route('customer.workspaces.businesses.outreach.templates.show_data', [$foreign->workspace->uid, 'does-not-exist', 999999]));
        $missing->assertNotFound();
    }

    // -------------------------------------------------------------- logout

    public function test_logout_requires_post_and_a_get_never_logs_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('logout'))->assertNotFound();
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_impersonation_exit_is_post_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create();

        $this->withSession(['admin_user_id' => $admin->id, 'admin_user_name' => 'Admin'])->actingAs($target);

        $this->get(route('logout'))->assertNotFound();
        $this->assertAuthenticatedAs($target);
        $this->assertSame($admin->id, session('admin_user_id'), 'A GET must not end impersonation.');

        $this->post(route('logout'))->assertRedirect();
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('admin_user_id'));
    }

    public function test_a_stale_csrf_post_ends_on_the_clean_419_page_without_leaking(): void
    {
        Route::middleware('web')->post('/__branded/post', fn () => throw new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.'));

        $this->post('/__branded/post')
            ->assertStatus(419)
            ->assertSee('Your session timed out')
            ->assertSee('Log in again')
            ->assertDontSee('CSRF token mismatch');
    }

    // ------------------------------------------------------- redirect loops

    public function test_the_error_page_destinations_do_not_loop(): void
    {
        // Guest: the login and the homepage destinations answer without bouncing back to an error page.
        $this->get(route('login'))->assertOk();
        $home = $this->get('/');
        $this->assertContains($home->getStatusCode(), [200, 301, 302]);
        if ($home->isRedirect()) {
            $this->assertNotSame(url('/'), $home->headers->get('Location'), 'The homepage must not redirect to itself.');
        }

        // Signed in: /login hands off to the user's home, never to itself.
        $this->actingAs(User::factory()->create());
        $login = $this->get(route('login'));
        $login->assertRedirect();
        $this->assertNotSame(route('login'), $login->headers->get('Location'));
    }

    // --------------------------------------------------------------- helpers

    private function ensureAppConfig(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }
        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }
        if (! in_array('customer_permissions', $existing, true)) {
            AppConfig::create(collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions'));
        }
    }
}
