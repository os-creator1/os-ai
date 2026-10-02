<?php

namespace Tests\Feature\V1Acceptance\AccountShell;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Navigation\CurrentLocation;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\Feature\V1Acceptance\AccountShell\Concerns\DrivesAccountJourneys;
use Tests\TestCase;

/**
 * Blueprint §7 — the shell's Location switcher: shown only with more than one
 * reachable Location, offering only already-granted Locations, never changing
 * Workspace/Business and never granting access, and re-scoping a Location-bound
 * surface (the Contacts directory).
 */
class LocationSwitcherTest extends TestCase
{
    use RefreshDatabase;
    use DrivesAccountJourneys;
    use CreatesCrmFixtures;

    private Workspace $workspace;

    private Business $business;

    private Customer $owner;

    private BusinessLocation $a;

    private BusinessLocation $b;

    private BusinessLocation $c;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->bootJourney();

        $account = $this->completeJourney('growth');
        $this->workspace = $account['workspace'];
        $this->business = $account['business'];
        $this->owner = $account['customer'];
        $this->a = BusinessLocation::query()->where('business_id', $this->business->id)->sole();
        $this->b = $this->makeLocation('Second Studio');
        $this->c = $this->makeLocation('Third Studio');
    }

    private function makeLocation(string $name): BusinessLocation
    {
        return BusinessLocation::query()->create(['business_id' => $this->business->id, 'name' => $name, 'service_mode' => 'storefront', 'country_code' => 'US']);
    }

    private function switchPayload(?string $locationUid, ?Business $business = null, ?Workspace $workspace = null): array
    {
        return ['workspace' => ($workspace ?? $this->workspace)->uid, 'business' => ($business ?? $this->business)->uid, 'location' => $locationUid];
    }

    private function staff(array $granted): Customer
    {
        $staff = $this->createCustomer();
        $membership = $this->member($this->workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        foreach ($granted as $location) {
            app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        }

        return $staff;
    }

    private function switcherOptions(Customer $actor): array
    {
        return app(CurrentLocation::class)->options($this->business, (int) $actor->user_id)->pluck('uid')->all();
    }

    public function test_owner_with_several_locations_sees_the_switcher_with_every_active_location(): void
    {
        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="location-switcher"', $html);
        foreach ([$this->a, $this->b, $this->c] as $location) {
            $this->assertStringContainsString('value="' . $location->uid . '"', $html);
        }
    }

    public function test_the_switcher_is_hidden_for_a_single_location_business_and_for_a_single_granted_location(): void
    {
        // Staff granted exactly one of three Locations: one reachable Location -> no switcher.
        $staff = $this->staff([$this->b]);
        $this->authenticateAs($staff, ['view_contact']);
        $this->assertSame([$this->b->uid], $this->switcherOptions($staff));
        $this->assertStringNotContainsString('data-role="location-switcher"', $this->get(route('user.home'))->assertOk()->getContent());

        // Down to one Location for the owner: hidden entirely.
        DB::table('workspace_membership_locations')->delete();
        BusinessLocation::query()->whereIn('id', [$this->b->id, $this->c->id])->delete();
        $this->authenticateAs($this->owner);
        $this->assertStringNotContainsString('data-role="location-switcher"', $this->get(route('user.home'))->assertOk()->getContent());
    }

    public function test_selected_staff_are_offered_only_their_granted_locations_and_cannot_select_another(): void
    {
        $staff = $this->staff([$this->a, $this->c]);
        $this->authenticateAs($staff, ['view_contact']);

        $this->assertEqualsCanonicalizing([$this->a->uid, $this->c->uid], $this->switcherOptions($staff));
        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringContainsString('value="' . $this->c->uid . '"', $html);
        $this->assertStringNotContainsString('value="' . $this->b->uid . '"', $html);

        $this->post(route('customer.context.location.switch'), $this->switchPayload($this->b->uid))->assertNotFound();
        $this->assertNull(session(CurrentLocation::SESSION_KEY)[$this->business->uid] ?? null);
        $this->post(route('customer.context.location.switch'), $this->switchPayload($this->c->uid))->assertRedirect();
        $this->assertSame($this->c->uid, session(CurrentLocation::SESSION_KEY)[$this->business->uid]);
    }

    public function test_a_forged_foreign_location_can_never_be_selected_and_context_is_unchanged(): void
    {
        $other = $this->createIndependentWorkspaceBusiness(null, 'Other Co', 'Other WS');
        $foreign = BusinessLocation::query()->create(['business_id' => $other['business']->id, 'name' => 'Foreign', 'service_mode' => 'storefront', 'country_code' => 'US']);

        $this->post(route('customer.context.location.switch'), $this->switchPayload($foreign->uid))->assertNotFound();
        $this->post(route('customer.context.location.switch'), $this->switchPayload($foreign->uid, $other['business'], $other['workspace']))->assertNotFound();
        $this->post(route('customer.context.location.switch'), $this->switchPayload($this->b->uid, $other['business'], $other['workspace']))->assertNotFound();
        $this->assertSame([], session(CurrentLocation::SESSION_KEY) ?? []);

        // A legitimate switch moves neither Workspace nor Business.
        $this->post(route('customer.context.location.switch'), $this->switchPayload($this->b->uid))->assertRedirect();
        $context = app(\App\Library\Navigation\CustomerContext::class);
        $this->get(route('user.home'))->assertOk();
        $context = app(\App\Library\Navigation\CustomerContext::class);
        $this->assertSame($this->business->uid, $context->selectedBusiness?->uid);
        $this->assertSame($this->workspace->uid, $context->selectedWorkspace?->uid);
    }

    public function test_switching_location_rescopes_the_contacts_directory(): void
    {
        $inA = $this->crmContact($this->business, ['FIRST_NAME' => 'Alma'], '14155550101');
        $inA->forceFill(['location_id' => $this->a->id])->save();
        $inB = $this->crmContact($this->business, ['FIRST_NAME' => 'Boris'], '14155550202');
        $inB->forceFill(['location_id' => $this->b->id])->save();
        $list = route('customer.workspaces.businesses.people.index', [$this->workspace->uid, $this->business->uid]);

        $all = $this->get($list)->assertOk()->getContent();
        $this->assertStringContainsString($inA->uid, $all);
        $this->assertStringContainsString($inB->uid, $all);

        $this->post(route('customer.context.location.switch'), $this->switchPayload($this->b->uid));
        $scoped = $this->get($list)->assertOk()->getContent();
        $this->assertStringContainsString($inB->uid, $scoped);
        $this->assertStringNotContainsString($inA->uid, $scoped);

        $this->post(route('customer.context.location.switch'), $this->switchPayload(null));
        $this->assertStringContainsString($inA->uid, $this->get($list)->assertOk()->getContent());
    }
}
