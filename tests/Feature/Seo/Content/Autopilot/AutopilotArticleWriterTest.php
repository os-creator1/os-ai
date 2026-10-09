<?php

namespace Tests\Feature\Seo\Content\Autopilot;

use App\Enums\Seo\ArticleStatus;
use App\Jobs\Seo\WriteAutopilotArticleJob;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Seo\Content\Autopilot\AutopilotArticleWriter;
use App\Library\Seo\Content\Autopilot\AutopilotPlanner;
use App\Library\Seo\Content\Autopilot\ContentProfile;
use App\Models\AiUsageLedgerEntry;
use App\Models\ContentAutopilotDecision as Decision;
use App\Models\WebsiteArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Content Autopilot, Slice 5 - brief-driven drafting and deterministic validation. The writer creates an ordinary Draft
 * through ArticleManager and never publishes; it spends only after checking the Business's budget; it repairs at most once.
 */
class AutopilotArticleWriterTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private FakeAiCompletionClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.active' => true, 'ai.enforce_budgets_for_existing_categories' => false]);
        $this->fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $this->fake);
        Http::preventStrayRequests();
    }

    /** @return array{0: array, 1: Decision} tenant + a briefed decision for the best eligible topic */
    private function briefed(array $profile = []): array
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        app(ContentProfile::class)->save($t[1], (int) $t[0]->user_id, $profile + [
            'differentiators' => 'Same-day setup',
            'common_questions' => "How much space does a photo booth need?\nHow much does a photo booth cost?",
        ]);

        $result = app(AutopilotPlanner::class)->evaluate($t[1]);
        $this->assertSame('create', $result['decision']);

        return [$t, $result['record']];
    }

    /** An article reply long enough to satisfy the brief, free of unsupported claims, linking the first allowed reference. */
    private function reply(Decision $decision, string $extra = '', ?int $paragraphs = 7): string
    {
        $paragraph = 'Planning a photo booth for your event starts with the guest list, the venue layout and the time you want the booth open. A booth works best when guests can walk up easily, so choose a spot near the action but away from the busiest doorway. Think about how long the line may get during peak moments and leave enough room for a small group to pose together. ';
        $link = $decision->brief['links'][0] ?? null;
        $body = "## What to plan first\n\n" . str_repeat($paragraph, $paragraphs) . ($link !== null ? "\n\nSee our [{$link['anchor']}]({$link['type']}:{$link['uid']}) for options." : '') . $extra . "\n";

        return json_encode([
            'title' => 'A helpful guide',
            'excerpt' => 'A practical look at planning a photo booth for your event.',
            'meta_description' => 'A practical look at planning a photo booth for your event, with tips on space, timing and setup that help your guests.',
            'body_markdown' => $body,
        ]);
    }

    private function queue(string $content): void
    {
        $this->fake->queueResult(AiCompletionResult::success($content, 'fake-model', 2500, 1500));
    }

    private function writer(): AutopilotArticleWriter
    {
        return app(AutopilotArticleWriter::class);
    }

    public function test_a_clean_draft_becomes_an_ordinary_draft_article_awaiting_approval(): void
    {
        [$t, $decision] = $this->briefed();
        $this->queue($this->reply($decision));

        $done = $this->writer()->write($decision);

        $this->assertSame(Decision::STATE_AWAITING_APPROVAL, $done->state);
        $this->assertSame('drafted_ready', $done->reason_code);
        $this->assertTrue($done->validation['ready']);

        $article = WebsiteArticle::query()->findOrFail($done->article_id);
        $this->assertSame(ArticleStatus::Draft, $article->status, 'never published by the writer');
        $this->assertSame('autopilot', $article->source);
        $this->assertTrue($article->ai_generated);
        $this->assertSame($decision->opportunity_key, $article->opportunity_key);
        $this->assertSame($decision->brief['supports']['uid'], $article->supports_page_uid);
        $this->assertSame($decision->brief['topic'], $article->title);

        $this->assertSame(1, $this->fake->callCount());
        $entry = AiUsageLedgerEntry::query()->where('business_id', $t[1]->id)->firstOrFail();
        $this->assertSame(AiModelRoute::ContentWriter, $entry->model_route, 'the article is written by the strong route');
        $this->assertSame('content_autopilot:' . $decision->uid . ':draft:1', $entry->idempotency_key);
        $this->assertGreaterThan(0, $done->cost_microusd);
        $this->assertSame((int) $entry->actual_cost_microusd, $done->cost_microusd);
    }

    public function test_the_prompt_carries_only_the_brief_facts_and_its_rules(): void
    {
        [, $decision] = $this->briefed();
        $this->queue($this->reply($decision));
        $this->writer()->write($decision);

        $messages = $this->fake->requests()[0]->messages;
        $user = json_decode($messages[1]['content'], true);

        $this->assertStringContainsString('BRIEF:', $messages[0]['content']);
        $this->assertStringContainsString('ONLY the business facts provided', $messages[0]['content'], 'the Content Engine grounding rules are reused');
        $this->assertEquals($decision->brief['facts'], $user['facts']);
        $this->assertSame($decision->brief['questions'], $user['questions']);
        $this->assertEquals($decision->brief['must_not'], $user['must_not']);
        $this->assertArrayNotHasKey('business', $user, 'the writer never sees the whole Business');
    }

    public function test_a_hard_finding_is_repaired_once_and_the_repair_prompt_names_it(): void
    {
        [, $decision] = $this->briefed();
        $this->queue($this->reply($decision, "\n\nOur flagship package is only \$777 for the night."));
        $this->queue($this->reply($decision));

        $done = $this->writer()->write($decision);

        $this->assertSame(Decision::STATE_AWAITING_APPROVAL, $done->state);
        $this->assertTrue($done->validation['repaired']);
        $this->assertSame(2, $this->fake->callCount());
        $this->assertStringContainsString('REJECTED', $this->fake->requests()[1]->messages[0]['content']);
        $this->assertStringContainsString('777', $this->fake->requests()[1]->messages[0]['content']);
        $this->assertStringNotContainsString('777', WebsiteArticle::query()->findOrFail($done->article_id)->body);
        $this->assertSame(1, WebsiteArticle::query()->count());
    }

    public function test_a_draft_that_still_fails_after_one_repair_is_rejected_and_creates_nothing(): void
    {
        [, $decision] = $this->briefed();
        $bad = $this->reply($decision, "\n\nOur flagship package is only \$777 for the night.");
        $this->queue($bad);
        $this->queue($bad);
        $this->queue($bad);

        $done = $this->writer()->write($decision);

        $this->assertSame(Decision::STATE_REJECTED, $done->state);
        $this->assertSame('validation_failed', $done->reason_code);
        $this->assertNull($done->article_id);
        $this->assertSame(0, WebsiteArticle::query()->count());
        $this->assertSame(2, $this->fake->callCount(), 'one draft and at most one repair');
        $this->assertNotEmpty($done->validation['hard']);

        // And the topic is not tried again for the cool-down.
        $again = app(AutopilotPlanner::class)->evaluate($decision->business, false);
        $this->assertNotSame($decision->opportunity_key, $again['selected']['key'] ?? null);
    }

    public function test_a_soft_finding_still_drafts_but_is_not_ready(): void
    {
        [, $decision] = $this->briefed();
        $this->queue($this->reply($decision, "\n\nMost couples add 47 extra prints."));

        $done = $this->writer()->write($decision);

        $this->assertSame(Decision::STATE_AWAITING_APPROVAL, $done->state);
        $this->assertSame('drafted_needs_review', $done->reason_code);
        $this->assertFalse($done->validation['ready']);
        $this->assertStringContainsString('47', implode(' ', $done->validation['soft']));
        $this->assertNotNull($done->article_id);
    }

    public function test_unusable_output_costs_one_repair_not_a_loop(): void
    {
        [, $decision] = $this->briefed();
        $this->queue('this is not json');
        $this->queue($this->reply($decision));

        $done = $this->writer()->write($decision);

        $this->assertSame(Decision::STATE_AWAITING_APPROVAL, $done->state);
        $this->assertSame(2, $this->fake->callCount());

        [, $other] = $this->briefed();
        $this->queue('still not json');
        $this->queue('{"nope":true}');
        $this->assertSame(Decision::STATE_REJECTED, $this->writer()->write($other)->state);
        $this->assertSame(4, $this->fake->callCount());
    }

    public function test_the_owners_prohibited_phrase_is_a_hard_finding(): void
    {
        [$t, $decision] = $this->briefed();
        app(BusinessKnowledgeProfileManager::class)->updateFields($t[1], ['prohibited_claims' => ['VIP treatment']], 'manual_edit', (int) $t[0]->user_id, true);
        $bad = $this->reply($decision, "\n\nEvery guest gets VIP treatment.");
        $this->queue($bad);
        $this->queue($bad);

        $done = $this->writer()->write($decision);

        $this->assertSame(Decision::STATE_REJECTED, $done->state);
        $this->assertStringContainsString('VIP treatment', implode(' ', $done->validation['hard']));
    }

    public function test_the_owners_confirmed_years_may_be_written(): void
    {
        [$t, $decision] = $this->briefed();
        app(BusinessKnowledgeProfileManager::class)->updateFields($t[1], ['years_operating' => 12], 'manual_edit', (int) $t[0]->user_id, true, ['years_operating']);
        $this->queue($this->reply($decision, "\n\nWith 12 years of experience we know what works."));

        $done = $this->writer()->write($decision);

        $this->assertSame(Decision::STATE_AWAITING_APPROVAL, $done->state);
        $this->assertSame([], $done->validation['hard']);
    }

    public function test_the_business_budget_is_checked_before_any_call_and_defers_rather_than_spends(): void
    {
        [, $decision] = $this->briefed();
        config(['ai.business_category_ceilings.content_autopilot.hard_ceiling_microusd' => 1_000]);

        $done = $this->writer()->write($decision);

        $this->assertSame(Decision::STATE_DEFERRED_BUDGET, $done->state);
        $this->assertSame('budget_ceiling', $done->reason_code);
        $this->assertSame(0, $this->fake->callCount());
        $this->assertSame(0, AiUsageLedgerEntry::query()->count());

        // The next period (a larger ceiling stands in for it) resumes the same decision.
        config(['ai.business_category_ceilings.content_autopilot.hard_ceiling_microusd' => 1_000_000]);
        $this->queue($this->reply($decision));
        $this->assertSame(Decision::STATE_AWAITING_APPROVAL, $this->writer()->write($done)->state);
    }

    public function test_a_provider_outage_goes_back_to_briefed_and_retries_with_a_fresh_key(): void
    {
        [, $decision] = $this->briefed();
        $this->fake->queueResult(AiCompletionResult::failure());

        $first = $this->writer()->write($decision);
        $this->assertSame([Decision::STATE_BRIEFED, 'ai_unavailable'], [$first->state, $first->reason_code]);

        $this->queue($this->reply($decision));
        $second = $this->writer()->write($first);
        $this->assertSame(Decision::STATE_AWAITING_APPROVAL, $second->state);

        $keys = AiUsageLedgerEntry::query()->orderBy('id')->pluck('idempotency_key')->all();
        $this->assertSame(['content_autopilot:' . $decision->uid . ':draft:1', 'content_autopilot:' . $decision->uid . ':draft:2'], $keys);
    }

    public function test_writing_is_idempotent_and_a_paid_attempt_is_never_repeated(): void
    {
        [, $decision] = $this->briefed();
        $this->queue($this->reply($decision));
        $done = $this->writer()->write($decision);

        $this->assertSame($done->article_id, $this->writer()->write($done)->article_id);
        (new WriteAutopilotArticleJob($done->id))->handle($this->writer());
        $this->assertSame(1, $this->fake->callCount());
        $this->assertSame(1, WebsiteArticle::query()->count());

        // A crashed worker: the call was paid for but no article was saved. Never pay for the same step twice.
        [$t, $lost] = $this->briefed();
        AiUsageLedgerEntry::query()->create([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'workspace_id' => $t[2]->id, 'business_id' => $t[1]->id,
            'category' => 'content_autopilot', 'lane' => 'product', 'model_route' => 'content_writer', 'provider' => 'openai',
            'price_version' => 1, 'status' => 'committed', 'period_key' => now('UTC')->format('Y-m'),
            'idempotency_key' => 'content_autopilot:' . $lost->uid . ':draft:1', 'estimated_cost_microusd' => 40000, 'actual_cost_microusd' => 30000,
        ]);
        $held = $this->writer()->write($lost);

        $this->assertSame([Decision::STATE_HELD, 'draft_paid_no_result'], [$held->state, $held->reason_code]);
        $this->assertSame(1, $this->fake->callCount());
    }
}
