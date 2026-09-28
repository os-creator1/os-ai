<?php

namespace Tests\Feature\Workspace;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Manual acceptance defect 1 (P0) — a self-signup V1 owner whose Workspace
 * already holds an assigned, usable Core/Growth plan (isBusinessFirstAccount()
 * requires a resolved tier, which only exists once a plan is assigned, and
 * CustomerAccountAccessGate already refuses an unusable assignment before
 * this controller ever runs) but whose one Business is still Draft had no
 * way out: WorkspaceController::show() redirected to Home, and Home's own
 * primary action for that exact state (AccountHomePresenter::createUrl())
 * pointed straight back to this same route — an infinite loop, since
 * draftClientActivationUrl() is Agency-invited-client only. Root cause:
 * nothing in the product ever activated a self-signup Business at all.
 */
class SelfSignupBusinessActivationRedirectTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
    }

    /**
     * @return array{customer: \App\Models\Customer, business: Business, workspace: \App\Models\Workspace}
     */
    private function stuckSelfSignupAccount(): array
    {
        $fixture = $this->createIndependentWorkspaceBusiness(status: BusinessStatus::Draft);
        app(EntitlementManager::class)->assignFirstPlan(
            $fixture['workspace'], WorkspacePlanTier::Growth, $this->platformAdminId(), 'test fixture', true, 0
        );

        return $fixture;
    }

    public function test_a_stuck_self_signup_account_is_self_healed_and_reaches_business_settings(): void
    {
        $fixture = $this->stuckSelfSignupAccount();
        $this->authenticateAs($fixture['customer']);

        $response = $this->get(route('customer.workspaces.show', $fixture['workspace']->uid));

        $response->assertRedirect(route('customer.workspaces.businesses.settings.show', [$fixture['workspace']->uid, $fixture['business']->uid]));
    }

    public function test_it_never_loops_back_to_home(): void
    {
        $fixture = $this->stuckSelfSignupAccount();
        $this->authenticateAs($fixture['customer']);

        $response = $this->get(route('customer.workspaces.show', $fixture['workspace']->uid));

        $response->assertRedirect();
        $this->assertNotSame(route('user.home'), $response->headers->get('Location'));
    }

    public function test_the_business_is_actually_activated_not_just_redirected_around(): void
    {
        $fixture = $this->stuckSelfSignupAccount();
        $this->authenticateAs($fixture['customer']);

        $this->get(route('customer.workspaces.show', $fixture['workspace']->uid));

        $business = Business::find($fixture['business']->id);
        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertNotNull($business->activated_at);
    }

    /**
     * A genuine Agency-managed Client Workspace must never be self-healed
     * through this path — activation there is deliberately the client
     * owner's own explicit confirmation step over real placeholder data
     * (ClientBusinessActivationController), never an automatic flip. This
     * constructs the (currently synthetic, since Client Workspaces carry no
     * billing today) edge case of an assigned plan coexisting with an
     * active relationship, to prove the relationship check really does
     * take precedence over the self-heal.
     */
    public function test_an_agency_managed_client_workspace_is_never_self_healed(): void
    {
        $managed = $this->createAgencyManagedClient();
        DB::table('businesses')->where('id', $managed['clientBusiness']->id)->update(['status' => BusinessStatus::Draft->value, 'activated_at' => null]);
        app(EntitlementManager::class)->assignFirstPlan(
            $managed['clientWorkspace'], WorkspacePlanTier::Growth, $this->platformAdminId(), 'test fixture', true, 0
        );

        $this->authenticateAs($managed['clientOwner']);

        $response = $this->get(route('customer.workspaces.show', $managed['clientWorkspace']->uid));

        $response->assertRedirect(route('user.home'));
        $this->assertSame(BusinessStatus::Draft, Business::find($managed['clientBusiness']->id)->status);
    }
}
