<?php

namespace Tests\Feature\Calendar\ExternalCalendar;

use App\DTO\Calendar\ExternalCalendarNotificationRegistration;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarConnectionManager;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarNotificationRegistrar;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarWebhookToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\Feature\Calendar\ExternalCalendar\Concerns\CreatesExternalCalendarFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §11/§12.F, review correction — proving the
 * provider push registration/subscription is genuinely CREATED (blocker 1)
 * and genuinely RENEWABLE, on both providers, against
 * ExternalCalendarNotificationRegistrar.
 */
class ExternalCalendarNotificationRegistrationTest extends TestCase
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

    private function registrar(): ExternalCalendarNotificationRegistrar
    {
        return app(ExternalCalendarNotificationRegistrar::class);
    }

    // -----------------------------------------------------------------
    // Registration — Google
    // -----------------------------------------------------------------

    public function test_a_successful_google_connection_registers_a_watch_channel_with_the_correct_notification_url_and_proof(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);

        $this->registrar()->ensureRegistered($connection);

        $this->assertSame(1, $this->fakeGoogle->registerCalls);
        $request = $this->fakeGoogle->registerRequests[0];

        $expectedUrl = route('public.calendar.webhooks.google', [
            'connectionUid' => $connection->uid,
            'token' => ExternalCalendarWebhookToken::forConnection($connection->uid, 'google'),
        ]);
        $this->assertSame($expectedUrl, $request['notificationUrl']);
        $this->assertSame(ExternalCalendarWebhookToken::forConnection($connection->uid, 'google'), $request['proofToken']);

        $fresh = $connection->fresh();
        $this->assertNotNull($fresh->notification_channel_id);
        $this->assertNotNull($fresh->notification_registration_id);
        $this->assertNotNull($fresh->notification_expires_at);
        $this->assertTrue($fresh->notification_expires_at->isFuture());
    }

    public function test_the_google_registration_expiration_is_bounded_by_configuration(): void
    {
        config(['calendar_external.notifications.google_expiration_minutes' => 100]);
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);

        $this->registrar()->ensureRegistered($connection);

        $requestedExpiry = $this->fakeGoogle->registerRequests[0]['requestedExpiry'];
        $this->assertTrue($requestedExpiry->diffInMinutes(now(), true) <= 101);
    }

    public function test_registering_a_google_channel_persists_no_oauth_access_token(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);

        $this->registrar()->ensureRegistered($connection);

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('external_calendar_connections', 'access_token'));
        $array = $connection->fresh()->toArray();
        $this->assertArrayNotHasKey('refresh_token_encrypted', $array);
    }

    // -----------------------------------------------------------------
    // Registration — Microsoft
    // -----------------------------------------------------------------

    public function test_a_successful_outlook_connection_creates_a_graph_subscription_with_the_correct_notification_url_and_clientstate(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Outlook);

        $this->registrar()->ensureRegistered($connection);

        $this->assertSame(1, $this->fakeOutlook->registerCalls);
        $request = $this->fakeOutlook->registerRequests[0];

        $expectedUrl = route('public.calendar.webhooks.outlook', [
            'connectionUid' => $connection->uid,
            'token' => ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook'),
        ]);
        $this->assertSame($expectedUrl, $request['notificationUrl']);
        $this->assertSame(ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook'), $request['proofToken']);

        $fresh = $connection->fresh();
        $this->assertNull($fresh->notification_channel_id);
        $this->assertNotNull($fresh->notification_registration_id);
        $this->assertNotNull($fresh->notification_expires_at);
        $this->assertTrue($fresh->notification_expires_at->isFuture());
    }

    public function test_the_outlook_subscription_expiration_never_exceeds_graphs_documented_maximum_for_events(): void
    {
        // https://learn.microsoft.com/graph/change-notifications-overview —
        // the documented maximum for the `event` resource is exactly 10,080
        // minutes (7 days); the default config value is pinned to it.
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Outlook);

        $this->registrar()->ensureRegistered($connection);

        $requestedExpiry = $this->fakeOutlook->registerRequests[0]['requestedExpiry'];
        $this->assertTrue($requestedExpiry->diffInMinutes(now(), true) <= 10080);
    }

    public function test_registering_an_outlook_subscription_persists_no_oauth_access_token(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Outlook);

        $this->registrar()->ensureRegistered($connection);

        $array = $connection->fresh()->toArray();
        $this->assertArrayNotHasKey('refresh_token_encrypted', $array);
    }

    // -----------------------------------------------------------------
    // Registration is triggered at connect completion
    // -----------------------------------------------------------------

    public function test_completing_a_connection_via_the_real_oauth_callback_registers_notifications(): void
    {
        $this->authenticate($this->staff);
        $connection = $this->createPendingConnection($this->staff, ExternalCalendarProvider::Google, [
            'oauth_state_nonce' => 'n-reg',
            'oauth_state_expires_at' => now()->addMinutes(5),
        ]);
        $state = $this->signedStateFor($connection);

        $this->get(route('customer.calendar-connection.oauth.callback', [
            'provider' => 'google',
            'state' => $state,
            'code' => 'auth-code-reg',
        ]))->assertRedirect();

        $this->assertSame(1, $this->fakeGoogle->registerCalls);
        $this->assertNotNull($connection->fresh()->notification_registration_id);
    }

    public function test_a_registration_failure_never_fails_the_connect_flow_polling_remains_the_fallback(): void
    {
        $this->authenticate($this->staff);
        $connection = $this->createPendingConnection($this->staff, ExternalCalendarProvider::Google, [
            'oauth_state_nonce' => 'n-reg-fail',
            'oauth_state_expires_at' => now()->addMinutes(5),
        ]);
        $state = $this->signedStateFor($connection);
        $this->fakeGoogle->throwOnRegister = ExternalCalendarProviderException::providerUnavailable();

        $response = $this->get(route('customer.calendar-connection.oauth.callback', [
            'provider' => 'google',
            'state' => $state,
            'code' => 'auth-code-reg-fail',
        ]));

        $response->assertRedirect(route('customer.calendar-connection.show'));
        $fresh = $connection->fresh();
        $this->assertSame(\App\Enums\Calendar\ExternalCalendarConnectionState::Active, $fresh->state);
        $this->assertNull($fresh->notification_registration_id);
    }

    // -----------------------------------------------------------------
    // Renewal
    // -----------------------------------------------------------------

    public function test_an_expiring_registration_renews(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, [
            'notification_channel_id' => 'old-channel',
            'notification_registration_id' => 'old-resource',
            'notification_expires_at' => now()->addHours(2), // inside the default 24h renewal lead
        ]);

        $this->registrar()->ensureRegistered($connection);

        $this->assertSame(1, $this->fakeGoogle->registerCalls);
        $this->assertNotSame('old-resource', $connection->fresh()->notification_registration_id);
    }

    public function test_a_healthy_future_dated_registration_is_not_churned_needlessly(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, [
            'notification_channel_id' => 'healthy-channel',
            'notification_registration_id' => 'healthy-resource',
            'notification_expires_at' => now()->addDays(5), // well outside the 24h renewal lead
        ]);

        $this->registrar()->ensureRegistered($connection);

        $this->assertSame(0, $this->fakeGoogle->registerCalls);
        $this->assertSame('healthy-resource', $connection->fresh()->notification_registration_id);
    }

    public function test_renewal_provider_failure_keeps_polling_and_internal_bookings_functional(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, [
            'notification_channel_id' => 'expiring-channel',
            'notification_registration_id' => 'expiring-resource',
            'notification_expires_at' => now()->addHour(),
            'sync_cursor' => 'seed-cursor',
        ]);
        $this->fakeGoogle->throwOnRegister = ExternalCalendarProviderException::providerUnavailable();

        // Must not throw — the sweep command relies on this.
        $this->registrar()->ensureRegistered($connection);

        $fresh = $connection->fresh();
        $this->assertSame('expiring-resource', $fresh->notification_registration_id);
        $this->assertSame(1, $fresh->sync_failure_count);

        // Polling (an ordinary sync) still works regardless.
        $this->fakeGoogle->fullBusyQueue[] = new \App\DTO\Calendar\ExternalCalendarSyncPage([], 'cursor', true);
        app(\App\Library\Calendar\ExternalCalendar\ExternalCalendarSyncService::class)->syncConnection($fresh);
        $this->assertSame(1, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_the_scheduled_sweep_command_renews_and_syncs_in_one_pass(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, [
            'notification_expires_at' => null,
        ]);
        $this->fakeGoogle->fullBusyQueue[] = new \App\DTO\Calendar\ExternalCalendarSyncPage([], 'cursor', true);

        $this->artisan('calendar:sync-external-connections')->assertExitCode(0);

        $fresh = $connection->fresh();
        $this->assertNotNull($fresh->notification_registration_id);
        $this->assertSame(1, $this->fakeGoogle->registerCalls);
        $this->assertSame(1, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    // -----------------------------------------------------------------
    // Disconnect / revoke cleanup
    // -----------------------------------------------------------------

    public function test_disconnect_unregisters_the_provider_channel_before_destroying_local_credentials(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, [
            'notification_channel_id' => 'channel-to-stop',
            'notification_registration_id' => 'resource-to-stop',
            'notification_expires_at' => now()->addDays(5),
        ]);

        $this->registrar()->unregister($connection);
        app(ExternalCalendarConnectionManager::class)->disconnect($connection, (int) $this->staff->id);

        $this->assertSame(1, $this->fakeGoogle->unregisterCalls);
        $this->assertSame('resource-to-stop', $this->fakeGoogle->unregisterRequests[0]['registrationId']);
        $this->assertSame('channel-to-stop', $this->fakeGoogle->unregisterRequests[0]['channelId']);

        $fresh = $connection->fresh();
        $this->assertNull($fresh->notification_channel_id);
        $this->assertNull($fresh->notification_registration_id);
        $this->assertNull($fresh->notification_expires_at);
    }

    public function test_a_failed_provider_side_unregister_still_lets_local_credentials_be_destroyed(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, [
            'notification_channel_id' => 'channel-to-stop',
            'notification_registration_id' => 'resource-to-stop',
            'notification_expires_at' => now()->addDays(5),
        ]);
        $this->fakeGoogle->throwOnUnregister = ExternalCalendarProviderException::providerUnavailable();

        // Must not throw.
        $this->registrar()->unregister($connection);
        app(ExternalCalendarConnectionManager::class)->disconnect($connection, (int) $this->staff->id);

        $fresh = $connection->fresh();
        $this->assertSame(\App\Enums\Calendar\ExternalCalendarConnectionState::Disconnected, $fresh->state);
        $this->assertNull($fresh->refresh_token_encrypted);
        $this->assertNull($fresh->notification_registration_id);
    }

    public function test_disconnecting_via_the_real_controller_action_unregisters_first(): void
    {
        $this->authenticate($this->staff);
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, [
            'notification_channel_id' => 'controller-channel',
            'notification_registration_id' => 'controller-resource',
            'notification_expires_at' => now()->addDays(5),
        ]);

        $this->post(route('customer.calendar-connection.disconnect'))->assertRedirect();

        $this->assertSame(1, $this->fakeGoogle->unregisterCalls);
        $this->assertNull($connection->fresh()->notification_registration_id);
    }
}
