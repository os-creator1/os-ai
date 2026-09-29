<?php

namespace Tests\Feature\Calendar\ExternalCalendar;

use App\DTO\Calendar\ExternalCalendarBusyEvent;
use App\DTO\Calendar\ExternalCalendarSyncPage;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarNotificationRegistrar;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarWebhookToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\Feature\Calendar\ExternalCalendar\Concerns\CreatesExternalCalendarFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §11/§12.F — inbound webhook authenticity,
 * REVIEW CORRECTION.
 *
 * Replaces the earlier version of this file, which authenticated a webhook
 * on the URL-embedded HMAC alone. That token remains and is still checked
 * first (still zero-DB-read defense in depth), but §11 names a SECOND proof
 * this file now proves is actually verified: Google's channel id + resource
 * id + channel token (headers), Microsoft's subscription id + clientState
 * (notification body) — each compared against the registration record
 * ExternalCalendarNotificationRegistrar itself wrote, including its
 * expiration.
 */
class ExternalCalendarWebhookTest extends TestCase
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

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function registeredGoogleConnection(array $overrides = []): \App\Models\ExternalCalendarConnection
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);

        DB::table('external_calendar_connections')->where('id', $connection->id)->update(array_merge([
            'notification_channel_id' => 'channel-' . Str::uuid(),
            'notification_registration_id' => 'resource-' . Str::uuid(),
            'notification_expires_at' => now()->addDays(6),
        ], $overrides));

        return $connection->fresh();
    }

    private function registeredOutlookConnection(array $overrides = []): \App\Models\ExternalCalendarConnection
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Outlook);

        DB::table('external_calendar_connections')->where('id', $connection->id)->update(array_merge([
            'notification_registration_id' => 'subscription-' . Str::uuid(),
            'notification_expires_at' => now()->addDays(6),
        ], $overrides));

        return $connection->fresh();
    }

    private function googleHeaders(\App\Models\ExternalCalendarConnection $connection, array $overrides = []): array
    {
        return array_merge([
            'X-Goog-Channel-ID' => $connection->notification_channel_id,
            'X-Goog-Resource-ID' => $connection->notification_registration_id,
            'X-Goog-Channel-Token' => ExternalCalendarWebhookToken::forConnection($connection->uid, 'google'),
            'X-Goog-Resource-State' => 'exists',
        ], $overrides);
    }

    private function outlookNotificationBody(\App\Models\ExternalCalendarConnection $connection, array $overrides = []): array
    {
        return [
            'value' => [array_merge([
                'subscriptionId' => $connection->notification_registration_id,
                'clientState' => ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook'),
                'changeType' => 'updated',
                'resource' => "me/events/attacker-supplied",
            ], $overrides)],
        ];
    }

    // -----------------------------------------------------------------
    // URL-embedded token — still the first, zero-DB-read gate
    // -----------------------------------------------------------------

    public function test_a_missing_url_token_is_rejected_with_no_side_effect(): void
    {
        $connection = $this->registeredGoogleConnection();

        $response = $this->postJson('/webhooks/calendar/' . $connection->uid . '/google');

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_a_forged_url_token_is_rejected_with_no_side_effect(): void
    {
        $connection = $this->registeredGoogleConnection();

        $response = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => 'forged']),
            [],
            $this->googleHeaders($connection)
        );

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    // -----------------------------------------------------------------
    // Google provider proof (channel id / resource id / channel token)
    // -----------------------------------------------------------------

    public function test_google_correct_channel_token_and_resource_identity_triggers_exactly_one_authenticated_pull(): void
    {
        $connection = $this->registeredGoogleConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');
        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([], 'cursor', true);

        $response = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]),
            [],
            $this->googleHeaders($connection)
        );

        $response->assertOk();
        $this->assertSame(1, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_google_missing_channel_token_header_refuses_the_pull(): void
    {
        $connection = $this->registeredGoogleConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');
        $headers = $this->googleHeaders($connection);
        unset($headers['X-Goog-Channel-Token']);

        $response = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]),
            [],
            $headers
        );

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_google_wrong_channel_token_refuses_the_pull(): void
    {
        $connection = $this->registeredGoogleConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        $response = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]),
            [],
            $this->googleHeaders($connection, ['X-Goog-Channel-Token' => 'not-the-real-token'])
        );

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_google_wrong_channel_identity_refuses_the_pull(): void
    {
        $connection = $this->registeredGoogleConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        $response = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]),
            [],
            $this->googleHeaders($connection, ['X-Goog-Channel-ID' => 'someone-elses-channel'])
        );

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_google_wrong_resource_identity_refuses_the_pull(): void
    {
        $connection = $this->registeredGoogleConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        $response = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]),
            [],
            $this->googleHeaders($connection, ['X-Goog-Resource-ID' => 'someone-elses-resource'])
        );

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_google_an_expired_registration_refuses_the_pull(): void
    {
        $connection = $this->registeredGoogleConnection(['notification_expires_at' => now()->subMinute()]);
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        $response = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]),
            [],
            $this->googleHeaders($connection->fresh())
        );

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_google_a_never_registered_connection_refuses_the_pull_indistinguishably(): void
    {
        // Active, but ExternalCalendarNotificationRegistrar never ran —
        // exactly the "unknown registration" case, and must look identical
        // to every forged-proof case above.
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        $response = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]),
            [],
            ['X-Goog-Channel-ID' => 'guessed', 'X-Goog-Resource-ID' => 'guessed', 'X-Goog-Channel-Token' => 'guessed']
        );

        $response->assertNotFound();
    }

    public function test_google_sync_resource_state_is_authenticated_but_does_not_trigger_a_pull(): void
    {
        $connection = $this->registeredGoogleConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        $response = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]),
            [],
            $this->googleHeaders($connection, ['X-Goog-Resource-State' => 'sync'])
        );

        $response->assertOk();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_google_a_replaced_channel_rejects_the_old_identity_but_accepts_the_new_one(): void
    {
        $connection = $this->registeredGoogleConnection();
        $oldChannelId = $connection->notification_channel_id;
        $oldResourceId = $connection->notification_registration_id;
        $urlToken = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        // Force a renewal-driven replacement — Google mechanically has no
        // in-place renewal, so ensureRegistered() always creates a NEW
        // channel with a NEW identity here and best-effort tears down the
        // old one, exactly as ExternalCalendarNotificationRegistrar's own
        // renew-then-fallback path does on a real expiring channel.
        app(ExternalCalendarNotificationRegistrar::class)->ensureRegistered($connection, force: true);
        $fresh = $connection->fresh();

        $this->assertNotSame($oldChannelId, $fresh->notification_channel_id);
        $this->assertNotSame($oldResourceId, $fresh->notification_registration_id);
        $this->assertSame(1, $this->fakeGoogle->unregisterCalls);

        // The OLD channel/resource identity is no longer the stored
        // authoritative registration and must be rejected indistinguishably
        // from any other forged proof, even though the URL token (keyed
        // only to the connection, not the channel identity) is still valid.
        $oldResponse = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $urlToken]),
            [],
            ['X-Goog-Channel-ID' => $oldChannelId, 'X-Goog-Resource-ID' => $oldResourceId, 'X-Goog-Channel-Token' => $urlToken, 'X-Goog-Resource-State' => 'exists']
        );
        $oldResponse->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);

        // The NEW identity is accepted and triggers a pull.
        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([], 'cursor', true);
        $newResponse = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $urlToken]),
            [],
            $this->googleHeaders($fresh)
        );
        $newResponse->assertOk();
        $this->assertSame(1, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    // -----------------------------------------------------------------
    // Microsoft provider proof (subscription id / clientState)
    // -----------------------------------------------------------------

    public function test_outlook_correct_subscription_and_clientstate_triggers_exactly_one_authenticated_pull(): void
    {
        $connection = $this->registeredOutlookConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook');
        $this->fakeOutlook->fullBusyQueue[] = new ExternalCalendarSyncPage([], 'cursor', true);

        $response = $this->postJson(
            route('public.calendar.webhooks.outlook', ['connectionUid' => $connection->uid, 'token' => $token]),
            $this->outlookNotificationBody($connection)
        );

        $response->assertStatus(202);
        $this->assertSame(1, $this->fakeOutlook->fullBusyCalls + $this->fakeOutlook->incrementalBusyCalls);
    }

    public function test_outlook_missing_clientstate_refuses_the_pull(): void
    {
        $connection = $this->registeredOutlookConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook');
        $body = ['value' => [['subscriptionId' => $connection->notification_registration_id]]];

        $response = $this->postJson(
            route('public.calendar.webhooks.outlook', ['connectionUid' => $connection->uid, 'token' => $token]),
            $body
        );

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeOutlook->fullBusyCalls + $this->fakeOutlook->incrementalBusyCalls);
    }

    public function test_outlook_wrong_clientstate_refuses_the_pull(): void
    {
        $connection = $this->registeredOutlookConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook');

        $response = $this->postJson(
            route('public.calendar.webhooks.outlook', ['connectionUid' => $connection->uid, 'token' => $token]),
            $this->outlookNotificationBody($connection, ['clientState' => 'not-the-real-secret'])
        );

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeOutlook->fullBusyCalls + $this->fakeOutlook->incrementalBusyCalls);
    }

    public function test_outlook_wrong_subscription_identity_refuses_the_pull(): void
    {
        $connection = $this->registeredOutlookConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook');

        $response = $this->postJson(
            route('public.calendar.webhooks.outlook', ['connectionUid' => $connection->uid, 'token' => $token]),
            $this->outlookNotificationBody($connection, ['subscriptionId' => 'someone-elses-subscription'])
        );

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeOutlook->fullBusyCalls + $this->fakeOutlook->incrementalBusyCalls);
    }

    public function test_outlook_an_expired_subscription_refuses_the_pull(): void
    {
        $connection = $this->registeredOutlookConnection(['notification_expires_at' => now()->subMinute()]);
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook');

        $response = $this->postJson(
            route('public.calendar.webhooks.outlook', ['connectionUid' => $connection->uid, 'token' => $token]),
            $this->outlookNotificationBody($connection->fresh())
        );

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeOutlook->fullBusyCalls + $this->fakeOutlook->incrementalBusyCalls);
    }

    public function test_outlook_an_unknown_subscription_is_indistinguishable_from_a_forged_proof(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Outlook);
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook');

        $response = $this->postJson(
            route('public.calendar.webhooks.outlook', ['connectionUid' => $connection->uid, 'token' => $token]),
            ['value' => [['subscriptionId' => 'guessed', 'clientState' => 'guessed']]]
        );

        $response->assertNotFound();
    }

    public function test_outlook_validation_handshake_does_not_mutate_connection_or_busy_data(): void
    {
        $connection = $this->registeredOutlookConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook');
        $before = $connection->fresh();

        $response = $this->post(route('public.calendar.webhooks.outlook', [
            'connectionUid' => $connection->uid,
            'token' => $token,
            'validationToken' => 'graph-handshake-value',
        ]));

        $response->assertOk();
        $response->assertSee('graph-handshake-value', false);
        $this->assertSame(0, $this->fakeOutlook->fullBusyCalls + $this->fakeOutlook->incrementalBusyCalls);
        $this->assertEquals($before->notification_registration_id, $connection->fresh()->notification_registration_id);
        $this->assertSame(0, DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connection->id)->count());
    }

    public function test_the_validation_handshake_itself_still_requires_the_url_token(): void
    {
        $connection = $this->registeredOutlookConnection();

        $response = $this->post(route('public.calendar.webhooks.outlook', [
            'connectionUid' => $connection->uid,
            'token' => 'wrong',
            'validationToken' => 'graph-handshake-value',
        ]));

        $response->assertNotFound();
        $response->assertDontSee('graph-handshake-value', false);
    }

    // -----------------------------------------------------------------
    // Trigger-not-data, idempotency, disconnect
    // -----------------------------------------------------------------

    public function test_attacker_supplied_event_data_in_the_notification_body_is_never_written_directly(): void
    {
        $connection = $this->registeredOutlookConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook');
        // No queued page — the fake's harmless empty default is what would
        // actually get applied, proving the body's resourceData is inert.
        $body = $this->outlookNotificationBody($connection, [
            'resourceData' => ['id' => 'attacker-supplied', 'start' => now()->toIso8601String(), 'end' => now()->addHour()->toIso8601String()],
        ]);

        $response = $this->postJson(route('public.calendar.webhooks.outlook', ['connectionUid' => $connection->uid, 'token' => $token]), $body);

        $response->assertStatus(202);
        $this->assertSame(0, DB::table('external_calendar_busy_blocks')
            ->where('external_calendar_connection_id', $connection->id)
            ->where('provider_event_id', 'attacker-supplied')
            ->count());
        // The trigger DID fire (an authenticated pull happened) — just never off the body.
        $this->assertSame(1, $this->fakeOutlook->fullBusyCalls);
    }

    public function test_two_valid_notifications_cause_redundant_reads_but_never_duplicate_busy_blocks(): void
    {
        $connection = $this->registeredGoogleConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-webhook', deleted: false, startAt: Carbon::now(), endAt: Carbon::now()->addHour()),
        ], 'cursor-a', true);
        $first = $this->postJson(route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]), [], $this->googleHeaders($connection));
        $first->assertOk();

        $this->fakeGoogle->incrementalBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-webhook', deleted: false, startAt: Carbon::now(), endAt: Carbon::now()->addHour()),
        ], 'cursor-b', true);
        $second = $this->postJson(route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]), [], $this->googleHeaders($connection));
        $second->assertOk();

        $this->assertSame(2, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
        $this->assertSame(1, DB::table('external_calendar_busy_blocks')
            ->where('external_calendar_connection_id', $connection->id)
            ->where('provider_event_id', 'evt-webhook')
            ->count());
    }

    public function test_a_webhook_for_a_disconnected_connection_no_longer_causes_a_sync(): void
    {
        $connection = $this->registeredGoogleConnection();
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');
        $headers = $this->googleHeaders($connection);

        app(\App\Library\Calendar\ExternalCalendar\ExternalCalendarConnectionManager::class)->disconnect($connection, (int) $this->staff->id);

        $response = $this->postJson(
            route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]),
            [],
            $headers
        );

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
        $this->assertSame(0, DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connection->id)->count());
        $this->assertNull($connection->fresh()->refresh_token_encrypted);
    }

    public function test_a_webhook_for_an_unknown_connection_uid_is_rejected(): void
    {
        $fakeUid = (string) Str::uuid();
        $token = ExternalCalendarWebhookToken::forConnection($fakeUid, 'google');

        $response = $this->postJson(route('public.calendar.webhooks.google', ['connectionUid' => $fakeUid, 'token' => $token]));

        $response->assertNotFound();
    }
}
