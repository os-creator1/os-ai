<?php

namespace Tests\Feature\Calendar\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Support\SeedCalendarEntitlementSnapshot;

/**
 * HTTP fixtures for Implementation Contract 15 Sub-slice D.
 *
 * Builds on the booking-engine fixtures, so every staff member is a real,
 * currently-eligible member as LocationAccessGuard sees them.
 *
 * TWO MODES, and the difference is the whole point:
 *   - entitledCalendar(): pushes SeedCalendarEntitlementSnapshot, which
 *     supplies ONLY the entitlement answer. Tenancy and LocationAccessGuard
 *     stay real, so the ACL matrix proven behind it is genuine.
 *   - not calling it: the real Planned enforcement, unmodified — every route
 *     404s. That is what CalendarUiEntitlementGateTest proves.
 */
trait CreatesCalendarHttpFixtures
{
    use CreatesBookingEngineFixtures;

    protected function bootCalendarHttpFixtures(): void
    {
        $this->bootCalendarAuthorityFixtures();

        DB::table('businesses')->where('id', $this->business->id)->update(['status' => BusinessStatus::Active->value]);
        $this->business = $this->business->fresh();

        $platformAdmin = User::create([
            'first_name' => 'Platform', 'last_name' => 'Admin',
            'email' => 'platform' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        // Calendar is already packaged in core/growth/agency, so the plan is
        // not what refuses a request — availability is.
        app(EntitlementManager::class)->assignFirstPlan(
            $this->workspace->fresh(),
            WorkspacePlanTier::Core,
            $platformAdmin->id,
            'Fixture assignment.',
            true,
            0
        );

        $this->workspace = $this->workspace->fresh();
    }

    /**
     * Supplies the entitlement ANSWER only. See SeedCalendarEntitlementSnapshot
     * for exactly what it does and does not touch.
     */
    protected function entitledCalendar(): void
    {
        $this->app['router']->pushMiddlewareToGroup('web', SeedCalendarEntitlementSnapshot::class);
    }

    protected function authenticate(User $user): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($user);
    }

    /**
     * The customer shell's own middleware reads these rows on every
     * authenticated request and fatals on a fresh database without them — an
     * environment fixture, unrelated to anything asserted here.
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

    /** @return array<int, string> [workspaceUid, businessUid, locationUid] */
    protected function scopeFor($location): array
    {
        return [$this->workspace->uid, $this->business->uid, $location->uid];
    }

    protected function calendarUrl(string $name, array $args): string
    {
        return route('customer.workspaces.businesses.calendar.' . $name, $args);
    }
}
