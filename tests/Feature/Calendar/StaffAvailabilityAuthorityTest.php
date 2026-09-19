<?php

namespace Tests\Feature\Calendar;

use App\Exceptions\Calendar\StaffAvailabilityAuthorityException;
use App\Library\Calendar\CalendarLocationResolver;
use App\Library\Calendar\StaffAvailabilityService;
use App\Models\StaffTimeOff;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Calendar\Concerns\CreatesCalendarAuthorityFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §5.2, §5.3, §6 — the availability-authority
 * table, proven row by row against the real LocationAccessGuard and real
 * persistence.
 *
 *   Workspace/Business Owner -> any CURRENTLY ELIGIBLE staff member
 *   Admin                    -> themselves only
 *   Staff                    -> themselves only
 *
 * plus the three binding conditions: the actor's own Location reach, the
 * target's re-derived eligibility, and time off being User-global.
 */
class StaffAvailabilityAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarAuthorityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarAuthorityFixtures();
    }

    private function service(): StaffAvailabilityService
    {
        return app(StaffAvailabilityService::class);
    }

    private function createRuleAs(int $actorId, int $targetId, $location = null)
    {
        return $this->service()->createRule(
            $this->workspace,
            $this->business,
            $location ?? $this->locationA,
            $targetId,
            1,
            '09:00:00',
            '17:00:00',
            $actorId
        );
    }

    private function createTimeOffAs(int $actorId, int $targetId, $location = null): StaffTimeOff
    {
        return $this->service()->createTimeOff(
            $this->workspace,
            $this->business,
            $location ?? $this->locationA,
            $targetId,
            Carbon::parse('2027-01-04 09:00:00'),
            Carbon::parse('2027-01-06 17:00:00'),
            'Annual leave',
            $actorId
        );
    }

    // --- owner row ---

    public function test_owner_may_manage_an_eligible_staff_members_availability(): void
    {
        $rule = $this->createRuleAs((int) $this->owner->user_id, (int) $this->staff->id);

        $this->assertDatabaseHas('staff_availability_rules', [
            'id' => $rule->id,
            'staff_user_id' => $this->staff->id,
            'business_location_id' => $this->locationA->id,
        ]);
    }

    public function test_owner_may_manage_an_eligible_admins_availability(): void
    {
        $rule = $this->createRuleAs((int) $this->owner->user_id, (int) $this->admin->id);

        $this->assertDatabaseHas('staff_availability_rules', ['id' => $rule->id, 'staff_user_id' => $this->admin->id]);
    }

    /**
     * §6 condition 2 — the target's eligibility is re-derived at WRITE time.
     * An outsider has no membership at all, so the owner's authority over
     * "their staff" does not reach them.
     */
    public function test_owner_cannot_manage_availability_for_someone_not_eligible_at_this_location(): void
    {
        $this->expectException(StaffAvailabilityAuthorityException::class);

        $this->createRuleAs((int) $this->owner->user_id, (int) $this->outsider->id);
    }

    /**
     * The sharp version of condition 2: a real member of this Workspace who
     * is granted Location A only is NOT a legitimate target at Location B,
     * even for the owner.
     */
    public function test_owner_cannot_manage_a_members_availability_at_a_location_that_member_cannot_reach(): void
    {
        $restricted = $this->memberGrantedOnly($this->locationA);

        // Eligible where they are granted...
        $rule = $this->createRuleAs((int) $this->owner->user_id, (int) $restricted->id, $this->locationA);
        $this->assertDatabaseHas('staff_availability_rules', ['id' => $rule->id]);

        // ...and refused at the sibling Location they are not.
        $this->expectException(StaffAvailabilityAuthorityException::class);
        $this->createRuleAs((int) $this->owner->user_id, (int) $restricted->id, $this->locationB);
    }

    /**
     * §6 condition 2 again, via the live membership: revoking the target's
     * membership makes them immediately ineligible, with no configuration
     * row deleted anywhere.
     */
    public function test_a_deactivated_members_availability_can_no_longer_be_written(): void
    {
        $this->createRuleAs((int) $this->owner->user_id, (int) $this->staff->id);

        $membership = app(WorkspaceMembershipRepository::class)
            ->findByWorkspaceAndUser($this->workspace, (int) $this->staff->id);
        app(WorkspaceMembershipRepository::class)->setActive($membership, false);

        $this->expectException(StaffAvailabilityAuthorityException::class);
        $this->createRuleAs((int) $this->owner->user_id, (int) $this->staff->id);
    }

    // --- admin row ---

    public function test_admin_may_manage_their_own_availability(): void
    {
        $rule = $this->createRuleAs((int) $this->admin->id, (int) $this->admin->id);

        $this->assertDatabaseHas('staff_availability_rules', ['id' => $rule->id, 'staff_user_id' => $this->admin->id]);
    }

    /**
     * §6 — the Admin row is deliberately the narrower reading: no
     * authoritative document grants Admins team-availability management in
     * V1.
     */
    public function test_admin_cannot_manage_another_users_availability(): void
    {
        $this->expectException(StaffAvailabilityAuthorityException::class);

        $this->createRuleAs((int) $this->admin->id, (int) $this->staff->id);
    }

    public function test_admin_cannot_manage_the_owners_availability(): void
    {
        $this->expectException(StaffAvailabilityAuthorityException::class);

        $this->createRuleAs((int) $this->admin->id, (int) $this->owner->user_id);
    }

    // --- staff row ---

    public function test_staff_may_manage_their_own_availability(): void
    {
        $rule = $this->createRuleAs((int) $this->staff->id, (int) $this->staff->id);

        $this->assertDatabaseHas('staff_availability_rules', ['id' => $rule->id, 'staff_user_id' => $this->staff->id]);
    }

    public function test_staff_cannot_manage_another_users_availability(): void
    {
        $this->expectException(StaffAvailabilityAuthorityException::class);

        $this->createRuleAs((int) $this->staff->id, (int) $this->admin->id);
    }

    // --- the predicate itself, as the surface reads it ---

    public function test_the_authority_predicate_matches_the_contract_table(): void
    {
        $service = $this->service();

        $ownerId = (int) $this->owner->user_id;
        $adminId = (int) $this->admin->id;
        $staffId = (int) $this->staff->id;

        $may = fn (int $actor, int $target): bool => $service->mayManageAvailabilityFor(
            $actor,
            $target,
            $this->workspace,
            $this->business,
            $this->locationA
        );

        // Owner: any eligible target, including themselves.
        $this->assertTrue($may($ownerId, $ownerId));
        $this->assertTrue($may($ownerId, $adminId));
        $this->assertTrue($may($ownerId, $staffId));
        $this->assertFalse($may($ownerId, (int) $this->outsider->id));

        // Admin and Staff: themselves only.
        $this->assertTrue($may($adminId, $adminId));
        $this->assertFalse($may($adminId, $staffId));
        $this->assertFalse($may($adminId, $ownerId));

        $this->assertTrue($may($staffId, $staffId));
        $this->assertFalse($may($staffId, $adminId));
        $this->assertFalse($may($staffId, $ownerId));
    }

    public function test_only_the_two_owner_branches_count_as_owner(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isOwner((int) $this->owner->user_id, $this->workspace, $this->business));
        $this->assertFalse($service->isOwner((int) $this->admin->id, $this->workspace, $this->business));
        $this->assertFalse($service->isOwner((int) $this->staff->id, $this->workspace, $this->business));
    }

    // --- deletion follows the same table ---

    public function test_staff_cannot_delete_another_users_availability_rule(): void
    {
        $rule = $this->createRuleAs((int) $this->owner->user_id, (int) $this->admin->id);

        $this->expectException(StaffAvailabilityAuthorityException::class);
        $this->service()->deleteRule($this->workspace, $this->business, $this->locationA, $rule, (int) $this->staff->id);
    }

    public function test_owner_may_delete_an_eligible_staff_members_rule(): void
    {
        $rule = $this->createRuleAs((int) $this->owner->user_id, (int) $this->staff->id);

        $this->service()->deleteRule($this->workspace, $this->business, $this->locationA, $rule, (int) $this->owner->user_id);

        $this->assertDatabaseMissing('staff_availability_rules', ['id' => $rule->id]);
    }

    // --- Location scoping of rules ---

    /**
     * §5.2 — a rule belongs to one Location. A rule id from a sibling
     * Location must not resolve through another Location's surface.
     */
    public function test_a_rule_from_a_sibling_location_does_not_resolve(): void
    {
        $rule = $this->createRuleAs((int) $this->owner->user_id, (int) $this->staff->id, $this->locationA);

        $this->assertNotNull($this->service()->findRuleForLocation($this->locationA, (int) $rule->id));
        $this->assertNull($this->service()->findRuleForLocation($this->locationB, (int) $rule->id));
    }

    public function test_split_shifts_on_one_day_are_allowed(): void
    {
        $service = $this->service();
        $ownerId = (int) $this->owner->user_id;

        $service->createRule($this->workspace, $this->business, $this->locationA, (int) $this->staff->id, 2, '09:00:00', '12:00:00', $ownerId);
        $service->createRule($this->workspace, $this->business, $this->locationA, (int) $this->staff->id, 2, '13:00:00', '17:00:00', $ownerId);

        $this->assertCount(2, $service->rulesForLocation($this->locationA));
    }

    // --- time off: User-global (§5.3) ---

    /**
     * The load-bearing assertion for §5.3: the row that is written carries
     * NO Location of any kind, and the table has no column that could hold
     * one.
     */
    public function test_time_off_writes_no_location_ownership_column(): void
    {
        $timeOff = $this->createTimeOffAs((int) $this->owner->user_id, (int) $this->staff->id, $this->locationA);

        $this->assertFalse(Schema::hasColumn('staff_time_off', 'business_location_id'));
        $this->assertFalse(Schema::hasColumn('staff_time_off', 'location_id'));

        $stored = StaffTimeOff::query()->findOrFail($timeOff->id)->getAttributes();

        foreach (array_keys($stored) as $column) {
            $this->assertStringNotContainsString('location', $column, "staff_time_off must carry no Location column, found [{$column}].");
        }

        $this->assertSame((int) $this->staff->id, (int) $stored['staff_user_id']);
        $this->assertSame((int) $this->owner->user_id, (int) $stored['created_by_user_id']);
    }

    /**
     * §6 condition 3 — time off reached through Location A is not scoped to
     * Location A: it is the same single User-global row however it is read.
     */
    public function test_time_off_taken_at_one_location_is_the_same_row_everywhere(): void
    {
        $this->createTimeOffAs((int) $this->owner->user_id, (int) $this->staff->id, $this->locationA);

        $forStaff = $this->service()->timeOffForStaff([(int) $this->staff->id]);

        $this->assertCount(1, $forStaff);
        $this->assertSame((int) $this->staff->id, (int) $forStaff->first()->staff_user_id);
    }

    public function test_time_off_follows_the_same_authority_table(): void
    {
        $this->createTimeOffAs((int) $this->staff->id, (int) $this->staff->id);
        $this->assertDatabaseCount('staff_time_off', 1);

        $this->expectException(StaffAvailabilityAuthorityException::class);
        $this->createTimeOffAs((int) $this->admin->id, (int) $this->staff->id);
    }

    public function test_owner_cannot_take_time_off_for_an_ineligible_target(): void
    {
        $this->expectException(StaffAvailabilityAuthorityException::class);

        $this->createTimeOffAs((int) $this->owner->user_id, (int) $this->outsider->id);
    }

    public function test_staff_cannot_delete_another_users_time_off(): void
    {
        $timeOff = $this->createTimeOffAs((int) $this->owner->user_id, (int) $this->admin->id);

        $this->expectException(StaffAvailabilityAuthorityException::class);
        $this->service()->deleteTimeOff($this->workspace, $this->business, $this->locationA, $timeOff, (int) $this->staff->id);
    }

    /**
     * A time-off row has no Location column to scope by (§5.3), so reach is
     * scoped instead: a row belonging to someone outside this Location's
     * reach is NOT FOUND rather than merely refused. That keeps the 404
     * (existence/reach) and 403 (authority table) refusals distinct.
     */
    public function test_time_off_for_someone_outside_this_locations_reach_is_not_found(): void
    {
        $service = $this->service();

        $mine = $this->createTimeOffAs((int) $this->owner->user_id, (int) $this->staff->id, $this->locationA);
        $this->assertNotNull($service->findTimeOffWithinReach($this->locationA, (int) $mine->id));

        // A foreign Business's staff member, with their own time off.
        [$foreignBusiness, $foreignLocation, $foreignOwner] = $this->foreignBusinessWithLocation();
        $foreignWorkspace = \App\Models\Workspace::query()->findOrFail($foreignBusiness->workspace_id);

        $foreignTimeOff = $service->createTimeOff(
            $foreignWorkspace,
            $foreignBusiness,
            $foreignLocation,
            (int) $foreignOwner->user_id,
            Carbon::parse('2027-02-01 09:00:00'),
            Carbon::parse('2027-02-02 17:00:00'),
            null,
            (int) $foreignOwner->user_id
        );

        $this->assertNull(
            $service->findTimeOffWithinReach($this->locationA, (int) $foreignTimeOff->id),
            'A foreign Business\'s time-off row must not resolve through this Location.'
        );
    }

    /**
     * A restricted member's time off is reachable at the Location they are
     * granted and unreachable at the sibling Location they are not — even
     * though the row itself is User-global and identical in both cases.
     */
    public function test_time_off_reach_follows_the_targets_current_location_eligibility(): void
    {
        $restricted = $this->memberGrantedOnly($this->locationA);
        $service = $this->service();

        $timeOff = $this->createTimeOffAs((int) $this->owner->user_id, (int) $restricted->id, $this->locationA);

        $this->assertNotNull($service->findTimeOffWithinReach($this->locationA, (int) $timeOff->id));
        $this->assertNull($service->findTimeOffWithinReach($this->locationB, (int) $timeOff->id));
    }

    // --- the actor's own Location reach (§6 condition 1) ---

    /**
     * Owner authority over staff is never authority over a Location the
     * actor cannot reach. The resolver is the gate the controller uses, and
     * it refuses before any authority question is asked.
     */
    public function test_an_actor_who_cannot_reach_the_location_resolves_nothing(): void
    {
        $resolver = app(CalendarLocationResolver::class);

        $this->assertNotNull($resolver->resolveForActor($this->business, $this->locationA->uid, (int) $this->owner->user_id));
        $this->assertNull($resolver->resolveForActor($this->business, $this->locationA->uid, (int) $this->outsider->id));

        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->assertNotNull($resolver->resolveForActor($this->business, $this->locationA->uid, (int) $restricted->id));
        $this->assertNull($resolver->resolveForActor($this->business, $this->locationB->uid, (int) $restricted->id));
    }

    /**
     * Cross-Business fail-closed: a Location uid from another Business
     * cannot be reached through this Business, even by an actor who owns
     * both sides of nothing in common.
     */
    public function test_a_foreign_businesss_location_uid_never_resolves(): void
    {
        [$foreignBusiness, $foreignLocation] = $this->foreignBusinessWithLocation();
        $resolver = app(CalendarLocationResolver::class);

        $this->assertNull($resolver->resolveForActor($this->business, $foreignLocation->uid, (int) $this->owner->user_id));
        $this->assertNull($resolver->resolveForActor($foreignBusiness, $this->locationA->uid, (int) $this->owner->user_id));
    }
}
