<?php

namespace Tests\Feature\Branding;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Branding\AgencyBrand;
use App\Library\Branding\AgencyBrandSource;
use App\Library\Branding\BrandingPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 2 — T-AUTH-2 (contract §9.1, §18, §24; brief
 * §3, §9). Agency white-label branding never leaks across Workspaces:
 * the only selector is the server-resolved request host handed to a
 * bound AgencyBrandSource, every claim is re-checked against the
 * authoritative Workspace (active, Agency tier), forged query/session
 * identifiers select nothing, failures fall back to the neutral identity
 * without exposing a path or exception, and the platform branding cache
 * never carries an Agency name. At this base no source is bound in
 * production (the repository has no custom-domain mapping and
 * `white_label` is Planned), so the fake below stands in for the future
 * authoritative signal exactly at the seam a real one would use.
 */
class AuthBrandTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const HOST_A = 'agency-a.example.test';

    private const HOST_B = 'agency-b.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.name' => 'AI Business OS', 'app.auth_illustration' => null]);
        Cache::forget(BrandingPresenter::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(BrandingPresenter::CACHE_KEY);
        parent::tearDown();
    }

    /**
     * @return array{0: \App\Models\Workspace, 1: \App\Models\Workspace}
     */
    private function twoAgencies(): array
    {
        [, , $workspaceA] = $this->tenant(WorkspacePlanTier::Agency, 'Client A One', 'Agency A');
        [, , $workspaceB] = $this->tenant(WorkspacePlanTier::Agency, 'Client B One', 'Agency B');

        return [$workspaceA, $workspaceB];
    }

    private function bindHostMap(array $map): void
    {
        $this->app->instance(AgencyBrandSource::class, new HostMapAgencyBrandSource($map));
    }

    private function loginOn(string $host): string
    {
        return $this->get('http://' . $host . '/login')->assertOk()->getContent();
    }

    public function test_neutral_login_with_no_authoritative_tenant_source(): void
    {
        $this->twoAgencies();

        $html = $this->loginOn('localhost');

        $this->assertStringContainsString('data-brand-source="neutral"', $html);
        $this->assertStringNotContainsString('Agency A', $html);
        $this->assertStringNotContainsString('Agency B', $html);
    }

    public function test_each_agency_host_sees_only_its_own_brand_and_alternating_requests_do_not_leak(): void
    {
        [$workspaceA, $workspaceB] = $this->twoAgencies();
        $this->bindHostMap([
            self::HOST_A => new AgencyBrand($workspaceA->uid, 'Agency A', null, 'Welcome back to Agency A.'),
            self::HOST_B => new AgencyBrand($workspaceB->uid, 'Agency B', null, 'Welcome back to Agency B.'),
        ]);

        foreach ([[self::HOST_A, 'Agency A', 'Agency B'], [self::HOST_B, 'Agency B', 'Agency A'], [self::HOST_A, 'Agency A', 'Agency B']] as [$host, $own, $other]) {
            $html = $this->loginOn($host);

            $this->assertStringContainsString('data-brand-source="agency"', $html, $host);
            $this->assertStringContainsString($own, $html, $host);
            $this->assertStringContainsString('Welcome back to ' . $own . '.', $html, $host);
            $this->assertStringNotContainsString($other, $html, "{$host} leaked {$other}.");
            $this->assertMatchesRegularExpression('/<title>[^<]*' . preg_quote($own, '/') . '<\/title>/', $html, $host);
            $this->assertStringNotContainsString('Test Title', $html);
            $this->assertStringNotContainsString($workspaceA->uid, $html, 'No Workspace identifier is exposed.');
            $this->assertStringNotContainsString($workspaceB->uid, $html, 'No Workspace identifier is exposed.');
        }

        $neutral = $this->loginOn('localhost');
        $this->assertStringContainsString('data-brand-source="neutral"', $neutral);
        $this->assertStringNotContainsString('Agency A', $neutral);
        $this->assertStringNotContainsString('Agency B', $neutral);
    }

    public function test_the_platform_branding_cache_never_carries_an_agency_brand(): void
    {
        [$workspaceA] = $this->twoAgencies();
        $this->bindHostMap([self::HOST_A => new AgencyBrand($workspaceA->uid, 'Agency A')]);

        $this->loginOn(self::HOST_A);

        $cached = Cache::get(BrandingPresenter::CACHE_KEY);
        $this->assertIsArray($cached);
        $this->assertSame('AI Business OS', $cached['name']);
        $this->assertStringNotContainsString('Agency A', json_encode($cached));
    }

    public function test_an_unknown_host_falls_back_to_neutral(): void
    {
        [$workspaceA] = $this->twoAgencies();
        $this->bindHostMap([self::HOST_A => new AgencyBrand($workspaceA->uid, 'Agency A')]);

        $html = $this->loginOn('unknown.example.test');

        $this->assertStringContainsString('data-brand-source="neutral"', $html);
        $this->assertStringNotContainsString('Agency A', $html);
    }

    public function test_a_forged_query_or_session_workspace_identifier_selects_no_brand(): void
    {
        [$workspaceA] = $this->twoAgencies();
        $this->bindHostMap([self::HOST_A => new AgencyBrand($workspaceA->uid, 'Agency A')]);

        $byQuery = $this->get('http://localhost/login?workspace=' . $workspaceA->uid . '&workspaceUid=' . $workspaceA->uid . '&host=' . self::HOST_A)
            ->assertOk()->getContent();
        $this->assertStringContainsString('data-brand-source="neutral"', $byQuery);
        $this->assertStringNotContainsString('Agency A', $byQuery);

        $bySession = $this->withSession(['workspace' => $workspaceA->uid, 'customer_context.workspace' => $workspaceA->uid, 'view_as_session_uid' => 'forged'])
            ->get('http://localhost/login')->assertOk()->getContent();
        $this->assertStringContainsString('data-brand-source="neutral"', $bySession);
        $this->assertStringNotContainsString('Agency A', $bySession);
    }

    public function test_a_deactivated_downgraded_or_missing_agency_falls_back_safely(): void
    {
        [$workspaceA] = $this->twoAgencies();
        [, , $growth] = $this->tenant(WorkspacePlanTier::Growth, 'Growth Shop', 'Growth Account');

        $this->bindHostMap([
            self::HOST_A => new AgencyBrand($workspaceA->uid, 'Agency A'),
            'growth.example.test' => new AgencyBrand($growth->uid, 'Growth Account'),
            'ghost.example.test' => new AgencyBrand('00000000-0000-0000-0000-000000000000', 'Ghost Agency'),
        ]);

        $this->assertStringContainsString('Agency A', $this->loginOn(self::HOST_A));

        $workspaceA->forceFill(['is_active' => false])->save();
        $deactivated = $this->loginOn(self::HOST_A);
        $this->assertStringContainsString('data-brand-source="neutral"', $deactivated);
        $this->assertStringNotContainsString('Agency A', $deactivated);

        $notAgencyTier = $this->loginOn('growth.example.test');
        $this->assertStringContainsString('data-brand-source="neutral"', $notAgencyTier);
        $this->assertStringNotContainsString('Growth Account', $notAgencyTier);

        $missing = $this->loginOn('ghost.example.test');
        $this->assertStringContainsString('data-brand-source="neutral"', $missing);
        $this->assertStringNotContainsString('Ghost Agency', $missing);
    }

    public function test_a_source_failure_falls_back_to_neutral_without_exposing_the_error(): void
    {
        $this->twoAgencies();
        $this->app->instance(AgencyBrandSource::class, new class implements AgencyBrandSource {
            public function forHost(string $host): ?AgencyBrand
            {
                throw new \RuntimeException('secret-source-failure /var/www/brands.php');
            }
        });

        $html = $this->loginOn(self::HOST_A);

        $this->assertStringContainsString('data-brand-source="neutral"', $html);
        $this->assertStringNotContainsString('secret-source-failure', $html);
        $this->assertStringNotContainsString('/var/www', $html);
    }

    public function test_an_agency_logo_failure_falls_back_to_the_typographic_mark_without_a_path_or_exception(): void
    {
        [$workspaceA, $workspaceB] = $this->twoAgencies();
        $this->bindHostMap([
            self::HOST_A => new AgencyBrand($workspaceA->uid, 'Agency A', 'images/branding/agency/does-not-exist.png'),
            self::HOST_B => new AgencyBrand($workspaceB->uid, 'Agency B', '../../.env'),
            'remote.example.test' => new AgencyBrand($workspaceA->uid, 'Agency A', 'https://evil.example/logo.png'),
        ]);

        foreach ([self::HOST_A, self::HOST_B, 'remote.example.test'] as $host) {
            $html = $this->loginOn($host);

            $this->assertStringContainsString('data-brand-source="agency"', $html, $host);
            $this->assertStringNotContainsString('does-not-exist', $html, $host);
            $this->assertStringNotContainsString('.env', $html, $host);
            $this->assertStringNotContainsString('evil.example', $html, $host);
            $this->assertStringNotContainsString('public_path', $html, $host);
            $this->assertStringNotContainsStringIgnoringCase('exception', $html, $host);
            $this->assertDoesNotMatchRegularExpression('/<img[^>]+src=["\']\s*["\']/', $html, $host);
            $this->assertStringContainsString('auth-brand-panel__mark', $html, "{$host} renders the initials mark instead.");
        }
    }

    public function test_a_valid_agency_logo_renders_as_an_informative_image(): void
    {
        [$workspaceA] = $this->twoAgencies();
        $this->bindHostMap([self::HOST_A => new AgencyBrand($workspaceA->uid, 'Agency A', 'images/branding/default-logo.svg', null, '#1a2b3c')]);

        $html = $this->loginOn(self::HOST_A);

        $this->assertMatchesRegularExpression('/<img[^>]+src="[^"]*images\/branding\/default-logo\.svg"[^>]*alt="Agency A"/', $html);
        $this->assertStringContainsString('--auth-brand-accent:#1a2b3c', $html);
    }

    public function test_the_customer_shell_context_still_comes_from_slice_1b_and_view_as_narrowing_holds(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client A One', 'Agency A');
        $sibling = $this->addBusiness($owner, $workspace, 'Client A Two');
        $this->bindHostMap([self::HOST_A => new AgencyBrand($workspace->uid, 'Agency A')]);
        $this->authenticateAs($owner);

        $home = $this->get('http://' . self::HOST_A . '/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('data-role="sidebar-context"', $home);
        $this->assertStringContainsString('data-role="context-switcher"', $home);

        $this->startViewAs($workspace, $business)->assertRedirect();

        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $sibling->uid]))->assertNotFound();
        $this->assertStringContainsString('data-role="view-as-banner"', $this->home()->assertOk()->getContent());
    }

    public function test_a_normal_customer_never_receives_platform_admin_branding_controls(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Harbor Lane');
        $this->authenticateAs($owner);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringNotContainsString('/admin/settings', $html);
        $this->assertStringNotContainsString('admin/settings/platform', $html);
        $this->assertStringNotContainsString('app_logo', $html);
        $this->assertStringNotContainsString('auth_illustration', $html);
    }
}

/**
 * Test double for the authoritative signal a future white-label slice
 * will bind: an exact host → brand map, nothing else.
 */
final class HostMapAgencyBrandSource implements AgencyBrandSource
{
    /** @param array<string, AgencyBrand> $map */
    public function __construct(private readonly array $map)
    {
    }

    public function forHost(string $host): ?AgencyBrand
    {
        return $this->map[$host] ?? null;
    }
}
