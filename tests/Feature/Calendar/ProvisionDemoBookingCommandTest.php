<?php

namespace Tests\Feature\Calendar;

use App\Console\Commands\ProvisionDemoBookingCommand as Command;
use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\StaffAvailabilityRule;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/** demo-booking:provision: inspect changes nothing, provisioning is find-or-create, and the result serves /book-demo. */
class ProvisionDemoBookingCommandTest extends TestCase
{
    use CreatesCalendarHttpFixtures;
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        User::create([
            'first_name' => 'Platform', 'last_name' => 'Admin', 'email' => 'admin'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->host = $this->createCustomer()->user;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function provision(array $override = [])
    {
        return $this->artisan('demo-booking:provision', $override + [
            '--owner-email' => $this->host->email,
            '--timezone' => 'America/New_York',
            '--confirm-database' => DB::connection()->getDatabaseName(),
        ]);
    }

    public function test_inspect_changes_nothing(): void
    {
        $this->artisan('demo-booking:provision', ['--inspect' => true])->assertExitCode(0);

        $this->assertSame(0, Workspace::query()->where('name', Command::NAME)->count());
        $this->assertSame(0, Business::query()->where('name', Command::NAME)->count());
    }

    public function test_it_refuses_a_wrong_database_an_unknown_owner_a_bad_zone_and_a_foreign_workspace(): void
    {
        $this->provision(['--confirm-database' => 'something_else'])->assertExitCode(1);
        $this->provision(['--owner-email' => 'nobody@example.test'])->assertExitCode(1);
        $this->provision(['--timezone' => 'Mars/Olympus'])->assertExitCode(1);
        $this->assertSame(0, Workspace::query()->where('name', Command::NAME)->count());

        // A platform administrator has no customer profile and so cannot own a Business.
        $admin = User::query()->where('is_admin', true)->firstOrFail();
        $this->provision(['--owner-email' => $admin->email])->assertExitCode(1);

        $other = $this->createCustomer()->user;
        Workspace::create(['name' => Command::NAME, 'owner_user_id' => $other->id, 'is_active' => true]);
        $this->provision()->assertExitCode(1);
        $this->assertSame(0, Business::query()->where('name', Command::NAME)->count());
    }

    public function test_it_is_idempotent_and_reuses_what_exists(): void
    {
        $this->provision()->assertExitCode(0);
        $counts = fn () => [
            Workspace::query()->where('name', Command::NAME)->count(), Business::query()->where('name', Command::NAME)->count(),
            BusinessLocation::query()->count(), BookingType::query()->where('name', Command::TYPE_NAME)->count(),
        ];
        $first = $counts();
        $this->assertSame([1, 1, 1, 1], $first);

        $this->provision()->assertExitCode(0);
        $this->assertSame($first, $counts());
        $this->assertSame([(int) $this->host->id], BookingType::query()->where('name', Command::TYPE_NAME)->firstOrFail()->staff()->pluck('users.id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_the_provisioned_business_serves_book_demo_end_to_end_and_stays_isolated(): void
    {
        $this->provision(['--duration' => 45])->assertExitCode(0);

        $type = BookingType::query()->where('name', Command::TYPE_NAME)->firstOrFail();
        $location = BusinessLocation::query()->findOrFail($type->business_location_id);
        $business = Business::query()->findOrFail($location->business_id);
        $this->assertSame(45, (int) $type->duration_minutes);
        $this->assertSame('America/New_York', $business->timezone);

        config([
            'demo_booking.booking_type_uuid' => $type->public_booking_uuid,
            'demo_booking.turnstile.site_key' => 'site', 'demo_booking.turnstile.secret_key' => 'secret',
        ]);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        // No hardcoded schedule: until the host sets availability, no times are offered.
        $this->getJson('/book-demo/slots?date=2027-03-01')->assertOk()->assertJsonPath('slots', []);

        StaffAvailabilityRule::create([
            'business_location_id' => $location->id, 'staff_user_id' => $this->host->id,
            'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '12:00:00',
        ]);
        $times = array_column($this->getJson('/book-demo/slots?date=2027-03-01')->assertOk()->json('slots'), 'time');
        $this->assertSame('09:00', $times[0]);

        $this->post('/book-demo', [
            'date' => '2027-03-01', 'time' => '09:00', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'email' => 'ada@example.test', 'business_name' => 'Analytical Engines', 'cf-turnstile-response' => 't',
        ])->assertRedirect(route('demo.booking.confirmed'));

        $appointment = Appointment::query()->sole();
        $this->assertSame((int) $location->id, (int) $appointment->business_location_id);
        $this->assertSame((int) $this->host->id, (int) $appointment->staff_user_id);
        $this->assertSame(1, Contacts::query()->count());
        $this->assertSame((int) $business->id, (int) Contacts::query()->sole()->business_id);
        $this->assertSame('2027-03-01 14:00:00', Carbon::parse($appointment->start_at)->utc()->toDateTimeString());
    }
}
