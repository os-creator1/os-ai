<?php

namespace Tests\Feature\Calendar\ExternalCalendar\Concerns;

use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarOAuthStateSigner;
use App\Library\Calendar\ExternalCalendar\GoogleCalendarProviderClient;
use App\Library\Calendar\ExternalCalendar\MicrosoftCalendarProviderClient;
use App\Models\ExternalCalendarConnection;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Calendar\ExternalCalendar\Fakes\FakeCalendarProviderClient;

/**
 * Implementation Contract 15 §12.F — fixtures shared by the Sub-slice F test
 * suite. Binds a deterministic FakeCalendarProviderClient over both provider
 * container keys so no test makes a real HTTP call, and gives each test a
 * fast path to a connection row in a specific lifecycle state.
 */
trait CreatesExternalCalendarFixtures
{
    protected FakeCalendarProviderClient $fakeGoogle;

    protected FakeCalendarProviderClient $fakeOutlook;

    /** @return array{0: FakeCalendarProviderClient, 1: FakeCalendarProviderClient} */
    protected function bindFakeCalendarProviders(): array
    {
        $this->fakeGoogle = new FakeCalendarProviderClient(ExternalCalendarProvider::Google);
        $this->fakeOutlook = new FakeCalendarProviderClient(ExternalCalendarProvider::Outlook);

        $this->app->instance(GoogleCalendarProviderClient::class, $this->fakeGoogle);
        $this->app->instance(MicrosoftCalendarProviderClient::class, $this->fakeOutlook);

        // ExternalCalendarOAuthConfig::assertUsable() requires every
        // credential + a redirect that matches the fixed callback route
        // exactly — set once here so every test's beginConnect() clears it
        // without needing its own config wiring.
        config([
            'calendar_external.google.client_id' => 'fake-google-client-id',
            'calendar_external.google.client_secret' => 'fake-google-client-secret',
            'calendar_external.google.redirect' => route('customer.calendar-connection.oauth.callback', ['provider' => 'google']),
            'calendar_external.outlook.client_id' => 'fake-outlook-client-id',
            'calendar_external.outlook.client_secret' => 'fake-outlook-client-secret',
            'calendar_external.outlook.redirect' => route('customer.calendar-connection.oauth.callback', ['provider' => 'outlook']),
        ]);

        return [$this->fakeGoogle, $this->fakeOutlook];
    }

    protected function fakeFor(ExternalCalendarProvider $provider): FakeCalendarProviderClient
    {
        return $provider === ExternalCalendarProvider::Google ? $this->fakeGoogle : $this->fakeOutlook;
    }

    protected function createPendingConnection(User $user, ExternalCalendarProvider $provider = ExternalCalendarProvider::Google, array $overrides = []): ExternalCalendarConnection
    {
        return ExternalCalendarConnection::create(array_merge([
            'user_id' => $user->id,
            'provider' => $provider,
            'state' => ExternalCalendarConnectionState::Pending,
        ], $overrides));
    }

    protected function createActiveConnection(User $user, ExternalCalendarProvider $provider = ExternalCalendarProvider::Google, array $overrides = []): ExternalCalendarConnection
    {
        return ExternalCalendarConnection::create(array_merge([
            'user_id' => $user->id,
            'provider' => $provider,
            'state' => ExternalCalendarConnectionState::Active,
            'refresh_token_encrypted' => Crypt::encryptString('seeded-refresh-token'),
            'connected_at' => now(),
            'last_refreshed_at' => now(),
        ], $overrides));
    }

    protected function signedStateFor(ExternalCalendarConnection $connection): string
    {
        return app(ExternalCalendarOAuthStateSigner::class)->issue($connection->fresh());
    }
}
