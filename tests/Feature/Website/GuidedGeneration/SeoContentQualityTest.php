<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Website\GuidedGeneration\GenerationBatchPlanner;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\GuidedGeneration\GuidedGenerationOutputValidator;
use App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient;
use App\Library\Website\GuidedGeneration\SeoContentFacts;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsitePageStrategy;
use App\Models\BusinessLocation;
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
 * SEO-first content quality, end to end with FAKE providers only: confirmed facts reach the bounded prompt, AI pages are judged by
 * PageContentQuality inside the batched generation, and a page that fails never touches the existing draft.
 *
 * The fixture is a representative local photo-booth business (Petal, Mississippi) described purely as data.
 */
class SeoContentQualityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;

    /** @var array<int, array{keys: array<int, string>, payload: array, system: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
        config(['ai.website_content_quality' => true]);
    }

    private function business(int $services = 3, bool $withLocation = true, bool $withEvents = true): array
    {
        [$customer, $business] = $this->entitledTenant();

        $names = ['Magic Mirror Photo Booth', 'Open-Air Photo Booth', '360 Video Booth', 'Glam Booth', 'Roaming Booth'];
        foreach (array_slice($names, 0, $services) as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => Str::slug($name), 'description' => "What {$name} is: a booth for guests with prints, props and an attendant at your venue.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i + 1]);
        }

        if ($withEvents) {
            foreach (['Weddings', 'Corporate Events'] as $i => $event) {
                BusinessService::create(['business_id' => $business->id, 'name' => $event, 'slug' => Str::slug($event), 'kind' => BusinessService::KIND_EVENT, 'description' => "How we run {$event} with an attendant, props and instant sharing for every guest.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 100 + $i]);
            }
        }

        if ($withLocation) {
            (new BusinessLocation())->forceFill(['business_id' => $business->id, 'is_primary' => true, 'service_mode' => 'service_area', 'name' => 'Main', 'city' => 'Petal', 'region' => 'Mississippi', 'country_code' => 'US', 'service_area_cities' => ['Hattiesburg', 'Laurel', 'Biloxi']])->save();
        }

        $website = $this->createWebsite($business);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');

        return [$customer, $business, $website, $template];
    }

    private function plan($business, $template, $website, ?array $areas = null): array
    {
        return WebsitePageStrategy::withoutAiUnfillableSections(app(WebsitePageStrategy::class)->buildPlan($business, $template, $website, null, null, $areas));
    }

    /** A fake provider that writes each requested page with $writer(entry, payload): array, recording every request. */
    private function provider(callable $writer): void
    {
        $this->calls = [];
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function (array $messages) use ($writer) {
            $payload = json_decode(collect($messages)->firstWhere('role', 'user')['content'], true);
            $this->calls[] = ['keys' => array_column($payload['plan'], 'page_key'), 'payload' => $payload, 'system' => (string) collect($messages)->firstWhere('role', 'system')['content']];

            return json_encode(['pages' => array_map(fn (array $entry) => $writer($entry, $payload), $payload['plan'])]);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }

    private function contactPath(array $payload): string
    {
        return collect($payload['site_pages'] ?? [])->firstWhere('page_type', 'contact')['path'] ?? '/photo-booth-contact';
    }

    /** Complete, fact-based copy built ONLY from what the request itself carried (site_context, plan entity, confirmed_facts). */
    private function richPage(array $entry, array $payload): array
    {
        $context = $payload['site_context'] ?? [];
        $city = $context['primary_market']['city'] ?? null;
        $region = $context['primary_market']['region'] ?? null;
        $contact = $this->contactPath($payload);
        $key = $entry['page_key'];
        $hero = fn (string $heading, ?string $sub = null) => ['type' => 'hero', 'data' => ['heading' => $heading, 'subheading' => $sub, 'primary_cta' => ['label' => 'Check availability', 'url' => $contact], 'secondary_cta' => null]];
        $text = fn (string $heading, string $body) => ['type' => 'text', 'data' => ['heading' => $heading, 'body' => $body]];
        $unique = hash('sha256', $key) . hash('sha256', $key . 'b');
        $where = $city === null ? '' : ' in ' . $city . ($region !== null ? ', ' . $region : '');

        switch ($entry['page_type']) {
            case 'home':
                $services = array_column($context['services'] ?? [], 'name');
                $sections = [
                    $hero(($context['primary_service'] ?? 'Photo booth') . ' Rental' . $where, 'Photo booth fun for every celebration.'),
                    $text('The booths you can book', 'Choose from ' . implode(', ', $services) . '.'),
                    $text('Where we work', 'We serve ' . implode(', ', $context['areas_served'] ?? []) . ' and the surrounding area.'),
                ];
                break;
            case 'service_detail':
            case 'event_detail':
                $name = $entry['entity']['name'];
                $sections = [
                    $hero($name . ' Rental' . $where),
                    $text('What ' . $name . ' is', ($entry['entity']['description'] ?? $name) . ' Details: ' . $unique),
                    $text('Booking ' . $name, 'Tell us the date and venue to confirm availability. Reference ' . substr($unique, 0, 20)),
                ];
                break;
            default:
                $sections = [$hero($entry['title'] . ' ' . substr($unique, 0, 8))];
        }

        return ['page_key' => $key, 'title' => $entry['title'] . ' ' . substr($unique, 0, 6), 'seo_title' => 'SEO ' . $entry['title'] . ' ' . substr($unique, 0, 10), 'meta_description' => 'Meta ' . $entry['title'] . ' ' . substr($unique, 0, 10), 'sections' => $sections];
    }

    private function generate($customer, $business, $website, $template, string $key = 'idem-q'): WebsiteGuidedGenerationAttempt
    {
        return app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, $key);
    }

    // ------------------------------------------------------------------ facts reach the bounded prompt

    public function test_confirmed_services_market_and_areas_reach_every_request_in_bounded_form(): void
    {
        [$customer, $business, $website, $template] = $this->business();
        $this->provider(fn ($entry, $payload) => $this->richPage($entry, $payload));

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            $context = $call['payload']['site_context'];
            $this->assertSame(['city' => 'Petal', 'region' => 'Mississippi'], $context['primary_market']);
            $this->assertSame('Magic Mirror Photo Booth', $context['primary_service']);
            $this->assertSame(['Hattiesburg', 'Laurel', 'Biloxi'], $context['areas_served']);
            $this->assertCount(3, $context['services']);
            $this->assertContains('Weddings', $context['event_types']);
            $this->assertLessThanOrEqual(GenerationBatchPlanner::inputTokenLimit(), app(\App\Library\Ai\AiModelRouter::class)->estimateInputTokens([['role' => 'system', 'content' => $call['system']], ['role' => 'user', 'content' => json_encode($call['payload'])]]));
        }
        $this->assertStringContainsString('hero heading (the page H1)', $this->calls[0]['system'], 'The first batch holds the home page and carries the home-page guidance.');
    }

    public function test_the_context_lists_are_capped_so_a_large_business_cannot_inflate_the_prompt(): void
    {
        [, $business] = $this->entitledTenant();
        foreach (range(1, 30) as $n) {
            BusinessService::create(['business_id' => $business->id, 'name' => "Booth {$n}", 'slug' => "booth-{$n}", 'description' => str_repeat('long description ', 40), 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $n]);
        }

        $facts = SeoContentFacts::build($business, app(WebsitePageStrategy::class), ['differentiators' => array_map(fn ($n) => str_repeat('d', 400) . $n, range(1, 20))]);

        $this->assertCount(SeoContentFacts::MAX_SERVICES, $facts['services']);
        $this->assertCount(SeoContentFacts::MAX_DIFFERENTIATORS, $facts['differentiators']);
        foreach ($facts['services'] as $service) {
            $this->assertLessThanOrEqual(120, mb_strlen((string) $service['description']));
        }
        $this->assertLessThan(6_000, strlen(json_encode(SeoContentFacts::promptContext($facts))), 'The site context is a small, fixed-size block.');
    }

    // ------------------------------------------------------------------ A / B / C: quality through the validator and generation

    public function test_a_kaleb_hill_style_generic_homepage_is_rejected_by_the_validator_with_the_facts(): void
    {
        [, $business, $website, $template] = $this->business();
        $plan = $this->plan($business, $template, $website);
        $facts = app(GuidedWebsiteGenerationClient::class)->contentFacts($business);
        $home = collect($plan)->firstWhere('page_key', 'home');
        $page = ['page_key' => 'home', 'title' => 'Home', 'seo_title' => 'Photo Booth', 'meta_description' => 'Photo booths', 'sections' => [
            ['type' => 'hero', 'data' => ['heading' => 'Capture Every Moment with Style', 'subheading' => 'Photo booths for your event.']],
            ['type' => 'text', 'data' => ['heading' => 'Our Amazing Services', 'body' => 'We offer great photo booths.']],
        ]];

        $validator = app(GuidedGenerationOutputValidator::class);
        $validator->validate([$page], [$home], [], null, null);   // structure alone accepts it, which is the problem being fixed

        try {
            $validator->validate([$page], [$home], [], null, $facts);
            $this->fail('A generic homepage must fail content-quality validation.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('primary market (Petal)', json_encode($e->errors()));
        }
    }

    public function test_meaningful_fact_based_pages_commit_and_real_services_get_dedicated_informative_pages(): void
    {
        [$customer, $business, $website, $template] = $this->business();
        $plan = $this->plan($business, $template, $website);
        $this->provider(fn ($entry, $payload) => $this->richPage($entry, $payload));

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertSame(count($plan), $website->pages()->count());

        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $this->assertStringContainsString('Magic Mirror Photo Booth Rental in Petal, Mississippi', $home->sections[0]['data']['heading']);

        foreach (['Magic Mirror Photo Booth', 'Open-Air Photo Booth', '360 Video Booth'] as $name) {
            $page = $website->pages()->where('slug', 'service-' . Str::slug($name))->first();
            $this->assertNotNull($page, "{$name} has a dedicated page.");
            $this->assertStringContainsString($name, $page->sections[0]['data']['heading']);
            $this->assertGreaterThanOrEqual(3, count($page->sections), 'Headline plus informative sections, not one short paragraph.');
        }
    }

    public function test_generic_filler_pages_fail_with_one_retry_and_leave_the_existing_draft_and_revision_untouched(): void
    {
        [$customer, $business, $website, $template] = $this->business();
        $this->homePage($website, ['title' => 'Existing Draft Home']);
        $before = $website->pages()->get(['id', 'title', 'slug', 'sections'])->toArray();
        $revisions = \App\Models\WebsiteRevision::where('website_id', $website->id)->count();
        $published = $website->fresh()->published_revision_id;

        // Structurally valid, but generic: the exact pattern of the thin Kaleb Hill homepage.
        $this->provider(function (array $entry) {
            return ['page_key' => $entry['page_key'], 'title' => $entry['title'], 'seo_title' => 'SEO ' . $entry['page_key'], 'meta_description' => 'Meta ' . $entry['page_key'], 'sections' => [
                ['type' => 'hero', 'data' => ['heading' => 'Capture Every Moment with Style', 'subheading' => null]],
                ['type' => 'text', 'data' => ['heading' => 'Our Amazing Services', 'body' => 'An unforgettable experience.']],
            ]];
        });

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(1, $attempt->retry_count);
        $this->assertCount(2, $this->calls, 'The first batch is tried once and retried once; nothing further is requested.');
        $this->assertSame($before, $website->fresh()->pages()->get(['id', 'title', 'slug', 'sections'])->toArray());
        $this->assertSame($revisions, \App\Models\WebsiteRevision::where('website_id', $website->id)->count());
        $this->assertSame($published, $website->fresh()->published_revision_id);
    }

    public function test_a_quality_failure_in_a_later_batch_discards_the_whole_generation(): void
    {
        [$customer, $business, $website, $template] = $this->business(5);
        $this->homePage($website, ['title' => 'Existing Draft Home']);
        // Everything is rich except the dedicated Glam Booth page, which is generic: it sits in a later batch than the home page.
        $this->provider(function (array $entry, array $payload) {
            if (($entry['entity']['name'] ?? null) === 'Glam Booth') {
                return ['page_key' => $entry['page_key'], 'title' => 'Glam', 'seo_title' => 'SEO glam', 'meta_description' => 'Meta glam', 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Discover the Magic']]]];
            }

            return $this->richPage($entry, $payload);
        });

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertGreaterThan(1, count(array_unique(array_map(fn ($c) => $c['keys'][0], $this->calls))), 'The generic page was in a later batch than the home page.');
        $this->assertSame(['Existing Draft Home'], $website->pages()->pluck('title')->all(), 'Earlier good batches are not committed on their own.');
    }

    // ------------------------------------------------------------------ E / F: location pages

    private function areaBusiness(): array
    {
        [$customer, $business, $website, $template] = $this->business(3, true, false);
        $areas = [
            ['name' => 'Hattiesburg, MS', 'details' => ['venues' => ['The Hattiesburg Saenger Theater', 'Lake Terrace Convention Center'], 'logistics' => 'About 20 minutes from Petal; we load in two hours before the event.']],
            ['name' => 'Laurel, MS', 'details' => ['venues' => ['The Landrum Center'], 'logistics' => 'About 45 minutes from Petal; parking is behind the hall.']],
            ['name' => 'Biloxi, MS'],   // no confirmed local fact: stays in the areas list, no page
        ];

        return [$customer, $business, $website, $template, $areas];
    }

    public function test_areas_without_confirmed_local_facts_do_not_become_pages_and_the_owner_is_told_what_to_add(): void
    {
        [, $business, $website, $template, $areas] = $this->areaBusiness();

        $explained = app(WebsitePageStrategy::class)->planWithExplanation($business, $template, $website, null, null, $areas);

        $planned = array_column($explained['plan'], 'page_key');
        $this->assertContains('area:hattiesburg-ms', $planned);
        $this->assertContains('area:laurel-ms', $planned);
        $this->assertNotContains('area:biloxi-ms', $planned);
        $excluded = collect($explained['areas']['excluded'])->firstWhere('area', 'Biloxi, MS');
        $this->assertStringContainsString('venue you have worked in Biloxi, MS', $excluded['reason'], 'An actionable missing-information message, not invented copy.');
    }

    public function test_qualified_area_pages_must_use_their_confirmed_venues_and_logistics(): void
    {
        [$customer, $business, $website, $template, $areas] = $this->areaBusiness();
        $makeArea = function (array $entry, array $payload, bool $local) {
            $contact = $this->contactPath($payload);
            $area = $entry['entity']['area'];
            $facts = $entry['entity']['local_facts'] ?? [];
            $body = $local
                ? 'We have set up at ' . implode(' and ', $facts['venues'] ?? []) . '. ' . ($facts['logistics'] ?? '')
                : "We bring our photo booths to {$area} for weddings, birthdays and corporate events.";
            $unique = hash('sha256', $entry['page_key']);

            return ['page_key' => $entry['page_key'], 'title' => "Photo booth rental in {$area}", 'seo_title' => "Booths in {$area} {$unique}", 'meta_description' => "Meta {$area} " . substr($unique, 0, 12), 'sections' => [
                ['type' => 'hero', 'data' => ['heading' => "Photo Booth Rental in {$area}", 'subheading' => null, 'primary_cta' => ['label' => 'Check availability', 'url' => $contact], 'secondary_cta' => null]],
                ['type' => 'text', 'data' => ['heading' => "Venues and setup in {$area}", 'body' => $body . ' Ref ' . $unique]],
                ['type' => 'text', 'data' => ['heading' => "Booking in {$area}", 'body' => 'Tell us the date and venue. Ref ' . substr($unique, 10)]],
            ]];
        };
        $areaCall = fn (bool $local) => function (array $entry, array $payload) use ($makeArea, $local) {
            return isset($entry['entity']['area']) ? $makeArea($entry, $payload, $local) : $this->richPage($entry, $payload);
        };

        // Swapped-city copy with no confirmed local information: rejected.
        $this->homePage($website, ['title' => 'Existing Draft Home']);
        $this->provider($areaCall(false));
        $failed = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-area-bad', null, null, null, null, $areas);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $failed->status);
        $this->assertSame(0, $website->pages()->where('slug', 'like', 'serving-%')->count());
        $this->assertSame(['Existing Draft Home'], $website->pages()->pluck('title')->all());

        // The same pages written from the confirmed venues and logistics: accepted.
        $this->provider($areaCall(true));
        $ok = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-area-good', null, null, null, null, $areas);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $ok->status, (string) $ok->failure_reason);
        $this->assertSame(2, $website->pages()->where('slug', 'like', 'serving-%')->count());
        $laurel = $website->pages()->where('slug', 'serving-laurel-ms')->firstOrFail();
        $this->assertStringContainsString('The Landrum Center', json_encode($laurel->sections));
    }

    // ------------------------------------------------------------------ G: gaps are reported, nothing is fabricated

    public function test_missing_business_facts_are_reported_on_the_attempt_and_no_fake_copy_is_required(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        // No city, no services, no differentiators: the pages only need to be honest and non-generic.
        $this->provider(fn (array $entry, array $payload) => $this->richPage($entry, $payload));

        $attempt = $this->generate($customer, $business, $website, $template, 'idem-gaps');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $warnings = implode(' | ', (array) $attempt->warnings);
        $this->assertStringContainsString('add the city (and state)', $warnings);
        $this->assertStringContainsString('at least one active service', $warnings);
        $this->assertStringContainsString('differentiators', $warnings);
        $this->assertArrayNotHasKey('site_context', $this->calls[0]['payload'], 'No confirmed fact, nothing sent as a fact.');
    }

    // ------------------------------------------------------------------ H: cross-batch links

    public function test_the_home_page_keeps_a_correct_link_to_the_contact_page_written_in_a_later_batch(): void
    {
        [$customer, $business, $website, $template] = $this->business(5);
        $plan = $this->plan($business, $template, $website);
        $contact = collect($plan)->firstWhere('page_type', 'contact');
        $this->provider(fn ($entry, $payload) => $this->richPage($entry, $payload));

        $attempt = $this->generate($customer, $business, $website, $template);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertNotContains($contact['page_key'], $this->calls[0]['keys'], 'Precondition: the contact page is in a later batch than the home page.');
        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $this->assertSame('/' . $contact['slug'], $home->sections[0]['data']['primary_cta']['url']);
    }
}
