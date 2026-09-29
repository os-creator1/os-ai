<?php

namespace Tests\Feature\Calendar\ExternalCalendar;

use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Library\Calendar\ExternalCalendar\MicrosoftCalendarProviderClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Implementation Contract 15 §12.F, review correction — a direct HTTP-level
 * proof against the REAL MicrosoftCalendarProviderClient (not
 * FakeCalendarProviderClient) that `renewNotifications()` uses Microsoft
 * Graph's actual in-place renewal mechanic (`PATCH /subscriptions/{id}`,
 * keeping the SAME subscription id) rather than re-registering on every
 * scheduled renewal, and that a 404 on that PATCH is reported as
 * registrationNotFound() — never retried, never silently swallowed.
 */
class MicrosoftCalendarProviderClientTest extends TestCase
{
    public function test_a_normal_renewal_patches_the_existing_subscription_in_place(): void
    {
        $newExpiry = Carbon::parse('2026-10-06T12:00:00Z');

        Http::fake([
            'https://graph.microsoft.com/v1.0/subscriptions/sub-old' => Http::response([
                'id' => 'sub-old',
                'expirationDateTime' => $newExpiry->toIso8601ZuluString(),
            ], 200),
        ]);

        $client = new MicrosoftCalendarProviderClient();
        $registration = $client->renewNotifications('fake-access-token', 'sub-old', $newExpiry);

        Http::assertSentCount(1);
        $recorded = Http::recorded()[0][0];

        $this->assertSame('PATCH', $recorded->method());
        $this->assertSame('https://graph.microsoft.com/v1.0/subscriptions/sub-old', $recorded->url());
        $this->assertSame($newExpiry->toIso8601ZuluString(), $recorded->data()['expirationDateTime'] ?? null);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/subscriptions'));

        $this->assertSame('sub-old', $registration->registrationId);
        $this->assertTrue($registration->expiresAt->equalTo($newExpiry));
    }

    public function test_a_disappeared_subscription_reports_registration_not_found_and_never_calls_delete_or_post(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/subscriptions/sub-old' => Http::response([], 404),
        ]);

        $client = new MicrosoftCalendarProviderClient();

        try {
            $client->renewNotifications('fake-access-token', 'sub-old', now()->addDays(7));
            $this->fail('Expected ExternalCalendarProviderException');
        } catch (ExternalCalendarProviderException $exception) {
            $this->assertSame(ExternalCalendarProviderException::FAILURE_REGISTRATION_NOT_FOUND, $exception->classification);
        }

        Http::assertSentCount(1);
        $recorded = Http::recorded()[0][0];
        $this->assertSame('PATCH', $recorded->method());

        // renewNotifications() itself never falls back to POST or DELETE —
        // that fallback belongs to the registrar
        // (ExternalCalendarNotificationRegistrar), which decides to call
        // registerNotifications() only after observing this classification.
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }
}
