<?php

namespace Tests\Feature\Auth;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\AppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * The inherited "ValidProduct" gate (App\Http\Middleware\RedirectIfNotValid, packages/kashem/licenseChecker).
 *
 * While `app_config.license` is empty, every signed-in user of the ADMIN area and the CUSTOMER route group
 * (routes/customer.php: every Business page) is sent to the vendor's purchase-code screen, which asks the ORIGINAL VENDOR's server
 * (ultimatesms.codeglen.com) to confirm the code. See docs/product/LICENSING-LEGACY-GATE.md: whether MotionGrove may
 * remove that gate is a licensing decision for the owner, so these tests PIN its present behaviour and the
 * access-control invariants around it. They contain no purchase code and never contact the vendor; the licence row
 * is set only to a labelled test-fixture value.
 *
 * If the gate is ever replaced after written authorisation, the "empty licence" expectations change; every other
 * assertion here (guests, customers vs Platform Owners, public page branding) must stay exactly as it is.
 */
class LegacyLicenceGateTest extends TestCase
{
    use PlatformOwnerFixtures;
    use RefreshDatabase;

    private function setLicence(string $value): void
    {
        AppConfig::updateOrCreate(['setting' => 'license'], ['value' => $value]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
        // The page keywords come from the environment; pin them so a developer's own .env cannot leak in.
        config(['app.name' => 'MotionGrove', 'app.keyword' => 'business operations, automation, workspace, crm, messaging']);
    }

    public function test_with_no_licence_recorded_a_signed_in_platform_owner_is_sent_to_the_activation_screen(): void
    {
        $this->setLicence('');
        $this->actingAsPlatformOwner();

        $this->get(route('admin.home'))->assertRedirect(route('verify.license'));
    }

    public function test_with_no_licence_recorded_a_signed_in_customer_cannot_open_any_business_page_either(): void
    {
        $this->setLicence('');
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('verify.license'));
    }

    public function test_with_a_licence_recorded_the_platform_owner_reaches_the_dashboard(): void
    {
        $this->setLicence('test-licence-fixture');
        $this->actingAsPlatformOwner();

        $this->get(route('admin.home'))->assertOk();
    }

    public function test_with_a_licence_recorded_a_customer_reaches_their_business_but_never_the_admin_area(): void
    {
        $this->setLicence('test-licence-fixture');
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))->assertOk();
        $this->get(route('user.home'))->assertOk();

        $admin = $this->get(route('admin.home'));
        $this->assertNotSame(200, $admin->getStatusCode(), 'A customer must never receive the Platform Owner dashboard.');
        $this->assertStringNotContainsString('platform-owner', (string) $this->get(route('user.home'))->getContent());
    }

    public function test_a_guest_never_reaches_either_area_and_is_never_sent_to_the_activation_screen(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $customerUrl = route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]);

        foreach (['', 'test-licence-fixture'] as $state) {
            $this->setLicence($state);

            foreach ([route('admin.home'), $customerUrl] as $url) {
                $response = $this->get($url);

                $this->assertNotSame(200, $response->getStatusCode(), "A guest must not reach {$url}.");
                $this->assertNotSame(route('verify.license'), $response->headers->get('Location'));
            }
        }
    }

    public function test_the_public_login_page_does_not_depend_on_the_licence_and_carries_the_platform_name(): void
    {
        foreach (['', 'test-licence-fixture'] as $state) {
            $this->setLicence($state);

            $html = $this->get(route('login'))->assertOk()->getContent();
            $this->assertStringContainsString('MotionGrove', $html);
            $this->assertStringNotContainsStringIgnoringCase('Ultimate SMS', $html);
            $this->assertStringNotContainsStringIgnoringCase('codeglen', $html);
        }
    }

    public function test_the_activation_screen_uses_the_platform_name_and_no_legacy_product_branding(): void
    {
        $this->setLicence('');

        $html = $this->get(route('verify.license'))->assertOk()->getContent();

        $this->assertStringContainsString('Activate MotionGrove', $html);
        $this->assertStringContainsString('third-party licensed software', $html);
        $this->assertStringNotContainsStringIgnoringCase('Ultimate SMS', $html);
        $this->assertStringNotContainsStringIgnoringCase('codeglen', $html);
        $this->assertStringNotContainsStringIgnoringCase('Verify Product code', $html);
    }

    public function test_an_incomplete_activation_submission_is_refused_without_changing_the_licence(): void
    {
        $this->setLicence('');

        $this->post(route('verify.license'), ['purchase_code' => 'short', 'application_url' => ''])
            ->assertRedirect('verify-purchase-code')
            ->assertSessionHasErrors(['purchase_code', 'application_url']);

        $this->assertSame('', AppConfig::where('setting', 'license')->value('value'), 'Nothing is recorded without vendor confirmation.');
    }

    public function test_the_default_page_keywords_carry_no_vendor_or_legacy_product_wording(): void
    {
        $source = (string) file_get_contents(config_path('app.php'));
        preg_match("/'keyword'\\s*=>\\s*env\\('APP_KEYWORD',\\s*'([^']*)'\\)/", $source, $m);

        $this->assertNotEmpty($m[1] ?? '', 'The keyword default must exist.');
        foreach (['ultimate sms', 'codeglen', 'ai business os'] as $legacy) {
            $this->assertStringNotContainsStringIgnoringCase($legacy, $m[1]);
        }
    }
}
