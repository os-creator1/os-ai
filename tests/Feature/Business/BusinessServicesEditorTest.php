<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Business\BusinessServiceManager;
use App\Library\Dashboard\DashboardStatusReader;
use App\Models\Business;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Settings -> Business -> Services: the Business-scoped BusinessService editor, and Home's missing-primary-Service action
 * pointing at it (a secondary Business has no onboarding wizard of its own).
 */
class BusinessServicesEditorTest extends TestCase
{
    use CreatesDashboardFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['business.onboarding.enabled' => true]);
    }

    /** @return array{0: Customer, 1: Business, 2: Workspace} a Business with the SEO-first policy and no Service. */
    private function tenant(string $name = 'Edit Co'): array
    {
        [$customer, $business, $workspace] = $this->bareTenant(WorkspacePlanTier::Growth, $name, $name . ' Account');
        $this->adoptSeoFirstPolicy($business);
        $this->authenticateAs($customer);

        return [$customer, $business, $workspace];
    }

    private function url(string $route, Workspace $workspace, Business $business, ?BusinessService $service = null): string
    {
        return route('customer.workspaces.businesses.services.' . $route, array_filter([$workspace->uid, $business->uid, $service?->uid]));
    }

    private function names(Business $business, ?string $status = null): array
    {
        return BusinessService::query()->where('business_id', $business->id)
            ->when($status, fn ($q) => $q->where('status', $status))->orderBy('id')->pluck('name')->all();
    }

    private function primaries(Business $business): array
    {
        return BusinessService::query()->where('business_id', $business->id)->where('is_primary', true)->pluck('name')->all();
    }

    private function service(Business $business, string $name): BusinessService
    {
        return BusinessService::query()->where('business_id', $business->id)->where('name', $name)->firstOrFail();
    }

    private function hasPrimaryService(Business $business): bool
    {
        return app(DashboardStatusReader::class)->forBusiness($business->id)->hasPrimaryService;
    }

    // ── the secondary-Business journey ──────────────────────────────────

    public function test_a_secondary_business_completes_its_primary_service_step_from_home(): void
    {
        [$customer, $first, $firstWorkspace] = $this->tenant('Primary Co');
        $secondWorkspace = $this->createWorkspace($customer->user, ['name' => 'Second Account']);
        $this->assignTier($secondWorkspace, WorkspacePlanTier::Growth);
        $second = $this->rawAddBusiness($customer, $secondWorkspace, 'Second Co');
        $this->primaryService($first, 'First Business Service');

        $this->switchTo($secondWorkspace, $second);
        $editor = $this->url('index', $secondWorkspace, $second);

        $home = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringContainsString('Add your primary service', $home);
        $this->assertStringContainsString($editor, $home);
        $this->assertStringNotContainsString(route('customer.onboarding.show', ['step' => 'services']), $home);
        $this->assertFalse($this->hasPrimaryService($second));

        $this->get($editor)->assertOk()->assertSee('Add a service')->assertSee('no active service yet');
        $this->post($this->url('store', $secondWorkspace, $second), ['name' => 'Roof Repair'])
            ->assertRedirect($editor)->assertSessionHas('flash_success');

        $this->assertSame(['Roof Repair'], $this->primaries($second));
        $this->assertTrue($this->hasPrimaryService($second));
        $this->assertSame(['First Business Service'], $this->names($first), 'The other Business is untouched.');

        $home = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Add your primary service', $home);
        $this->assertStringNotContainsString($editor, $home, 'The step is done, so it is not asked for again.');
        $this->get($editor)->assertOk()->assertSee('Roof Repair')->assertSee('Primary service');
    }

    public function test_the_first_service_becomes_primary_and_a_later_one_does_not_take_it(): void
    {
        [, $business, $workspace] = $this->tenant();

        $this->post($this->url('store', $workspace, $business), ['name' => 'Alpha']);
        $this->post($this->url('store', $workspace, $business), ['name' => 'Beta']);

        $this->assertSame(['Alpha', 'Beta'], $this->names($business));
        $this->assertSame(['Alpha'], $this->primaries($business));
    }

    // ── primary invariants, inactive services, no deletion ──────────────

    public function test_choosing_a_primary_keeps_exactly_one_and_only_an_active_service_can_be_it(): void
    {
        [, $business, $workspace] = $this->tenant();
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $this->post($this->url('store', $workspace, $business), ['name' => $name]);
        }

        $this->post($this->url('primary', $workspace, $business, $this->service($business, 'Gamma')))->assertSessionHas('flash_success');
        $this->assertSame(['Gamma'], $this->primaries($business));

        $this->post($this->url('deactivate', $workspace, $business, $this->service($business, 'Beta')));
        $this->post($this->url('primary', $workspace, $business, $this->service($business, 'Beta')))
            ->assertSessionHas('flash_error', 'Only an active service can be your primary service.');
        $this->assertSame(['Gamma'], $this->primaries($business));
    }

    public function test_turning_off_the_primary_promotes_another_active_service_and_turning_off_the_last_leaves_none(): void
    {
        [, $business, $workspace] = $this->tenant();
        $this->post($this->url('store', $workspace, $business), ['name' => 'Alpha']);
        $this->post($this->url('store', $workspace, $business), ['name' => 'Beta']);

        $this->post($this->url('deactivate', $workspace, $business, $this->service($business, 'Alpha')));
        $this->assertSame(['Beta'], $this->primaries($business));
        $this->assertTrue($this->hasPrimaryService($business));

        $this->post($this->url('deactivate', $workspace, $business, $this->service($business, 'Beta')));
        $this->assertSame([], $this->primaries($business));
        $this->assertFalse($this->hasPrimaryService($business), 'An inactive service is not a foundation.');
        $this->assertSame(['Alpha', 'Beta'], $this->names($business), 'Nothing is ever deleted.');

        $this->post($this->url('activate', $workspace, $business, $this->service($business, 'Alpha')));
        $this->assertSame(['Alpha'], $this->primaries($business));
        $this->assertTrue($this->hasPrimaryService($business));
    }

    public function test_rename_keeps_the_service_its_slug_and_its_other_details(): void
    {
        [, $business, $workspace] = $this->tenant();
        $this->post($this->url('store', $workspace, $business), ['name' => 'Alpha']);
        $this->post($this->url('store', $workspace, $business), ['name' => 'Beta']);
        $alpha = $this->service($business, 'Alpha');
        $alpha->forceFill(['description' => 'Kept description', 'starting_price' => 12.5])->save();

        $this->post($this->url('rename', $workspace, $business, $alpha), ['name' => '  Alpha   Plus '])->assertSessionHas('flash_success');

        $fresh = $alpha->fresh();
        $this->assertSame('Alpha Plus', $fresh->name);
        $this->assertSame($alpha->slug, $fresh->slug, 'The slug is stable so website / SEO mappings keep resolving.');
        $this->assertSame('Kept description', $fresh->description);
        $this->assertTrue($fresh->is_primary);

        $this->post($this->url('rename', $workspace, $business, $fresh), ['name' => 'beta'])
            ->assertSessionHas('flash_error', 'You already have a service with that name.');
        $this->assertSame('Alpha Plus', $fresh->fresh()->name);
    }

    public function test_adding_the_name_of_an_inactive_service_turns_it_back_on_instead_of_duplicating_it(): void
    {
        [, $business, $workspace] = $this->tenant();
        $this->post($this->url('store', $workspace, $business), ['name' => 'Alpha']);
        $this->post($this->url('deactivate', $workspace, $business, $this->service($business, 'Alpha')));

        $this->post($this->url('store', $workspace, $business), ['name' => 'ALPHA']);

        $this->assertSame(['Alpha'], $this->names($business));
        $this->assertSame(['Alpha'], $this->names($business, 'active'));
        $this->assertSame(['Alpha'], $this->primaries($business));
    }

    // ── repeated submissions ────────────────────────────────────────────

    public function test_repeated_identical_submissions_change_nothing_more(): void
    {
        [, $business, $workspace] = $this->tenant();
        $alpha = null;

        for ($i = 0; $i < 3; $i++) {
            $this->post($this->url('store', $workspace, $business), ['name' => 'Alpha'])->assertSessionHas('flash_success');
        }
        $this->assertSame(['Alpha'], $this->names($business));

        $alpha = $this->service($business, 'Alpha');
        $slug = $alpha->slug;
        for ($i = 0; $i < 3; $i++) {
            $this->post($this->url('primary', $workspace, $business, $alpha));
            $this->post($this->url('activate', $workspace, $business, $alpha));
        }
        $this->post($this->url('deactivate', $workspace, $business, $alpha));
        $this->post($this->url('deactivate', $workspace, $business, $alpha));

        $this->assertSame(['Alpha'], $this->names($business));
        $this->assertSame($slug, $alpha->fresh()->slug);
        $this->assertSame([], $this->primaries($business));
    }

    public function test_validation_refuses_a_blank_or_too_short_name_and_writes_nothing(): void
    {
        [, $business, $workspace] = $this->tenant();

        $this->post($this->url('store', $workspace, $business), ['name' => ' '])->assertSessionHasErrors('name');
        $this->post($this->url('store', $workspace, $business), ['name' => 'x'])->assertSessionHasErrors('name');
        $this->assertSame([], $this->names($business));
    }

    // ── authorization and isolation ─────────────────────────────────────

    public function test_staff_may_read_but_not_change_services_and_home_offers_them_no_editor(): void
    {
        [, $business, $workspace] = $this->tenant();
        $this->post($this->url('store', $workspace, $business), ['name' => 'Alpha']);
        $alpha = $this->service($business, 'Alpha');

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $this->authenticateAs($staff);

        $this->get($this->url('index', $workspace, $business))->assertOk()->assertSee('Alpha')->assertDontSee('Add a service')->assertDontSee('Make primary');

        $this->post($this->url('store', $workspace, $business), ['name' => 'Sneaky'])->assertStatus(401);
        $this->post($this->url('rename', $workspace, $business, $alpha), ['name' => 'Renamed'])->assertStatus(401);
        $this->post($this->url('deactivate', $workspace, $business, $alpha))->assertStatus(401);
        $this->post($this->url('primary', $workspace, $business, $alpha))->assertStatus(401);

        $this->assertSame(['Alpha'], $this->names($business));
        $this->assertTrue($alpha->fresh()->status->value === 'active');
    }

    public function test_the_manager_itself_refuses_an_actor_who_may_not_manage(): void
    {
        [, $business, $workspace] = $this->tenant();
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);

        $this->expectException(AuthorizationException::class);
        app(BusinessServiceManager::class)->add((int) $staff->user_id, $business, 'Nope');
    }

    public function test_an_outsider_cannot_see_or_change_another_customers_business_services(): void
    {
        [, $business, $workspace] = $this->tenant('Owner Co');
        $this->post($this->url('store', $workspace, $business), ['name' => 'Alpha']);
        $alpha = $this->service($business, 'Alpha');

        [$stranger] = $this->bareTenant(WorkspacePlanTier::Growth, 'Stranger Co', 'Stranger Account');
        $this->authenticateAs($stranger);

        $this->get($this->url('index', $workspace, $business))->assertNotFound();
        $this->post($this->url('store', $workspace, $business), ['name' => 'Sneaky'])->assertNotFound();
        $this->post($this->url('deactivate', $workspace, $business, $alpha))->assertNotFound();
        $this->assertSame(['Alpha'], $this->names($business));
    }

    public function test_a_service_of_one_business_cannot_be_changed_through_another_business(): void
    {
        [$customer, $a, $aWorkspace] = $this->tenant('A Co');
        $bWorkspace = $this->createWorkspace($customer->user, ['name' => 'B Account']);
        $this->assignTier($bWorkspace, WorkspacePlanTier::Growth);
        $b = $this->rawAddBusiness($customer, $bWorkspace, 'B Co');

        $this->post($this->url('store', $aWorkspace, $a), ['name' => 'Alpha']);
        $this->post($this->url('store', $bWorkspace, $b), ['name' => 'Beta']);
        $alpha = $this->service($a, 'Alpha');

        // A's service uid through B's scope: not found, nothing changes in either Business.
        $this->post($this->url('rename', $bWorkspace, $b, $alpha), ['name' => 'Hijacked'])->assertNotFound();
        $this->post($this->url('deactivate', $bWorkspace, $b, $alpha))->assertNotFound();
        $this->post($this->url('primary', $bWorkspace, $b, $alpha))->assertNotFound();

        // And the manager refuses a foreign service even when called directly.
        try {
            app(BusinessServiceManager::class)->deactivate((int) $customer->user_id, $b, $alpha);
            $this->fail('A foreign service must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('That service does not belong to this business.', $e->getMessage());
        }

        $this->assertSame(['Alpha'], $this->names($a, 'active'));
        $this->assertSame(['Beta'], $this->names($b, 'active'));
        $this->assertSame(['Alpha'], $this->primaries($a));
        $this->assertSame(['Beta'], $this->primaries($b));
    }

    // ── contracts ───────────────────────────────────────────────────────

    public function test_services_are_not_catalog_items(): void
    {
        [, $business, $workspace] = $this->tenant();
        $before = DB::table('catalog_items')->count();

        $this->post($this->url('store', $workspace, $business), ['name' => 'Alpha']);

        $this->assertSame($before, DB::table('catalog_items')->count());
    }

    public function test_the_settings_hub_links_to_the_services_editor(): void
    {
        [, $business, $workspace] = $this->tenant();

        $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))
            ->assertOk()->assertSee($this->url('index', $workspace, $business), false);
    }
}
