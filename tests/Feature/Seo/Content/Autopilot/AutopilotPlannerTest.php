<?php

namespace Tests\Feature\Seo\Content\Autopilot;

use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Library\Seo\Content\Autopilot\AutopilotPlanner;
use App\Library\Seo\Content\Autopilot\AutopilotScorer;
use App\Library\Seo\Content\Autopilot\ContentProfile;
use App\Models\ContentAutopilotDecision;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Content Autopilot, Slice 4 - the deterministic decision step: score, brief, ask once, hold, or do nothing. None of it
 * ever calls a model.
 */
class AutopilotPlannerTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private FakeAiCompletionClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $this->fake);
        Http::preventStrayRequests();
        config(['seo.content_autopilot.max_new_articles_per_month' => 4]);
    }

    private function planner(): AutopilotPlanner
    {
        return app(AutopilotPlanner::class);
    }

    /** Enough facts that a topic can be written from (two answers + the fixture's priced packages). */
    private function giveFacts(array $tenant, array $extra = []): void
    {
        app(ContentProfile::class)->save($tenant[1], (int) $tenant[0]->user_id, $extra + [
            'differentiators' => 'Same-day setup',
            'common_questions' => "How much space does a photo booth need?\nHow much does a photo booth cost?",
        ]);
    }

    public function test_no_website_means_do_nothing_and_never_a_model_call(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        Website::query()->where('business_id', $business->id)->forceDelete();

        $result = $this->planner()->evaluate($business);

        $this->assertSame(['none', AutopilotPlanner::REASON_NO_WEBSITE], [$result['decision'], $result['reason']]);
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_with_no_facts_it_asks_the_owner_for_exactly_one_thing_and_only_once(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);

        $first = $this->planner()->evaluate($t[1]);

        $this->assertSame('needs_input', $first['decision']);
        $this->assertSame('common_questions', $first['record']->needs_input['key']);
        $this->assertNotSame('', $first['record']->needs_input['prompt']);
        $this->assertSame(ContentAutopilotDecision::STATE_NEEDS_INPUT, $first['record']->state);

        // Still unanswered: no second question, no spam - the open one stands.
        $second = $this->planner()->evaluate($t[1]);
        $this->assertNotSame('needs_input', $second['decision']);
        $this->assertSame(1, ContentAutopilotDecision::query()->where('state', 'needs_input')->count());
        $this->assertSame($first['record']->id, $this->planner()->openQuestion($t[1])->id);

        // Answered: the question closes itself and the same topics become eligible.
        $this->giveFacts($t);
        $third = $this->planner()->evaluate($t[1]);

        $this->assertSame('create', $third['decision']);
        $this->assertNull($this->planner()->openQuestion($t[1]));
        $this->assertSame(ContentAutopilotDecision::STATE_RESOLVED, $first['record']->fresh()->state);
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_an_eligible_topic_gets_a_structured_brief_from_real_facts_only(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->giveFacts($t, ['emphasis' => 'Weddings']);

        $result = $this->planner()->evaluate($t[1]);
        $decision = $result['record'];

        $this->assertSame('create', $result['decision']);
        $this->assertSame(ContentAutopilotDecision::STATE_BRIEFED, $decision->state);
        $this->assertGreaterThanOrEqual(70, $decision->score);
        $this->assertSame($decision->score, array_sum($decision->score_breakdown['factors']));

        $brief = $decision->brief;
        foreach (['topic', 'intent', 'supports', 'cta', 'facts', 'questions', 'links', 'must_not', 'niche', 'length', 'fact_hash', 'brief_hash'] as $key) {
            $this->assertArrayHasKey($key, $brief);
        }

        $this->assertSame('Jazmin Photo Booth Co.', $brief['facts']['business_name']);
        $this->assertNotEmpty($brief['questions']);
        $this->assertLessThanOrEqual(6, count($brief['questions']));
        $this->assertContains('Same-day setup', $brief['facts']['differentiators']);
        $this->assertNotEmpty($brief['must_not']['invent']);
        $this->assertSame($decision->brief_hash, $brief['brief_hash']);

        // A cost topic may state the real prices; any other kind of topic gets package names only.
        if ($brief['intent'] === 'cost') {
            $this->assertNotEmpty(array_filter($brief['facts']['packages'], fn (array $p) => isset($p['price'])), 'the real prices are available to a cost article');
        }
    }

    public function test_a_non_cost_brief_carries_no_prices(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->giveFacts($t);
        $rows = $this->planner()->evaluate($t[1], false)['ranked'];
        $topic = collect($rows)->first(fn ($s) => $s['band'] === 'eligible' && $s['intent'] !== 'cost' && ! str_contains(strtolower((string) ($s['opportunity']['supports_page_title'] ?? '')), 'package'));
        $this->assertNotNull($topic, 'the fixture offers at least one eligible non-cost topic');

        $brief = app(\App\Library\Seo\Content\Autopilot\ArticleBriefBuilder::class)->build($t[1], $topic, app(\App\Library\Seo\Content\Autopilot\ContentFactPack::class)->forBusiness($t[1]));

        foreach ($brief['facts']['packages'] ?? [] as $package) {
            $this->assertArrayNotHasKey('price', $package);
        }
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->giveFacts($t);

        $result = $this->planner()->evaluate($t[1], persist: false);

        $this->assertSame('create', $result['decision']);
        $this->assertNull($result['record']);
        $this->assertSame(0, ContentAutopilotDecision::query()->count());
    }

    public function test_a_topic_in_progress_is_never_chosen_twice_and_the_monthly_maximum_is_a_ceiling(): void
    {
        config(['seo.content_autopilot.max_new_articles_per_month' => 2]);
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->giveFacts($t);

        $a = $this->planner()->evaluate($t[1]);
        $b = $this->planner()->evaluate($t[1]);
        $c = $this->planner()->evaluate($t[1]);

        $this->assertSame('create', $a['decision']);
        $this->assertSame('create', $b['decision']);
        $this->assertNotSame($a['selected']['key'], $b['selected']['key'], 'the topic already in progress is skipped');
        $this->assertSame(['none', AutopilotPlanner::REASON_MONTHLY_CEILING], [$c['decision'], $c['reason']]);
        $this->assertTrue(collect($c['ranked'])->contains(fn ($s) => $s['blocked']), 'the topics in progress are shown as such');
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_a_maximum_of_zero_produces_nothing_at_all(): void
    {
        config(['seo.content_autopilot.max_new_articles_per_month' => 0]);
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->giveFacts($t);

        $this->assertSame('none', $this->planner()->evaluate($t[1])['decision']);
        $this->assertSame(0, ContentAutopilotDecision::query()->where('decision', 'create')->count());
    }

    public function test_doing_nothing_is_a_successful_decision_when_nothing_is_worth_writing(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->giveFacts($t, ['avoid_topics' => "photo booth
prints digital"]);

        $result = $this->planner()->evaluate($t[1]);

        $this->assertSame(['none', AutopilotPlanner::REASON_NO_CANDIDATES], [$result['decision'], $result['reason']]);
        $this->assertTrue(collect($result['ranked'])->every(fn ($s) => $s['disqualified'] === 'owner_avoids_topic'));

        // The same answer for the same reason is recorded once, not every tick.
        $this->planner()->evaluate($t[1]);
        $this->assertSame(1, ContentAutopilotDecision::query()->where('decision', 'none')->count());
    }

    public function test_covered_prohibited_and_unpriceable_topics_are_never_selected(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->giveFacts($t);
        $first = $this->planner()->evaluate($t[1], false)['selected'];

        $this->draftArticle($t[1], ['title' => $first['title'], 'primary_topic' => $first['opportunity']['primary_topic'], 'opportunity_key' => $first['key']]);
        $ranked = collect($this->planner()->evaluate($t[1], false)['ranked']);
        $this->assertSame('already_covered', $ranked->firstWhere('key', $first['key'])['disqualified']);

        // A phrase the owner prohibits disqualifies a topic that contains it.
        app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->updateFields($t[1], ['prohibited_claims' => ['room']], 'manual_edit', (int) $t[0]->user_id, true);
        $ranked = collect($this->planner()->evaluate($t[1], false)['ranked']);
        $this->assertSame('prohibited_claim', $ranked->first(fn ($s) => str_contains(strtolower($s['title']), 'room'))['disqualified']);
    }

    public function test_a_rejected_topic_is_not_retried_until_the_cooldown_passes(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->giveFacts($t);
        $now = Carbon::parse('2026-11-10 09:00:00', 'UTC');

        $picked = $this->planner()->evaluate($t[1], true, $now);
        $picked['record']->forceFill(['state' => ContentAutopilotDecision::STATE_REJECTED])->save();

        $soon = $this->planner()->evaluate($t[1], false, $now->copy()->addDays(30));
        $this->assertNotSame($picked['selected']['key'], $soon['selected']['key'] ?? null);

        $later = $this->planner()->evaluate($t[1], false, $now->copy()->addDays(91));
        $this->assertSame($picked['selected']['key'], $later['selected']['key'], 'after the cool-down it may be tried again');
    }

    public function test_seasonality_keeps_out_of_season_topics_on_hold(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->giveFacts($t);

        $topic = fn (array $months) => ['title' => 'How much does a photo booth rental cost in {city}?', 'intent' => 'cost', 'per' => 'city', 'supports' => 'packages', 'cluster' => 'Pricing', 'months' => $months];
        $reader = fn (array $months) => $this->partialMock(BlueprintConfigReader::class, fn ($m) => $m->shouldReceive('seoStrategy')->andReturn(['content_topics' => [$topic($months)]]));

        $this->app->instance(BlueprintConfigReader::class, $reader([5]));
        $june = Carbon::parse('2026-06-10', 'UTC');
        $inSeason = app(AutopilotScorer::class)->rank($t[1], null, Carbon::parse('2026-04-10', 'UTC'));
        $outOfSeason = app(AutopilotScorer::class)->rank($t[1], null, $june);

        $cost = fn (array $rows) => collect($rows)->first(fn ($s) => $s['intent'] === 'cost');
        $this->assertSame([], $cost($inSeason)['flags']);
        $this->assertSame(5, $cost($inSeason)['breakdown']['season']);
        $this->assertContains('out_of_season', $cost($outOfSeason)['flags']);
        $this->assertSame('hold', $cost($outOfSeason)['band']);
    }

    public function test_the_score_is_a_sum_of_bounded_factors_that_cannot_exceed_100(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->giveFacts($t, ['emphasis' => 'Weddings']);

        foreach ($this->planner()->evaluate($t[1], false)['ranked'] as $scored) {
            if ($scored['disqualified'] !== null) {
                continue;
            }

            $this->assertSame($scored['score'], array_sum($scored['breakdown']));
            $this->assertLessThanOrEqual(100, $scored['score']);
            foreach (['relevance' => 20, 'usefulness' => 15, 'support' => 15, 'gap' => 15, 'facts' => 15, 'rank' => 10, 'season' => 5, 'links' => 5] as $factor => $max) {
                $this->assertLessThanOrEqual($max, $scored['breakdown'][$factor], $factor);
            }
        }
    }

    public function test_the_command_is_a_dry_run_unless_asked_to_persist(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->giveFacts($t);

        $this->artisan('content:autopilot-evaluate', ['business' => (string) $t[1]->id])->expectsOutputToContain('dry run')->assertExitCode(0);
        $this->assertSame(0, ContentAutopilotDecision::query()->count());

        $this->artisan('content:autopilot-evaluate', ['business' => (string) $t[1]->id, '--persist' => true])->expectsOutputToContain('recorded')->assertExitCode(0);
        $this->assertSame(1, ContentAutopilotDecision::query()->count());
        $this->assertSame(0, $this->fake->callCount());
    }
}
