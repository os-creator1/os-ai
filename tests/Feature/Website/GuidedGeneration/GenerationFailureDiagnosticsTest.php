<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsitePageStrategy;
use App\Models\BusinessService;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteRevision;
use App\Models\WebsiteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * A guided generation that is rejected used to end as one generic customer message with nothing to diagnose. Each rejected
 * batch now leaves one sanitized `motiongrove.website_generation.batch_rejected` log line naming the failure stage, while the
 * customer-facing message, the bounded single retry, the untouched draft and the untouched published revision are unchanged.
 * No real provider is ever called: the low-level AI client is always a double.
 */
class GenerationFailureDiagnosticsTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;
    use RefreshDatabase;

    private const GENERIC = 'Generation did not produce a valid page batch.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
    }

    /** @param array<int, ?string> $responses one per provider call, in order */
    private function sequencedClient(array $responses, mixed $refusal = null): void
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn($refusal);
        $mock->shouldReceive('complete')->andReturnValues($responses);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }

    /** @return array<int, array<string, mixed>> */
    private function captureRejections(callable $run): array
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            if ($event->message === 'motiongrove.website_generation.batch_rejected') {
                $logged[] = $event->context;
            }
        });
        $run();

        return $logged;
    }

    /** A site with a saved draft, a published revision, and a generation that is about to fail. */
    private function siteWithDraftAndLiveRevision(): array
    {
        [$customer, $business] = $this->entitledTenant();
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $this->homePage($website, ['title' => 'Owner Edited Home']);
        $revision = WebsiteRevision::create(['website_id' => $website->id, 'version_number' => 2, 'snapshot' => ['pages' => [['slug' => null, 'is_home' => true]]], 'schema_version' => 1, 'created_by' => $customer->user_id]);
        $website->forceFill(['published_revision_id' => $revision->id])->save();

        return [$customer, $business, $website->fresh(), $template, $revision];
    }

    private function assertDraftAndLiveRevisionUntouched(Website $website, WebsiteRevision $revision): void
    {
        $this->assertSame(1, $website->pages()->count());
        $this->assertSame('Owner Edited Home', $website->pages()->first()->title);
        $this->assertSame($revision->id, $website->fresh()->published_revision_id);
        $this->assertSame(['pages' => [['slug' => null, 'is_home' => true]]], $revision->fresh()->snapshot);
        $this->assertSame(1, WebsiteRevision::where('website_id', $website->id)->count());
    }

    public function test_a_truncated_provider_response_is_logged_as_not_json_with_no_response_text(): void
    {
        [$customer, $business, $website, $template, $revision] = $this->siteWithDraftAndLiveRevision();
        $complete = json_encode(['pages' => [['page_key' => 'home', 'title' => 'SECRET CUSTOMER TEXT', 'sections' => []]]]);
        $truncated = substr($complete, 0, 40); // cut mid-page, as an output-token ceiling does
        $this->sequencedClient([$truncated, $truncated]);

        $logged = $this->captureRejections(function () use (&$attempt, $business, $website, $template, $customer) {
            $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'diag-trunc');
        });

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(1, $attempt->retry_count, 'Exactly one bounded corrective retry.');
        $this->assertSame(self::GENERIC, $attempt->failure_reason, 'The customer-facing message is unchanged.');
        $this->assertCount(2, $logged);
        $this->assertSame([0, 1], array_column($logged, 'try'));
        $this->assertSame('response_not_json', $logged[0]['stage']);
        $this->assertSame(strlen($truncated), $logged[0]['response_chars']);
        $this->assertSame($website->id, $logged[0]['website_id']);
        $this->assertStringNotContainsString('SECRET', json_encode($logged));
        $this->assertDraftAndLiveRevisionUntouched($website, $revision);
    }

    public function test_a_response_without_a_pages_list_is_logged_as_missing_pages(): void
    {
        [$customer, $business, $website, $template, $revision] = $this->siteWithDraftAndLiveRevision();
        $this->sequencedClient([json_encode(['site' => 'x']), json_encode(['site' => 'x'])]);

        $logged = $this->captureRejections(function () use ($business, $website, $template, $customer) {
            app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'diag-nopages');
        });

        $this->assertSame(['response_missing_pages', 'response_missing_pages'], array_column($logged, 'stage'));
        $this->assertDraftAndLiveRevisionUntouched($website, $revision);
    }

    public function test_a_provider_that_returns_nothing_is_logged_as_no_content(): void
    {
        [$customer, $business, $website, $template, $revision] = $this->siteWithDraftAndLiveRevision();
        $this->sequencedClient([null, null]);

        $logged = $this->captureRejections(function () use ($business, $website, $template, $customer) {
            app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'diag-none');
        });

        $this->assertSame(['provider_no_content', 'provider_no_content'], array_column($logged, 'stage'));
        $this->assertDraftAndLiveRevisionUntouched($website, $revision);
    }

    public function test_a_validator_rejection_logs_only_field_paths_never_messages_or_copy(): void
    {
        [$customer, $business, $website, $template, $revision] = $this->siteWithDraftAndLiveRevision();
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        // Right pages, but one has no sections - the validator rejects an empty page.
        $pages = collect($plan)->map(fn ($page, $i) => [
            'page_key' => $page['page_key'],
            'title' => 'LEAKCHECK ' . $page['page_key'],
            'seo_title' => 'Seo ' . $page['page_key'],
            'meta_description' => 'Meta ' . $page['page_key'],
            'sections' => $i === 0 ? [] : [['type' => 'hero', 'data' => ['heading' => 'LEAKCHECK']]],
        ])->values()->all();
        $this->sequencedClient([json_encode(['pages' => $pages]), json_encode(['pages' => $pages])]);

        $logged = $this->captureRejections(function () use (&$attempt, $business, $website, $template, $customer) {
            $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'diag-validate');
        });

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(1, $attempt->retry_count);
        $this->assertSame('validation_failed', $logged[0]['stage']);
        $this->assertContains('pages.0.sections', $logged[0]['error_paths']);
        $this->assertSame(count($plan), $logged[0]['expected_pages']);
        $this->assertSame(count($plan), $logged[0]['returned_pages']);
        $this->assertStringNotContainsString('LEAKCHECK', json_encode($logged));
        $this->assertStringNotContainsString('empty page', json_encode($logged));
        $this->assertDraftAndLiveRevisionUntouched($website, $revision);
    }

    public function test_a_wrong_shape_is_logged_as_unexpected_shape_with_only_the_exception_class(): void
    {
        [$customer, $business, $website, $template, $revision] = $this->siteWithDraftAndLiveRevision();
        $bad = json_encode(['pages' => [['page_key' => 'home', 'title' => 'T', 'sections' => 'NOT-AN-ARRAY']]]);
        $this->sequencedClient([$bad, $bad]);

        $logged = $this->captureRejections(function () use ($business, $website, $template, $customer) {
            app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'diag-shape');
        });

        $this->assertNotEmpty($logged);
        $this->assertContains($logged[0]['stage'], ['validation_failed', 'unexpected_shape']);
        $this->assertStringNotContainsString('NOT-AN-ARRAY', json_encode($logged));
        $this->assertDraftAndLiveRevisionUntouched($website, $revision);
    }

    public function test_a_retry_that_succeeds_still_commits_and_logs_only_the_first_rejection(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $valid = json_encode(['pages' => collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => $page['title'] . ' seo title',
            'meta_description' => $page['title'] . ' meta description for this specific page.',
            'sections' => [['type' => 'hero', 'data' => ['heading' => $page['title']]]],
        ])->values()->all()]);
        $this->sequencedClient(['{"pages": [', $valid]);

        $logged = $this->captureRejections(function () use (&$attempt, $business, $website, $template, $customer) {
            $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'diag-retry-ok');
        });

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame(1, $attempt->retry_count);
        $this->assertCount(1, $logged);
        $this->assertSame('response_not_json', $logged[0]['stage']);
        $this->assertSame(count($plan), $website->pages()->count());
    }

    public function test_a_large_photo_booth_plan_with_events_and_area_pages_generates_and_stays_isolated_from_the_live_revision(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->completeV2Setup($workspace, $business);
        foreach (['Digital Photo Booth', 'Magic Mirror Photo Booth', 'Polaroid Photo Booth'] as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'description' => "What the {$name} is and what it includes.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i]);
        }
        foreach (['Weddings', 'Corporate Events', 'Parties'] as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'kind' => BusinessService::KIND_EVENT, 'description' => "How the booths are run for {$name} and what the owner brings.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 100 + $i]);
        }
        $areas = array_map(fn (string $name) => ['name' => $name, 'details' => ['logistics' => "Serving {$name} takes under an hour from our base; we arrive two hours before the event."]], ['Jackson, MS', 'Madison, MS', 'Ridgeland, MS', 'Brandon, MS']);

        $website = Website::where('business_id', $business->id)->first() ?? $this->createWebsite($business);
        $this->homePage($website, ['title' => 'Owner Edited Home']);
        $revision = WebsiteRevision::create(['website_id' => $website->id, 'version_number' => 2, 'snapshot' => ['pages' => []], 'schema_version' => 1, 'created_by' => $customer->user_id]);
        $website->forceFill(['published_revision_id' => $revision->id])->save();
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');

        $plan = app(WebsitePageStrategy::class)->buildPlan($business->fresh(), $template, $website->fresh(), null, null, $areas);
        $this->assertGreaterThanOrEqual(10, count($plan), 'A realistic photo booth site plans well over ten pages.');
        $this->assertNotEmpty(array_filter($plan, fn ($p) => $p['page_type'] === 'event_detail'));
        $this->assertNotEmpty(array_filter($plan, fn ($p) => str_starts_with($p['page_key'], 'area:')), 'Service-area (location) pages are planned.');

        $this->bindDistinctAiClient();
        $logged = $this->captureRejections(function () use (&$attempt, $business, $website, $template, $customer, $areas) {
            $attempt = app(GuidedGenerationCommitService::class)->rebuild($business->fresh(), $website->fresh(), $template, $customer->user_id, 'diag-large', null, null, null, null, $areas, null);
        });

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);
        $this->assertSame([], $logged, 'A valid batch logs no rejection.');
        $this->assertSame(count($plan), $website->pages()->count());
        $this->assertSame($revision->id, $website->fresh()->published_revision_id, 'Rebuild never publishes or changes the live revision.');
        $this->assertSame(['pages' => []], $revision->fresh()->snapshot);
        $this->assertSame(1, WebsiteRevision::where('website_id', $website->id)->count(), 'Rebuild creates no revision.');
    }
}
