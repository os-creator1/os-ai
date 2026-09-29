<?php

namespace Tests\Feature\Calendar\ExternalCalendar;

use App\Library\Calendar\ExternalCalendar\GoogleCalendarProviderClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Implementation Contract 15 §12.F, review correction — a direct HTTP-level
 * proof against the REAL GoogleCalendarProviderClient (not
 * FakeCalendarProviderClient, which only proves the application-level
 * contract and cannot catch a wrong outgoing query string).
 *
 * Regression for: fetchIncrementalBusy() page 2+ replaced its query
 * wholesale with `['pageToken' => $pageToken]`, silently dropping
 * `syncToken`/`singleEvents` from every page after the first. Google's
 * documented pagination rule (https://developers.google.com/calendar/api/guides/sync)
 * is that a subsequent page repeats the SAME request parameters and adds
 * `pageToken` — never a pageToken-only request.
 */
class GoogleCalendarProviderClientTest extends TestCase
{
    public function test_incremental_pagination_repeats_synctoken_and_singleevents_on_every_page(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::sequence()
                ->push([
                    'items' => [$this->rawEvent('evt-page-1')],
                    'nextPageToken' => 'page-2',
                ], 200)
                ->push([
                    'items' => [$this->rawEvent('evt-page-2')],
                    'nextSyncToken' => 'sync-new',
                ], 200),
        ]);

        $client = new GoogleCalendarProviderClient();
        $page = $client->fetchIncrementalBusy('fake-access-token', 'sync-old');

        Http::assertSentCount(2);
        $recorded = Http::recorded();

        $query1 = $this->queryFrom($recorded[0][0]->url());
        $this->assertSame('sync-old', $query1['syncToken'] ?? null);
        $this->assertSame('true', $query1['singleEvents'] ?? null);
        $this->assertSame('true', $query1['showDeleted'] ?? null, 'a syncToken read is exactly where Google reports deletions');
        $this->assertSame('250', $query1['maxResults'] ?? null);
        $this->assertArrayNotHasKey('timeMin', $query1, 'timeMin/timeMax are never allowed alongside syncToken');
        $this->assertArrayNotHasKey('timeMax', $query1);
        $this->assertArrayNotHasKey('pageToken', $query1);

        $query2 = $this->queryFrom($recorded[1][0]->url());
        $this->assertSame('sync-old', $query2['syncToken'] ?? null, 'page 2 must still carry the original syncToken');
        $this->assertSame('true', $query2['singleEvents'] ?? null, 'page 2 must still carry singleEvents');
        $this->assertSame('true', $query2['showDeleted'] ?? null, 'page 2 must still carry showDeleted');
        $this->assertSame('250', $query2['maxResults'] ?? null, 'page 2 must still carry maxResults');
        $this->assertSame('page-2', $query2['pageToken'] ?? null);

        $this->assertCount(2, $page->events);
        $this->assertSame(['evt-page-1', 'evt-page-2'], array_map(fn ($e) => $e->providerEventId, $page->events));
        $this->assertSame('sync-new', $page->nextCursor);
        $this->assertTrue($page->complete);
    }

    public function test_incremental_pagination_with_only_one_page_carries_synctoken_and_no_pagetoken(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [$this->rawEvent('evt-only')],
                'nextSyncToken' => 'sync-only',
            ], 200),
        ]);

        $client = new GoogleCalendarProviderClient();
        $page = $client->fetchIncrementalBusy('fake-access-token', 'sync-old');

        Http::assertSentCount(1);
        $query = $this->queryFrom(Http::recorded()[0][0]->url());
        $this->assertSame('sync-old', $query['syncToken'] ?? null);
        $this->assertSame('true', $query['showDeleted'] ?? null);
        $this->assertSame('250', $query['maxResults'] ?? null);
        $this->assertArrayNotHasKey('pageToken', $query);
        $this->assertSame('sync-only', $page->nextCursor);
    }

    public function test_a_410_response_on_an_incremental_read_is_reported_as_an_invalid_cursor(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([], 410),
        ]);

        $client = new GoogleCalendarProviderClient();

        $this->expectException(\App\Exceptions\Calendar\ExternalCalendarProviderException::class);

        try {
            $client->fetchIncrementalBusy('fake-access-token', 'expired-sync-token');
        } catch (\App\Exceptions\Calendar\ExternalCalendarProviderException $exception) {
            $this->assertSame(\App\Exceptions\Calendar\ExternalCalendarProviderException::FAILURE_CURSOR_INVALID, $exception->classification);

            throw $exception;
        }
    }

    private function rawEvent(string $id): array
    {
        return [
            'id' => $id,
            'status' => 'confirmed',
            'start' => ['dateTime' => now()->toIso8601String()],
            'end' => ['dateTime' => now()->addHour()->toIso8601String()],
        ];
    }

    /** @return array<string, string> */
    private function queryFrom(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY) ?? '';
        parse_str($query, $parsed);

        return $parsed;
    }
}
