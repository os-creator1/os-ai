<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Owner / Admin V1, post-Agency integration — the support cockpit
 * against the Agency-aware domain now on main. Nothing here re-implements an
 * Agency rule: every expectation is the canonical resolver's, or a persisted
 * Agency fact read back.
 */
class PlatformOwnerAgencyAwareTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
    }

    private function adminReason(Workspace $workspace): string
    {
        $html = $this->get(route('admin.workspaces.show', $workspace))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/data-testid="po-access-reason">([^<]+)</', $html, $match));

        return trim($match[1]);
    }

    public function test_the_diagnostic_matches_the_agency_aware_resolver_for_a_client_in_every_agency_state(): void
    {
        $fixture = $this->createAgencyManagedClient();
        $client = $fixture['clientWorkspace'];
        $agency = $fixture['agencyWorkspace'];
        $manager = app(EntitlementManager::class);
        $this->actingAsPlatformOwner();

        $states = [
            'agency usable' => [fn () => null, 'usable'],
            'agency locked' => [fn () => $manager->lockForNonPayment($agency, $this->platformAdminId(), 'Fixture.'), 'agency_locked'],
            'agency inactive' => [fn () => $manager->changePlanStatus($agency, WorkspacePlanAssignmentStatus::Inactive, $this->platformAdminId(), 'Fixture.'), 'agency_inactive'],
            'agency suspended' => [fn () => $manager->changePlanStatus($agency, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Fixture.'), 'agency_suspended'],
        ];

        foreach ($states as $name => [$apply, $expectedReason]) {
            $apply();

            $canonical = app(CustomerAccountAccessResolver::class)->resolve($client->fresh());
            $this->assertSame($expectedReason, $canonical->reason, "[{$name}] sanity: the canonical Agency-aware answer.");
            $this->assertSame($canonical->reason, $this->adminReason($client), "[{$name}] the client's diagnostic must be the resolver's.");
        }

        // The Agency's own page shows ITS own canonical answer, not its client's.
        $this->assertSame(
            app(CustomerAccountAccessResolver::class)->resolve($agency->fresh())->reason,
            $this->adminReason($agency),
        );
    }

    public function test_an_agency_managed_workspace_is_represented_with_agency_plan_and_white_label(): void
    {
        $fixture = $this->createAgencyManagedClient();
        AgencyWhiteLabelSetting::query()->getModel()->forceFill([
            'agency_workspace_id' => $fixture['agencyWorkspace']->id,
            'is_enabled' => true,
            'display_name' => 'Brand Of The Agency',
        ])->save();
        $this->actingAsPlatformOwner();

        $client = $this->get(route('admin.workspaces.show', $fixture['clientWorkspace']))->assertOk();
        $client->assertSee('data-testid="po-agency"', false);
        $client->assertSee('Agency-managed');
        $client->assertSee($fixture['agencyWorkspace']->name);
        $client->assertSee('active since');
        $client->assertSee('data-testid="po-agency-white-label"', false);
        $client->assertSee('enabled');
        $client->assertDontSee('data-testid="po-agency-terminated"', false);

        $agency = $this->get(route('admin.workspaces.show', $fixture['agencyWorkspace']))->assertOk();
        $agency->assertSee('1 client Workspace');
        $agency->assertSee('data-testid="po-agency-white-label"', false);
    }

    public function test_a_workspace_with_no_agency_shows_no_agency_card(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsPlatformOwner();

        $this->get(route('admin.workspaces.show', $workspace))->assertOk()->assertDontSee('data-testid="po-agency"', false);
    }

    public function test_a_terminated_relationship_never_appears_active(): void
    {
        $fixture = $this->createAgencyManagedClient();
        app(AgencyClientRelationshipManager::class)->terminate(
            (int) $fixture['agencyOwner']->user_id,
            $fixture['relationship'],
            'Contract ended by mutual agreement.',
        );
        $this->actingAsPlatformOwner();

        $response = $this->get(route('admin.workspaces.show', $fixture['clientWorkspace']))->assertOk();

        $response->assertSee('data-testid="po-agency-terminated"', false);
        $response->assertSee('the relationship was terminated');
        $response->assertSee('Contract ended by mutual agreement.');
        $response->assertSee('Previously managed by');
        $response->assertDontSee('Managing Agency');
        $response->assertDontSee('Agency SaaS plan');
        $response->assertDontSee('data-testid="po-agency-white-label"', false);

        // And the canonical answer no longer composes with the Agency.
        $this->assertNotSame('agency_locked', $this->adminReason($fixture['clientWorkspace']));

        // The Agency no longer counts the client as managed.
        $this->get(route('admin.workspaces.show', $fixture['agencyWorkspace']))->assertOk()->assertDontSee('client Workspace');
    }

    public function test_an_agency_owner_still_gains_no_platform_owner_authority(): void
    {
        $fixture = $this->createAgencyManagedClient();
        AgencyWhiteLabelSetting::query()->getModel()->forceFill([
            'agency_workspace_id' => $fixture['agencyWorkspace']->id,
            'is_enabled' => true,
            'display_name' => 'Brand Of The Agency',
        ])->save();
        $this->authenticateAs($fixture['agencyOwner']);

        foreach ($this->platformOwnerGetUrls($fixture['clientWorkspace'], $fixture['clientBusiness']) as $url) {
            $this->get($url)->assertUnauthorized();
        }
        foreach ($this->platformOwnerGetUrls($fixture['agencyWorkspace'], $fixture['agencyBusiness']) as $url) {
            $this->get($url)->assertUnauthorized();
        }
    }

    public function test_no_agency_or_provider_secret_is_rendered_on_agency_pages(): void
    {
        $fixture = $this->createAgencyManagedClient();
        AgencyWhiteLabelSetting::query()->getModel()->forceFill([
            'agency_workspace_id' => $fixture['agencyWorkspace']->id,
            'is_enabled' => true,
            'display_name' => 'Brand Of The Agency',
            'support_email' => 'support@agency.test',
        ])->save();
        $this->actingAsPlatformOwner();

        $urls = [
            route('admin.workspaces.show', $fixture['clientWorkspace']),
            route('admin.workspaces.show', $fixture['agencyWorkspace']),
            route('admin.businesses.show', $fixture['clientBusiness']),
            route('admin.businesses.show', $fixture['agencyBusiness']),
            route('admin.platform-owner.overview'),
        ];

        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach (['refresh_token', 'access_token', 'sk_live', 'sk_test', 'whsec_', 'client_secret', 'api_secret', 'logo_path'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $html, "{$url} must not render [{$forbidden}].");
            }
        }
    }

    public function test_workspace_detail_query_count_stays_flat_as_an_agency_gains_clients(): void
    {
        $fixture = $this->createAgencyManagedClient();
        $this->actingAsPlatformOwner();

        DB::enableQueryLog();
        $this->get(route('admin.workspaces.show', $fixture['agencyWorkspace']))->assertOk();
        $this->get(route('admin.workspaces.show', $fixture['clientWorkspace']))->assertOk();
        $few = count(DB::getQueryLog());

        for ($i = 2; $i <= 6; $i++) {
            $this->createAgencyManagedClient($fixture['agencyWorkspace'], "Client {$i}", "Client WS {$i}");
        }

        DB::flushQueryLog();
        $this->get(route('admin.workspaces.show', $fixture['agencyWorkspace']))->assertOk();
        $this->get(route('admin.workspaces.show', $fixture['clientWorkspace']))->assertOk();
        $many = count(DB::getQueryLog());

        $this->assertSame($few, $many, 'Agency client count must not change the cockpit query count.');
    }
}
