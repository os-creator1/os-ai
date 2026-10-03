<?php

namespace Tests\Feature\Messaging;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\DTO\LocationSendContext;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * The Location-aware sender seam: which Locations a managed number is USED BY,
 * and the one rule — BusinessMessagingIdentityResolver::numberServes() — that the
 * dispatcher and Automations' preflight both apply.
 *
 * A caller that passes no Location context is exactly what it always was.
 */
class LocationAwareManagedSendTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeAdapter();
    }

    private function location(Business $business, string $name): BusinessLocation
    {
        return BusinessLocation::create(['business_id' => $business->id, 'name' => $name, 'service_mode' => 'storefront', 'country_code' => 'US']);
    }

    private function resolver(): BusinessMessagingIdentityResolver
    {
        return app(BusinessMessagingIdentityResolver::class);
    }

    private function dispatch(Business $business, ?LocationSendContext $context, string $key): \App\Library\Messaging\DTO\OutboundMessageResult
    {
        return app(ManagedMessageDispatcher::class)->dispatch($business, '+14155558201', 'hello', $key, [], '1', $context);
    }

    // ---------------------------------------------------------------
    // The rule
    // ---------------------------------------------------------------

    public function test_a_business_of_one_location_lets_its_unassigned_number_speak_for_that_location(): void
    {
        [$business, , $number] = $this->managedBusiness();
        $only = $this->location($business, 'Only');
        $other = $this->location($business, 'Other');
        DB::table('business_locations')->where('id', $other->id)->update(['lifecycle_state' => BusinessLocationLifecycleState::Archived->value, 'archived_at' => now()]);

        foreach ([true, false] as $bound) {
            $this->assertTrue($this->resolver()->numberServes($number, $business, new LocationSendContext((int) $only->id, $bound)));
            $this->assertTrue($this->resolver()->numberServes($number, $business, new LocationSendContext(null, $bound)));
            $this->assertFalse($this->resolver()->numberServes($number, $business, new LocationSendContext((int) $other->id, $bound)), 'An archived Location is not the sole active one.');
        }
    }

    public function test_with_several_locations_an_unassigned_number_serves_only_an_unrestricted_workflow(): void
    {
        [$business, , $number] = $this->managedBusiness();
        $a = $this->location($business, 'A');
        $this->location($business, 'B');

        // Business-wide: the Business's own number, exactly as before.
        $this->assertTrue($this->resolver()->numberServes($number, $business, new LocationSendContext((int) $a->id, false)));
        $this->assertTrue($this->resolver()->numberServes($number, $business, new LocationSendContext(null, false)));

        // Limited to Locations: nothing shows the number is theirs.
        $this->assertFalse($this->resolver()->numberServes($number, $business, new LocationSendContext((int) $a->id, true)));
        $this->assertFalse($this->resolver()->numberServes($number, $business, new LocationSendContext(null, true)));
    }

    public function test_an_assigned_number_serves_exactly_its_locations_for_every_kind_of_workflow(): void
    {
        [$business, , $number] = $this->managedBusiness();
        $a = $this->location($business, 'A');
        $b = $this->location($business, 'B');
        $c = $this->location($business, 'C');

        $this->resolver()->assignLocations($number, $business, [(int) $a->id, (int) $b->id]);

        foreach ([true, false] as $bound) {
            $this->assertTrue($this->resolver()->numberServes($number, $business, new LocationSendContext((int) $a->id, $bound)));
            $this->assertTrue($this->resolver()->numberServes($number, $business, new LocationSendContext((int) $b->id, $bound)));
            $this->assertFalse($this->resolver()->numberServes($number, $business, new LocationSendContext((int) $c->id, $bound)), 'Not one of its Locations.');
            $this->assertFalse($this->resolver()->numberServes($number, $business, new LocationSendContext(null, $bound)), 'A send with no Location is ambiguous for a scoped number.');
        }

        // Returning the number to Business level restores the Business-wide behaviour.
        $this->resolver()->assignLocations($number, $business, []);
        $this->assertTrue($this->resolver()->numberServes($number, $business, new LocationSendContext((int) $c->id, false)));
        $this->assertFalse($this->resolver()->numberServes($number, $business, new LocationSendContext((int) $c->id, true)));
    }

    public function test_assignment_refuses_foreign_archived_and_unknown_locations_and_foreign_numbers(): void
    {
        [$business, , $number] = $this->managedBusiness();
        [$other] = $this->managedBusiness();
        $mine = $this->location($business, 'Mine');
        $theirs = $this->location($other, 'Theirs');
        $archived = $this->location($business, 'Closed');
        DB::table('business_locations')->where('id', $archived->id)->update(['lifecycle_state' => BusinessLocationLifecycleState::Archived->value, 'archived_at' => now()]);

        foreach ([[(int) $theirs->id], [(int) $archived->id], [987654], [(int) $mine->id, (int) $theirs->id]] as $ids) {
            try {
                $this->resolver()->assignLocations($number, $business, $ids);
                $this->fail('Only active Locations of the same Business may be assigned.');
            } catch (\InvalidArgumentException) {
                $this->assertSame(0, DB::table('business_messaging_number_locations')->where('business_messaging_number_id', $number->id)->count());
            }
        }

        // A number that is not this Business's cannot be assigned through this Business.
        try {
            $this->resolver()->assignLocations($number, $other, []);
            $this->fail('A foreign number must be refused.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->resolver()->assignLocations($number, $business, [(int) $mine->id]);
        $this->assertSame([(int) $mine->id], $this->resolver()->assignedLocationIds($number));
    }

    // ---------------------------------------------------------------
    // The dispatcher
    // ---------------------------------------------------------------

    public function test_a_send_with_no_location_context_is_unchanged(): void
    {
        [$business] = $this->managedBusiness();
        $this->location($business, 'A');
        $this->location($business, 'B');

        $this->assertTrue($this->dispatch($business, null, 'op_plain')->accepted);
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
    }

    public function test_a_send_a_number_cannot_prove_is_refused_before_any_operation_measurement_or_provider_call(): void
    {
        [$business] = $this->managedBusiness();
        $a = $this->location($business, 'A');
        $this->location($business, 'B');

        try {
            $this->dispatch($business, new LocationSendContext((int) $a->id, true), 'op_refused');
            $this->fail('An unprovable sender must be refused.');
        } catch (MessagingIdentityConflictException) {
            $this->assertCount(0, $this->fakeAdapter->sentRequests);
            $this->assertSame(0, DB::table('business_messaging_operations')->where('operation_key', 'op_refused')->count());
            $this->assertSame(0, DB::table('business_usage_measurements')->where('business_id', $business->id)->count());
        }
    }

    public function test_a_send_for_an_assigned_location_goes_out_from_the_number_and_replays_once(): void
    {
        [$business, , $number] = $this->managedBusiness();
        $a = $this->location($business, 'A');
        $b = $this->location($business, 'B');
        $this->resolver()->assignLocations($number, $business, [(int) $a->id]);

        $context = new LocationSendContext((int) $a->id, true);

        $this->assertTrue($this->dispatch($business, $context, 'op_a')->accepted);
        $this->assertTrue($this->dispatch($business, $context, 'op_a')->accepted, 'The recorded result is returned.');
        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'A replay never reaches the provider twice.');
        $this->assertSame((string) $number->phone_number, $this->fakeAdapter->sentRequests[0]->fromNumber);

        $this->expectException(MessagingIdentityConflictException::class);
        $this->dispatch($business, new LocationSendContext((int) $b->id, false), 'op_b');
    }
}
