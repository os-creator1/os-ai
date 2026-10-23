<?php

namespace Tests\Unit\Migration;

use App\Library\Migration\Ghl\GhlApiClient;
use App\Library\Migration\Ghl\GhlSnapshotExporter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GhlSnapshotExporterTest extends TestCase
{
    public function test_export_paginates_reports_gaps_and_only_issues_gets(): void
    {
        $contactCalls = 0;
        Http::fake(function (Request $r) use (&$contactCalls) {
            $url = $r->url();
            if (str_contains($url, '/contacts/')) {
                $contactCalls++;

                return Http::response($contactCalls === 1
                    ? ['contacts' => [['id' => 'a'], ['id' => 'b']], 'meta' => ['startAfter' => 1, 'startAfterId' => 'b']]
                    : ['contacts' => [['id' => 'c']], 'meta' => []]);
            }
            if (str_contains($url, '/workflows/')) {
                return Http::response(['message' => 'The token does not have access'], 403);
            }
            if (str_contains($url, '/conversations/search')) {
                return Http::response(['conversations' => [['id' => 'v1', 'contactId' => 'a', 'lastMessageDate' => 5]]]);
            }
            if (str_contains($url, '/conversations/v1/messages')) {
                return Http::response(['messages' => ['messages' => [['id' => 'm1']], 'nextPage' => false]]);
            }
            return Http::response([]);
        });

        $export = (new GhlSnapshotExporter(new GhlApiClient('https://example.test', 'tkn')))->export('L1');

        $this->assertCount(3, $export['data']['contacts']);
        $this->assertSame(['status' => 403, 'count' => null], $export['report']['workflows'], 'A denied dataset is reported UNVERIFIED, not silently empty.');
        $this->assertSame(1, $export['report']['messages']['count']);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->hasHeader('Version') && $r->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_rate_limit_is_retried(): void
    {
        Http::fakeSequence()->push([], 429, ['Retry-After' => '0'])->push(['ok' => true], 200);
        $client = new class('https://example.test', 'tkn') extends GhlApiClient {
            protected function sleep(int $seconds): void
            {
            }
        };

        $result = $client->get('/x');
        $this->assertTrue($result->ok());
        $this->assertSame(['ok' => true], $result->body);
    }

    public function test_missing_token_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        new GhlApiClient('https://example.test', '');
    }
}
