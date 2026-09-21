<?php

namespace Tests\Feature\Calendar;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\CustomerAccountAccessGuard;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\Appointment;
use App\Models\Blacklists;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PublicBookingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    private $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootCalendarHttpFixtures();
        $staff = $this->bookableStaff();
        $this->type = $this->bookingType();
        $this->type->staff()->attach($staff->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(): string
    {
        return route('public.booking.show', [$this->type->public_booking_uuid]);
    }

    private function payload(string $phone = '+1 (415) 555-1234', string $time = '10:00'): array
    {
        return [
            'date' => '2027-03-01', 'time' => $time,
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => $phone,
        ];
    }

    public function test_uuid_route_and_throttle_are_the_only_public_address(): void
    {
        $route = Route::getRoutes()->getByName('public.booking.store');
        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
        $this->assertSame('book/{bookingTypeUuid}', $route->uri());
        $this->get('/book/not-a-uuid')->assertNotFound();
        $this->get('/book/'.$this->type->uid)->assertNotFound();
        $this->get('/book/'.$this->business->uid)->assertNotFound();
        $this->get('/book/'.$this->locationA->uid)->assertNotFound();
        $this->get($this->url().'?date=2027-02-31')->assertNotFound();
    }

    public function test_guest_books_and_owner_sees_exact_appointment(): void
    {
        $this->get($this->url().'?date=2027-03-01')
            ->assertOk()->assertSee('10:00')->assertSee('name="phone"', false);

        $this->post($this->url(), $this->payload())
            ->assertRedirect(route('public.booking.confirmed', [$this->type->public_booking_uuid]));
        $this->get(route('public.booking.confirmed', [$this->type->public_booking_uuid]))
            ->assertOk()->assertSee('Booking confirmed');

        $this->assertDatabaseCount('appointments', 1);
        $appointment = Appointment::query()->firstOrFail();
        $this->assertNull($appointment->created_by_user_id);
        $this->assertSame((int) $this->type->id, (int) $appointment->booking_type_id);
        $this->assertSame((int) $this->locationA->id, (int) $appointment->business_location_id);
        $this->assertSame('2027-03-01 15:00:00', Carbon::parse($appointment->start_at)->utc()->toDateTimeString());
        $this->assertSame((int) $this->locationA->id, (int) $appointment->contact->location_id);

        $this->authenticate($this->owner->user);
        $this->get($this->calendarUrl('booking-types.index', $this->scopeFor($this->locationA)))
            ->assertOk()->assertSee($this->url(), false);
        $this->get($this->calendarUrl('schedule', $this->scopeFor($this->locationA)).'?date=2027-03-01')
            ->assertOk()->assertSee($appointment->uid);
    }

    public function test_contact_identity_is_location_local_and_oldest_match_wins(): void
    {
        $this->post($this->url(), $this->payload())->assertRedirect();
        $first = Contacts::query()->firstOrFail();
        $this->post($this->url(), $this->payload('14155551234', '11:00'))->assertRedirect();
        $this->assertDatabaseCount('contacts', 1);

        $group = ContactGroups::query()->where('business_id', $this->business->id)->firstOrFail();
        $later = Contacts::create([
            'customer_id' => $group->customer_id, 'business_id' => $group->business_id,
            'location_id' => $this->locationA->id, 'group_id' => $group->id,
            'phone' => '14155551234', 'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
        $this->post($this->url(), $this->payload('1 (415) 555-1234', '12:00'))->assertRedirect();
        $this->assertSame((int) $first->id, (int) Appointment::query()->latest('id')->first()->contact_id);
        $this->assertNotSame((int) $later->id, (int) Appointment::query()->latest('id')->first()->contact_id);

        $sibling = $this->bookingType($this->locationB);
        $staff = $this->bookableStaff($this->locationB);
        $sibling->staff()->attach($staff->id);
        $this->post(route('public.booking.store', [$sibling->public_booking_uuid]), $this->payload('14155551234', '13:00'))
            ->assertRedirect();
        $this->assertSame(2, Contacts::query()->where('phone', '14155551234')->distinct()->count('location_id'));
    }

    public function test_public_authority_refusals_are_identical_on_get_and_post(): void
    {
        $this->assertDenied(function (): void {
            $this->locationA->lifecycle_state = BusinessLocationLifecycleState::Archived;
            $this->locationA->save();
        });
        $this->locationA->lifecycle_state = BusinessLocationLifecycleState::Active;
        $this->locationA->save();

        $this->assertDenied(function (): void {
            $this->business->status = BusinessStatus::Inactive;
            $this->business->save();
        });
        $this->business->status = BusinessStatus::Active;
        $this->business->save();

        $this->assertDenied(function (): void {
            $this->workspace->is_active = false;
            $this->workspace->save();
        });
        $this->workspace->is_active = true;
        $this->workspace->save();

        $this->assertDenied(function (): void {
            $this->type->is_active = false;
            $this->type->save();
        });
        $this->type->is_active = true;
        $this->type->save();

        $this->assertDenied(function (): void { $this->type->staff()->detach(); });
    }

    private function platformAdminId(): int
    {
        return (int) User::create([
            'first_name' => 'Platform', 'last_name' => 'Admin',
            'email' => 'calendar-admin-'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    private function assertPublicNotFound(): void
    {
        $this->get($this->url())->assertNotFound();
        $this->post($this->url(), $this->payload())->assertNotFound();
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_canonical_locked_lifecycle_refuses_get_and_post(): void
    {
        $manager = app(EntitlementManager::class);
        $manager->enterGracePeriod($this->workspace, null, 'Fixture grace.');
        $manager->lockForNonPayment($this->workspace, null, 'Fixture lock.');
        $this->assertTrue(app(CustomerAccountAccessGuard::class)->decisionForBusiness($this->business->fresh())->isLocked());
        $this->assertPublicNotFound();
    }

    #[DataProvider('inactiveAndSuspended')]
    public function test_canonical_account_status_refuses_get_and_post(WorkspacePlanAssignmentStatus $status): void
    {
        app(EntitlementManager::class)->changePlanStatus($this->workspace, $status, $this->platformAdminId(), 'Fixture status.');
        $this->assertTrue(app(CustomerAccountAccessGuard::class)->decisionForBusiness($this->business->fresh())->isLocked());
        $this->assertPublicNotFound();
    }

    public static function inactiveAndSuspended(): array
    {
        return [
            'inactive' => [WorkspacePlanAssignmentStatus::Inactive],
            'suspended' => [WorkspacePlanAssignmentStatus::Suspended],
        ];
    }

    public function test_no_plan_entitlement_refuses_get_and_post(): void
    {
        DB::table('workspace_plan_assignments')->where('workspace_id', $this->workspace->id)->delete();
        $this->assertPublicNotFound();
    }

    #[DataProvider('agencyLockedStates')]
    public function test_managing_agency_lock_refuses_client_public_get_and_post(string $state): void
    {
        $agencyOwner = $this->createCustomer();
        $agency = $this->createWorkspace($agencyOwner->user);
        $manager = app(EntitlementManager::class);
        $manager->assignFirstPlan($agency, WorkspacePlanTier::Agency, $this->platformAdminId(), 'Fixture Agency.', true, 0);
        $this->assertFalse(app(CustomerAccountAccessGuard::class)->decisionForBusiness($this->business->fresh())->isLocked());
        app(AgencyClientRelationshipManager::class)->create((int) $agency->owner_user_id, $agency, $this->workspace);

        match ($state) {
            'locked' => [
                $manager->enterGracePeriod($agency, null, 'Fixture grace.'),
                $manager->lockForNonPayment($agency, null, 'Fixture lock.'),
            ],
            'inactive' => $manager->changePlanStatus($agency, WorkspacePlanAssignmentStatus::Inactive, $this->platformAdminId(), 'Fixture inactive.'),
            'suspended' => $manager->changePlanStatus($agency, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Fixture suspended.'),
        };
        $this->assertTrue(app(CustomerAccountAccessGuard::class)->decisionForBusiness($this->business->fresh())->isLocked());
        $this->assertPublicNotFound();
    }

    public static function agencyLockedStates(): array
    {
        return ['locked' => ['locked'], 'inactive' => ['inactive'], 'suspended' => ['suspended']];
    }

    public function test_stale_staff_pivot_and_rule_do_not_restore_revoked_location_access(): void
    {
        $staffId = $this->type->staff()->firstOrFail()->id;
        DB::table('workspace_memberships')->where('workspace_id', $this->workspace->id)
            ->where('user_id', $staffId)->update(['location_access_scope' => 'selected']);
        $this->assertDatabaseHas('booking_type_staff', ['booking_type_id' => $this->type->id, 'staff_user_id' => $staffId]);
        $this->assertDatabaseHas('staff_availability_rules', ['business_location_id' => $this->locationA->id, 'staff_user_id' => $staffId]);
        $this->assertPublicNotFound();
    }

    public function test_public_creation_writes_custom_fields_without_welcome_or_signup_sms(): void
    {
        $group = app(\App\Repositories\Eloquent\EloquentContactsRepository::class)->store([
            'name' => 'Contacts', 'business_id' => $this->business->id,
            'user_id' => $this->business->customer_id,
        ]);
        $group->send_welcome_sms = true;
        $group->welcome_sms = 'Welcome';
        $group->signup_sms = 'Signup';
        $group->save();

        $this->post($this->url(), $this->payload())->assertRedirect();
        $contact = Contacts::query()->firstOrFail();
        $this->assertSame('14155551234', (string) $contact->phone);
        $this->assertSame((int) $this->locationA->id, (int) $contact->location_id);
        $this->assertDatabaseHas('contacts_custom_field', ['contact_id' => $contact->id, 'value' => 'Ada']);
        $this->assertDatabaseHas('contacts_custom_field', ['contact_id' => $contact->id, 'value' => 'Lovelace']);
        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_blacklisted_phone_is_refused_without_contact_or_appointment(): void
    {
        Blacklists::create([
            'user_id' => $this->business->customer_id,
            'business_id' => $this->business->id,
            'number' => '14155551234',
            'reason' => 'Fixture',
        ]);
        $this->post($this->url(), $this->payload())->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('appointments', 0);
    }

    private function assertDenied(callable $change): void
    {
        $change();
        $this->get($this->url())->assertNotFound();
        $this->post($this->url(), $this->payload())->assertNotFound();
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_spoofed_internal_ids_do_not_change_derived_location_or_contact(): void
    {
        $this->post($this->url(), $this->payload() + [
            'business_id' => 99999, 'business_location_id' => $this->locationB->id,
            'contact_id' => 99999, 'staff_user_id' => 99999,
        ])->assertRedirect();
        $appointment = Appointment::query()->firstOrFail();
        $this->assertSame((int) $this->locationA->id, (int) $appointment->business_location_id);
        $this->assertNotSame(99999, (int) $appointment->contact_id);
        $this->assertNotSame(99999, (int) $appointment->staff_user_id);
    }

    public function test_conflicting_slot_leaves_one_appointment_and_one_contact(): void
    {
        $this->post($this->url(), $this->payload())->assertRedirect();
        $this->post($this->url(), $this->payload())->assertSessionHasErrors('time');
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_refused_new_phone_leaves_no_orphan_contact(): void
    {
        $this->post($this->url(), $this->payload())->assertRedirect();
        $this->post($this->url(), $this->payload('14155559999'))->assertSessionHasErrors('time');
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseMissing('contacts', ['location_id' => $this->locationA->id, 'phone' => '14155559999']);
    }
}
