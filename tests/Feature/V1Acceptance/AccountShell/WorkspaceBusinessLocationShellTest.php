<?php

namespace Tests\Feature\V1Acceptance\AccountShell;

use App\Library\Navigation\CustomerContext;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\V1Acceptance\AccountShell\Concerns\DrivesAccountJourneys;
use Tests\TestCase;

/**
 * V1 FINAL ACCEPTANCE 01 — journey E: the Workspace / Business / Location shell
 * of a Business owner who got there through the real signup.
 *
 * What is proven: one Business per V1 Workspace, a Primary Location from
 * signup, the context switcher staying hidden for a single Business, a second
 * Location added through the canonical customer path without moving the
 * Workspace/Business identity, and a guessed foreign Location being refused.
 *
 * NOT proven here, on purpose and recorded in the acceptance document: a global
 * "current Location" switcher. None exists on this branch (Blueprint §7 asks
 * for one; `ContextSwitcherPresenter` states a Location is deliberately not
 * offered, and Location-bound surfaces take their Location per request).
 */
class WorkspaceBusinessLocationShellTest extends TestCase
{
    use RefreshDatabase;
    use DrivesAccountJourneys;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->bootJourney();
    }

    private function locationForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Harbor Lane — Second Studio',
            'service_mode' => 'storefront',
            'address_line_1' => '12 Dock Street',
            'city' => 'Portland',
            'region' => 'OR',
            'postal_code' => '97201',
            'country_code' => 'US',
            'public_address' => '1',
        ], $overrides);
    }

    public function test_the_owner_context_is_exactly_one_business_with_a_primary_location_and_no_switcher(): void
    {
        $account = $this->completeJourney('growth');
        $workspace = $account['workspace'];
        $business = $account['business'];

        $home = $this->get(route('user.home'))->assertOk();

        // Exactly one Business for the V1 Workspace, and it is the one selected.
        $this->assertSame(1, Business::query()->where('workspace_id', $workspace->id)->count());
        $context = app(CustomerContext::class);
        $this->assertTrue($context->isBusinessFrame());
        $this->assertSame($business->uid, $context->selectedBusiness?->uid);
        $this->assertSame($workspace->uid, $context->selectedWorkspace?->uid);
        $this->assertSame(1, $context->selectableBusinessCount());

        // One Business: the context switcher is not rendered at all.
        $this->assertFalse($context->showsSwitcher());
        $this->assertStringNotContainsString('customer.context.business.switch', $home->getContent());
        $this->assertStringNotContainsString(route('customer.context.business.switch'), $home->getContent());

        // The Primary Location from signup exists, is active, and is listed.
        $location = BusinessLocation::query()->where('business_id', $business->id)->sole();
        $this->assertTrue((bool) $location->is_primary);
        $this->assertTrue($location->isActive());
        $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee($location->uid, false);
    }

    public function test_a_second_location_added_through_the_canonical_path_never_moves_workspace_or_business_identity(): void
    {
        $account = $this->completeJourney('growth');
        $workspace = $account['workspace'];
        $business = $account['business'];
        $primary = BusinessLocation::query()->where('business_id', $business->id)->sole();
        $scoped = [$workspace->uid, $business->uid];

        $identityBefore = [
            Workspace::query()->pluck('uid')->all(),
            Business::query()->pluck('uid')->all(),
        ];
        $navBefore = $this->menuKeys($this->get(route('user.home'))->assertOk()->getContent());

        $this->post(route('customer.workspaces.businesses.locations.store', $scoped), $this->locationForm())
            ->assertRedirect(route('customer.workspaces.businesses.locations.index', $scoped))
            ->assertSessionHas('flash_success');

        $locations = BusinessLocation::query()->where('business_id', $business->id)->orderBy('id')->get();
        $this->assertCount(2, $locations);
        $this->assertSame($primary->id, $locations->firstWhere('is_primary', true)->id, 'Adding a Location never moves the Primary one.');
        $this->assertSame(1, $locations->where('is_primary', true)->count());

        // Identity is untouched: same Workspace, same Business, still one of each.
        $this->assertSame($identityBefore, [Workspace::query()->pluck('uid')->all(), Business::query()->pluck('uid')->all()]);
        $this->assertSame(1, Business::query()->where('workspace_id', $workspace->id)->count());

        // The shell is unchanged: a Location is not an account-switcher level.
        $home = $this->get(route('user.home'))->assertOk();
        $this->assertSame($navBefore, $this->menuKeys($home->getContent()));
        $context = app(CustomerContext::class);
        $this->assertSame($business->uid, $context->selectedBusiness?->uid);
        $this->assertFalse($context->showsSwitcher(), 'Two Locations are not two Businesses: no Business switcher.');

        // Both Locations are listed for the owner.
        $second = $locations->firstWhere('is_primary', false);
        $this->get(route('customer.workspaces.businesses.locations.index', $scoped))
            ->assertOk()
            ->assertSee($primary->uid, false)
            ->assertSee($second->uid, false);
    }

    public function test_a_guessed_foreign_location_is_refused_and_unchanged(): void
    {
        $account = $this->completeJourney('growth');
        $workspace = $account['workspace'];
        $business = $account['business'];

        // A second, unrelated tenant with its own Location.
        $other = $this->createIndependentWorkspaceBusiness(null, 'Other Co', 'Other WS');
        $foreign = BusinessLocation::query()->create([
            'business_id' => $other['business']->id,
            'name' => 'Foreign Studio',
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
        $snapshot = $foreign->fresh()->only(['name', 'is_primary', 'status']);

        // The foreign Location uid under MY Workspace/Business: not found.
        $mine = [$workspace->uid, $business->uid];
        $this->get(route('customer.workspaces.businesses.locations.edit', [...$mine, $foreign->uid]))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.locations.update', [...$mine, $foreign->uid]), $this->locationForm(['name' => 'Hijacked']))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.locations.primary', [...$mine, $foreign->uid]))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.locations.archive', [...$mine, $foreign->uid]), ['confirm' => '1'])->assertNotFound();

        // The foreign Business itself, by its own uids: not found either.
        $theirs = [$other['workspace']->uid, $other['business']->uid];
        $this->get(route('customer.workspaces.businesses.locations.index', $theirs))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.locations.edit', [...$theirs, $foreign->uid]))->assertNotFound();

        $this->assertSame($snapshot, $foreign->fresh()->only(['name', 'is_primary', 'status']), 'A refused request changes nothing.');
    }
}
