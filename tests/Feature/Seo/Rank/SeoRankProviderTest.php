<?php

namespace Tests\Feature\Seo\Rank;

use App\Library\Seo\Rank\Provider\DataForSeoRankProvider;
use App\Library\Seo\Rank\Provider\SeoRankProviderException;
use App\Library\Seo\Rank\Provider\SeoRankTaskRequest;
use App\Library\Seo\Rank\Provider\SeoRankTaskResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DataForSeoRankProvider against Http::fake() ONLY: request shape, response
 * parsing, local refusal of billable-at-5x queries, and the Rejected /
 * Ambiguous classification that decides whether a paid submit may be retried.
 * No real network request is possible in this file.
 */
class SeoRankProviderTest extends TestCase
{
    private const LOGIN = 'loginUser@example.test';
    private const PASSWORD = 'S3cr3t-Pa55word';
    private const TASK_ID = '10101010-2020-3030-4040-505050505050';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seo.rank_tracking.dataforseo.login' => self::LOGIN,
            'seo.rank_tracking.dataforseo.password' => self::PASSWORD,
            'seo.rank_tracking.dataforseo.base_url' => 'https://api.dataforseo.com',
        ]);
    }

    private function provider(): DataForSeoRankProvider
    {
        return new DataForSeoRankProvider();
    }

    private function request(
        string $type = 'organic',
        string $keyword = 'photo booth rental',
        ?int $depth = null,
        string $device = 'mobile',
        int $location = 1016367,
        string $language = 'en',
        string $tag = '4f5a1c52-uid-run',
    ): SeoRankTaskRequest {
        return new SeoRankTaskRequest($type, $keyword, $location, $language, $device, $depth ?? ($type === 'local' ? 10 : 100), $tag);
    }

    /** @param array<string, mixed> $task */
    private function envelope(int $taskStatus, array $task = []): array
    {
        return [
            'status_code' => 20000,
            'tasks' => [array_merge(['status_code' => $taskStatus], $task)],
        ];
    }

    private function fakeAll(mixed $response): void
    {
        Http::swap(new Factory());
        Http::fake(["*" => $response]);
    }

    /** @return list<Request> */
    private function sent(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])->values()->all();
    }

    private function assertSafeException(SeoRankProviderException $e, string $outcome, string $code): void
    {
        $this->assertSame($outcome, $e->outcome, 'outcome');
        $this->assertSame($code, $e->errorCode, 'code');
        $this->assertNull($e->getPrevious());

        $haystack = $e->getMessage() . '|' . $e->errorCode . '|' . $e->getFile();
        $this->assertStringNotContainsString(self::PASSWORD, $haystack);
        $this->assertStringNotContainsString(self::LOGIN, $haystack);
        $this->assertStringNotContainsString('LEAKY-BODY-TEXT', $haystack);
        $this->assertStringNotContainsString('dataforseo.com', $e->getMessage());
        $this->assertSame('Rank provider call failed (' . $code . ').', $e->getMessage());
    }

    // ---------------------------------------------------------------
    // submit: request shape
    // ---------------------------------------------------------------

    public function test_an_organic_submit_posts_the_documented_standard_queue_payload(): void
    {
        $this->fakeAll(Http::response($this->envelope(20100, ['id' => self::TASK_ID, 'cost' => 0.006])));

        $submission = $this->provider()->submit($this->request('organic', 'photo booth rental', 100, 'mobile', 1016367, 'en', 'run-uid-1'));

        $this->assertSame(self::TASK_ID, $submission->taskId);
        $this->assertSame(6000, $submission->costMicros);

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('POST', $sent[0]->method());
        $this->assertSame('https://api.dataforseo.com/v3/serp/google/organic/task_post', $sent[0]->url());

        $payload = $sent[0]->data();
        $this->assertCount(1, $payload);
        $this->assertSame([
            'keyword' => 'photo booth rental',
            'location_code' => 1016367,
            'language_code' => 'en',
            'device' => 'mobile',
            'depth' => 100,
            'priority' => 1,
            'tag' => 'run-uid-1',
        ], $payload[0]);

        $this->assertTrue($sent[0]->hasHeader('Authorization', 'Basic ' . base64_encode(self::LOGIN . ':' . self::PASSWORD)));
    }

    public function test_a_local_submit_uses_the_local_finder_endpoint(): void
    {
        $this->fakeAll(Http::response($this->envelope(20100, ['id' => self::TASK_ID, 'cost' => 0.0006])));

        $submission = $this->provider()->submit($this->request('local', 'photo booth rental', 10, 'mobile', 1016367, 'en', 'run-uid-2'));

        $this->assertSame(600, $submission->costMicros);
        $sent = $this->sent();
        $this->assertSame('https://api.dataforseo.com/v3/serp/google/local_finder/task_post', $sent[0]->url());
        $this->assertSame(10, $sent[0]->data()[0]['depth']);
        $this->assertSame(1, $sent[0]->data()[0]['priority']);
        $this->assertSame('mobile', $sent[0]->data()[0]['device']);
        $this->assertSame('run-uid-2', $sent[0]->data()[0]['tag']);
    }

    public function test_a_submit_never_carries_a_pingback_or_postback_or_other_unbudgeted_option(): void
    {
        $this->fakeAll(Http::response($this->envelope(20100, ['id' => self::TASK_ID])));

        $this->provider()->submit($this->request('organic'));
        $this->provider()->submit($this->request('local'));

        foreach ($this->sent() as $request) {
            $keys = array_keys($request->data()[0]);
            sort($keys);
            $this->assertSame(['depth', 'device', 'keyword', 'language_code', 'location_code', 'priority', 'tag'], $keys);
            $this->assertStringNotContainsString('pingback', json_encode($request->data()));
            $this->assertStringNotContainsString('postback', json_encode($request->data()));
            $this->assertStringNotContainsString('max_crawl_pages', json_encode($request->data()));
            $this->assertStringNotContainsString('live', $request->url());
        }
    }

    public function test_a_submit_without_a_reported_cost_returns_a_null_cost(): void
    {
        $this->fakeAll(Http::response($this->envelope(20100, ['id' => self::TASK_ID])));

        $this->assertNull($this->provider()->submit($this->request())->costMicros);
    }

    public function test_a_hyphenated_keyword_is_not_mistaken_for_an_operator(): void
    {
        $this->fakeAll(Http::response($this->envelope(20100, ['id' => self::TASK_ID])));

        $submission = $this->provider()->submit($this->request('organic', 'photo-booth rental near me'));

        $this->assertSame(self::TASK_ID, $submission->taskId);
    }

    // ---------------------------------------------------------------
    // submit: local refusals BEFORE any HTTP
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function operatorKeywords(): array
    {
        return [
            'quoted phrase' => ['"photo booth" rental'],
            'site operator' => ['site:example.com photo booth'],
            'inurl operator' => ['inurl:booth rental'],
            'exclusion' => ['photo booth -cheap'],
            'leading exclusion' => ['-cheap booth'],
            'OR' => ['photo booth OR kiosk'],
            'AND' => ['photo AND booth'],
            'wildcard' => ['photo * rental'],
            'pipe' => ['photo | booth'],
        ];
    }

    #[DataProvider('operatorKeywords')]
    public function test_operator_keywords_are_rejected_locally_before_any_http_call(string $keyword): void
    {
        Http::fake();

        try {
            $this->provider()->submit($this->request('organic', $keyword));
            $this->fail('An operator keyword must be refused.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, SeoRankProviderException::REJECTED, SeoRankProviderException::CODE_INVALID_REQUEST);
        }

        Http::assertNothingSent();
    }

    public function test_has_search_operator_detects_billable_operators_only(): void
    {
        $this->assertTrue(DataForSeoRankProvider::hasSearchOperator('"exact"'));
        $this->assertTrue(DataForSeoRankProvider::hasSearchOperator('a OR b'));
        $this->assertFalse(DataForSeoRankProvider::hasSearchOperator('photo booth rental'));
        $this->assertFalse(DataForSeoRankProvider::hasSearchOperator('dj-services chicago'));
        $this->assertFalse(DataForSeoRankProvider::hasSearchOperator('wedding corp'));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function badDepths(): array
    {
        return [
            'organic 101' => ['organic', 101],
            'organic 1000' => ['organic', 1000],
            'organic 0' => ['organic', 0],
            'organic negative' => ['organic', -10],
            'local 11' => ['local', 11],
            'local 100' => ['local', 100],
            'local 0' => ['local', 0],
        ];
    }

    #[DataProvider('badDepths')]
    public function test_out_of_range_depths_are_rejected_before_any_http_call(string $type, int $depth): void
    {
        Http::fake();

        try {
            $this->provider()->submit($this->request($type, 'photo booth rental', $depth));
            $this->fail('Depth must be bounded.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, SeoRankProviderException::REJECTED, SeoRankProviderException::CODE_INVALID_REQUEST);
        }

        Http::assertNothingSent();
    }

    public function test_the_maximum_allowed_depths_are_accepted(): void
    {
        $this->fakeAll(Http::response($this->envelope(20100, ['id' => self::TASK_ID])));

        $this->provider()->submit($this->request('organic', 'photo booth rental', 100));
        $this->provider()->submit($this->request('local', 'photo booth rental', 10));

        $this->assertCount(2, $this->sent());
    }

    public function test_a_non_mobile_device_is_rejected_before_any_http_call(): void
    {
        Http::fake();

        foreach (['desktop', 'tablet', 'Mobile', ''] as $device) {
            try {
                $this->provider()->submit($this->request('organic', 'photo booth rental', 100, $device));
                $this->fail("Device '{$device}' must be refused.");
            } catch (SeoRankProviderException $e) {
                $this->assertSame(SeoRankProviderException::CODE_INVALID_REQUEST, $e->errorCode);
            }
        }

        Http::assertNothingSent();
    }

    public function test_other_malformed_requests_are_rejected_before_any_http_call(): void
    {
        Http::fake();

        $bad = [
            $this->request('maps'),
            $this->request('organic', ''),
            $this->request('organic', str_repeat('a', 121)),
            $this->request('organic', 'photo booth rental', 100, 'mobile', 0),
            $this->request('organic', 'photo booth rental', 100, 'mobile', -4),
            $this->request('organic', 'photo booth rental', 100, 'mobile', 1016367, 'EN'),
            $this->request('organic', 'photo booth rental', 100, 'mobile', 1016367, 'english'),
        ];

        foreach ($bad as $request) {
            try {
                $this->provider()->submit($request);
                $this->fail('A malformed request must be refused.');
            } catch (SeoRankProviderException $e) {
                $this->assertSame(SeoRankProviderException::REJECTED, $e->outcome);
                $this->assertSame(SeoRankProviderException::CODE_INVALID_REQUEST, $e->errorCode);
            }
        }

        Http::assertNothingSent();
    }

    public function test_missing_credentials_are_rejected_as_not_configured_with_no_http(): void
    {
        Http::fake();

        foreach ([
            ['login' => null, 'password' => 'x'],
            ['login' => '', 'password' => 'x'],
            ['login' => 'x', 'password' => null],
            ['login' => 'x', 'password' => ''],
        ] as $creds) {
            config([
                'seo.rank_tracking.dataforseo.login' => $creds['login'],
                'seo.rank_tracking.dataforseo.password' => $creds['password'],
            ]);

            try {
                $this->provider()->submit($this->request());
                $this->fail('Credentials are required.');
            } catch (SeoRankProviderException $e) {
                $this->assertSame(SeoRankProviderException::REJECTED, $e->outcome);
                $this->assertSame(SeoRankProviderException::CODE_NOT_CONFIGURED, $e->errorCode);
            }
        }

        foreach (['fetch', 'ready', 'locations'] as $call) {
            try {
                match ($call) {
                    'fetch' => $this->provider()->fetch('organic', self::TASK_ID),
                    'ready' => $this->provider()->readyTasksByTag('organic'),
                    'locations' => $this->provider()->locations('US'),
                };
                $this->fail('Credentials are required.');
            } catch (SeoRankProviderException $e) {
                $this->assertSame(SeoRankProviderException::CODE_NOT_CONFIGURED, $e->errorCode);
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_non_https_base_url_is_refused_as_not_configured(): void
    {
        Http::fake();
        config(['seo.rank_tracking.dataforseo.base_url' => 'http://api.dataforseo.com']);

        try {
            $this->provider()->submit($this->request());
            $this->fail('Plain http must never carry credentials.');
        } catch (SeoRankProviderException $e) {
            $this->assertSame(SeoRankProviderException::CODE_NOT_CONFIGURED, $e->errorCode);
        }

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // submit: failure classification
    // ---------------------------------------------------------------

    /** @return array<string, array{0: int, 1: string}> HTTP status => closed code, all REJECTED */
    public static function rejectedHttpStatuses(): array
    {
        return [
            '401' => [401, SeoRankProviderException::CODE_AUTH],
            '402' => [402, SeoRankProviderException::CODE_PAYMENT],
            '429' => [429, SeoRankProviderException::CODE_RATE_LIMIT],
            '400' => [400, SeoRankProviderException::CODE_INVALID_REQUEST],
            '403' => [403, SeoRankProviderException::CODE_INVALID_REQUEST],
            '404' => [404, SeoRankProviderException::CODE_INVALID_REQUEST],
            '422' => [422, SeoRankProviderException::CODE_INVALID_REQUEST],
        ];
    }

    #[DataProvider('rejectedHttpStatuses')]
    public function test_definitive_http_refusals_are_rejected_with_a_closed_code(int $status, string $code): void
    {
        $this->fakeAll(Http::response(['status_message' => 'LEAKY-BODY-TEXT ' . self::PASSWORD], $status));

        try {
            $this->provider()->submit($this->request());
            $this->fail('Expected a rejection.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, SeoRankProviderException::REJECTED, $code);
            $this->assertFalse($e->isAmbiguous());
        }
    }

    /** @return array<string, array{0: int}> */
    public static function ambiguousHttpStatuses(): array
    {
        return ['500' => [500], '502' => [502], '503' => [503], '504' => [504], '599' => [599]];
    }

    #[DataProvider('ambiguousHttpStatuses')]
    public function test_server_errors_are_ambiguous_because_a_task_may_exist(int $status): void
    {
        $this->fakeAll(Http::response('LEAKY-BODY-TEXT ' . self::PASSWORD, $status));

        try {
            $this->provider()->submit($this->request());
            $this->fail('Expected ambiguity.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, SeoRankProviderException::AMBIGUOUS, SeoRankProviderException::CODE_UNAVAILABLE);
            $this->assertTrue($e->isAmbiguous());
        }
    }

    public function test_a_connection_exception_is_ambiguous_and_leaks_nothing(): void
    {
        $this->fakeAll(fn () => throw new ConnectionException('cURL error 28: timeout talking to api.dataforseo.com as ' . self::LOGIN . ':' . self::PASSWORD));

        try {
            $this->provider()->submit($this->request());
            $this->fail('Expected ambiguity.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, SeoRankProviderException::AMBIGUOUS, SeoRankProviderException::CODE_UNAVAILABLE);
        }
    }

    public function test_an_arbitrary_throwable_during_send_is_ambiguous(): void
    {
        $this->fakeAll(fn () => throw new \RuntimeException('LEAKY-BODY-TEXT'));

        try {
            $this->provider()->submit($this->request());
            $this->fail('Expected ambiguity.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, SeoRankProviderException::AMBIGUOUS, SeoRankProviderException::CODE_UNAVAILABLE);
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function garbageBodies(): array
    {
        return [
            'not json' => ['LEAKY-BODY-TEXT <html>oops</html>'],
            'empty body' => [''],
            'json scalar' => ['"LEAKY-BODY-TEXT"'],
            'empty object' => [[]],
            'no tasks' => [['status_code' => 20000]],
            'empty tasks' => [['status_code' => 20000, 'tasks' => []]],
            'task without status' => [['tasks' => [['id' => 'abc', 'note' => 'LEAKY-BODY-TEXT']]]],
            'task not array' => [['tasks' => ['LEAKY-BODY-TEXT']]],
        ];
    }

    #[DataProvider('garbageBodies')]
    public function test_a_garbage_200_body_is_ambiguous_on_submit(mixed $body): void
    {
        $this->fakeAll(is_array($body) ? Http::response($body, 200) : Http::response($body, 200, ['Content-Type' => 'application/json']));

        try {
            $this->provider()->submit($this->request());
            $this->fail('Expected ambiguity.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, SeoRankProviderException::AMBIGUOUS, SeoRankProviderException::CODE_BAD_RESPONSE);
        }
    }

    #[DataProvider('garbageBodies')]
    public function test_a_garbage_200_body_is_ambiguous_on_fetch(mixed $body): void
    {
        $this->fakeAll(is_array($body) ? Http::response($body, 200) : Http::response($body, 200, ['Content-Type' => 'application/json']));

        try {
            $this->provider()->fetch('organic', self::TASK_ID);
            $this->fail('Expected ambiguity.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, SeoRankProviderException::AMBIGUOUS, SeoRankProviderException::CODE_BAD_RESPONSE);
        }
    }

    public function test_a_created_status_without_a_task_id_is_ambiguous(): void
    {
        foreach ([[], ['id' => ''], ['id' => 12345], ['id' => null]] as $task) {
            $this->fakeAll(Http::response($this->envelope(20100, $task)));

            try {
                $this->provider()->submit($this->request());
                $this->fail('A task id is required.');
            } catch (SeoRankProviderException $e) {
                $this->assertSafeException($e, SeoRankProviderException::AMBIGUOUS, SeoRankProviderException::CODE_BAD_RESPONSE);
            }
        }
    }

    public function test_a_non_created_non_refusal_task_status_inside_a_200_is_ambiguous(): void
    {
        foreach ([20000, 50000, 50301, 30000] as $status) {
            $this->fakeAll(Http::response($this->envelope($status, ['id' => self::TASK_ID, 'status_message' => 'LEAKY-BODY-TEXT'])));

            try {
                $this->provider()->submit($this->request());
                $this->fail("Status {$status} is not a created task.");
            } catch (SeoRankProviderException $e) {
                $this->assertSafeException($e, SeoRankProviderException::AMBIGUOUS, SeoRankProviderException::CODE_BAD_RESPONSE);
            }
        }
    }

    public function test_vendor_refusals_inside_a_200_envelope_are_rejected_with_closed_codes(): void
    {
        $cases = [
            40100 => SeoRankProviderException::CODE_AUTH,
            40200 => SeoRankProviderException::CODE_PAYMENT,
            40202 => SeoRankProviderException::CODE_RATE_LIMIT,
            40501 => SeoRankProviderException::CODE_INVALID_REQUEST,
            40000 => SeoRankProviderException::CODE_INVALID_REQUEST,
        ];

        foreach ($cases as $status => $code) {
            $this->fakeAll(Http::response($this->envelope($status, ['status_message' => 'LEAKY-BODY-TEXT ' . self::PASSWORD])));

            try {
                $this->provider()->submit($this->request());
                $this->fail("Status {$status} must be a rejection.");
            } catch (SeoRankProviderException $e) {
                $this->assertSafeException($e, SeoRankProviderException::REJECTED, $code);
            }
        }
    }

    public function test_a_redirect_is_never_followed_and_is_not_accepted(): void
    {
        $this->fakeAll(Http::response('', 302, ['Location' => 'https://evil.example/collect']));

        try {
            $this->provider()->submit($this->request());
            $this->fail('A 302 is not a created task.');
        } catch (SeoRankProviderException $e) {
            // 3xx is neither a clean 200 nor a 4xx refusal: the task may exist.
            $this->assertSame(SeoRankProviderException::AMBIGUOUS, $e->outcome);
        }

        $this->assertCount(1, $this->sent());
    }

    // ---------------------------------------------------------------
    // fetch
    // ---------------------------------------------------------------

    public function test_an_organic_fetch_uses_task_get_regular_and_parses_organic_items(): void
    {
        $this->fakeAll(Http::response($this->envelope(20000, [
            'cost' => 0.006,
            'result' => [[
                'items' => [
                    ['type' => 'organic', 'rank_group' => 1, 'rank_absolute' => 2, 'domain' => 'competitor.com', 'url' => 'https://competitor.com/a'],
                    ['type' => 'paid', 'rank_group' => 1, 'domain' => 'ads.com', 'url' => 'https://ads.com/'],
                    ['type' => 'local_pack', 'rank_group' => 1, 'domain' => 'pack.com'],
                    ['type' => 'organic', 'rank_group' => 7, 'domain' => 'www.photoboothco.com', 'url' => 'https://www.photoboothco.com/rentals', 'phone' => '+1 555 0100'],
                    ['type' => 'organic', 'rank_group' => '9', 'domain' => 'stringrank.com'],
                    ['type' => 'organic', 'rank_group' => 0, 'domain' => 'zero.com'],
                    ['type' => 'organic', 'domain' => 'norank.com'],
                    'garbage',
                    ['type' => 'organic', 'rank_group' => 12, 'domain' => '   ', 'url' => ''],
                ],
            ]],
        ])));

        $result = $this->provider()->fetch('organic', self::TASK_ID);

        $this->assertSame('GET', $this->sent()[0]->method());
        $this->assertSame('https://api.dataforseo.com/v3/serp/google/organic/task_get/regular/' . self::TASK_ID, $this->sent()[0]->url());

        $this->assertSame(SeoRankTaskResult::COMPLETED, $result->state);
        $this->assertSame(6000, $result->costMicros);
        $this->assertNull($result->errorCode);
        $this->assertCount(3, $result->items);

        $this->assertSame(1, $result->items[0]->position);
        $this->assertSame('competitor.com', $result->items[0]->domain);
        $this->assertSame('https://competitor.com/a', $result->items[0]->url);
        $this->assertNull($result->items[0]->cid);

        $this->assertSame(7, $result->items[1]->position);
        $this->assertSame('www.photoboothco.com', $result->items[1]->domain);
        $this->assertSame('https://www.photoboothco.com/rentals', $result->items[1]->url);

        $this->assertSame(12, $result->items[2]->position);
        $this->assertNull($result->items[2]->domain);
        $this->assertNull($result->items[2]->url);
    }

    public function test_a_local_fetch_uses_task_get_advanced_and_parses_local_pack_items(): void
    {
        $this->fakeAll(Http::response($this->envelope(20000, [
            'cost' => 0.0006,
            'result' => [[
                'items' => [
                    ['type' => 'local_pack', 'rank_group' => 1, 'cid' => '1234567890123', 'phone' => '+1 312 555 0000', 'domain' => 'other.com', 'url' => 'https://other.com/'],
                    ['type' => 'organic', 'rank_group' => 1, 'domain' => 'organic-only.com'],
                    ['type' => 'local_pack', 'rank_group' => 3, 'cid' => '999', 'phone' => '(555) 010-1234', 'domain' => null],
                ],
            ]],
        ])));

        $result = $this->provider()->fetch('local', self::TASK_ID);

        $this->assertSame('https://api.dataforseo.com/v3/serp/google/local_finder/task_get/advanced/' . self::TASK_ID, $this->sent()[0]->url());
        $this->assertSame(SeoRankTaskResult::COMPLETED, $result->state);
        $this->assertSame(600, $result->costMicros);
        $this->assertCount(2, $result->items);

        $this->assertSame(1, $result->items[0]->position);
        $this->assertSame('1234567890123', $result->items[0]->cid);
        $this->assertSame('+1 312 555 0000', $result->items[0]->phone);
        $this->assertSame('other.com', $result->items[0]->domain);

        $this->assertSame(3, $result->items[1]->position);
        $this->assertSame('999', $result->items[1]->cid);
        $this->assertSame('(555) 010-1234', $result->items[1]->phone);
        $this->assertNull($result->items[1]->domain);
        $this->assertNull($result->items[1]->url);
    }

    public function test_status_40602_and_40601_are_pending_not_failures(): void
    {
        foreach ([40602, 40601] as $status) {
            $this->fakeAll(Http::response($this->envelope($status)));

            $result = $this->provider()->fetch('organic', self::TASK_ID);

            $this->assertSame(SeoRankTaskResult::PENDING, $result->state, "status {$status}");
            $this->assertSame([], $result->items);
            $this->assertNull($result->errorCode);
        }
    }

    public function test_status_40102_is_a_completed_empty_result_with_its_cost(): void
    {
        $this->fakeAll(Http::response($this->envelope(40102, ['cost' => 0.006])));

        $result = $this->provider()->fetch('organic', self::TASK_ID);

        $this->assertSame(SeoRankTaskResult::COMPLETED, $result->state);
        $this->assertSame([], $result->items);
        $this->assertSame(6000, $result->costMicros);
        $this->assertNull($result->errorCode);
    }

    public function test_a_200_task_with_no_result_block_is_a_completed_empty_serp(): void
    {
        $this->fakeAll(Http::response($this->envelope(20000, ['cost' => 0.0006, 'result' => null])));

        $result = $this->provider()->fetch('local', self::TASK_ID);

        $this->assertSame(SeoRankTaskResult::COMPLETED, $result->state);
        $this->assertSame([], $result->items);
        $this->assertSame(600, $result->costMicros);
    }

    public function test_a_failed_task_status_is_a_failed_result_with_a_closed_code(): void
    {
        $cases = [
            50000 => SeoRankProviderException::CODE_TASK_FAILED,
            40501 => SeoRankProviderException::CODE_TASK_FAILED,
            40100 => SeoRankProviderException::CODE_AUTH,
            40200 => SeoRankProviderException::CODE_PAYMENT,
            40202 => SeoRankProviderException::CODE_RATE_LIMIT,
        ];

        foreach ($cases as $status => $code) {
            $this->fakeAll(Http::response($this->envelope($status, ['status_message' => 'LEAKY-BODY-TEXT', 'cost' => 0])));

            $result = $this->provider()->fetch('organic', self::TASK_ID);

            $this->assertSame(SeoRankTaskResult::FAILED, $result->state, "status {$status}");
            $this->assertSame($code, $result->errorCode, "status {$status}");
            $this->assertSame([], $result->items);
            $this->assertSame(0, $result->costMicros);
        }
    }

    public function test_cost_is_parsed_to_integer_micro_usd_and_insane_values_are_ignored(): void
    {
        $cases = [
            [0.0006, 600],
            [0.006, 6000],
            [0.0012, 1200],
            [0.0005999, 600],
            [0, 0],
            [0.1, 100000],
            [1, 1000000],
            ['0.006', null],
            [-0.5, null],
            [100, null],
            [1e9, null],
            [null, null],
        ];

        foreach ($cases as [$usd, $expected]) {
            $task = ['result' => [['items' => []]]];
            if ($usd !== null) {
                $task['cost'] = $usd;
            }

            $this->fakeAll(Http::response($this->envelope(20000, $task)));

            $this->assertSame($expected, $this->provider()->fetch('organic', self::TASK_ID)->costMicros, 'cost ' . var_export($usd, true));
        }
    }

    public function test_http_failures_on_fetch_are_classified_like_submit(): void
    {
        foreach ([401 => ['rejected', 'provider_auth'], 402 => ['rejected', 'provider_payment'], 429 => ['rejected', 'provider_rate_limit'], 404 => ['rejected', 'provider_invalid_request'], 500 => ['ambiguous', 'provider_unavailable'], 503 => ['ambiguous', 'provider_unavailable']] as $http => [$outcome, $code]) {
            $this->fakeAll(Http::response('LEAKY-BODY-TEXT', $http));

            try {
                $this->provider()->fetch('organic', self::TASK_ID);
                $this->fail("HTTP {$http}");
            } catch (SeoRankProviderException $e) {
                $this->assertSafeException($e, $outcome, $code);
            }
        }

        $this->fakeAll(fn () => throw new ConnectionException('boom ' . self::PASSWORD));

        try {
            $this->provider()->fetch('local', self::TASK_ID);
            $this->fail('Expected ambiguity.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, 'ambiguous', 'provider_unavailable');
        }
    }

    public function test_a_malformed_task_id_never_reaches_the_network(): void
    {
        Http::fake();

        foreach (['', 'short', '../../etc/passwd', 'abc def ghi jkl', str_repeat('a', 65), 'abc/12345678', "abc12345\n678"] as $id) {
            try {
                $this->provider()->fetch('organic', $id);
                $this->fail('Malformed id must be refused: ' . var_export($id, true));
            } catch (SeoRankProviderException $e) {
                $this->assertSame(SeoRankProviderException::REJECTED, $e->outcome);
                $this->assertSame(SeoRankProviderException::CODE_INVALID_REQUEST, $e->errorCode);
            }
        }

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // readyTasksByTag
    // ---------------------------------------------------------------

    public function test_ready_tasks_by_tag_maps_our_tag_to_the_provider_task_id(): void
    {
        $this->fakeAll(Http::response($this->envelope(20000, ['result' => [
            ['id' => 'task-aaaaaaaa', 'tag' => 'run-1'],
            ['id' => 'task-bbbbbbbb', 'data' => ['tag' => 'run-2']],
            ['id' => 'task-cccccccc'],
            ['id' => 'task-dddddddd', 'tag' => ''],
            ['id' => 12345, 'tag' => 'run-5'],
            ['tag' => 'run-6'],
            'garbage',
        ]])));

        $ready = $this->provider()->readyTasksByTag('organic');

        $this->assertSame(['run-1' => 'task-aaaaaaaa', 'run-2' => 'task-bbbbbbbb'], $ready);
        $this->assertSame('GET', $this->sent()[0]->method());
        $this->assertSame('https://api.dataforseo.com/v3/serp/google/organic/tasks_ready', $this->sent()[0]->url());
    }

    public function test_ready_tasks_for_local_use_the_local_finder_endpoint_and_tolerate_an_empty_list(): void
    {
        $this->fakeAll(Http::response($this->envelope(20000, ['result' => null])));

        $this->assertSame([], $this->provider()->readyTasksByTag('local'));
        $this->assertSame('https://api.dataforseo.com/v3/serp/google/local_finder/tasks_ready', $this->sent()[0]->url());
    }

    public function test_ready_tasks_failures_are_classified(): void
    {
        $this->fakeAll(Http::response('LEAKY-BODY-TEXT', 500));

        try {
            $this->provider()->readyTasksByTag('organic');
            $this->fail('Expected ambiguity.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, 'ambiguous', 'provider_unavailable');
        }

        $this->fakeAll(fn () => throw new ConnectionException('x'));

        try {
            $this->provider()->readyTasksByTag('local');
            $this->fail('Expected ambiguity.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, 'ambiguous', 'provider_unavailable');
        }
    }

    // ---------------------------------------------------------------
    // locations
    // ---------------------------------------------------------------

    public function test_locations_are_parsed_from_the_free_catalogue_endpoint(): void
    {
        $this->fakeAll(Http::response($this->envelope(20000, ['result' => [
            ['location_code' => 1016367, 'location_name' => 'Chicago,Illinois,United States', 'location_code_parent' => 21144, 'country_iso_code' => 'US', 'location_type' => 'City'],
            ['location_code' => 21144, 'location_name' => 'Illinois,United States', 'country_iso_code' => 'US', 'location_type' => 'State'],
            ['location_code' => 2840, 'location_name' => 'United States', 'location_code_parent' => 'x', 'location_type' => 'Country'],
            ['location_code' => '123', 'location_name' => 'String Code,US', 'location_type' => 'City'],
            ['location_code' => 5, 'location_name' => 123, 'location_type' => 'City'],
            ['location_name' => 'No Code', 'location_type' => 'City'],
            'garbage',
            ['location_code' => 7, 'location_name' => str_repeat('n', 400), 'country_iso_code' => 'us', 'location_type' => str_repeat('T', 60)],
        ]])));

        $locations = $this->provider()->locations('US');

        $this->assertSame('GET', $this->sent()[0]->method());
        $this->assertSame('https://api.dataforseo.com/v3/serp/google/locations/us', $this->sent()[0]->url());

        $this->assertCount(4, $locations);
        $this->assertSame([
            'code' => 1016367,
            'name' => 'Chicago,Illinois,United States',
            'parent_code' => 21144,
            'country_iso' => 'US',
            'type' => 'City',
        ], $locations[0]);
        $this->assertNull($locations[1]['parent_code']);
        $this->assertSame('State', $locations[1]['type']);
        $this->assertNull($locations[2]['parent_code'], 'A non-integer parent is ignored.');
        $this->assertSame('US', $locations[2]['country_iso'], 'Falls back to the requested country, upper-cased.');
        $this->assertSame(255, mb_strlen($locations[3]['name']));
        $this->assertSame(32, mb_strlen($locations[3]['type']));
        $this->assertSame('US', $locations[3]['country_iso']);
    }

    public function test_a_malformed_country_code_never_reaches_the_network(): void
    {
        Http::fake();

        foreach (['USA', 'u', '', '1', '../', 'us/../x', 'U S'] as $iso) {
            try {
                $this->provider()->locations($iso);
                $this->fail('Malformed country must be refused: ' . var_export($iso, true));
            } catch (SeoRankProviderException $e) {
                $this->assertSame(SeoRankProviderException::REJECTED, $e->outcome);
                $this->assertSame(SeoRankProviderException::CODE_INVALID_REQUEST, $e->errorCode);
            }
        }

        Http::assertNothingSent();
    }

    public function test_locations_failures_are_classified(): void
    {
        $this->fakeAll(Http::response('LEAKY-BODY-TEXT', 429));

        try {
            $this->provider()->locations('US');
            $this->fail('Expected a rejection.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, 'rejected', 'provider_rate_limit');
        }

        $this->fakeAll(fn () => throw new ConnectionException('x'));

        try {
            $this->provider()->locations('US');
            $this->fail('Expected ambiguity.');
        } catch (SeoRankProviderException $e) {
            $this->assertSafeException($e, 'ambiguous', 'provider_unavailable');
        }
    }

    public function test_the_provider_key_is_dataforseo(): void
    {
        $this->assertSame('dataforseo', $this->provider()->key());
        $this->assertSame('dataforseo', DataForSeoRankProvider::KEY);
    }
}
