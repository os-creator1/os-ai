<?php

namespace Tests\Feature\Calendar;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Calendar\Concerns\CreatesCalendarAuthorityFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §6, §11, §12.B, §13 proof 8 (first half) — every
 * authenticated Calendar route added by Sub-slice B fails closed with 404
 * while `PlatformFeature::Calendar` is `Planned`, for an actor who is
 * otherwise fully authorized.
 *
 * "Otherwise fully authorized" is load-bearing and is set up deliberately:
 * the Workspace is active, the Business is Active, a Core plan is assigned
 * (Calendar is already packaged in core/growth/agency, so the plan is not
 * what refuses), the actor owns both the Workspace and the Business, and the
 * Location is a real active Location of that Business. The ONLY reason these
 * routes 404 is the entitlement gate — which is exactly the property this
 * file exists to prove.
 *
 * The second half of §13 proof 8 — "and the same request succeeds after the
 * flip" — belongs to Sub-slice E, which owns the Planned -> Available flip.
 * `PlatformFeatureRegistry::AVAILABILITY` is a private const that only a
 * code deploy may change, so it cannot be, and must not be, faked here.
 */
class CalendarRouteEntitlementGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarAuthorityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarAuthorityFixtures();

        DB::table('businesses')->where('id', $this->business->id)->update(['status' => BusinessStatus::Active->value]);
        $this->business = $this->business->fresh();

        $admin = User::create([
            'first_name' => 'Platform', 'last_name' => 'Admin',
            'email' => 'platform' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        app(EntitlementManager::class)->assignFirstPlan(
            $this->workspace->fresh(),
            WorkspacePlanTier::Core,
            $admin->id,
            'Fixture assignment.',
            true,
            0
        );

        $this->workspace = $this->workspace->fresh();
    }

    private function authenticate(User $user): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($user);
    }

    /**
     * The customer shell's own middleware (`ValidProduct`) reads these rows
     * on every authenticated request and fatals on a fresh database without
     * them. Seeded exactly as AgencyProspectingTest already does — an
     * environment fixture, unrelated to anything this sub-slice asserts.
     */
    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }

    /** @return array<int, array{0: string, 1: string, 2: array<int, mixed>}> */
    private function calendarRoutes(string $locationUid): array
    {
        $scope = [$this->workspace->uid, $this->business->uid, $locationUid];
        $withType = array_merge($scope, ['any-booking-type-uid']);

        return [
            ['get', 'booking-types.index', $scope],
            ['get', 'booking-types.create', $scope],
            ['post', 'booking-types.store', $scope],
            ['get', 'booking-types.edit', $withType],
            ['post', 'booking-types.update', $withType],
            ['post', 'booking-types.active', $withType],
            ['post', 'booking-types.staff', $withType],
            ['get', 'availability.index', $scope],
            ['post', 'availability.rules.store', $scope],
            ['post', 'availability.rules.destroy', array_merge($scope, [1])],
            ['post', 'availability.time-off.store', $scope],
            ['post', 'availability.time-off.destroy', array_merge($scope, [1])],
        ];
    }

    private function assertEveryCalendarRouteIsNotFound(string $locationUid): void
    {
        foreach ($this->calendarRoutes($locationUid) as [$verb, $name, $args]) {
            $url = route('customer.workspaces.businesses.calendar.' . $name, $args);

            $this->{$verb}($url)->assertNotFound();
        }
    }

    /**
     * The premise: Calendar is still Planned, and the decision layer refuses
     * before any plan or database question is asked.
     */
    public function test_calendar_is_planned_and_the_decision_layer_refuses_it(): void
    {
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::Calendar->value));

        $decision = app(EntitlementManager::class)->decide(
            $this->workspace,
            $this->business,
            PlatformFeature::Calendar->value,
            (int) $this->owner->user_id
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('platform_feature_unavailable', $decision->reason);
    }

    public function test_every_calendar_route_is_not_found_for_the_account_owner(): void
    {
        $this->authenticate($this->owner->user);

        $this->assertEveryCalendarRouteIsNotFound($this->locationA->uid);
    }

    public function test_every_calendar_route_is_not_found_for_an_admin(): void
    {
        $this->authenticate($this->admin);

        $this->assertEveryCalendarRouteIsNotFound($this->locationA->uid);
    }

    public function test_every_calendar_route_is_not_found_for_a_staff_member(): void
    {
        $this->authenticate($this->staff);

        $this->assertEveryCalendarRouteIsNotFound($this->locationA->uid);
    }

    /**
     * An ungranted Location is refused too — and indistinguishably so. While
     * Calendar is Planned the entitlement gate answers first, which is the
     * correct fail-closed ordering: the actor learns nothing about the
     * Location either way.
     */
    public function test_every_calendar_route_is_not_found_for_an_ungranted_location(): void
    {
        $this->authenticate($this->owner->user);

        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($restricted);

        $this->assertEveryCalendarRouteIsNotFound($this->locationB->uid);
    }

    public function test_every_calendar_route_is_not_found_for_a_user_outside_the_workspace(): void
    {
        $this->authenticate($this->outsider);

        $this->assertEveryCalendarRouteIsNotFound($this->locationA->uid);
    }

    /**
     * A forged direct POST writes nothing. Navigation is irrelevant — the
     * request never rendered a link.
     */
    public function test_a_forged_post_cannot_create_a_booking_type_or_availability(): void
    {
        $this->authenticate($this->owner->user);

        $this->post(route('customer.workspaces.businesses.calendar.booking-types.store', [
            $this->workspace->uid, $this->business->uid, $this->locationA->uid,
        ]), ['name' => 'Forged', 'duration_minutes' => 30])->assertNotFound();

        $this->post(route('customer.workspaces.businesses.calendar.availability.rules.store', [
            $this->workspace->uid, $this->business->uid, $this->locationA->uid,
        ]), [
            'staff_user_id' => $this->staff->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ])->assertNotFound();

        $this->post(route('customer.workspaces.businesses.calendar.availability.time-off.store', [
            $this->workspace->uid, $this->business->uid, $this->locationA->uid,
        ]), [
            'staff_user_id' => $this->staff->id,
            'start_at' => '2027-01-04 09:00:00',
            'end_at' => '2027-01-06 17:00:00',
        ])->assertNotFound();

        $this->assertDatabaseCount('booking_types', 0);
        $this->assertDatabaseCount('staff_availability_rules', 0);
        $this->assertDatabaseCount('staff_time_off', 0);
    }

    /**
     * The route table itself is asserted, so the gate above cannot pass
     * merely because a route is missing.
     */
    public function test_every_contracted_calendar_route_is_registered(): void
    {
        $expected = [
            'booking-types.index', 'booking-types.create', 'booking-types.store',
            'booking-types.edit', 'booking-types.update', 'booking-types.active',
            'booking-types.staff',
            'availability.index', 'availability.rules.store', 'availability.rules.destroy',
            'availability.time-off.store', 'availability.time-off.destroy',
        ];

        foreach ($expected as $name) {
            $full = 'customer.workspaces.businesses.calendar.' . $name;

            $this->assertNotNull(Route::getRoutes()->getByName($full), "Route [{$full}] is not registered.");
        }
    }

    /**
     * UPDATED BY SUB-SLICE D, which owns the nav entry (§12.D): `'calendar'`
     * joined ENTITLEMENT_GATED_FEATURES there. That list is nav gating and
     * never the security gate — every route in this file is STILL refused while
     * Calendar is Planned, which the tests above prove unchanged.
     */
    public function test_calendar_is_a_nav_gated_feature_since_sub_slice_d(): void
    {
        $this->assertContains(
            PlatformFeature::Calendar->value,
            \App\Library\Navigation\CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES
        );
    }
}
