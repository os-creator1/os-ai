<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\Branding\AgencyAppDomainService;
use App\Library\Branding\AgencyBrandResolver;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\Website\Domains\DnsVerifier;
use App\Models\AgencySite;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * White Label — branded sign-in from the Agency's site, and hostname → Agency resolution that fails closed.
 */
class AgencySiteLoginAndHostTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
    }

    /** @return array{agency: Workspace, owner: Customer, site: AgencySite} */
    private function liveAgency(string $name, bool $brandOn = true): array
    {
        [$owner, $business, $agency] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        app(AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $agency, ['display_name' => $name . ' Digital', 'accent_color' => '#1a73e8', 'is_enabled' => $brandOn ? '1' : '0']);

        $type = app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro', 'duration_minutes' => 30, 'default_hours' => true]);
        $sites = app(AgencySiteManager::class);
        $sites->ensure((int) $owner->user_id, $agency);
        $sites->save((int) $owner->user_id, $agency, ['x.goal' => 'book_call', 'x.pricing_mode' => 'book_call', 'x.booking_type_uid' => $type->public_booking_uuid]);

        return ['agency' => $agency, 'owner' => $owner, 'site' => $sites->publish((int) $owner->user_id, $agency)];
    }

    public function test_the_login_reached_from_an_agency_site_is_the_platform_login_in_the_agencys_brand(): void
    {
        $a = $this->liveAgency('Agency A');
        $b = $this->liveAgency('Agency B');

        $html = $this->get(route('agency.site.login', $a['site']->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('Agency A Digital', $html);
        $this->assertStringNotContainsString('Agency B Digital', $html);
        $this->assertStringContainsString('action="' . route('login') . '"', $html, 'it posts to the one real login');

        $this->assertStringContainsString('Agency B Digital', $this->get(route('agency.site.login', $b['site']->public_id))->getContent());

        // The site's own "Log in" button leads here.
        $home = $this->get(route('agency.site.home', $a['site']->public_id))->getContent();
        $this->assertStringContainsString('href="' . route('agency.site.login', $a['site']->public_id) . '"', $home);
    }

    public function test_the_login_is_unbranded_when_the_brand_is_off_and_404_when_the_site_is_not_live(): void
    {
        $off = $this->liveAgency('Quiet Agency', false);
        $this->assertStringNotContainsString('Quiet Agency Digital', $this->get(route('agency.site.login', $off['site']->public_id))->assertOk()->getContent());

        $this->get(route('agency.site.login', '00000000-0000-4000-8000-000000000000'))->assertNotFound();

        $this->authenticateAs($off['owner']);
        $this->postJson(route('customer.workspaces.agency.site.unpublish', $off['agency']->uid))->assertOk();
        auth()->logout();
        $this->get(route('agency.site.login', $off['site']->public_id))->assertNotFound();
    }

    public function test_an_ordinary_login_page_stays_unbranded(): void
    {
        $this->liveAgency('Agency A');

        $this->assertStringNotContainsString('Agency A Digital', $this->get(route('login'))->assertOk()->getContent());
    }

    private function verifiedDomain(array $live, string $domain): void
    {
        $dns = Mockery::mock(DnsVerifier::class);
        $dns->shouldReceive('hasTxtRecord')->andReturn(true);
        $this->app->instance(DnsVerifier::class, $dns);

        $svc = app(AgencyAppDomainService::class);
        $svc->attach((int) $live['owner']->user_id, $live['agency'], $domain);
        $svc->check((int) $live['owner']->user_id, $live['agency']);
    }

    public function test_only_an_exactly_matching_verified_app_domain_of_an_enabled_agency_resolves_a_brand(): void
    {
        $a = $this->liveAgency('Agency A');
        $resolver = app(AgencyBrandResolver::class);
        $at = fn (string $host) => $resolver->resolve(Request::create('https://' . $host . '/login'));

        // Not yet proven: nothing resolves, whatever the host.
        $svc = app(AgencyAppDomainService::class);
        $svc->attach((int) $a['owner']->user_id, $a['agency'], 'app.agency-a.example');
        $this->assertNull($at('app.agency-a.example'));

        $this->verifiedDomain($a, 'app.agency-a.example');
        $this->assertSame('Agency A Digital', $at('app.agency-a.example')?->displayName);
        $this->assertSame('Agency A Digital', $at('APP.Agency-A.example')?->displayName, 'host case is normalised');

        foreach (['agency-a.example', 'x.app.agency-a.example', 'app.agency-a.example.evil.example', 'evilapp.agency-a.example', 'localhost'] as $other) {
            $this->assertNull($at($other), "'{$other}' must not resolve");
        }
    }

    public function test_a_verified_domain_stops_resolving_when_the_brand_is_off_the_domain_removed_or_the_agency_is_inactive(): void
    {
        $a = $this->liveAgency('Agency A');
        $this->verifiedDomain($a, 'app.agency-a.example');
        $resolver = app(AgencyBrandResolver::class);
        $request = fn () => Request::create('https://app.agency-a.example/login');
        $this->assertNotNull($resolver->resolve($request()));

        Workspace::query()->whereKey($a['agency']->id)->update(['is_active' => false]);
        $this->assertNull($resolver->resolve($request()));
        Workspace::query()->whereKey($a['agency']->id)->update(['is_active' => true]);
        $this->assertNotNull($resolver->resolve($request()));

        app(AgencyWhiteLabelManager::class)->save((int) $a['owner']->user_id, $a['agency'], ['display_name' => 'Agency A Digital', 'is_enabled' => '0']);
        $this->assertNull($resolver->resolve($request()));
        app(AgencyWhiteLabelManager::class)->save((int) $a['owner']->user_id, $a['agency'], ['display_name' => 'Agency A Digital', 'is_enabled' => '1']);

        app(AgencyAppDomainService::class)->remove((int) $a['owner']->user_id, $a['agency']);
        $this->assertNull($resolver->resolve($request()));
    }

    public function test_a_domain_cannot_be_claimed_by_two_agencies_and_verification_does_not_make_the_platform_answer_on_it(): void
    {
        $a = $this->liveAgency('Agency A');
        $b = $this->liveAgency('Agency B');
        $this->verifiedDomain($a, 'app.agency-a.example');

        try {
            app(AgencyAppDomainService::class)->attach((int) $b['owner']->user_id, $b['agency'], 'app.agency-a.example');
            $this->fail('a second Agency claimed the same domain');
        } catch (\App\Exceptions\Branding\AgencyWhiteLabelException $e) {
            $this->assertSame('domain_taken', $e->reason);
        }

        // Ownership proof is not activation: the host allow-list the platform enforces in production does not gain the
        // hostname, so a forged Host header naming it is refused before routing ever reaches the brand lookup.
        $trusted = implode('|', array_map('strval', app(\App\Http\Middleware\TrustHosts::class)->hosts()));
        $this->assertStringNotContainsString('agency-a', $trusted);
    }
}
