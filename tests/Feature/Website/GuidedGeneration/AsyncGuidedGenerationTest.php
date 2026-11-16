<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Jobs\Website\RunGuidedWebsiteGenerationJob;
use App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator;
use App\Library\Website\WebsitePageStrategy;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Generation/rebuild runs on the queue, not in the web request. The request returns at once to a progress screen
 * that reports the REAL outcome from the lease and the attempt row. No AI provider call is ever made (mocked).
 */
class AsyncGuidedGenerationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private array $ctx;

    private function site(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.store', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern'])->assertRedirect();
        $website = Website::where('business_id', $business->id)->firstOrFail();
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');

        return $this->ctx = compact('customer', 'business', 'workspace', 'website', 'template');
    }

    private function batch(array $plan): string
    {
        return json_encode(['pages' => collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'], 'title' => $page['title'], 'seo_title' => $page['title'] . ' | Async', 'meta_description' => $page['title'] . ' meta description page.',
            'sections' => [['type' => 'hero', 'data' => ['heading' => $page['title']]]],
        ])->values()->all()]);
    }

    private function rebuildUrl(): string
    {
        return route('customer.workspaces.businesses.website.rebuild', [$this->ctx['workspace']->uid, $this->ctx['business']->uid]);
    }

    private function statusJson(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(route('customer.workspaces.businesses.website.generation.status', [$this->ctx['workspace']->uid, $this->ctx['business']->uid, 'since' => 0]));
    }

    private function job(string $token, string $mode = 'rebuild'): RunGuidedWebsiteGenerationJob
    {
        return new RunGuidedWebsiteGenerationJob($this->ctx['website']->id, $this->ctx['business']->id, 'photo_booth_modern', (int) $this->ctx['customer']->user_id, (string) Str::uuid(), $mode, $token, null, null, null, null, null);
    }

    public function test_the_request_queues_the_job_and_returns_at_once_without_touching_the_draft_or_calling_ai(): void
    {
        Queue::fake();
        $this->site();
        $before = $this->ctx['website']->pages()->pluck('title', 'id')->all();
        $ai = $this->mockAiClient($this->batch([]));
        $ai->shouldNotReceive('complete');

        $response = $this->post($this->rebuildUrl(), ['template_key' => 'photo_booth_modern', 'confirm_rebuild' => '1', 'idempotency_key' => (string) Str::uuid()]);

        $response->assertRedirectContains('/website/generation?since=');
        Queue::assertPushed(RunGuidedWebsiteGenerationJob::class, 1);
        $this->assertNotNull($this->ctx['website']->fresh()->generation_lease_token, 'The lease is held for the queued run.');
        $this->assertSame(0, WebsiteGuidedGenerationAttempt::count());
        $this->assertSame($before, $this->ctx['website']->pages()->pluck('title', 'id')->all());
        $this->statusJson()->assertJsonPath('state', 'running');
        $this->get(route('customer.workspaces.businesses.website.generation.progress', [$this->ctx['workspace']->uid, $this->ctx['business']->uid, 'since' => 0]))->assertOk()->assertSee('generation-status', false);
    }

    public function test_a_concurrent_second_request_is_refused_and_queues_nothing(): void
    {
        Queue::fake();
        $this->site();
        $this->post($this->rebuildUrl(), ['template_key' => 'photo_booth_modern', 'confirm_rebuild' => '1', 'idempotency_key' => (string) Str::uuid()]);

        $this->post($this->rebuildUrl(), ['template_key' => 'photo_booth_modern', 'confirm_rebuild' => '1', 'idempotency_key' => (string) Str::uuid()])
            ->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$this->ctx['workspace']->uid, $this->ctx['business']->uid]));

        $this->assertSame('error', session('status'));
        Queue::assertPushed(RunGuidedWebsiteGenerationJob::class, 1);
    }

    public function test_a_successful_job_commits_the_draft_releases_the_lease_and_reports_success(): void
    {
        $this->site();
        $plan = app(WebsitePageStrategy::class)->buildPlan($this->ctx['business'], $this->ctx['template'], $this->ctx['website']);
        $this->mockAiClient($this->batch($plan));
        $token = app(WebsiteGenerationCoordinator::class)->beginLease($this->ctx['website']);

        app()->call([$this->job($token), 'handle']);

        $this->assertSame(count($plan), $this->ctx['website']->pages()->count());
        $this->assertNull($this->ctx['website']->fresh()->generation_lease_token);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, WebsiteGuidedGenerationAttempt::firstOrFail()->status);
        $this->statusJson()->assertJsonPath('state', 'succeeded');
    }

    public function test_a_failed_generation_keeps_the_existing_draft_and_reports_failure(): void
    {
        $this->site();
        $before = $this->ctx['website']->pages()->pluck('title', 'id')->all();
        $this->mockAiClient(json_encode(['pages' => [['page_key' => 'invented', 'title' => 'X', 'seo_title' => null, 'meta_description' => null, 'sections' => []]]]));
        $token = app(WebsiteGenerationCoordinator::class)->beginLease($this->ctx['website']);

        app()->call([$this->job($token), 'handle']);

        $this->assertSame($before, $this->ctx['website']->pages()->pluck('title', 'id')->all());
        $this->assertNull($this->ctx['website']->fresh()->generation_lease_token);
        $this->statusJson()->assertJsonPath('state', 'failed')->assertJsonStructure(['message']);
    }

    public function test_an_obsolete_job_whose_lease_was_reclaimed_does_nothing_and_makes_no_ai_request(): void
    {
        $this->site();
        $before = $this->ctx['website']->pages()->pluck('title', 'id')->all();
        $this->mockAiClient('{}')->shouldNotReceive('complete');
        app(WebsiteGenerationCoordinator::class)->beginLease($this->ctx['website']);

        app()->call([$this->job('a-token-that-no-longer-owns-the-lease'), 'handle']);

        $this->assertSame(0, WebsiteGuidedGenerationAttempt::count());
        $this->assertSame($before, $this->ctx['website']->pages()->pluck('title', 'id')->all());
        $this->assertNotNull($this->ctx['website']->fresh()->generation_lease_token, 'The live lease is untouched.');
    }

    public function test_a_queue_timeout_fails_the_owned_attempt_and_releases_the_lease_leaving_the_draft(): void
    {
        $this->site();
        $before = $this->ctx['website']->pages()->pluck('title', 'id')->all();
        $token = app(WebsiteGenerationCoordinator::class)->beginLease($this->ctx['website']);
        $attempt = WebsiteGuidedGenerationAttempt::create(['website_id' => $this->ctx['website']->id, 'template_key' => 'photo_booth_modern', 'mode' => 'rebuild', 'idempotency_key' => 'k:1', 'status' => 'pending', 'created_by_user_id' => $this->ctx['customer']->user_id]);
        Website::whereKey($this->ctx['website']->id)->update(['generation_lease_attempt_uid' => $attempt->uid]);

        $this->job($token)->failed(new \RuntimeException('has timed out'));

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->fresh()->status);
        $this->assertNull($this->ctx['website']->fresh()->generation_lease_token);
        $this->assertSame($before, $this->ctx['website']->pages()->pluck('title', 'id')->all());
    }

    public function test_a_stale_lease_and_its_pending_attempt_are_recovered_without_touching_the_draft(): void
    {
        $this->site();
        $before = $this->ctx['website']->pages()->pluck('title', 'id')->all();
        $attempt = WebsiteGuidedGenerationAttempt::create(['website_id' => $this->ctx['website']->id, 'template_key' => 'photo_booth_modern', 'mode' => 'rebuild', 'idempotency_key' => 'k:1', 'status' => 'pending', 'created_by_user_id' => $this->ctx['customer']->user_id]);
        Website::whereKey($this->ctx['website']->id)->update(['generation_lease_token' => 'dead', 'generation_lease_started_at' => now()->subMinutes(30), 'generation_lease_attempt_uid' => $attempt->uid]);

        $this->statusJson()->assertJsonPath('state', 'failed');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->fresh()->status);
        $this->assertNull($this->ctx['website']->fresh()->generation_lease_token);
        $this->assertSame($before, $this->ctx['website']->pages()->pluck('title', 'id')->all());
    }

    public function test_an_orphaned_old_pending_attempt_is_failed_when_a_new_lease_is_taken_but_a_young_one_is_not(): void
    {
        $this->site();
        $mk = fn (string $key, $age) => tap(WebsiteGuidedGenerationAttempt::create(['website_id' => $this->ctx['website']->id, 'template_key' => 'photo_booth_modern', 'mode' => 'rebuild', 'idempotency_key' => $key, 'status' => 'pending', 'created_by_user_id' => $this->ctx['customer']->user_id]), fn ($a) => WebsiteGuidedGenerationAttempt::whereKey($a->id)->update(['created_at' => $age]));
        $old = $mk('old:1', now()->subHours(20));
        $young = $mk('young:1', now());

        app(WebsiteGenerationCoordinator::class)->beginLease($this->ctx['website']);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $old->fresh()->status);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_PENDING, $young->fresh()->status);
    }

    public function test_job_timeout_lease_and_queue_retry_window_are_compatible(): void
    {
        $this->assertLessThan(WebsiteGenerationCoordinator::LEASE_SECONDS, RunGuidedWebsiteGenerationJob::TIMEOUT_SECONDS);
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), RunGuidedWebsiteGenerationJob::TIMEOUT_SECONDS);
        $this->assertSame(1, (new RunGuidedWebsiteGenerationJob(1, 1, 'k', 1, 'i', 'rebuild', 't', null, null, null, null, null))->tries);
    }
}
