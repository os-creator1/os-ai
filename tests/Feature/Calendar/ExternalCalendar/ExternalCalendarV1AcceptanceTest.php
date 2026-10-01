<?php

namespace Tests\Feature\Calendar\ExternalCalendar;

use App\DTO\Calendar\ExternalCalendarBusyEvent;
use App\DTO\Calendar\ExternalCalendarSyncPage;
use App\DTO\Calendar\ExternalCalendarTokenGrant;
use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\AppointmentSlotUnavailableException;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarConnectionManager;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarSyncService;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarWebhookToken;
use App\Library\Calendar\ExternalCalendar\GoogleCalendarProviderClient;
use App\Library\Calendar\ExternalCalendar\MicrosoftCalendarProviderClient;
use App\Models\ExternalCalendarConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\Feature\Calendar\ExternalCalendar\Concerns\CreatesExternalCalendarFixtures;
use Tests\TestCase;

/**
 * V1 acceptance for the external calendars: one canonical conflict path shared
 * by native appointments and provider busy blocks, a webhook that is only ever
 * a signal, and an ended connection that can never be resurrected by a request
 * that was already in flight. No test here reaches a real provider.
 */
class ExternalCalendarV1AcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;
    use CreatesExternalCalendarFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->bindFakeCalendarProviders();
    }

    private function registeredGoogleConnection($user, array $overrides = []): ExternalCalendarConnection
    {
        $connection = $this->createActiveConnection($user, ExternalCalendarProvider::Google);
        DB::table('external_calendar_connections')->where('id', $connection->id)->update(array_merge([
            'notification_channel_id' => 'channel-'.Str::uuid(),
            'notification_registration_id' => 'resource-'.Str::uuid(),
            'notification_expires_at' => now()->addDays(6),
        ], $overrides));

        return $connection->fresh();
    }

    private function notify(ExternalCalendarConnection $connection, array $headerOverrides = [], array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            route('public.calendar.webhooks.google', [
                'connectionUid' => $connection->uid,
                'token' => ExternalCalendarWebhookToken::forConnection($connection->uid, 'google'),
            ]),
            $body,
            array_merge([
                'X-Goog-Channel-ID' => $connection->notification_channel_id,
                'X-Goog-Resource-ID' => $connection->notification_registration_id,
                'X-Goog-Channel-Token' => ExternalCalendarWebhookToken::forConnection($connection->uid, 'google'),
                'X-Goog-Resource-State' => 'exists',
            ], $headerOverrides)
        );
    }

    private function busyEvent(string $id, $start, $end): ExternalCalendarBusyEvent
    {
        return new ExternalCalendarBusyEvent($id, false, $start, $end, 'busy');
    }

    private function busyBlocks(int $connectionId): int
    {
        return DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connectionId)->count();
    }

    public function test_a_webhook_is_only_a_signal_the_authenticated_pull_alone_creates_the_conflict(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $connection = $this->registeredGoogleConnection($staff);
        $busy = $this->slotStart('10:00:00');

        // Forged proof: nothing is pulled, nothing is cached, the slot stays free.
        $this->notify($connection, ['X-Goog-Channel-Token' => 'forged'], [
            'attacker' => ['start' => $busy->toIso8601String()],
        ])->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
        $this->assertSame(0, $this->busyBlocks($connection->id));

        // Authentic signal whose body claims a different busy window: the body is ignored,
        // only the provider's own answer (10:00-11:00) is written.
        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage(
            [$this->busyEvent('evt-real', $busy, $busy->copy()->addHour())], 'cursor-1', true
        );
        $this->notify($connection, [], ['claimed_busy' => ['2027-03-01T19:00:00Z', '2027-03-01T20:00:00Z']])->assertOk();

        $this->assertSame(1, $this->busyBlocks($connection->id));

        try {
            $this->engine()->book($type, (int) $staff->id, $this->contactId(), $busy);
            $this->fail('The provider-confirmed busy block must refuse the booking.');
        } catch (AppointmentSlotUnavailableException) {
        }

        $claimed = $this->slotStart('14:00:00');
        $this->assertSame(
            (int) $staff->id,
            (int) $this->engine()->book($type, (int) $staff->id, $this->contactId(), $claimed)->staff_user_id,
            'Event data supplied in a notification body must never block anything.'
        );
    }

    public function test_native_appointments_and_external_busy_blocks_share_one_conflict_path_and_one_lock(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $connection = $this->createActiveConnection($staff, ExternalCalendarProvider::Google);
        $start = $this->slotStart('10:00:00');

        // --- booking path: tier-2 lock BEFORE both conflict sources are read ---
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = strtolower($q->sql);
        });
        $this->engine()->book($type, (int) $staff->id, $this->contactId(), $start);
        $lock = $this->firstIndex($queries, fn (string $q): bool => str_contains($q, 'staff_booking_locks') && str_contains($q, 'for update'));
        $nativeRead = $this->firstIndex($queries, fn (string $q): bool => str_starts_with($q, 'select') && str_contains($q, 'from `appointments`') && str_contains($q, 'staff_user_id'));
        $externalRead = $this->firstIndex($queries, fn (string $q): bool => str_contains($q, 'from `external_calendar_busy_blocks`'));
        $this->assertNotNull($lock);
        $this->assertNotNull($externalRead, 'The booking must consult external busy blocks.');
        $this->assertLessThan($nativeRead, $lock);
        $this->assertLessThan($externalRead, $lock);

        // --- sync path: the SAME lock BEFORE the first busy-block write ---
        $queries = [];
        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage(
            [$this->busyEvent('evt-1', $start->copy()->addHours(3), $start->copy()->addHours(4))], 'c', true
        );
        app(ExternalCalendarSyncService::class)->syncConnection($connection);
        $syncLock = $this->firstIndex($queries, fn (string $q): bool => str_contains($q, 'staff_booking_locks') && str_contains($q, 'for update'));
        $write = $this->firstIndex($queries, fn (string $q): bool => str_contains($q, '`external_calendar_busy_blocks`'));
        $this->assertNotNull($syncLock);
        $this->assertNotNull($write);
        $this->assertLessThan($write, $syncLock, 'Busy blocks may only be written under the staff tier-2 lock.');

        // --- one detector answers for both sources ---
        $detector = app(\App\Library\Calendar\BookingConflictDetector::class);
        $this->assertTrue($detector->hasConflict((int) $staff->id, $start, $start->copy()->addHour()), 'native appointment');
        $this->assertTrue($detector->hasConflict((int) $staff->id, $start->copy()->addHours(3), $start->copy()->addHours(4)), 'external block');
        $this->assertFalse($detector->hasConflict((int) $staff->id, $start->copy()->addHours(5), $start->copy()->addHours(6)));
    }

    /** @param array<int, string> $queries */
    private function firstIndex(array $queries, callable $match): ?int
    {
        foreach ($queries as $i => $q) {
            if ($match($q)) {
                return $i;
            }
        }

        return null;
    }

    public function test_a_page_fetched_for_a_connection_that_ended_mid_pull_is_discarded(): void
    {
        $staff = $this->bookableStaff();
        $connection = $this->createActiveConnection($staff, ExternalCalendarProvider::Google);
        $start = $this->slotStart('10:00:00');

        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage(
            [$this->busyEvent('evt-late', $start, $start->copy()->addHour())], 'cursor-late', true
        );
        // The user disconnects while the provider read is in flight.
        $this->fakeGoogle->whileFetching = fn () => app(ExternalCalendarConnectionManager::class)
            ->disconnect($connection->fresh(), (int) $staff->id);

        app(ExternalCalendarSyncService::class)->syncConnection($connection);

        $fresh = $connection->fresh();
        $this->assertSame(ExternalCalendarConnectionState::Disconnected, $fresh->state);
        $this->assertSame(0, $this->busyBlocks($connection->id), 'A late page must not resurrect busy blocks.');
        $this->assertNull($fresh->sync_cursor);
        $this->assertNull($fresh->getRawOriginal('refresh_token_encrypted'));
    }

    public function test_a_rotated_refresh_token_is_never_written_back_onto_an_ended_connection(): void
    {
        $staff = $this->bookableStaff();
        $connection = $this->createActiveConnection($staff, ExternalCalendarProvider::Outlook);
        $this->fakeOutlook->refreshExchangeGrant = new ExternalCalendarTokenGrant('access', 'rotated-after-disconnect', null, null);
        $this->fakeOutlook->whileRefreshing = fn () => app(ExternalCalendarConnectionManager::class)
            ->disconnect($connection->fresh(), (int) $staff->id);

        try {
            app(ExternalCalendarConnectionManager::class)->accessTokenFor($connection);
            $this->fail('An ended connection must not yield an access token.');
        } catch (ExternalCalendarProviderException) {
        }

        $fresh = $connection->fresh();
        $this->assertSame(ExternalCalendarConnectionState::Disconnected, $fresh->state);
        $this->assertNull($fresh->getRawOriginal('refresh_token_encrypted'), 'Disconnect destroys credentials for good.');
    }

    public function test_refresh_rotation_is_stored_encrypted_and_a_missing_rotation_changes_nothing(): void
    {
        $staff = $this->bookableStaff();
        $outlook = $this->createActiveConnection($staff, ExternalCalendarProvider::Outlook);
        $this->fakeOutlook->refreshExchangeGrant = new ExternalCalendarTokenGrant('access-1', 'rotated-refresh-token', null, null);

        $this->assertSame('access-1', app(ExternalCalendarConnectionManager::class)->accessTokenFor($outlook));

        $raw = (string) DB::table('external_calendar_connections')->where('id', $outlook->id)->value('refresh_token_encrypted');
        $this->assertStringNotContainsString('rotated-refresh-token', $raw);
        $this->assertSame('rotated-refresh-token', Crypt::decryptString($raw));

        $other = $this->bookableStaff();
        $google = $this->createActiveConnection($other, ExternalCalendarProvider::Google);
        $before = (string) DB::table('external_calendar_connections')->where('id', $google->id)->value('refresh_token_encrypted');
        app(ExternalCalendarConnectionManager::class)->accessTokenFor($google);
        $after = (string) DB::table('external_calendar_connections')->where('id', $google->id)->value('refresh_token_encrypted');
        $this->assertSame($before, $after, 'No rotation returned: the stored credential must not be touched.');
        $this->assertSame(1, $this->fakeGoogle->refreshExchangeCalls);
    }

    public function test_a_revoked_refresh_token_revokes_the_connection_and_purges_its_blocks(): void
    {
        $staff = $this->bookableStaff();
        $connection = $this->createActiveConnection($staff, ExternalCalendarProvider::Google, ['sync_cursor' => 'c']);
        $start = $this->slotStart('10:00:00');
        DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connection->id, 'provider_event_id' => 'x',
            'start_at' => $start, 'end_at' => $start->copy()->addHour(), 'busy_type' => 'busy',
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakeGoogle->throwOnRefreshExchange = ExternalCalendarProviderException::invalidGrant();

        app(ExternalCalendarSyncService::class)->syncConnection($connection);

        $this->assertSame(ExternalCalendarConnectionState::Revoked, $connection->fresh()->state);
        $this->assertSame(0, $this->busyBlocks($connection->id));

        // A disconnected provider fails safe: the slot is bookable again and a further sync is a no-op.
        $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $start);
        app(ExternalCalendarSyncService::class)->syncConnection($connection->fresh());
        $this->assertSame(1, $this->fakeGoogle->refreshExchangeCalls);
    }

    public function test_requested_scopes_are_the_minimum_read_only_set(): void
    {
        $this->assertSame(['https://www.googleapis.com/auth/calendar.readonly'], config('calendar_external.google.scopes'));
        $this->assertSame(['offline_access', 'Calendars.Read'], config('calendar_external.outlook.scopes'));

        config([
            'calendar_external.google.client_id' => 'g-id', 'calendar_external.google.redirect' => 'https://app.test/cb',
            'calendar_external.outlook.client_id' => 'm-id', 'calendar_external.outlook.redirect' => 'https://app.test/cb',
        ]);

        parse_str((string) parse_url((new GoogleCalendarProviderClient())->authorizationUrl('state', false), PHP_URL_QUERY), $google);
        parse_str((string) parse_url((new MicrosoftCalendarProviderClient())->authorizationUrl('state', false), PHP_URL_QUERY), $outlook);

        $this->assertSame('https://www.googleapis.com/auth/calendar.readonly', $google['scope']);
        $this->assertSame('offline_access Calendars.Read', $outlook['scope']);
    }

    public function test_one_users_connection_is_invisible_and_untouchable_to_another_user(): void
    {
        $owner = $this->bookableStaff();
        $intruder = $this->bookableStaff();
        $connection = $this->createActiveConnection($owner, ExternalCalendarProvider::Google, ['external_account_email' => 'private-owner@example.test']);

        $this->authenticate($intruder);
        $this->get(route('customer.calendar-connection.show'))->assertOk()->assertDontSee('private-owner@example.test');
        $this->post(route('customer.calendar-connection.disconnect'))->assertRedirect();

        $this->assertSame(ExternalCalendarConnectionState::Active, $connection->fresh()->state);
        $this->assertNotNull($connection->fresh()->getRawOriginal('refresh_token_encrypted'));
    }
}
