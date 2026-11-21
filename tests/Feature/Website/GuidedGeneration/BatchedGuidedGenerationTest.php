<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Website\GuidedGeneration\GenerationBatchPlanner;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\GuidedGeneration\GuidedGenerationOutputValidator;
use App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsitePageStrategy;
use App\Models\BusinessService;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * Slice 1 of the SEO-first generator: bounded, sequential, multi-batch guided generation. The complete deterministic plan is
 * written in small provider requests (GenerationBatchPlanner), each batch validated against its own plan, the accepted pages
 * re-validated together, and the assembled site validated against the complete plan before one atomic commit.
 *
 * Every provider call is a fake (a Mockery double of WebsiteAiGenerationClient that answers from the request's own plan) —
 * no real AI call is ever attempted.
 */
class BatchedGuidedGenerationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;

    /** @var array<int, array{keys: array<int, string>, max: ?int, idem: ?string, payload: array, system: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
        // These tests are about batching mechanics with minimal fake pages; content quality is covered in SeoContentQualityTest.
        config(['ai.website_content_quality' => false]);
    }

    // ------------------------------------------------------------------ fixtures

    /** 12 described booths + 4 described event types: a plan well past the old 14-page cap. */
    private function largeBusiness(): array
    {
        [$customer, $business] = $this->entitledTenant();

        foreach (range(1, 12) as $n) {
            BusinessService::create(['business_id' => $business->id, 'name' => "Booth Style {$n}", 'slug' => "booth-style-{$n}", 'description' => "What Booth Style {$n} is and what it includes for your guests.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $n]);
        }

        foreach (['Weddings', 'Corporate Events', 'Birthday Parties', 'Graduations'] as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => Str::slug($name), 'kind' => BusinessService::KIND_EVENT, 'description' => "How we run {$name} with an attendant, props and instant sharing for every guest.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 100 + $i]);
        }

        $website = $this->createWebsite($business);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');

        return [$customer, $business, $website, $template];
    }

    private function areas(int $count): array
    {
        return array_map(fn ($n) => ['name' => "Town {$n}, WA", 'details' => ['logistics' => "Town {$n} is a {$n}0 minute drive; we load in two hours early."]], range(1, $count));
    }

    private function plan($business, $template, $website, ?array $areas = null): array
    {
        return WebsitePageStrategy::withoutAiUnfillableSections(app(WebsitePageStrategy::class)->buildPlan($business, $template, $website, null, null, $areas));
    }

    /**
     * Binds a fake provider. $respond(array $payload, int $call): ?string returns the raw response text for that provider call
     * (null = the provider produced nothing). Every call is recorded in $this->calls.
     */
    private function rawProvider(callable $respond, bool $budgetExhausted = false): \Mockery\MockInterface
    {
        $this->calls = [];

        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn($budgetExhausted);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function (array $messages, $business, $actor = null, $max = null, $idem = null) use ($respond) {
            $payload = json_decode(collect($messages)->firstWhere('role', 'user')['content'], true);
            $this->calls[] = ['keys' => array_column($payload['plan'], 'page_key'), 'max' => $max, 'idem' => $idem, 'payload' => $payload, 'system' => (string) collect($messages)->firstWhere('role', 'system')['content']];

            return $respond($payload, count($this->calls));
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        return $mock;
    }

    /**
     * A fake provider that answers each request from the plan IN that request. $page(array $planEntry, int $call): ?array may
     * replace one page; $fail(int $call, array $keys): bool returns true to make that provider call produce no content.
     */
    private function provider(?callable $page = null, ?callable $fail = null, bool $budgetExhausted = false): \Mockery\MockInterface
    {
        return $this->rawProvider(function (array $payload, int $call) use ($page, $fail) {
            if ($fail !== null && $fail($call, array_column($payload['plan'], 'page_key'))) {
                return null;
            }

            return json_encode(['pages' => array_map(fn (array $entry) => ($page !== null ? $page($entry, $call) : null) ?? $this->defaultPage($entry), $payload['plan'])]);
        }, $budgetExhausted);
    }

    private function defaultPage(array $entry, array $override = []): array
    {
        $sections = [['type' => 'hero', 'data' => ['heading' => $entry['page_key'], 'subheading' => null, 'primary_cta' => null, 'secondary_cta' => null]]];

        if (isset($entry['entity']['area'])) {
            $sections[] = ['type' => 'text', 'data' => ['heading' => 'About this area', 'body' => 'Photo booths for ' . $entry['entity']['area'] . ' — ' . hash('sha256', $entry['page_key']) . hash('sha256', $entry['page_key'] . 'b')]];
        }

        return $override + [
            'page_key' => $entry['page_key'],
            'title' => $entry['page_key'] . ' page',
            'seo_title' => 'SEO ' . $entry['page_key'],
            'meta_description' => 'Meta ' . $entry['page_key'],
            'sections' => $sections,
        ];
    }

    private function generate($customer, $business, $website, $template, string $key = 'idem-batch', ?array $areas = null, ?string $fence = null): WebsiteGuidedGenerationAttempt
    {
        return app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, $key, $fence, null, null, null, $areas);
    }

    /** The batches the service will use for this plan: the very same size-aware preflight it runs. */
    private function expectedBatches($business, array $plan): array
    {
        $fit = GenerationBatchPlanner::plan($business, $plan, app(GuidedWebsiteGenerationClient::class));
        $this->assertNull($fit['refusal'], (string) $fit['refusal']);

        return $fit['batches'];
    }

    private function requestedKeys(): array
    {
        return array_merge(...array_column($this->calls, 'keys'));
    }

    // ------------------------------------------------------------------ the planner

    public function test_the_plan_is_no_longer_capped_at_fourteen_pages_and_stays_within_the_operational_budget(): void
    {
        [, $business, $website, $template] = $this->largeBusiness();

        $result = app(WebsitePageStrategy::class)->planWithExplanation($business, $template, $website);

        $this->assertGreaterThan(14, count($result['plan']), 'A real site with many distinct services and events is planned in full.');
        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_PAGES_PER_GENERATION, count($result['plan']));
        $this->assertSame(WebsitePageStrategy::MAX_PAGES_PER_GENERATION, $result['budget']['max']);
    }

    public function test_every_candidate_page_left_out_by_an_operational_limit_is_reported_with_a_reason(): void
    {
        [, $business, $website, $template] = $this->largeBusiness();

        $result = app(WebsitePageStrategy::class)->planWithExplanation($business, $template, $website, null, null, null, null, null, null, 8);

        $this->assertLessThanOrEqual(8, count($result['plan']));
        $left = collect($result['decisions'])->where('included', false);
        $this->assertGreaterThan(0, $left->count());
        foreach ($left as $decision) {
            $this->assertNotSame('', trim((string) $decision['reason']), "{$decision['key']} is left out with a reason.");
        }
        $planned = array_column($result['plan'], 'page_key');
        foreach (BusinessService::where('business_id', $business->id)->get() as $service) {
            $key = ($service->kind === BusinessService::KIND_EVENT ? 'event:' : 'service:') . $service->uid;
            $this->assertTrue(in_array($key, $planned, true) || $left->has($key) || $left->pluck('key')->contains($key), "{$service->name} is planned or explained, never silently dropped.");
        }
    }

    public function test_batches_preserve_plan_order_lose_no_page_and_respect_the_size_limit(): void
    {
        $plan = array_map(fn ($i) => ['page_key' => "p{$i}"], range(1, 23));

        $batches = GenerationBatchPlanner::batches($plan);

        $this->assertSame(array_column($plan, 'page_key'), array_merge(...array_map(fn ($b) => array_column($b, 'page_key'), $batches)));
        foreach ($batches as $batch) {
            $this->assertLessThanOrEqual(GenerationBatchPlanner::MAX_PAGES_PER_BATCH, count($batch));
        }
        $this->assertSame((int) ceil(23 / GenerationBatchPlanner::MAX_PAGES_PER_BATCH), count($batches));
    }

    public function test_a_plan_over_the_operational_budget_is_refused_explicitly_never_trimmed(): void
    {
        $over = array_map(fn ($i) => ['page_key' => "p{$i}"], range(1, WebsitePageStrategy::MAX_PAGES_PER_GENERATION + 1));

        $this->assertNotNull(GenerationBatchPlanner::refusal($over, WebsitePageStrategy::MAX_PAGES_PER_GENERATION));
        $this->assertNull(GenerationBatchPlanner::refusal(array_slice($over, 0, WebsitePageStrategy::MAX_PAGES_PER_GENERATION), WebsitePageStrategy::MAX_PAGES_PER_GENERATION));
        $this->assertGreaterThanOrEqual(
            count(GenerationBatchPlanner::batches(array_slice($over, 0, WebsitePageStrategy::MAX_PAGES_PER_GENERATION))),
            GenerationBatchPlanner::MAX_AI_REQUESTS_PER_GENERATION,
            'A maximal plan always fits the request budget with room for retries.'
        );
    }

    // ------------------------------------------------------------------ multi-batch generation

    public function test_a_plan_beyond_fourteen_pages_is_generated_in_bounded_sequential_batches_and_committed_whole(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $plan = $this->plan($business, $template, $website);
        $this->assertGreaterThan(14, count($plan));
        $this->provider();

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertSame(0, $attempt->retry_count);
        $this->assertSame(count($plan), $website->pages()->count(), 'Every planned page is committed — none is dropped for the old single-request budget.');

        $this->assertCount(count($this->expectedBatches($business, $plan)), $this->calls);
        foreach ($this->calls as $call) {
            $this->assertLessThanOrEqual(GenerationBatchPlanner::MAX_PAGES_PER_BATCH, count($call['keys']));
            $this->assertSame(GuidedWebsiteGenerationClient::MAX_OUTPUT_TOKENS, $call['max'], 'The provider token budget is unchanged for every request.');
        }
        $this->assertSame(array_column($plan, 'page_key'), $this->requestedKeys(), 'Every plan page is requested exactly once, in plan order.');
    }

    public function test_each_request_carries_the_whole_sites_link_targets_so_a_page_can_link_to_another_batch(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $plan = $this->plan($business, $template, $website);
        $last = end($plan);
        $lastPath = '/' . $last['slug'];
        // The home page (first batch) links to the contact page, which is written in a LATER batch.
        $contact = collect($plan)->firstWhere('page_type', 'contact');
        $this->provider(function (array $entry) use ($contact) {
            if ($entry['page_key'] !== 'home') {
                return null;
            }

            return $this->defaultPage($entry, ['sections' => [['type' => 'hero', 'data' => ['heading' => 'Home', 'subheading' => null, 'primary_cta' => ['label' => 'Book', 'url' => '/' . $contact['slug']], 'secondary_cta' => null]]]]);
        });

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $firstBatchKeys = $this->calls[0]['keys'];
        $this->assertNotContains($contact['page_key'], $firstBatchKeys, 'Precondition: the link target is in a different batch than the page that links to it.');

        foreach ($this->calls as $call) {
            $paths = array_column($call['payload']['site_pages'], 'path');
            $this->assertContains($lastPath, $paths, 'Every request lists the complete site\'s paths.');
            $this->assertContains('/' . $contact['slug'], $paths);
            $this->assertCount(count($plan), $call['payload']['site_pages']);
            $this->assertStringContainsString('/' . $contact['slug'], $call['system'], 'The link instruction names every real path of the whole site.');
        }

        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $this->assertStringContainsString('"url":"/' . $contact['slug'] . '"', json_encode($home->sections, JSON_UNESCAPED_SLASHES), 'The cross-batch link survives validation and commit unchanged.');
    }

    public function test_a_link_to_a_path_outside_the_complete_plan_is_still_rejected_in_a_batch(): void
    {
        [, $business, $website, $template] = $this->largeBusiness();
        $plan = $this->plan($business, $template, $website);
        $batch = $this->expectedBatches($business, $plan)[0];
        $contact = collect($plan)->firstWhere('page_type', 'contact');
        $pages = array_map(fn ($entry) => $this->defaultPage($entry), $batch);
        $validator = app(GuidedGenerationOutputValidator::class);

        // A link to another batch's page is valid when the complete plan is the link universe ...
        $pages[0]['sections'][0]['data']['primary_cta'] = ['label' => 'Contact', 'url' => '/' . $contact['slug']];
        $validator->validate($pages, $batch, [], $plan);

        // ... an invented path never is.
        $pages[0]['sections'][0]['data']['primary_cta'] = ['label' => 'Nope', 'url' => '/invented-page'];
        $this->expectException(ValidationException::class);
        $validator->validate($pages, $batch, [], $plan);
    }

    public function test_a_batch_is_validated_against_its_own_plan_so_a_page_missing_from_it_is_rejected(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $this->homePage($website, ['title' => 'Existing Draft Home']);
        // Batch 1 is complete; every response for batch 2 (first try and the one retry) omits its first page.
        $this->rawProvider(function (array $payload, int $call) {
            $entries = $call >= 2 ? array_slice($payload['plan'], 1) : $payload['plan'];

            return json_encode(['pages' => array_map(fn ($entry) => $this->defaultPage($entry), $entries)]);
        });

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(1, $attempt->retry_count);
        $this->assertCount(3, $this->calls);
        $this->assertSame(['Existing Draft Home'], $website->pages()->pluck('title')->all());
    }

    // ------------------------------------------------------------------ cross-batch validation

    public function test_duplicate_metadata_across_separate_batches_fails_the_generation_and_stops_spending(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $this->homePage($website, ['title' => 'Existing Draft Home']);
        $before = $website->pages()->pluck('title', 'id')->all();
        // The first page of batch 2 repeats the SEO title of the home page written in batch 1 — on every try.
        $this->rawProvider(function (array $payload, int $call) {
            $pages = [];
            foreach ($payload['plan'] as $i => $entry) {
                $page = $this->defaultPage($entry);
                if ($entry['page_key'] === 'home' || ($call > 1 && $i === 0)) {
                    $page['seo_title'] = 'Shared SEO title';
                }
                $pages[] = $page;
            }

            return json_encode(['pages' => $pages]);
        });

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(1, $attempt->retry_count);
        // Batch 1 (1 request) + batch 2 (first try + one retry): batch 3 and later are never requested.
        $this->assertCount(3, $this->calls);
        $this->assertSame($before, $website->pages()->pluck('title', 'id')->all(), 'The existing draft is untouched.');
    }

    public function test_a_corrective_retry_can_repair_a_cross_batch_duplicate_and_the_site_then_commits(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $plan = $this->plan($business, $template, $website);
        $secondBatchFirstKey = $this->expectedBatches($business, $plan)[1][0]['page_key'];
        $this->provider(function (array $entry, int $call) use ($secondBatchFirstKey) {
            if ($entry['page_key'] === 'home') {
                return $this->defaultPage($entry, ['seo_title' => 'Shared SEO title']);
            }

            // Duplicates the home page's SEO title only on the FIRST try of batch 2 (call 2).
            return $entry['page_key'] === $secondBatchFirstKey && $call === 2 ? $this->defaultPage($entry, ['seo_title' => 'Shared SEO title']) : null;
        });

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertSame(1, $attempt->retry_count);
        $this->assertSame(count($plan), $website->pages()->count());
        $this->assertCount(count($this->expectedBatches($business, $plan)) + 1, $this->calls, 'Exactly one extra request for the one retry.');
    }

    public function test_near_identical_area_copy_in_different_batches_is_rejected(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $areas = $this->areas(WebsitePageStrategy::MAX_AREA_PAGES);
        $plan = $this->plan($business, $template, $website, $areas);
        $areaKeys = array_values(array_filter(array_column($plan, 'page_key'), fn ($k) => str_starts_with($k, 'area:')));
        $this->assertGreaterThanOrEqual(6, count($areaKeys));
        $batchNumber = [];
        foreach ($this->expectedBatches($business, $plan) as $n => $batch) {
            foreach ($batch as $entry) {
                $batchNumber[$entry['page_key']] = $n;
            }
        }
        $batchOf = fn (string $key) => $batchNumber[$key];
        $first = $areaKeys[0];
        $last = end($areaKeys);
        $this->assertNotSame($batchOf($first), $batchOf($last), 'Precondition: the two copied area pages are written in different batches.');

        // Same sentence for those two pages (only the place name differs); every other area page has its own copy.
        $this->provider(function (array $entry) use ($first, $last) {
            if (! in_array($entry['page_key'], [$first, $last], true)) {
                return null;
            }
            $area = $entry['entity']['area'];

            return $this->defaultPage($entry, [
                'sections' => [
                    ['type' => 'hero', 'data' => ['heading' => "Photo booths in {$area}", 'subheading' => null, 'primary_cta' => null, 'secondary_cta' => null]],
                    ['type' => 'text', 'data' => ['heading' => 'About this area', 'body' => "We bring our photo booths to {$area} for weddings, birthdays and corporate events with unlimited prints and props."]],
                ],
            ]);
        });

        $attempt = $this->generate($customer, $business, $website, $template, 'idem-area', $areas);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(0, $website->pages()->where('slug', 'like', 'serving-%')->count(), 'No area page was committed.');
    }

    public function test_distinct_area_copy_across_batches_commits_every_area_page(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $areas = $this->areas(WebsitePageStrategy::MAX_AREA_PAGES);
        $plan = $this->plan($business, $template, $website, $areas);
        $this->provider();

        $attempt = $this->generate($customer, $business, $website, $template, 'idem-area-ok', $areas);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertSame(count($plan), $website->pages()->count());
        $this->assertSame(WebsitePageStrategy::MAX_AREA_PAGES, $website->pages()->where('slug', 'like', 'serving-%')->count());
    }

    // ------------------------------------------------------------------ failure safety and bounded spend

    public function test_a_failure_in_a_later_batch_leaves_the_existing_draft_and_published_revision_untouched(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $this->homePage($website, ['title' => 'Existing Draft Home']);
        $before = $website->fresh()->pages()->get(['id', 'title', 'slug', 'sections'])->toArray();
        $publishedBefore = $website->fresh()->published_revision_id;
        $revisionsBefore = \App\Models\WebsiteRevision::where('website_id', $website->id)->count();

        // Batches 1 and 2 are fine; the provider returns nothing for batch 3 (call 3) and for its retry (call 4).
        $this->provider(null, fn (int $call) => $call >= 3);

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertCount(4, $this->calls, 'Two good batches, then batch 3 once and its single retry — nothing more.');
        $this->assertSame($before, $website->fresh()->pages()->get(['id', 'title', 'slug', 'sections'])->toArray(), 'Not one page was added, changed or removed.');
        $this->assertSame($publishedBefore, $website->fresh()->published_revision_id);
        $this->assertSame($revisionsBefore, \App\Models\WebsiteRevision::where('website_id', $website->id)->count());
    }

    public function test_every_invalid_response_is_bounded_to_one_retry_for_the_batch_it_belongs_to(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $this->provider(fn (array $entry) => $this->defaultPage($entry, ['sections' => []]));

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(1, $attempt->retry_count);
        $this->assertCount(1 + GenerationBatchPlanner::MAX_RETRIES_PER_BATCH, $this->calls, 'The first batch fails twice and generation stops: later batches are never requested.');
        $this->assertLessThanOrEqual(GenerationBatchPlanner::MAX_AI_REQUESTS_PER_GENERATION, count($this->calls));
    }

    public function test_an_exhausted_ai_budget_stops_immediately_without_a_retry(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $this->provider(null, fn () => true, budgetExhausted: true);

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertCount(1, $this->calls, 'A retry cannot help when the allowance is used up, and must not spend.');
        $this->assertStringContainsString('budget is used up', (string) $attempt->failure_reason);
    }

    public function test_a_page_the_provider_echoes_from_another_batch_is_ignored_but_an_invented_one_fails(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $plan = $this->plan($business, $template, $website);
        $all = array_map(fn ($e) => $this->defaultPage($e), $plan);
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        // Always answers with the WHOLE plan, whatever batch was asked for.
        $mock->shouldReceive('complete')->andReturn(json_encode(['pages' => $all]));
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertSame(count($plan), $website->pages()->count(), 'Each page is committed once, from the batch it belongs to.');

        [$customer2, $business2, $website2, $template2] = $this->largeBusiness();
        $mock2 = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock2->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock2->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock2->shouldReceive('complete')->andReturn(json_encode(['pages' => array_merge($all, [['page_key' => 'invented:page', 'title' => 'X', 'seo_title' => null, 'meta_description' => null, 'sections' => []]])]));
        $this->app->instance(WebsiteAiGenerationClient::class, $mock2);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $this->generate($customer2, $business2, $website2, $template2)->status);
    }

    // ------------------------------------------------------------------ input-size aware batching

    public function test_every_batch_prompt_fits_the_input_limit_with_headroom_and_no_page_is_lost(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $plan = $this->plan($business, $template, $website);
        $this->provider();

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertSame(count($plan), $website->pages()->count());
        $router = app(\App\Library\Ai\AiModelRouter::class);
        $this->assertSame(11_400, GenerationBatchPlanner::inputTokenLimit());
        foreach ($this->calls as $i => $call) {
            $messages = [['role' => 'system', 'content' => $call['system']], ['role' => 'user', 'content' => json_encode($call['payload'])]];
            // Re-encoding the decoded payload is not byte-identical to what was sent, so allow no more than the 5% headroom itself.
            $this->assertLessThanOrEqual(12_000, $router->estimateInputTokens($messages), "Request {$i} stays under the route's input ceiling.");
            $this->assertSame(8_000, $call['max']);
        }
    }

    public function test_a_tighter_input_limit_shrinks_batches_without_dropping_or_reordering_pages(): void
    {
        $plan = array_map(fn ($i) => ['page_key' => "p{$i}"], range(1, 23));
        // Fixed overhead 1,000 + 700 per page + 30 per already-written page (the used-titles list grows).
        $estimate = fn (array $batch, int $prior) => 1_000 + 700 * count($batch) + 30 * min($prior, 30);

        $roomy = GenerationBatchPlanner::fit($plan, $estimate, 11_400, 100);
        $tight = GenerationBatchPlanner::fit($plan, $estimate, 3_800, 100);

        $this->assertNull($roomy['refusal']);
        $this->assertNull($tight['refusal']);
        $this->assertCount(GenerationBatchPlanner::MAX_PAGES_PER_BATCH, $roomy['batches'][0], 'Roomy limit: capped by the per-batch page limit only.');
        $this->assertLessThan(GenerationBatchPlanner::MAX_PAGES_PER_BATCH, count($tight['batches'][0]), 'A tighter limit shrinks the batch.');
        $this->assertGreaterThan(count($roomy['batches']), count($tight['batches']));
        foreach ([$roomy, $tight] as $result) {
            $this->assertSame(array_column($plan, 'page_key'), array_merge(...array_map(fn ($b) => array_column($b, 'page_key'), $result['batches'])), 'Stable order, nothing lost, nothing repeated.');
        }
        $limit = 3_800;
        $written = 0;
        foreach ($tight['batches'] as $batch) {
            $this->assertLessThanOrEqual($limit, $estimate($batch, $written));
            $written += count($batch);
        }
        $this->assertSame($tight, GenerationBatchPlanner::fit($plan, $estimate, 3_800, 100), 'Deterministic: identical input, identical batches (so identical idempotency keys).');
    }

    public function test_the_planned_batches_for_a_real_business_fit_the_limit_with_worst_case_titles(): void
    {
        [, $business, $website, $template] = $this->largeBusiness();
        $plan = $this->plan($business, $template, $website);
        $client = app(GuidedWebsiteGenerationClient::class);
        $facts = $client->canonicalFacts($business);

        $written = 0;
        foreach ($this->expectedBatches($business, $plan) as $batch) {
            $this->assertLessThanOrEqual(GenerationBatchPlanner::inputTokenLimit(), $client->estimateInputTokens($business, $batch, $plan, $written, $facts));
            $written += count($batch);
        }
        $this->assertSame(count($plan), $written);
    }

    public function test_a_plan_whose_prompt_cannot_fit_is_refused_before_any_ai_request(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $this->homePage($website, ['title' => 'Existing Draft Home']);
        $this->provider();
        // A ceiling so small that even one page plus the confirmed facts cannot fit.
        config(['ai.routes.website_generation.max_input_tokens' => 300]);

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertCount(0, $this->calls, 'Refused before any provider request: nothing spent.');
        $this->assertStringContainsString('cannot be written within the AI input limit', (string) $attempt->failure_reason);
        $this->assertSame(['Existing Draft Home'], $website->pages()->pluck('title')->all());
    }

    public function test_a_plan_needing_more_batches_than_the_request_budget_is_refused_before_any_ai_request(): void
    {
        $plan = array_map(fn ($i) => ['page_key' => "p{$i}"], range(1, 30));

        // Every page alone fits, but only one page per batch: 30 batches > the 20-request budget.
        $fit = GenerationBatchPlanner::fit($plan, fn (array $batch) => 100 * count($batch), 100);

        $this->assertNull($fit['batches']);
        $this->assertStringContainsString('request limit', (string) $fit['refusal']);
        $this->assertStringContainsString('Nothing was changed', (string) $fit['refusal']);
    }

    // ------------------------------------------------------------------ idempotency, leases, isolation

    public function test_a_repeated_submission_converges_on_the_same_attempt_and_makes_no_further_ai_request(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $this->provider();

        $first = $this->generate($customer, $business, $website, $template, 'idem-same');
        $requests = count($this->calls);
        $second = $this->generate($customer, $business, $website->fresh(), $template, 'idem-same');

        $this->assertSame($first->id, $second->id);
        $this->assertCount($requests, $this->calls, 'The repeat spent nothing.');
    }

    public function test_every_request_has_its_own_stable_idempotency_key(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $this->provider();

        $this->generate($customer, $business, $website, $template, 'idem-keys');

        $keys = array_column($this->calls, 'idem');
        $this->assertSame($keys, array_values(array_unique($keys)), 'No two provider calls share a key.');
        $this->assertStringContainsString(':b1:retry0', $keys[0]);
        $this->assertStringContainsString(':b' . count($keys) . ':retry0', end($keys));
        $this->assertLessThanOrEqual(100, max(array_map('strlen', $keys)));
    }

    public function test_a_small_single_batch_plan_keeps_one_request_and_its_historical_key(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $plan = $this->plan($business, $template, $website);
        $this->assertLessThanOrEqual(GenerationBatchPlanner::MAX_PAGES_PER_BATCH, count($plan));
        $this->assertCount(1, $this->expectedBatches($business, $plan), 'Precondition: a small plan fits one request.');
        $this->provider();

        $attempt = $this->generate($customer, $business, $website, $template, 'idem-small');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertCount(1, $this->calls);
        $this->assertStringEndsWith(':retry0', $this->calls[0]['idem']);
        $this->assertStringNotContainsString(':b1', $this->calls[0]['idem']);
        $this->assertArrayNotHasKey('site_pages', $this->calls[0]['payload'], 'The single-request prompt is unchanged.');
    }

    public function test_a_caller_that_no_longer_owns_the_generation_lease_never_reaches_the_provider(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $this->homePage($website, ['title' => 'Existing Draft Home']);
        $this->provider();

        $attempt = $this->generate($customer, $business, $website, $template, 'idem-fenced', null, 'a-lease-token-this-website-does-not-hold');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertCount(0, $this->calls, 'A fenced-out caller spends nothing.');
        $this->assertSame(['Existing Draft Home'], $website->pages()->pluck('title')->all());
    }

    public function test_another_tenants_facts_never_reach_any_batch_request(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        [, $other] = $this->entitledTenant();
        BusinessService::create(['business_id' => $other->id, 'name' => 'OTHER-TENANT-SECRET-BOOTH', 'slug' => 'other-secret', 'description' => 'Belongs to a different business.', 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 1]);
        $this->provider();

        $this->generate($customer, $business, $website, $template);

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            $this->assertStringNotContainsString('OTHER-TENANT-SECRET-BOOTH', json_encode($call['payload']) . $call['system']);
        }
        $this->assertSame(0, \App\Models\WebsitePage::where('website_id', $website->id)->where('title', 'like', '%OTHER-TENANT%')->count());
    }

    // ------------------------------------------------------------------ recovery from malformed batch output (production attempts 6 and 7)

    /** A section type the entry's page does not allow, whatever the page type is. */
    private function disallowedType(array $entry): string
    {
        return collect(['testimonials', 'gallery', 'backdrops', 'packages', 'form', 'faq', 'cta', 'custom_section'])
            ->first(fn (string $type) => ! in_array($type, $entry['allowed_section_types'], true)) ?? $this->fail('every probe type is allowed on ' . $entry['page_key']);
    }

    public function test_a_disallowed_section_and_an_overlong_seo_title_are_repaired_in_place_with_no_extra_ai_call(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $long = 'Photo Booth Rental and Event Services for Weddings, Corporate Parties and Birthdays Across the Region';
        $this->assertGreaterThan(70, mb_strlen($long));

        $this->provider(function (array $entry, int $call) use ($long) {
            $page = $this->defaultPage($entry);
            $first = $entry['page_key'] === $this->calls[$call - 1]['keys'][0];
            if ($first) {
                // pages.0.sections.N.type: a type this page does not allow.
                $page['sections'][] = ['type' => $this->disallowedType($entry), 'data' => ['heading' => 'Not allowed here']];
            }
            if ($entry['page_key'] === ($this->calls[$call - 1]['keys'][3] ?? null)) {
                $page['seo_title'] = ($call === 1 ? $long : 'Batch ' . $call . ' Event Photo Booth Hire and Guest Experience Packages for Every Celebration') . ' - Harbor';   // pages.3.seo_title: over 70 characters, distinct per batch
            }

            return $page;
        });

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertSame(0, $attempt->retry_count, 'repaired, not retried');
        $planned = $this->plan($business, $template, $website);
        $this->assertCount(count($this->expectedBatches($business, $planned)), $this->calls, 'one request per batch and nothing more');

        foreach ($website->fresh()->pages as $page) {
            $allowed = collect($planned)->firstWhere('slug', $page->slug ?? '') ['allowed_section_types'] ?? null;
            foreach ($page->sections as $section) {
                $this->assertNotSame('Not allowed here', $section['data']['heading'] ?? null, 'the disallowed section never reached a page');
            }
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page->seo_title));
        }
        $this->assertTrue($website->fresh()->pages->contains(fn ($page) => str_starts_with((string) $page->seo_title, 'Photo Booth Rental and Event Services for Weddings')), 'the long title was shortened at a word, not rewritten');
    }

    public function test_a_corrective_retry_tells_the_model_why_and_a_valid_answer_then_completes_generation(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();

        // Try 1 of batch 1: two pages share one seo_title (pages.N.seo_title). The retry is valid.
        $this->rawProvider(function (array $payload, int $call) {
            $pages = array_map(fn ($entry) => $this->defaultPage($entry), $payload['plan']);
            if ($call === 1) {
                $pages[3]['seo_title'] = $pages[1]['seo_title'];
            }

            return json_encode(['pages' => $pages]);
        });

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertSame(1, $attempt->retry_count);
        $this->assertStringNotContainsString('PREVIOUS ANSWER FOR THIS REQUEST WAS REJECTED', $this->calls[0]['system'], 'the first request is unchanged');
        $this->assertStringContainsString('PREVIOUS ANSWER FOR THIS REQUEST WAS REJECTED', $this->calls[1]['system']);
        $this->assertStringContainsString('duplicates another page', $this->calls[1]['system'], 'the retry says what was wrong');
        $this->assertSame($this->calls[0]['keys'], $this->calls[1]['keys'], 'the same batch is retried, with the same plan');
        $this->assertSame($this->calls[0]['payload']['plan'], $this->calls[1]['payload']['plan'], 'the plan is identical across the retry');
        $this->assertCount(count($this->expectedBatches($business, $this->plan($business, $template, $website))) + 1, $this->calls, 'exactly one extra request, for the one retry');
        $this->assertSame(count($this->plan($business, $template, $website)), $website->fresh()->pages()->count(), 'every planned page was written');
    }

    public function test_the_exact_production_failure_a_bad_first_answer_then_one_page_of_five_commits_nothing_and_keeps_the_published_site(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();
        $this->homePage($website, ['title' => 'Published Home']);
        $revision = app(\App\Library\Website\WebsitePublisher::class)->publish($website->fresh(), $customer->user_id);
        $draftBefore = $website->fresh()->pages()->get(['id', 'title', 'slug', 'sections'])->toArray();

        $this->rawProvider(function (array $payload, int $call) {
            $pages = array_map(fn ($entry) => $this->defaultPage($entry), $payload['plan']);

            if ($call === 1) {
                // attempt 6/7, first request: an unusable duplicate title AND a page whose hero is missing.
                $pages[3]['seo_title'] = $pages[1]['seo_title'];
                $pages[0]['sections'] = [['type' => 'text', 'data' => ['heading' => 'No hero', 'body' => 'Body text.']]];

                return json_encode(['pages' => $pages]);
            }

            // The retry returns ONE page of the five (returned_pages=1, expected_pages=5).
            return json_encode(['pages' => [$pages[0]]]);
        });

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertCount(1 + GenerationBatchPlanner::MAX_RETRIES_PER_BATCH, $this->calls, 'one request and its single retry; nothing further is spent');
        $this->assertStringContainsString('duplicates another page', $this->calls[1]['system'], 'the retry carries the reasons the first answer was rejected');
        $this->assertSame($draftBefore, $website->fresh()->pages()->get(['id', 'title', 'slug', 'sections'])->toArray(), 'not one page was added, changed or removed');
        $this->assertSame($revision->id, $website->fresh()->published_revision_id, 'the published website is untouched');
    }

    public function test_an_incomplete_answer_is_retried_asking_for_every_page_and_a_complete_one_then_commits(): void
    {
        [$customer, $business, $website, $template] = $this->largeBusiness();

        $this->rawProvider(function (array $payload, int $call) {
            $pages = array_map(fn ($entry) => $this->defaultPage($entry), $payload['plan']);

            return json_encode(['pages' => $call === 1 ? [$pages[0]] : $pages]);   // returned_pages=1, expected_pages=5 — once
        });

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertStringContainsString('You must return exactly 5 pages', $this->calls[1]['system']);
        foreach ($this->calls[0]['keys'] as $key) {
            $this->assertStringContainsString($key, $this->calls[1]['system'], "the retry names the required page {$key}");
        }
        $this->assertSame(count($this->plan($business, $template, $website)), $website->fresh()->pages()->count(), 'no planned page is missing');
    }

    public function test_the_normalizer_only_repairs_what_cannot_change_meaning(): void
    {
        $plan = [['page_key' => 'home', 'allowed_section_types' => ['hero', 'text']], ['page_key' => 'about', 'allowed_section_types' => ['hero', 'text']]];
        $hero = ['type' => 'hero', 'data' => ['heading' => 'H']];

        $out = \App\Library\Website\GuidedGeneration\GuidedOutputNormalizer::normalize([
            ['page_key' => 'home', 'seo_title' => '  ' . str_repeat('word ', 20) . ' ', 'meta_description' => '   ', 'sections' => [
                $hero,
                ['type' => 'image_text', 'data' => ['heading' => 'Kept', 'body' => 'Same words.', 'image' => null]],
                ['type' => 'testimonials', 'data' => ['items' => [['quote' => 'Invented praise']]]],
                ['type' => 'image_text', 'data' => ['heading' => 'Empty', 'body' => '']],
            ]],
            ['page_key' => 'unknown', 'seo_title' => str_repeat('x', 200), 'sections' => 'not-an-array'],
            'not-a-page',
        ], $plan);

        $this->assertCount(3, $out['pages'], 'no page is added or removed');
        $home = $out['pages'][0];
        $this->assertSame(['hero', 'text'], array_column($home['sections'], 'type'), 'image_text with real copy became text; invented testimonials and an empty image_text are dropped, never converted');
        $this->assertSame('Same words.', $home['sections'][1]['data']['body']);
        $this->assertLessThanOrEqual(70, mb_strlen($home['seo_title']));
        $this->assertSame('word', substr($home['seo_title'], -4), 'cut at a word boundary');
        $this->assertNull($home['meta_description'], 'a blank description is absent, never invented');
        $this->assertSame(str_repeat('x', 200), $out['pages'][1]['seo_title'], 'a page_key outside the plan is left for the validator to reject');
        $this->assertSame('not-a-page', $out['pages'][2]);

        // A model-written form is never tidied away: it stays, so validation fails the batch.
        $form = ['type' => 'form', 'data' => ['form_uid' => 'ai-invented']];
        $kept = \App\Library\Website\GuidedGeneration\GuidedOutputNormalizer::normalize([['page_key' => 'about', 'sections' => [$hero, $form]]], $plan);
        $this->assertSame(['hero', 'form'], array_column($kept['pages'][0]['sections'], 'type'));
    }

    public function test_rejection_notes_are_bounded_printable_and_name_the_required_pages(): void
    {
        $notes = GuidedWebsiteGenerationClient::rejectionNotes([
            'plan.missing' => ["Required planned page 'about' is missing from the generated output."],
            'pages.0.sections.3.type' => ["Section type '" . "\u{202E}evil\n<script>" . "' is not allowed on the 'home' page."],
            'pages.1.content' => array_fill(0, 20, str_repeat('long problem ', 40)),
        ], ['home', 'about', 'contact', 'faq', 'services']);

        $this->assertLessThanOrEqual(GuidedWebsiteGenerationClient::MAX_REJECTION_NOTES, count($notes));
        $this->assertStringContainsString('exactly 5 pages', $notes[0]);
        $this->assertStringContainsString('home, about, contact, faq, services', $notes[0]);
        foreach ($notes as $note) {
            $this->assertLessThanOrEqual(GuidedWebsiteGenerationClient::MAX_REJECTION_CHARS + 120, mb_strlen($note));
            $this->assertSame(1, preg_match('/^[\x20-\x7E]+$/', $note), 'printable ASCII only');
        }
    }
}
