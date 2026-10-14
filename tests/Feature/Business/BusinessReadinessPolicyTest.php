<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Dashboard\DashboardStatusReader;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * The Home readiness policy is a fact of the BUSINESS (businesses.readiness_policy): every Business created through the
 * single creation seam follows seo_first_v1, an established Business that predates it stays NULL (legacy), and nothing
 * but completing the new onboarding ever moves a Business from one to the other.
 */
class BusinessReadinessPolicyTest extends TestCase
{
    use CreatesDashboardFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['business.onboarding.enabled' => true]);
    }

    private function policy(Business $business): ?string
    {
        return DB::table('businesses')->where('id', $business->id)->value('readiness_policy');
    }

    private function statusPolicy(Business $business): ?string
    {
        return app(DashboardStatusReader::class)->forBusiness($business->id)->readinessPolicy;
    }

    /** A second Workspace for the same customer with a plan, as Workspace management creates one. */
    private function newWorkspaceFor(Customer $customer, string $name): Workspace
    {
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);
        $this->assignTier($workspace, WorkspacePlanTier::Growth);

        return $workspace;
    }

    // ── assignment ───────────────────────────────────────────────────────

    public function test_every_business_created_through_the_creation_seam_follows_the_new_policy(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        $this->assertSame(Business::READINESS_POLICY_SEO_FIRST, $this->policy($business));
        $this->assertSame(Business::READINESS_POLICY_SEO_FIRST, $this->statusPolicy($business));
    }

    public function test_the_first_business_created_through_onboarding_carries_the_policy_from_its_first_save(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();
        $customer->user->email_verified_at = now();
        $customer->user->save();
        $this->actingAs($customer->user);

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes())->assertSessionHasNoErrors();

        $business = Business::where('customer_id', $customer->user_id)->firstOrFail();
        $this->assertSame(Business::READINESS_POLICY_SEO_FIRST, $this->policy($business), 'Still a Draft: it is on the policy before it is ever activated.');
        $this->assertSame(BusinessStatus::Draft, $business->status);
    }

    public function test_a_second_business_created_through_workspace_management_follows_the_new_policy_and_leaves_the_first_alone(): void
    {
        [$customer, $first] = $this->bareTenant(WorkspacePlanTier::Growth, 'Established One', 'Established One Account');
        $this->assertNull($this->policy($first), 'Precondition: an established Business predating the policy.');

        $second = app(WorkspaceManager::class)->createBusinessInWorkspace(
            (int) $customer->user_id,
            $customer,
            $this->newWorkspaceFor($customer, 'Second Account'),
            $this->businessAttributes(['name' => 'Second Co']),
        );

        $this->assertSame(Business::READINESS_POLICY_SEO_FIRST, $this->policy($second), 'A new Business added to an established customer is new-policy.');
        $this->assertNull($this->policy($first), 'The customer\'s established Business is untouched.');
        $this->assertNotSame($this->statusPolicy($first), $this->statusPolicy($second));
    }

    // ── legacy stays legacy ──────────────────────────────────────────────

    public function test_an_established_business_stays_legacy_however_it_is_touched(): void
    {
        [$customer, $business, $workspace] = array_merge($this->bareTenant(WorkspacePlanTier::Growth, 'Old Co', 'Old Account'));

        // Visiting the wizard starts a voluntary row; it must not reclassify the Business.
        $this->authenticateAs($customer);
        $this->get(route('customer.onboarding.show'))->assertOk();
        $this->get(route('user.home'));
        $this->assertNull($this->policy($business));

        // Moving it to another Workspace changes where it lives, not which policy governs it.
        $target = $this->newWorkspaceFor($customer, 'Moved To');
        app(WorkspaceManager::class)->reassignBusiness((int) $customer->user_id, $business, $target);
        $this->assertSame((int) $target->id, (int) $business->fresh()->workspace_id);
        $this->assertNull($this->policy($business));

        // A new-policy Business moved the same way keeps ITS policy.
        $second = app(WorkspaceManager::class)->createBusinessInWorkspace((int) $customer->user_id, $customer, $this->newWorkspaceFor($customer, 'Fresh'), $this->businessAttributes(['name' => 'New Co']));
        app(WorkspaceManager::class)->reassignBusiness((int) $customer->user_id, $second, $this->newWorkspaceFor($customer, 'Fresh Target'));
        $this->assertSame(Business::READINESS_POLICY_SEO_FIRST, $this->policy($second));
    }

    public function test_an_already_completed_legacy_onboarding_stays_completed_and_legacy(): void
    {
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Done Co', 'Done Account');
        $onboarding = CustomerOnboarding::create(['customer_id' => $customer->user_id, 'business_id' => $business->id, 'is_required' => true]);
        DB::table('customer_onboardings')->where('id', $onboarding->id)->update(['status' => 'completed', 'current_step' => 'complete', 'completed_at' => now()]);

        $this->authenticateAs($customer);
        $this->get(route('customer.onboarding.show'))->assertOk()->assertSee('Your setup is complete');
        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $this->assertSame('completed', $onboarding->fresh()->status->value);
        $this->assertNull($this->policy($business), 'Revisiting or re-posting completion never retro-stamps a legacy completion.');
    }

    public function test_a_partially_completed_legacy_onboarding_adopts_the_policy_when_it_finishes_in_the_new_flow(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->assignTier($workspace, WorkspacePlanTier::Growth);
        // A Draft Business created before the policy existed (column NULL) with an onboarding left at the old Assets step.
        $business = $this->rawAddBusiness($customer, $workspace, 'Half Done Co', BusinessStatus::Draft);
        DB::table('businesses')->where('id', $business->id)->update(['readiness_policy' => null]);
        app(BusinessLocationRepository::class)->upsertPrimary($business, ['name' => 'Main', 'service_mode' => 'service_area', 'city' => 'Orange', 'region' => 'CA', 'country_code' => 'US']);
        $this->primaryService($business);
        DB::table('customer_onboardings')->insert([
            'customer_id' => $customer->user_id, 'business_id' => $business->id, 'is_required' => 1, 'status' => 'started', 'current_step' => 'assets',
            'completed_steps' => json_encode(['goals', 'business', 'location', 'services']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNull($this->policy($business));

        $this->authenticateAs($customer);
        $this->get(route('customer.onboarding.show', ['step' => 'assets']))->assertRedirect(route('customer.onboarding.show', ['step' => 'website']));
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'later'])->assertSessionHasNoErrors();
        $this->assertNull($this->policy($business), 'Not before completion.');

        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
        $this->assertSame(Business::READINESS_POLICY_SEO_FIRST, $this->policy($business));
    }

    // ── isolation ────────────────────────────────────────────────────────

    public function test_adopting_the_policy_changes_only_that_business(): void
    {
        [, $a] = $this->bareTenant(WorkspacePlanTier::Growth, 'A Co', 'A Account');
        [, $b] = $this->bareTenant(WorkspacePlanTier::Growth, 'B Co', 'B Account');

        app(BusinessRepository::class)->adoptReadinessPolicy($a);
        app(BusinessRepository::class)->adoptReadinessPolicy($a);

        $this->assertSame(Business::READINESS_POLICY_SEO_FIRST, $this->policy($a));
        $this->assertNull($this->policy($b), 'Another Business, and another customer, is never touched.');
    }

    // ── setup links for a secondary Business ────────────────────────────

    public function test_a_secondary_business_opens_its_own_settings_not_the_customers_wizard(): void
    {
        [$customer, $first, $firstWorkspace] = array_merge($this->bareTenant(WorkspacePlanTier::Growth, 'Primary Co', 'Primary Account'));
        $this->adoptSeoFirstPolicy($first);
        DB::table('customer_onboardings')->insert(['customer_id' => $customer->user_id, 'business_id' => $first->id, 'is_required' => 1, 'status' => 'completed', 'current_step' => 'finish', 'created_at' => now(), 'updated_at' => now()]);

        $secondWorkspace = $this->newWorkspaceFor($customer, 'Secondary Account');
        $second = $this->rawAddBusiness($customer, $secondWorkspace, 'Secondary Co');
        $this->assertSame(Business::READINESS_POLICY_SEO_FIRST, $this->policy($second));
        $this->assertFalse((bool) $second->is_primary);

        $this->authenticateAs($customer);

        // The primary Business, which the wizard is bound to, may use the wizard for its missing Service.
        $this->switchTo($firstWorkspace, $first);
        $primaryHtml = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringContainsString(route('customer.onboarding.show', ['step' => 'services']), $primaryHtml);

        // The secondary Business must not send the customer into the wizard of the OTHER Business.
        $this->switchTo($secondWorkspace, $second);
        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-home-state="setup"', $html);
        $this->assertStringNotContainsString(route('customer.onboarding.show', ['step' => 'services']), $html);
        $this->assertStringNotContainsString(route('customer.onboarding.show', ['step' => 'business']), $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.settings.show', [$secondWorkspace->uid, $second->uid]), $html, 'Its own Settings.');
        $this->assertStringContainsString(route('customer.workspaces.businesses.locations.index', [$secondWorkspace->uid, $second->uid]), $html, 'Its own Locations.');
    }

    public function test_setup_links_are_authorised_per_actor_and_never_cross_businesses(): void
    {
        [$owner, $business, $workspace] = array_merge($this->bareTenant(WorkspacePlanTier::Growth, 'Shared Co', 'Shared Account'));
        $this->adoptSeoFirstPolicy($business);
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, \App\Enums\Workspace\WorkspaceMembershipRole::Staff, \App\Enums\Workspace\WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);

        $this->authenticateAs($staff);
        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('customer.onboarding.show', ['step' => 'services']), $html, 'Staff never get the owner\'s wizard.');
        $this->assertStringNotContainsString(route('customer.business.edit'), $html);

        // Another customer cannot open this Business's settings at all.
        [$stranger] = $this->bareTenant(WorkspacePlanTier::Growth, 'Stranger Co', 'Stranger Account');
        $this->authenticateAs($stranger);
        $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertNotFound();
    }
}
