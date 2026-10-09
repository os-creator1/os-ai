<?php

namespace Tests\Feature\Seo\Content\Autopilot;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\ArticleStatus;
use App\Jobs\Seo\RunContentAutopilotJob;
use App\Jobs\Seo\WriteAutopilotArticleJob;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Library\Seo\Content\ArticleManager;
use App\Library\Seo\Content\Autopilot\AutopilotPublisher;
use App\Library\Seo\Content\Autopilot\AutopilotRunner;
use App\Library\Seo\Content\Autopilot\AutopilotSwitch;
use App\Library\Seo\Content\Autopilot\ContentProfile;
use App\Models\AiUsageLedgerEntry;
use App\Models\Business;
use App\Models\ContentAutopilotDecision as Decision;
use App\Models\ContentAutopilotSetting;
use App\Models\WebsiteArticle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Content Autopilot, Slice 6 - the daily run: gates, anti-burst cadence, the trust ramp, niche-policy publishing, and the
 * rule that most runs start nothing. The run itself is free; only the queued writer job spends.
 */
class AutopilotRunnerTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private FakeAiCompletionClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.active' => true, 'ai.enforce_budgets_for_existing_categories' => false, 'seo.content_autopilot.max_new_articles_per_month' => 4]);
        $this->fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $this->fake);
        Http::preventStrayRequests();
    }

    private function runner(): AutopilotRunner
    {
        return app(AutopilotRunner::class);
    }

    /** A Growth Business with facts, switched on. */
    private function running(array $profile = []): array
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        app(ContentProfile::class)->save($t[1], (int) $t[0]->user_id, $profile + ['differentiators' => 'Same-day setup', 'common_questions' => "How much space does a photo booth need?\nHow much does a photo booth cost?"]);
        app(AutopilotSwitch::class)->enable($t[1], (int) $t[0]->user_id);

        return $t;
    }

    private function decision(Business $business, array $attributes = []): Decision
    {
        return Decision::query()->create($attributes + [
            'business_id' => $business->id, 'kind' => 'create', 'decision' => 'create', 'state' => Decision::STATE_AWAITING_APPROVAL,
            'opportunity_key' => 'k:' . Str::random(8), 'reason_code' => 'drafted_ready', 'validation' => ['hard' => [], 'soft' => [], 'repaired' => false, 'ready' => true],
            'period_key' => now('UTC')->format('Y-m'), 'evaluated_at' => now(),
        ]);
    }

    /** A validated draft article attached to a decision, optionally already counted as a published Autopilot article. */
    private function draftFor(Business $business, array $decision = [], array $article = []): Decision
    {
        $row = $this->draftArticle($business, $article + ['title' => 'Draft ' . Str::random(6), 'primary_topic' => 'topic ' . Str::random(6)]);
        $row->forceFill(['source' => 'autopilot', 'ai_generated' => true])->save();

        return $this->decision($business, $decision + ['article_id' => $row->id]);
    }

    private function allowStandardNiche(): void
    {
        $reader = $this->partialMock(BlueprintConfigReader::class, fn ($m) => $m->shouldReceive('seoStrategy')->andReturn(['content_policy' => ['risk_tier' => 'standard', 'auto_publish' => 'allowed']]));
        $this->app->instance(BlueprintConfigReader::class, $reader);
    }

    private function rampOver(Business $business): void
    {
        foreach ([1, 2] as $i) {
            $a = $this->publishedArticle($business, ['title' => "Earlier article {$i}", 'primary_topic' => "earlier topic {$i}"]);
            $a->forceFill(['source' => 'autopilot'])->save();
        }
    }

    // ------------------------------------------------------------------ gates

    public function test_it_is_off_by_default_and_when_paused_and_when_the_plan_lacks_it(): void
    {
        Bus::fake([WriteAutopilotArticleJob::class, RunContentAutopilotJob::class]);
        $t = $this->photoBoothContentTenant(withBlueprint: true);

        $this->assertSame('off', $this->runner()->run($t[1])['skipped']);

        app(AutopilotSwitch::class)->enable($t[1], (int) $t[0]->user_id);
        app(AutopilotSwitch::class)->pause($t[1]);
        $this->assertSame('paused_by_owner', $this->runner()->run($t[1])['skipped']);

        [$core, $coreBusiness] = $this->entitledTenant(WorkspacePlanTier::Core);
        app(AutopilotSwitch::class)->enable($coreBusiness, (int) $core->user_id);
        $this->assertSame('plan', $this->runner()->run($coreBusiness)['skipped']);
        $this->assertSame('plan', ContentAutopilotSetting::query()->where('business_id', $coreBusiness->id)->first()->paused_reason);

        Bus::assertNothingDispatched();
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_the_system_never_overwrites_an_owner_pause(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $switch = app(AutopilotSwitch::class);
        $switch->enable($t[1], (int) $t[0]->user_id);
        $switch->pause($t[1]);

        $switch->markPaused($t[1], AutopilotSwitch::PAUSE_BUDGET);
        $this->assertSame('owner', ContentAutopilotSetting::query()->first()->paused_reason);

        $switch->resume($t[1]);
        $this->assertTrue($switch->isRunning($t[1]));
        $this->assertFalse($switch->disable($t[1])->enabled);
    }

    public function test_the_daily_tick_only_queues_jobs_for_businesses_that_are_on_and_not_paused(): void
    {
        Bus::fake([WriteAutopilotArticleJob::class, RunContentAutopilotJob::class]);
        $on = $this->running();
        $paused = $this->photoBoothContentTenant(withBlueprint: true);
        app(AutopilotSwitch::class)->enable($paused[1], (int) $paused[0]->user_id);
        app(AutopilotSwitch::class)->pause($paused[1]);
        $off = $this->photoBoothContentTenant(withBlueprint: true);
        ContentAutopilotSetting::query()->create(['business_id' => $off[1]->id, 'enabled' => false]);

        $this->artisan('content:autopilot-tick')->expectsOutputToContain('Queued 1 Content Autopilot run')->assertExitCode(0);

        Bus::assertDispatched(RunContentAutopilotJob::class, fn ($job) => $job->businessId === $on[1]->id && $job->delay !== null);
        Bus::assertDispatchedTimes(RunContentAutopilotJob::class, 1);
        $this->assertSame(0, $this->fake->callCount());
    }

    // ------------------------------------------------------------------ starting

    public function test_a_run_starts_at_most_one_article_and_then_waits(): void
    {
        Bus::fake([WriteAutopilotArticleJob::class, RunContentAutopilotJob::class]);
        $t = $this->running();

        $first = $this->runner()->run($t[1]);
        $this->assertStringStartsWith('started:', $first['actions'][0]);
        Bus::assertDispatched(WriteAutopilotArticleJob::class, 1);
        $this->assertSame(Decision::STATE_BRIEFED, Decision::query()->where('decision', 'create')->first()->state);

        $second = $this->runner()->run($t[1]);
        $this->assertSame(['wait:in_flight'], $second['actions']);
        Bus::assertDispatched(WriteAutopilotArticleJob::class, 1);
        $this->assertSame(0, $this->fake->callCount(), 'the run itself never calls a model');
    }

    public function test_when_nothing_is_worth_writing_nothing_is_started(): void
    {
        Bus::fake([WriteAutopilotArticleJob::class, RunContentAutopilotJob::class]);
        $t = $this->running(['avoid_topics' => "photo booth\nprints digital"]);

        $result = $this->runner()->run($t[1]);

        $this->assertSame(['decided:none:no_candidates'], $result['actions']);
        Bus::assertNothingDispatched();
        $this->assertSame(0, WebsiteArticle::query()->count());
    }

    public function test_drafts_waiting_for_the_owner_stop_new_writing(): void
    {
        Bus::fake([WriteAutopilotArticleJob::class, RunContentAutopilotJob::class]);
        $t = $this->running();
        $this->draftFor($t[1]);
        $this->draftFor($t[1], [], ['title' => 'Another waiting draft']);

        $this->assertSame(['wait:drafts_waiting'], collect($this->runner()->run($t[1])['actions'])->reject(fn ($a) => str_starts_with($a, 'waiting:'))->values()->all());
        Bus::assertNothingDispatched();
    }

    public function test_new_articles_are_spaced_out(): void
    {
        Bus::fake([WriteAutopilotArticleJob::class, RunContentAutopilotJob::class]);
        $t = $this->running();
        $this->decision($t[1], ['state' => Decision::STATE_PUBLISHED, 'evaluated_at' => now()->subDays(2)]);

        $this->assertSame(['wait:spacing'], $this->runner()->run($t[1])['actions']);

        Decision::query()->update(['evaluated_at' => now()->subDays(6)]);
        $this->assertStringStartsWith('started:', $this->runner()->run($t[1])['actions'][0]);
    }

    public function test_end_to_end_the_run_queues_the_writer_and_the_first_articles_wait_for_the_owner(): void
    {
        $t = $this->running();
        $this->allowStandardNiche();
        config(['queue.default' => 'sync']);
        $this->fake->setDefaultResult(AiCompletionResult::success($this->article(), 'fake-model', 2500, 1500));

        $result = $this->runner()->run($t[1]);

        $this->assertStringStartsWith('started:', $result['actions'][0]);
        $decision = Decision::query()->where('decision', 'create')->firstOrFail();
        $this->assertSame(Decision::STATE_AWAITING_APPROVAL, $decision->state, 'the queued writer ran and produced a draft');
        $article = WebsiteArticle::query()->findOrFail($decision->article_id);
        $this->assertSame(ArticleStatus::Draft, $article->status);

        // With zero Autopilot articles published so far nothing publishes itself (trust ramp; any finding waits regardless).
        $this->assertNotNull(app(AutopilotPublisher::class)->approvalReason($decision));
        $this->runner()->run($t[1]);
        $this->assertSame(ArticleStatus::Draft, $article->fresh()->status);
    }

    private function article(): string
    {
        $paragraph = 'Planning a photo booth for your event starts with the guest list, the venue layout and the time you want the booth open. A booth works best when guests can walk up easily, so choose a spot near the action but away from the busiest doorway. Think about how long the line may get during peak moments and leave enough room for a small group to pose together. ';

        return json_encode(['title' => 'A helpful guide', 'excerpt' => 'A practical look at planning a photo booth for your event.', 'meta_description' => 'A practical look at planning a photo booth for your event, with tips on space, timing and setup that help your guests.', 'body_markdown' => "## What to plan first\n\n" . str_repeat($paragraph, 7) . "\n"]);
    }

    // ------------------------------------------------------------------ publishing rules

    public function test_a_draft_publishes_itself_only_when_every_rule_agrees(): void
    {
        $t = $this->running();
        $publisher = app(AutopilotPublisher::class);

        // Unknown niche: fail closed.
        $d = $this->draftFor($t[1]);
        $this->assertSame(AutopilotPublisher::WAIT_NICHE_POLICY, $publisher->approvalReason($d));

        $this->allowStandardNiche();
        $publisher = app(AutopilotPublisher::class);

        // Standard + allowed, but the trust ramp is not over.
        $this->assertSame(AutopilotPublisher::WAIT_TRUST_RAMP, $publisher->approvalReason($d));
        $this->rampOver($t[1]);
        $this->assertNull($publisher->approvalReason($d));

        // Any finding at all keeps it with the owner.
        $d->forceFill(['validation' => ['hard' => [], 'soft' => ['A percentage needs a source.'], 'repaired' => false, 'ready' => false]])->save();
        $this->assertSame(AutopilotPublisher::WAIT_NOT_READY, $publisher->approvalReason($d));
    }

    public function test_sensitive_and_regulated_niches_never_publish_themselves(): void
    {
        $t = $this->running();
        $this->rampOver($t[1]);
        $d = $this->draftFor($t[1]);

        foreach ([['risk_tier' => 'sensitive', 'auto_publish' => 'approval_required'], ['risk_tier' => 'regulated', 'auto_publish' => 'never'], ['risk_tier' => 'standard', 'auto_publish' => 'never']] as $policy) {
            $reader = $this->partialMock(BlueprintConfigReader::class, fn ($m) => $m->shouldReceive('seoStrategy')->andReturn(['content_policy' => $policy]));
            $this->app->instance(BlueprintConfigReader::class, $reader);

            $this->assertSame(AutopilotPublisher::WAIT_NICHE_POLICY, app(AutopilotPublisher::class)->approvalReason($d), json_encode($policy));
        }
    }

    public function test_a_self_publishing_article_is_scheduled_on_a_weekday_morning_and_goes_live_through_publish_due(): void
    {
        $t = $this->running();
        $this->allowStandardNiche();
        $this->rampOver($t[1]);
        DB::table('businesses')->where('id', $t[1]->id)->update(['timezone' => 'America/Chicago']);
        $business = $t[1]->fresh();
        $d = $this->draftFor($business);
        $publisher = app(AutopilotPublisher::class);

        $this->assertNull($publisher->scheduleIfAllowed($d));

        $article = $d->fresh()->article;
        $this->assertSame(ArticleStatus::Scheduled, $article->status);
        $this->assertSame(Decision::STATE_SCHEDULED, $d->fresh()->state);

        $local = CarbonImmutable::instance($article->scheduled_at)->setTimezone('America/Chicago');
        $this->assertFalse($local->isWeekend());
        $this->assertGreaterThanOrEqual(9, $local->hour);
        $this->assertLessThan(12, $local->hour);

        // It goes live through the existing publish-due, which re-checks it first.
        $result = app(ArticleManager::class)->publishDue($article->scheduled_at->copy()->addMinute());
        $this->assertSame(1, $result['published']);
        $this->assertSame(ArticleStatus::Published, $article->fresh()->status);
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_self_published_articles_are_never_closer_together_than_the_cadence(): void
    {
        $t = $this->running();
        $this->allowStandardNiche();
        $this->rampOver($t[1]);
        $publisher = app(AutopilotPublisher::class);

        $first = $this->draftFor($t[1]);
        $second = $this->draftFor($t[1], [], ['title' => 'A second article']);
        $this->assertNull($publisher->scheduleIfAllowed($first));
        $this->assertNull($publisher->scheduleIfAllowed($second));

        $a = $first->fresh()->article->scheduled_at;
        $b = $second->fresh()->article->scheduled_at;
        $this->assertGreaterThanOrEqual(5, $a->diffInDays($b, true) , 'at least the configured spacing apart');
    }

    // ------------------------------------------------------------------ reconcile and resume

    public function test_decisions_follow_what_the_owner_does_with_the_article(): void
    {
        Bus::fake([WriteAutopilotArticleJob::class, RunContentAutopilotJob::class]);
        $t = $this->running(['avoid_topics' => "photo booth\nprints digital"]);
        $published = $this->draftFor($t[1]);
        $archived = $this->draftFor($t[1], [], ['title' => 'To be archived']);
        $returned = $this->draftFor($t[1], ['state' => Decision::STATE_SCHEDULED], ['title' => 'Returned to draft']);

        $owner = (int) $t[0]->user_id;
        app(ArticleManager::class)->publish($owner, $t[1], $published->article);
        app(ArticleManager::class)->archive($owner, $t[1], $archived->article);

        $this->runner()->run($t[1]);

        $this->assertSame(Decision::STATE_PUBLISHED, $published->fresh()->state);
        $this->assertSame(Decision::STATE_RESOLVED, $archived->fresh()->state);
        $this->assertSame([Decision::STATE_AWAITING_APPROVAL, 'returned_to_draft'], [$returned->fresh()->state, $returned->fresh()->reason_code], 'never re-scheduled on its own');
    }

    public function test_budget_deferred_work_resumes_only_when_the_period_rolls_over(): void
    {
        Bus::fake([WriteAutopilotArticleJob::class, RunContentAutopilotJob::class]);
        $t = $this->running();
        $same = $this->decision($t[1], ['state' => Decision::STATE_DEFERRED_BUDGET, 'article_id' => null, 'validation' => null]);

        $result = $this->runner()->run($t[1]);

        $this->assertSame([], $result['actions']);
        Bus::assertNothingDispatched();
        $this->assertSame('budget', ContentAutopilotSetting::query()->first()->paused_reason, 'the owner can see why it is quiet');
        $this->assertSame(Decision::STATE_DEFERRED_BUDGET, $same->fresh()->state);

        $same->forceFill(['period_key' => now('UTC')->subMonth()->format('Y-m')])->save();
        $this->runner()->run($t[1]);

        Bus::assertDispatched(WriteAutopilotArticleJob::class, fn ($job) => $job->decisionId === $same->id);
        $this->assertSame(Decision::STATE_BRIEFED, $same->fresh()->state);
        $this->assertNull(ContentAutopilotSetting::query()->first()->paused_reason);
    }

    public function test_stuck_and_failed_writes_are_retried_a_few_times_and_then_given_up(): void
    {
        Bus::fake([WriteAutopilotArticleJob::class, RunContentAutopilotJob::class]);
        $t = $this->running();
        $stale = $this->decision($t[1], ['state' => Decision::STATE_DRAFTING, 'article_id' => null, 'validation' => null]);
        Decision::query()->whereKey($stale->id)->update(['updated_at' => now()->subHours(2)]);

        $this->runner()->run($t[1]);
        $this->assertSame(Decision::STATE_BRIEFED, $stale->fresh()->state, 'a dead worker is released');

        $stale->forceFill(['reason_code' => 'ai_unavailable'])->save();
        Decision::query()->whereKey($stale->id)->update(['updated_at' => now()->subHours(7)]);
        $this->runner()->run($t[1]);
        Bus::assertDispatched(WriteAutopilotArticleJob::class, fn ($job) => $job->decisionId === $stale->id);

        foreach ([1, 2, 3] as $n) {
            AiUsageLedgerEntry::query()->create([
                'uid' => (string) Str::uuid(), 'workspace_id' => $t[2]->id, 'business_id' => $t[1]->id, 'category' => 'content_autopilot', 'lane' => 'product',
                'model_route' => 'content_writer', 'provider' => 'openai', 'price_version' => 1, 'status' => 'released', 'period_key' => now('UTC')->format('Y-m'),
                'idempotency_key' => 'content_autopilot:' . $stale->uid . ':draft:' . $n, 'estimated_cost_microusd' => 1, 'actual_cost_microusd' => null,
            ]);
        }

        Decision::query()->whereKey($stale->id)->update(['updated_at' => now()->subHours(7)]);
        $this->runner()->run($t[1]);
        $this->assertSame([Decision::STATE_HELD, 'ai_unavailable_gave_up'], [$stale->fresh()->state, $stale->fresh()->reason_code]);
    }
}
