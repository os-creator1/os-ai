<?php

namespace App\Console\Commands;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Business\BusinessLocationManager;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\DemoBooking\TurnstileVerifier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Inspect, then (only when told to) top up, the internal Business that receives
 * /book-demo bookings. Find-or-create by stable name: an existing internal
 * Workspace, Business, Location or Booking Type is reused and never duplicated.
 * It sets no weekly hours and no host schedule: the host configures those in
 * the Business's own Calendar settings.
 *
 *   php artisan demo-booking:provision --inspect
 *   php artisan demo-booking:provision --owner-email=you@example.com --timezone=America/New_York --confirm-database=<name>
 */
class ProvisionDemoBookingCommand extends Command
{
    public const NAME = 'MotionGrove Internal';
    public const TYPE_NAME = 'MotionGrove Demo';

    protected $signature = 'demo-booking:provision
        {--inspect : Print what exists and what /book-demo is configured to use; change nothing}
        {--owner-email= : Existing customer login that will own and host the internal Workspace}
        {--timezone= : IANA timezone of the internal Business (availability is interpreted in it)}
        {--duration=30 : Demo length in minutes (only used when the Booking Type is created)}
        {--confirm-database= : The exact connected database name (see --inspect)}
        {--production : Required, in addition, when APP_ENV=production: this creates real internal records}';

    protected $description = 'Inspect or provision the internal MotionGrove Business that receives /book-demo bookings';

    public function handle(
        WorkspaceManager $workspaces,
        BusinessRepository $businesses,
        BusinessLocationManager $locations,
        BookingTypeManager $types,
        EntitlementManager $entitlements,
        TurnstileVerifier $turnstile,
    ): int {
        $database = (string) DB::connection()->getDatabaseName();

        if ($this->option('inspect')) {
            return $this->inspect($database, $turnstile);
        }

        if ($this->option('confirm-database') !== $database) {
            $this->error("Refusing: --confirm-database must equal the connected database [{$database}].");

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('production')) {
            $this->error('APP_ENV is production. Re-run with --production to confirm you intend to create the internal Business here.');

            return self::FAILURE;
        }

        $owner = User::query()->where('email', strtolower(trim((string) $this->option('owner-email'))))->first();
        $timezone = (string) $this->option('timezone');
        $duration = (int) $this->option('duration');

        if ($owner === null || ! in_array($timezone, \DateTimeZone::listIdentifiers(), true) || $duration < 5 || $duration > 480) {
            $this->error('--owner-email must be an existing login, --timezone a valid IANA zone and --duration 5-480.');

            return self::FAILURE;
        }
        $customer = Customer::query()->where('user_id', $owner->id)->first();
        if ($customer === null) {
            $this->error('That login has no customer profile, so it cannot own a Business. A platform administrator account usually has none: use a customer login you operate.');

            return self::FAILURE;
        }
        $adminId = (int) User::query()->where('is_admin', true)->orderBy('id')->value('id');
        if ($adminId < 1) {
            $this->error('No platform administrator exists to record the complimentary plan against.');

            return self::FAILURE;
        }

        $workspace = Workspace::query()->where('name', self::NAME)->first();
        if ($workspace !== null && (int) $workspace->owner_user_id !== (int) $owner->id) {
            $this->error("A Workspace named [".self::NAME."] already exists with a different owner. Refusing to adopt it.");

            return self::FAILURE;
        }
        $workspace ??= $workspaces->createWorkspace((int) $owner->id, self::NAME);

        $business = Business::query()->where('workspace_id', $workspace->id)->where('name', self::NAME)->first();
        if ($business === null) {
            // Activated only when WE create it: an operator who later deactivated it is never silently reversed.
            $business = $businesses->createForCustomerInWorkspace($customer, $workspace, [
                'name' => self::NAME, 'industry' => 'other', 'country_code' => 'US', 'timezone' => $timezone, 'currency_code' => 'USD',
            ]);
            $business->forceFill(['status' => BusinessStatus::Active->value, 'activated_at' => now()])->save();
        }

        $location = BusinessLocation::query()->where('business_id', $business->id)->orderBy('id')->first()
            ?? $locations->upsertPrimaryLocation($business, ['name' => 'Online demo', 'service_mode' => 'online', 'country_code' => 'US']);

        if (! DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->exists()) {
            $entitlements->assignFirstPlan(
                $workspace, WorkspacePlanTier::Growth, $adminId,
                'Internal MotionGrove Business: complimentary plan for /book-demo. No billing.', true, 0
            );
        }

        $type = BookingType::query()->where('business_location_id', $location->id)->where('name', self::TYPE_NAME)->first();
        if ($type === null) {
            $type = $types->create($location, [
                'name' => self::TYPE_NAME,
                'description' => 'A walkthrough of MotionGrove for your business.',
                'duration_minutes' => $duration,
                'booking_window_days' => 60,
                'minimum_notice_minutes' => 240,
                'slot_interval_minutes' => 30,
            ], (int) $owner->id);
            // Hosts are set once; a re-run never overwrites hosts the operator has since changed.
            $types->syncStaff($type, [(int) $owner->id]);
        }

        $this->info('Internal demo Business is ready.');
        $this->line('  Workspace     : '.$workspace->uid);
        $this->line('  Business      : '.$business->uid);
        $this->line('  Booking Type  : '.$type->uid);
        $this->line('  Set in the environment: DEMO_BOOKING_TYPE_UUID='.$type->public_booking_uuid);
        $this->warn('No availability is set. The host must add weekly hours in the Business Calendar, or /book-demo offers no times.');

        return self::SUCCESS;
    }

    private function inspect(string $database, TurnstileVerifier $turnstile): int
    {
        $this->line('database       '.$database);
        $this->line('turnstile      '.($turnstile->isConfigured() ? 'configured' : ($turnstile->bypassActive() ? 'BYPASSED (local/testing)' : 'NOT configured: /book-demo will refuse')));
        $designated = config('demo_booking.booking_type_uuid');
        $this->line('designated     '.($designated ?: 'DEMO_BOOKING_TYPE_UUID is not set: /book-demo will 404'));

        $workspace = Workspace::query()->where('name', self::NAME)->first();
        $this->line('workspace      '.($workspace ? $workspace->uid.' (owner user #'.$workspace->owner_user_id.')' : 'none named ['.self::NAME.']'));
        $business = $workspace ? Business::query()->where('workspace_id', $workspace->id)->where('name', self::NAME)->first() : null;
        $this->line('business       '.($business ? $business->uid.' ['.($business->status instanceof \BackedEnum ? $business->status->value : $business->status).'] '.$business->timezone : 'none'));
        $locationIds = $business ? BusinessLocation::query()->where('business_id', $business->id)->pluck('id') : collect();
        $this->line('locations      '.$locationIds->count());
        foreach (BookingType::query()->whereIn('business_location_id', $locationIds)->get() as $type) {
            $this->line('booking type   '.$type->name.' '.$type->public_booking_uuid.($type->public_booking_uuid === $designated ? '  <- designated' : ''));
        }

        return self::SUCCESS;
    }
}
