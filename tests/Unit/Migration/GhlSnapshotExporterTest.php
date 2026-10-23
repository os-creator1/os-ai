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

    /** @param array<string, int> $messageStatus conversation id => HTTP status of its message page */
    private function exportWith(array $messageStatus, int $secondContactsPage = 200): array
    {
        $this->fakeGhl($messageStatus, $secondContactsPage);

        return (new GhlSnapshotExporter(new GhlApiClient('https://example.test', 'tkn')))->export('L1');
    }

    private function fakeGhl(array $messageStatus, int $secondContactsPage = 200): void
    {
        $contactCalls = 0;
        Http::fake(function (Request $r) use (&$contactCalls, $messageStatus, $secondContactsPage) {
            $url = $r->url();
            if (str_contains($url, '/contacts/')) {
                $contactCalls++;

                return $contactCalls === 1
                    ? Http::response(['contacts' => [['id' => 'a']], 'meta' => ['startAfter' => 1, 'startAfterId' => 'a']])
                    : Http::response($secondContactsPage === 200 ? ['contacts' => [['id' => 'b']], 'meta' => []] : ['message' => 'nope'], $secondContactsPage);
            }
            if (str_contains($url, '/conversations/search')) {
                return Http::response(['conversations' => [['id' => 'v1', 'lastMessageDate' => 5], ['id' => 'v2', 'lastMessageDate' => 4]]]);
            }
            if (preg_match('#/conversations/(v\d)/messages#', $url, $m)) {
                $status = $messageStatus[$m[1]] ?? 200;

                return $status === 200
                    ? Http::response(['messages' => ['messages' => [['id' => 'm-'.$m[1]]], 'nextPage' => false]])
                    : Http::response(['message' => 'denied'], $status);
            }

            return Http::response([]);
        });
    }

    public function test_a_clean_export_is_proven_complete(): void
    {
        $export = $this->exportWith([]);

        $this->assertSame([], $export['report']['messages']['failures']);
        $this->assertSame(2, $export['report']['messages']['count']);
        $this->assertSame([], GhlSnapshotExporter::incompleteDatasets($export['report']));
    }

    public function test_a_failed_message_page_makes_the_export_unverified_and_keeps_the_failure(): void
    {
        $export = $this->exportWith(['v2' => 403]);

        $this->assertNull($export['report']['messages']['count'], 'A partial message download is UNVERIFIED, never a smaller successful count.');
        $this->assertSame(403, $export['report']['messages']['status']);
        $this->assertSame([['conversation' => 'v2', 'page' => 1, 'status' => 403]], $export['report']['messages']['failures']);
        $this->assertSame([['id' => 'm-v1']], $export['data']['messages']['v1'], 'What was downloaded is preserved.');
        $this->assertSame(['messages'], GhlSnapshotExporter::incompleteDatasets($export['report']));
    }

    public function test_a_failed_later_contacts_page_cannot_pass_as_the_end_of_the_list(): void
    {
        $export = $this->exportWith([], 403);

        $this->assertNull($export['report']['contacts']['count']);
        $this->assertSame([['page' => 2, 'status' => 403]], $export['report']['contacts']['failures']);
        $this->assertCount(1, $export['data']['contacts']);
        $this->assertContains('contacts', GhlSnapshotExporter::incompleteDatasets($export['report']));
    }

    public function test_a_report_without_failure_evidence_or_a_missing_report_is_not_trusted(): void
    {
        $legacy = ['location' => ['status' => 200, 'count' => 1], 'customFields' => ['status' => 200, 'count' => 1], 'tags' => ['status' => 200, 'count' => 1], 'pipelines' => ['status' => 200, 'count' => 1],
            'contacts' => ['status' => 200, 'count' => 1], 'opportunities' => ['status' => 200, 'count' => 1], 'conversations' => ['status' => 200, 'count' => 1], 'messages' => ['status' => 200, 'count' => 5]];

        $this->assertContains('messages', GhlSnapshotExporter::incompleteDatasets($legacy));
        $this->assertSame(GhlSnapshotExporter::REQUIRED, GhlSnapshotExporter::incompleteDatasets([]));
        $this->assertSame(GhlSnapshotExporter::REQUIRED, GhlSnapshotExporter::incompleteInDirectory(sys_get_temp_dir().'/does-not-exist-'.uniqid()));
    }

    public function test_the_export_command_exits_non_zero_and_keeps_the_partial_files_when_a_page_fails(): void
    {
        $dir = sys_get_temp_dir().'/ghl-export-'.uniqid();
        $this->fakeGhl(['v2' => 403]);
        putenv('GHL_PRIVATE_INTEGRATION_TOKEN=tkn');

        try {
            $this->artisan('ghl:export-snapshot', ['--location' => 'L1', '--out' => $dir])->expectsOutputToContain('INCOMPLETE / UNVERIFIED: messages')->assertExitCode(1);
            $report = json_decode((string) file_get_contents("{$dir}/_report.json"), true);
            $this->assertNull($report['messages']['count']);
            $this->assertSame('v2', $report['messages']['failures'][0]['conversation']);
            $this->assertFileExists("{$dir}/messages.json");
        } finally {
            putenv('GHL_PRIVATE_INTEGRATION_TOKEN');
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
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

    public function test_the_token_is_read_from_the_process_environment_when_config_is_cached(): void
    {
        config(['ghl_migration.token' => null, 'ghl_migration.base_url' => 'https://example.test']);
        putenv('GHL_PRIVATE_INTEGRATION_TOKEN=from-process-env');

        try {
            $client = \App\Library\Migration\Ghl\GhlApiClient::fromConfig();
            Http::fake(['*' => Http::response(['ok' => true])]);
            $client->get('/x');
            Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer from-process-env'));
        } finally {
            putenv('GHL_PRIVATE_INTEGRATION_TOKEN');
        }
    }
}
