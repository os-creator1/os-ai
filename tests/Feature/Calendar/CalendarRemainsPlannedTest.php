<?php

namespace Tests\Feature\Calendar;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\PlatformFeatureAvailability;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\Navigation\CustomerMenuBuilder;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Implementation Contract 15 §11, §12.A — Sub-slice A ships SCHEMA ONLY.
 *
 * PlatformFeature::Calendar stays `Planned` and is flipped to `Available`
 * only at the very end of Sub-slice E, once "a customer books a slot and
 * both parties see it" is true end-to-end. These tests are the guard that
 * a schema-only sub-slice did not quietly make the feature reachable, and
 * they are expected to be UPDATED — not deleted — by Sub-slices B/D/E as
 * each surface legitimately appears.
 */
class CalendarRemainsPlannedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * §11 — the flip happens in Sub-slice E, not here.
     */
    public function test_calendar_platform_feature_is_still_planned(): void
    {
        $this->assertFalse(
            PlatformFeatureRegistry::isAvailable(PlatformFeature::Calendar->value),
            'Sub-slice A must not flip PlatformFeature::Calendar to Available.'
        );

        $this->assertTrue(PlatformFeatureRegistry::isKnown(PlatformFeature::Calendar->value));
        $this->assertTrue(PlatformFeatureRegistry::isBusinessScoped(PlatformFeature::Calendar->value));
    }

    /**
     * §6/§11 — a Planned feature already fails closed at the decision layer,
     * before any database read. This is what makes it safe for Sub-slices B
     * and D to build authenticated routes behind the ordinary entitlement
     * gate while the flip is still pending.
     */
    public function test_entitlement_manager_refuses_calendar_for_a_fully_entitled_business(): void
    {
        $owner = User::create([
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);
        $admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $customer = Customer::create(['user_id' => $owner->id]);
        $workspace = Workspace::create([
            'name' => 'Test Workspace',
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Test Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ]);

        // Calendar is packaged in core/growth/agency already, so the plan is
        // not what refuses this — availability is.
        app(EntitlementManager::class)->assignFirstPlan(
            $workspace->fresh(),
            WorkspacePlanTier::Core,
            $admin->id,
            'Fixture assignment.',
            true,
            0
        );

        $decision = app(EntitlementManager::class)->decide(
            $workspace->fresh(),
            $business->fresh(),
            PlatformFeature::Calendar->value,
            $admin->id
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('platform_feature_unavailable', $decision->reason);
    }

    /**
     * §12.A/§12.B — UPDATED BY SUB-SLICE B, as this file's own header said
     * it would be: Sub-slice A registered no Calendar routes, and Sub-slice
     * B legitimately adds the authenticated Booking Type and availability
     * surfaces (§12.B). What must stay true is narrower and still
     * load-bearing: every Calendar route that exists belongs to B's
     * contracted set, all of it is authenticated and Business-scoped, and
     * none of C's, D's or E's surfaces has appeared early.
     *
     * That those routes all fail closed while Calendar is `Planned` is
     * proven separately and exhaustively by
     * CalendarRouteEntitlementGateTest.
     *
     * `mark-booked` (Agency Prospecting, §3.4.2) is a deliberate, unrelated
     * exclusion: it is a manual suppression marker on a prospecting record,
     * explicitly not this slice's "Booked".
     */
    public function test_only_sub_slice_b_and_d_calendar_routes_are_registered(): void
    {
        $allowed = [
            'customer.workspaces.businesses.calendar.booking-types.index',
            'customer.workspaces.businesses.calendar.booking-types.create',
            'customer.workspaces.businesses.calendar.booking-types.store',
            'customer.workspaces.businesses.calendar.booking-types.edit',
            'customer.workspaces.businesses.calendar.booking-types.update',
            'customer.workspaces.businesses.calendar.booking-types.active',
            'customer.workspaces.businesses.calendar.booking-types.staff',
            'customer.workspaces.businesses.calendar.availability.index',
            'customer.workspaces.businesses.calendar.availability.rules.store',
            'customer.workspaces.businesses.calendar.availability.rules.destroy',
            'customer.workspaces.businesses.calendar.availability.time-off.store',
            'customer.workspaces.businesses.calendar.availability.time-off.destroy',
            // Sub-slice D (§12.D) — the authenticated day/week schedule and the
            // five appointment actions. Still every one entitlement-gated, so
            // still 404 while Planned (CalendarUiEntitlementGateTest).
            'customer.workspaces.businesses.calendar.index',
            'customer.workspaces.businesses.calendar.schedule',
            'customer.workspaces.businesses.calendar.appointments.create',
            'customer.workspaces.businesses.calendar.appointments.store',
            'customer.workspaces.businesses.calendar.appointments.show',
            'customer.workspaces.businesses.calendar.appointments.reschedule',
            'customer.workspaces.businesses.calendar.appointments.cancel',
            'customer.workspaces.businesses.calendar.appointments.complete',
            'customer.workspaces.businesses.calendar.appointments.no-show',
        ];

        $offending = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $name = (string) $route->getName();

            if ($uri === 'prospects/{prospect}/mark-booked' || str_contains($name, 'mark-booked')) {
                continue;
            }

            if (in_array($name, $allowed, true)) {
                continue;
            }

            foreach (['calendar', 'appointment', 'booking'] as $needle) {
                if (str_contains(strtolower($uri), $needle) || str_contains(strtolower($name), $needle)) {
                    $offending[] = $name !== '' ? $name : $uri;
                }
            }
        }

        $this->assertSame([], $offending, 'Only Sub-slices B and D\'s contracted Calendar routes may exist.');
    }

    /**
     * The surfaces Sub-slices C, D and E own must NOT exist yet: no public
     * scheduler, no appointment lifecycle action, no calendar grid.
     */
    public function test_no_public_calendar_routes_exist_yet(): void
    {
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            $this->assertStringNotContainsString('public.booking', $name);
            $this->assertStringNotContainsString('public.scheduler', $name);
        }
    }

    /**
     * §12.D adds `'calendar'` to ENTITLEMENT_GATED_FEATURES, not this
     * sub-slice — and that list is nav gating only, never the security
     * gate (§6).
     */
    public function test_calendar_is_a_nav_gated_feature_since_sub_slice_d(): void
    {
        $this->assertContains(
            PlatformFeature::Calendar->value,
            CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES,
            "Sub-slice D adds 'calendar' to ENTITLEMENT_GATED_FEATURES with the nav entry it gates."
        );
    }

    /**
     * §5.5/§11 — nothing in this sub-slice may reach a provider. The OAuth
     * columns exist, but no client, command or webhook does.
     */
    public function test_no_external_calendar_provider_code_exists_yet(): void
    {
        $this->assertFalse(class_exists(\App\Console\Commands\SyncExternalCalendars::class));
        $this->assertSame(
            PlatformFeatureAvailability::Planned,
            PlatformFeatureAvailability::from('planned')
        );
    }
}
