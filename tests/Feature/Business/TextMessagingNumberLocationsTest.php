<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Settings -> Text messaging -> "Locations that use this number": the one control
 * that lets a workflow limited to Locations prove its text speaks for them.
 *
 * Changing it changes what the Business sends as, so it takes the same owner or
 * account-manager authority as registration — and a Location chosen by uid is
 * resolved inside THIS Business only.
 */
class TextMessagingNumberLocationsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private function location(Business $business, string $name): BusinessLocation
    {
        return BusinessLocation::create(['business_id' => $business->id, 'name' => $name, 'service_mode' => 'storefront', 'country_code' => 'US']);
    }

    private function url(Business $business, $workspace): string
    {
        return route('customer.workspaces.businesses.text-messaging.number.locations.update', [$workspace->uid, $business->uid]);
    }

    private function assigned(Business $business): array
    {
        $number = app(BusinessMessagingIdentityResolver::class)->resolvePrimaryNumber(
            app(BusinessMessagingIdentityResolver::class)->resolveForBusiness($business),
        );

        return app(BusinessMessagingIdentityResolver::class)->assignedLocationIds($number);
    }

    public function test_the_owner_chooses_the_locations_that_use_the_number_and_can_clear_them(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550601');
        $a = $this->location($business, 'Downtown');
        $b = $this->location($business, 'Uptown');
        $this->location($business, 'Midtown');
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $this->put($this->url($business, $workspace), ['location_uids' => [$a->uid, $b->uid]])->assertSessionHas('status', 'success');
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], $this->assigned($business));

        $this->put($this->url($business, $workspace), [])->assertSessionHas('status', 'success');
        $this->assertSame([], $this->assigned($business), 'Clearing returns the number to Business level.');
    }

    public function test_another_businesss_or_an_archived_location_is_refused_and_nothing_changes(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        [, $other] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550602');
        $mine = $this->location($business, 'Mine');
        $theirs = $this->location($other, 'Theirs');
        $closed = $this->location($business, 'Closed');
        DB::table('business_locations')->where('id', $closed->id)->update(['lifecycle_state' => BusinessLocationLifecycleState::Archived->value, 'archived_at' => now()]);
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $this->put($this->url($business, $workspace), ['location_uids' => [$mine->uid]])->assertSessionHas('status', 'success');

        $this->put($this->url($business, $workspace), ['location_uids' => [$mine->uid, $theirs->uid]])->assertSessionHas('status', 'error');
        $this->put($this->url($business, $workspace), ['location_uids' => [$closed->uid]])->assertSessionHas('status', 'error');
        $this->put($this->url($business, $workspace), ['location_uids' => ['not-a-uid']])->assertSessionHas('status', 'error');

        $this->assertSame([(int) $mine->id], $this->assigned($business), 'A refused change leaves the last good assignment.');
    }

    public function test_a_plain_member_cannot_change_which_locations_use_the_number(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550603');
        $a = $this->location($business, 'Downtown');

        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, true);
        $this->authenticateAs($staff, ['view_numbers', 'buy_numbers']);

        $this->put($this->url($business, $workspace), ['location_uids' => [$a->uid]])->assertStatus(401);
        $this->assertSame([], $this->assigned($business));
    }

    public function test_without_a_number_there_is_nothing_to_assign(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $a = $this->location($business, 'Downtown');
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $this->put($this->url($business, $workspace), ['location_uids' => [$a->uid]])->assertSessionHas('status', 'error');
        $this->assertSame(0, DB::table('business_messaging_number_locations')->count());
    }
}
