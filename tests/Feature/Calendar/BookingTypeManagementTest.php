<?php

namespace Tests\Feature\Calendar;

use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\CalendarLocationResolver;
use App\Models\BookingType;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Calendar\Concerns\CreatesCalendarAuthorityFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §5.1, §6, §12.B — Booking Type CRUD and the
 * staff pool.
 *
 * Booking Type management is deliberately ROLE-BLIND (§6): any actor
 * authorized for the Location may manage that Location's Booking Types.
 * What is NOT role-blind is the staff pool — every nomination re-derives
 * the candidate's eligibility through LocationAccessGuard, and a
 * `booking_type_staff` row is configuration intent that never grants
 * anything.
 */
class BookingTypeManagementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarAuthorityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarAuthorityFixtures();
    }

    private function manager(): BookingTypeManager
    {
        return app(BookingTypeManager::class);
    }

    private function makeBookingType($location = null, array $overrides = []): BookingType
    {
        return $this->manager()->create(
            $location ?? $this->locationA,
            array_merge(['name' => 'Consultation', 'duration_minutes' => 30], $overrides),
            (int) $this->owner->user_id
        );
    }

    // --- CRUD ---

    public function test_a_booking_type_is_created_bound_to_exactly_one_location(): void
    {
        $bookingType = $this->makeBookingType();

        $this->assertDatabaseHas('booking_types', [
            'id' => $bookingType->id,
            'business_location_id' => $this->locationA->id,
            'name' => 'Consultation',
            'duration_minutes' => 30,
            'created_by_user_id' => $this->owner->user_id,
        ]);
        $this->assertTrue($bookingType->is_active);
        $this->assertNotSame($bookingType->uid, $bookingType->public_booking_uuid);
    }

    public function test_updating_a_booking_type_never_moves_it_between_locations(): void
    {
        $bookingType = $this->makeBookingType();

        $this->manager()->update($bookingType, [
            'name' => 'Extended consultation',
            'duration_minutes' => 60,
            'description' => 'Longer slot',
            // Deliberately submitted, and deliberately ignored: a Booking
            // Type belongs to one Location from creation (§5.1).
            'business_location_id' => $this->locationB->id,
        ]);

        $this->assertDatabaseHas('booking_types', [
            'id' => $bookingType->id,
            'name' => 'Extended consultation',
            'duration_minutes' => 60,
            'business_location_id' => $this->locationA->id,
        ]);
    }

    public function test_updating_never_rewrites_the_public_booking_address(): void
    {
        $bookingType = $this->makeBookingType();
        $original = $bookingType->public_booking_uuid;

        $this->manager()->update($bookingType, [
            'name' => 'Renamed',
            'duration_minutes' => 45,
            'public_booking_uuid' => 'attacker-supplied',
        ]);

        $this->assertSame($original, $bookingType->refresh()->public_booking_uuid);
    }

    public function test_a_booking_type_can_be_deactivated_and_reactivated(): void
    {
        $bookingType = $this->makeBookingType();

        $this->manager()->setActive($bookingType, false);
        $this->assertFalse($bookingType->refresh()->is_active);

        $this->manager()->setActive($bookingType, true);
        $this->assertTrue($bookingType->refresh()->is_active);
    }

    public function test_listing_is_scoped_to_one_location(): void
    {
        $this->makeBookingType($this->locationA, ['name' => 'At A']);
        $this->makeBookingType($this->locationB, ['name' => 'At B']);

        $this->assertSame(['At A'], $this->manager()->forLocation($this->locationA)->pluck('name')->all());
        $this->assertSame(['At B'], $this->manager()->forLocation($this->locationB)->pluck('name')->all());
    }

    /**
     * Fail closed on a sibling Location's uid: knowing the id never
     * bypasses the scope (Addendum §4).
     */
    public function test_a_booking_type_uid_from_a_sibling_location_does_not_resolve(): void
    {
        $bookingType = $this->makeBookingType($this->locationA);

        $this->assertNotNull($this->manager()->findForLocation($this->locationA, $bookingType->uid));
        $this->assertNull($this->manager()->findForLocation($this->locationB, $bookingType->uid));
    }

    public function test_a_booking_type_uid_from_a_foreign_business_does_not_resolve(): void
    {
        [, $foreignLocation] = $this->foreignBusinessWithLocation();
        $foreignType = $this->manager()->create(
            $foreignLocation,
            ['name' => 'Foreign', 'duration_minutes' => 15],
            (int) $this->owner->user_id
        );

        $this->assertNull($this->manager()->findForLocation($this->locationA, $foreignType->uid));
    }

    // --- Location ACL: who may reach the surface at all ---

    /**
     * §6 — Booking Type management is role-blind: owner, Admin and Staff
     * all reach it through the same Location authorization, and an
     * unrelated user reaches nothing.
     */
    public function test_every_location_authorized_role_reaches_the_surface_and_outsiders_do_not(): void
    {
        $resolver = app(CalendarLocationResolver::class);

        foreach ([$this->owner->user_id, $this->admin->id, $this->staff->id] as $actorId) {
            $this->assertNotNull(
                $resolver->resolveForActor($this->business, $this->locationA->uid, (int) $actorId),
                "Actor [{$actorId}] should reach Location A."
            );
        }

        $this->assertNull($resolver->resolveForActor($this->business, $this->locationA->uid, (int) $this->outsider->id));
    }

    public function test_a_member_granted_one_location_cannot_reach_the_sibling_location(): void
    {
        $restricted = $this->memberGrantedOnly($this->locationA);
        $resolver = app(CalendarLocationResolver::class);

        $this->assertNotNull($resolver->resolveForActor($this->business, $this->locationA->uid, (int) $restricted->id));
        $this->assertNull($resolver->resolveForActor($this->business, $this->locationB->uid, (int) $restricted->id));
    }

    // --- staff pool: configuration intent, never authorization ---

    public function test_eligible_candidates_are_attached(): void
    {
        $bookingType = $this->makeBookingType();

        $result = $this->manager()->syncStaff($bookingType, [(int) $this->staff->id, (int) $this->admin->id]);

        $this->assertSame([], $result['refused']);
        $this->assertEqualsCanonicalizing([(int) $this->staff->id, (int) $this->admin->id], $result['attached']);
        $this->assertDatabaseHas('booking_type_staff', [
            'booking_type_id' => $bookingType->id,
            'staff_user_id' => $this->staff->id,
        ]);
    }

    /**
     * §6 configuration-time eligibility: an unrelated Workspace's User
     * cannot be attached by supplying a raw id.
     */
    public function test_an_ineligible_candidate_is_refused_and_never_written(): void
    {
        $bookingType = $this->makeBookingType();

        $result = $this->manager()->syncStaff($bookingType, [(int) $this->outsider->id]);

        $this->assertSame([(int) $this->outsider->id], $result['refused']);
        $this->assertSame([], $result['attached']);
        $this->assertDatabaseMissing('booking_type_staff', [
            'booking_type_id' => $bookingType->id,
            'staff_user_id' => $this->outsider->id,
        ]);
    }

    public function test_a_member_who_cannot_reach_this_locations_sibling_is_refused_there(): void
    {
        $restricted = $this->memberGrantedOnly($this->locationA);

        $atA = $this->makeBookingType($this->locationA);
        $atB = $this->makeBookingType($this->locationB);

        $this->assertSame([], $this->manager()->syncStaff($atA, [(int) $restricted->id])['refused']);
        $this->assertSame([(int) $restricted->id], $this->manager()->syncStaff($atB, [(int) $restricted->id])['refused']);
    }

    public function test_a_mixed_submission_saves_the_eligible_and_refuses_the_rest(): void
    {
        $bookingType = $this->makeBookingType();

        $result = $this->manager()->syncStaff($bookingType, [(int) $this->staff->id, (int) $this->outsider->id]);

        $this->assertSame([(int) $this->staff->id], $result['attached']);
        $this->assertSame([(int) $this->outsider->id], $result['refused']);
    }

    /**
     * THE load-bearing pivot assertion (§5.1, §6): a nomination that is
     * left in place after the person loses access does NOT make them
     * eligible again. The row survives; the eligibility does not.
     */
    public function test_a_stale_pivot_row_never_restores_eligibility(): void
    {
        $bookingType = $this->makeBookingType();
        $this->manager()->syncStaff($bookingType, [(int) $this->staff->id]);

        $this->assertDatabaseHas('booking_type_staff', [
            'booking_type_id' => $bookingType->id,
            'staff_user_id' => $this->staff->id,
        ]);

        // Access revoked, configuration row deliberately left alone.
        $membership = app(WorkspaceMembershipRepository::class)
            ->findByWorkspaceAndUser($this->workspace, (int) $this->staff->id);
        app(WorkspaceMembershipRepository::class)->setActive($membership, false);

        $this->assertDatabaseHas('booking_type_staff', [
            'booking_type_id' => $bookingType->id,
            'staff_user_id' => $this->staff->id,
        ]);

        $configured = $this->manager()->configuredStaffWithEligibility($bookingType->refresh());
        $row = collect($configured)->firstWhere('user.id', $this->staff->id);

        $this->assertNotNull($row, 'The stale nomination is still configured.');
        $this->assertFalse($row['eligible'], 'A stale booking_type_staff row must never read as eligible.');

        // And re-submitting it is refused rather than silently re-saved.
        $this->assertSame([(int) $this->staff->id], $this->manager()->syncStaff($bookingType, [(int) $this->staff->id])['refused']);
    }

    public function test_detaching_works_even_for_a_now_ineligible_member(): void
    {
        $bookingType = $this->makeBookingType();
        $this->manager()->syncStaff($bookingType, [(int) $this->staff->id]);

        $membership = app(WorkspaceMembershipRepository::class)
            ->findByWorkspaceAndUser($this->workspace, (int) $this->staff->id);
        app(WorkspaceMembershipRepository::class)->setActive($membership, false);

        $this->manager()->syncStaff($bookingType, []);

        $this->assertDatabaseMissing('booking_type_staff', [
            'booking_type_id' => $bookingType->id,
            'staff_user_id' => $this->staff->id,
        ]);
    }

    // --- eligible-candidate enumeration ---

    public function test_eligible_staff_lists_only_currently_authorized_people(): void
    {
        $restricted = $this->memberGrantedOnly($this->locationA);
        $resolver = app(CalendarLocationResolver::class);

        $atA = $resolver->eligibleStaff($this->workspace, $this->business, $this->locationA)
            ->pluck('id')->map(static fn ($id) => (int) $id);
        $atB = $resolver->eligibleStaff($this->workspace, $this->business, $this->locationB)
            ->pluck('id')->map(static fn ($id) => (int) $id);

        $this->assertTrue($atA->contains((int) $restricted->id));
        $this->assertFalse($atB->contains((int) $restricted->id));

        $this->assertTrue($atA->contains((int) $this->owner->user_id));
        $this->assertTrue($atA->contains((int) $this->admin->id));
        $this->assertFalse($atA->contains((int) $this->outsider->id));
    }
}
