<?php

namespace Tests\Feature\V1Acceptance\CalendarBooking;

use App\DTO\Calendar\ExternalCalendarBusyEvent;
use App\DTO\Calendar\ExternalCalendarSyncPage;
use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarSyncService;
use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\ExternalCalendarConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Acceptance journey E — the external calendar, through the real connection
 * routes and the real sync service, with the provider client replaced by the
 * repository's own deterministic fake (no live Google/Microsoft call anywhere).
 */
class ExternalCalendarJourneyTest extends CalendarJourneyTestCase
{
    private User $worker;

    private BookingType $typeA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = $this->bookableStaff($this->locationA);
        $this->giveMondayAvailability((int) $this->worker->id, $this->locationB);
        $this->typeA = $this->typeWithStaff($this->locationA, $this->worker);
    }

    /** @return array<string, array{0: ExternalCalendarProvider, 1: string}> */
    public static function providers(): array
    {
        return [
            'google' => [ExternalCalendarProvider::Google, 'google'],
            'outlook' => [ExternalCalendarProvider::Outlook, 'outlook'],
        ];
    }

    private function busy(string $id, string $from, string $to): ExternalCalendarBusyEvent
    {
        return new ExternalCalendarBusyEvent($id, false, $this->slotStart($from), $this->slotStart($to), 'busy');
    }

    private function gone(string $id): ExternalCalendarBusyEvent
    {
        return new ExternalCalendarBusyEvent($id, true);
    }

    /** Connect through the real routes: initiate, follow the provider redirect's state, return with a code. */
    private function connectThroughTheUi(User $user, string $provider): ExternalCalendarConnection
    {
        $this->actAs($user);
        $this->get(route('customer.calendar-connection.show'))->assertOk()->assertSee('Connect Google Calendar');

        $redirect = $this->post(route('customer.calendar-connection.connect', [$provider]));
        $redirect->assertRedirect();
        $location = (string) $redirect->headers->get('Location');
        $this->assertStringStartsWith('https://fake-provider.test/authorize', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->get(route('customer.calendar-connection.oauth.callback', [
            'provider' => $provider, 'state' => $query['state'], 'code' => 'auth-code-xyz',
        ]))->assertRedirect(route('customer.calendar-connection.show'));

        return ExternalCalendarConnection::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function sync(ExternalCalendarConnection $connection, bool $forceFull = false): ExternalCalendarConnection
    {
        app(ExternalCalendarSyncService::class)->syncConnection($connection->fresh(), $forceFull);

        return $connection->fresh();
    }

    private function offered(?BookingType $type = null): array
    {
        return $this->offeredSlots($type ?? $this->typeA, '2027-03-01');
    }

    private function guestBooks(string $time, string $phone, ?BookingType $type = null)
    {
        $type ??= $this->typeA;

        return $this->from($this->publicShow($type))
            ->post($this->publicStore($type), $this->guest(['time' => $time, 'phone' => $phone]));
    }

    #[DataProvider('providers')]
    public function test_connect_sync_block_delta_and_disconnect_journey(ExternalCalendarProvider $provider, string $slug): void
    {
        $fake = $this->fakeFor($provider);
        $connection = $this->connectThroughTheUi($this->worker, $slug);

        // Connected, and the page that says so never renders a credential.
        $this->assertSame(ExternalCalendarConnectionState::Active, $connection->state);
        $page = $this->get(route('customer.calendar-connection.show'))->assertOk()->getContent();
        $this->assertStringContainsString('Connected to', $page);
        $this->assertStringContainsString('staff@example.test', $page);
        foreach (['fake-refresh-token', 'fake-access-token', 'auth-code-xyz', (string) $connection->oauth_state_nonce, 'refresh_token_encrypted'] as $secret) {
            if ($secret !== '') {
                $this->assertStringNotContainsString($secret, $page);
            }
        }
        $raw = (string) DB::table('external_calendar_connections')->where('id', $connection->id)->value('refresh_token_encrypted');
        $this->assertStringNotContainsString('fake-refresh-token', $raw, 'the stored credential is encrypted at rest');

        // Nothing synced yet: nothing blocked.
        $this->assertContains('10:00', $this->offered());

        // FULL sync: provider says busy 10:00–11:00.
        $fake->fullBusyQueue[] = new ExternalCalendarSyncPage([$this->busy('evt-1', '10:00:00', '11:00:00')], 'cursor-1', true);
        $connection = $this->sync($connection);
        $this->assertSame('cursor-1', $connection->sync_cursor);
        $this->assertSame(1, $fake->fullBusyCalls);

        $slots = $this->offered();
        foreach (['09:30', '10:00', '10:30'] as $blocked) {
            $this->assertNotContains($blocked, $slots);
        }
        $this->assertContains('11:00', $slots);
        $this->guestBooks('10:00', '4155550301')->assertSessionHasErrors('time');
        $this->assertSame(0, $this->appointmentCount());

        // The public page never leaks what the provider said.
        $this->assertStringNotContainsString('evt-1', $this->get($this->publicShow($this->typeA, '2027-03-01'))->getContent());

        // INCREMENTAL delta: a tombstone frees the slot, a new event blocks another, the cursor advances.
        $fake->incrementalBusyQueue[] = new ExternalCalendarSyncPage(
            [$this->gone('evt-1'), $this->busy('evt-2', '14:00:00', '15:00:00')], 'cursor-2', true
        );
        $connection = $this->sync($connection);
        $this->assertSame(1, $fake->incrementalBusyCalls);
        $this->assertSame('cursor-2', $connection->sync_cursor);
        $this->assertContains('10:00', $this->offered());
        $this->assertNotContains('14:00', $this->offered());

        // Replaying the same delta is idempotent: still exactly one block, still blocked.
        $fake->incrementalBusyQueue[] = new ExternalCalendarSyncPage(
            [$this->gone('evt-1'), $this->busy('evt-2', '14:00:00', '15:00:00')], 'cursor-2', true
        );
        $connection = $this->sync($connection);
        $this->assertSame(1, DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connection->id)->count());

        // An UPDATED event moves its block (same provider id, one row).
        $fake->incrementalBusyQueue[] = new ExternalCalendarSyncPage([$this->busy('evt-2', '15:00:00', '16:00:00')], 'cursor-3', true);
        $connection = $this->sync($connection);
        $this->assertSame(1, DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connection->id)->count());
        $this->assertContains('14:00', $this->offered());
        $this->assertNotContains('15:00', $this->offered());

        // Disconnect destroys the credential, purges the cache and frees the slot at once.
        $this->actAs($this->worker)->post(route('customer.calendar-connection.disconnect'))
            ->assertRedirect(route('customer.calendar-connection.show'));
        $ended = $connection->fresh();
        $this->assertSame(ExternalCalendarConnectionState::Disconnected, $ended->state);
        $this->assertNull($ended->refresh_token_encrypted);
        $this->assertSame(0, DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connection->id)->count());
        $this->assertContains('15:00', $this->offered());

        // A disconnected connection is never synced again.
        $calls = $fake->fullBusyCalls + $fake->incrementalBusyCalls;
        $this->sync($connection, true);
        $this->assertSame($calls, $fake->fullBusyCalls + $fake->incrementalBusyCalls);
    }

    public function test_an_invalid_cursor_falls_back_to_one_full_read_and_an_outage_keeps_the_last_good_cache(): void
    {
        $fake = $this->fakeGoogle;
        $connection = $this->connectThroughTheUi($this->worker, 'google');

        $fake->fullBusyQueue[] = new ExternalCalendarSyncPage([$this->busy('evt-a', '10:00:00', '11:00:00')], 'c1', true);
        $connection = $this->sync($connection);

        // The provider disowns the cursor: exactly one fallback full read, reconciled by absence.
        $fake->incrementalBusyQueue[] = ExternalCalendarProviderException::cursorInvalid();
        $fake->fullBusyQueue[] = new ExternalCalendarSyncPage([$this->busy('evt-b', '16:00:00', '17:00:00')], 'c2', true);
        $connection = $this->sync($connection);
        $this->assertSame(2, $fake->fullBusyCalls);
        $this->assertSame('c2', $connection->sync_cursor);
        $this->assertContains('10:00', $this->offered(), 'evt-a vanished from the provider, so its block is reconciled away');
        $this->assertNotContains('16:00', $this->offered());

        // Outage: failure is recorded, the cache stays, the connection stays Active, bookings keep working.
        $fake->incrementalBusyQueue[] = ExternalCalendarProviderException::providerUnavailable();
        $connection = $this->sync($connection);
        $this->assertSame(ExternalCalendarConnectionState::Active, $connection->state);
        $this->assertSame(1, (int) $connection->sync_failure_count);
        $this->assertSame('c2', $connection->sync_cursor);
        $this->assertNotContains('16:00', $this->offered(), 'stale but safe: the last good block still holds');
        $this->guestBooks('09:00', '4155550302')->assertRedirect(route('public.booking.confirmed', [$this->typeA->public_booking_uuid]));
        $this->guestBooks('16:00', '4155550303')->assertSessionHasErrors('time');

        // The page for a failing-but-active connection is still sane and still secret-free.
        $this->actAs($this->worker);
        $this->get(route('customer.calendar-connection.show'))->assertOk()
            ->assertSee('Connected to')->assertDontSee('fake-refresh-token');

        // Recovery clears the failure bookkeeping.
        $fake->incrementalBusyQueue[] = new ExternalCalendarSyncPage([], 'c3', true);
        $connection = $this->sync($connection);
        $this->assertSame(0, (int) $connection->sync_failure_count);
        $this->assertNull($connection->failure_classification);
    }

    public function test_a_revoked_grant_ends_the_connection_frees_the_calendar_and_offers_reconnect(): void
    {
        $fake = $this->fakeGoogle;
        $connection = $this->connectThroughTheUi($this->worker, 'google');
        $fake->fullBusyQueue[] = new ExternalCalendarSyncPage([$this->busy('evt-r', '10:00:00', '11:00:00')], 'c1', true);
        $connection = $this->sync($connection);
        $this->assertNotContains('10:00', $this->offered());

        // The user revokes access at Google; the next refresh exchange says invalid_grant.
        $fake->throwOnRefreshExchange = ExternalCalendarProviderException::invalidGrant();
        $connection = $this->sync($connection, true);

        $this->assertSame(ExternalCalendarConnectionState::Revoked, $connection->state);
        $this->assertNull($connection->refresh_token_encrypted);
        $this->assertSame(0, DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connection->id)->count());
        $this->assertContains('10:00', $this->offered(), 'a revoked calendar must not keep blocking bookings');

        // The page now offers to connect again — the reconnect-needed state — and shows no credential.
        $this->actAs($this->worker);
        $this->get(route('customer.calendar-connection.show'))->assertOk()
            ->assertSee('Connect Google Calendar')->assertSee('Connect Outlook Calendar')
            ->assertDontSee('Disconnect');

        // Reconnecting works and replaces the slot with a fresh Active connection.
        $fake->throwOnRefreshExchange = null;
        $again = $this->connectThroughTheUi($this->worker, 'google');
        $this->assertNotSame($connection->id, $again->id);
        $this->assertSame(ExternalCalendarConnectionState::Active, $again->state);
        $this->assertSame(1, ExternalCalendarConnection::query()->where('user_id', $this->worker->id)->where('state', 'active')->count());
    }

    public function test_only_an_active_connections_cache_blocks_and_only_for_its_own_user(): void
    {
        $other = $this->bookableStaff($this->locationA);
        $this->typeA->staff()->attach($other->id);
        $slot = $this->slotStart('10:00:00');
        $block = fn (int $connectionId) => DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connectionId, 'provider_event_id' => 'x-' . $connectionId,
            'start_at' => $slot, 'end_at' => $slot->copy()->addHour(), 'busy_type' => 'busy',
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Leftover rows under a pending / disconnected / revoked connection are inert.
        foreach ([ExternalCalendarConnectionState::Pending, ExternalCalendarConnectionState::Disconnected, ExternalCalendarConnectionState::Revoked] as $state) {
            $stale = $this->createActiveConnection($this->worker, ExternalCalendarProvider::Google, ['state' => $state]);
            $block((int) $stale->id);
            $this->assertContains('10:00', $this->offered(), "a {$state->value} connection's rows must not block");
            DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $stale->id)->delete();
            DB::table('external_calendar_connections')->where('id', $stale->id)->delete();
        }

        // An ACTIVE connection for ONE person blocks that person only: the other still takes the slot.
        $active = $this->createActiveConnection($this->worker, ExternalCalendarProvider::Google);
        $block((int) $active->id);
        $this->assertContains('10:00', $this->offered(), 'the other staff member is free, so the slot is still offered');
        $this->guestBooks('10:00', '4155550304')->assertRedirect(route('public.booking.confirmed', [$this->typeA->public_booking_uuid]));
        $this->assertSame((int) $other->id, (int) Appointment::query()->firstOrFail()->staff_user_id, 'the guest was routed to the person whose calendar is free');

        // With both calendars busy the slot is gone.
        $theirs = $this->createActiveConnection($other, ExternalCalendarProvider::Outlook);
        $block((int) $theirs->id);
        $this->assertNotContains('10:00', $this->offered());
        $this->guestBooks('10:00', '4155550305')->assertSessionHasErrors('time');
        $this->assertSame(1, $this->appointmentCount());
    }

    public function test_a_busy_period_blocks_the_person_at_every_location(): void
    {
        $typeB = $this->typeWithStaff($this->locationB, $this->worker);
        $connection = $this->connectThroughTheUi($this->worker, 'google');
        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([$this->busy('evt-x', '10:00:00', '11:00:00')], 'c1', true);
        $this->sync($connection);

        foreach ([$this->typeA, $typeB] as $type) {
            $this->assertNotContains('10:00', $this->offered($type));
            $this->guestBooks('10:00', '4155550306', $type)->assertSessionHasErrors('time');
        }
        $this->assertSame(0, $this->appointmentCount());

        // And the staff-side calendar refuses it too, at either Location.
        $this->actAsOwner();
        foreach ([[$this->locationA, $this->typeA], [$this->locationB, $typeB]] as [$location, $type]) {
            $contactUid = $this->contactUidAt($location);
            $this->post($this->cal('appointments.store', $location), [
                'booking_type_uid' => $type->uid, 'contact_uid' => $contactUid,
                'staff' => (string) $this->worker->id, 'date' => '2027-03-01', 'time' => '10:00',
            ])->assertSessionHas('flash_error');
        }
        $this->assertSame(0, $this->appointmentCount());
    }

    public function test_the_connection_surface_is_per_user_and_secret_free(): void
    {
        $mine = $this->connectThroughTheUi($this->worker, 'google');
        $someoneElse = $this->memberWithFullReach(\App\Enums\Workspace\WorkspaceMembershipRole::Staff);

        // Another user sees THEIR (empty) surface, never mine, and cannot end mine.
        $this->actAs($someoneElse);
        $page = $this->get(route('customer.calendar-connection.show'))->assertOk()->getContent();
        $this->assertStringContainsString('Connect Google Calendar', $page);
        $this->assertStringNotContainsString('staff@example.test', $page);
        $this->post(route('customer.calendar-connection.disconnect'))->assertRedirect(route('customer.calendar-connection.show'));
        $this->assertSame(ExternalCalendarConnectionState::Active, $mine->fresh()->state);

        // Nor can they finish my OAuth attempt with my signed state.
        $mine->fresh()->update(['state' => ExternalCalendarConnectionState::Disconnected]);
        $pending = $this->createPendingConnection($this->worker, ExternalCalendarProvider::Outlook, [
            'state' => ExternalCalendarConnectionState::Pending,
            'oauth_state_nonce' => 'n-x', 'oauth_state_expires_at' => now()->addMinutes(5),
        ]);
        $stolen = $this->signedStateFor($pending);
        $this->get(route('customer.calendar-connection.oauth.callback', ['provider' => 'outlook', 'state' => $stolen, 'code' => 'c']))->assertNotFound();
        $this->assertSame(0, $this->fakeOutlook->codeExchangeCalls, 'a refused callback never reaches the provider');
    }
}
