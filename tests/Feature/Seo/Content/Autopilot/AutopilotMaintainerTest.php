<?php

namespace Tests\Feature\Seo\Content\Autopilot;

use App\Enums\Seo\ArticleStatus;
use App\Jobs\Seo\MaintainAutopilotArticlesJob;
use App\Jobs\Seo\WriteAutopilotArticleJob;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Library\Seo\Content\ArticleManager;
use App\Library\Seo\Content\Autopilot\AutopilotMaintainer;
use App\Library\Seo\Content\Autopilot\AutopilotArticleWriter;
use App\Library\Seo\Content\Autopilot\AutopilotSwitch;
use App\Library\Seo\Content\Autopilot\ContentProfile;
use App\Models\Business;
use App\Models\CatalogItem;
use App\Models\ContentAutopilotDecision as Decision;
use App\Models\ContentAutopilotSetting;
use App\Models\WebsiteArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Content Autopilot, Slice 8 - weekly maintenance of existing articles: leave alone by default, free related links, one
 * budget-capped rewrite when facts changed, suggestions (never actions) for duplicates and dead pages.
 */
class AutopilotMaintainerTest extends TestCase
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
        Bus::fake([WriteAutopilotArticleJob::class, MaintainAutopilotArticlesJob::class]);
    }

    private function maintainer(): AutopilotMaintainer
    {
        return app(AutopilotMaintainer::class);
    }

    private function running(): array
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        app(ContentProfile::class)->save($t[1], (int) $t[0]->user_id, ['differentiators' => 'Same-day setup', 'common_questions' => 'How much space does a photo booth need?']);
        app(AutopilotSwitch::class)->enable($t[1], (int) $t[0]->user_id);

        return $t;
    }

    /** A published article; `own` makes it one Autopilot wrote. Its body links to $links of the fixture pages. */
    private function live(Business $business, string $title, string $topic, bool $own = true, int $links = 1, array $extra = []): WebsiteArticle
    {
        $pages = [self::PAGE_WEDDING, self::PAGE_CORPORATE, self::PAGE_360];
        $refs = implode(' ', array_map(fn ($uid) => "[a page](page:{$uid})", array_slice($pages, 0, $links)));
        $body = $this->goodBody() . "\n\nLearn more: {$refs}\n";
        $article = $this->publishedArticle($business, $extra + ['title' => $title, 'primary_topic' => $topic, 'body' => $body]);
        $article->forceFill(['source' => $own ? 'autopilot' : 'manual', 'ai_generated' => $own])->save();

        return $article->fresh();
    }

    private function allowStandardNiche(): void
    {
        $reader = $this->partialMock(BlueprintConfigReader::class, fn ($m) => $m->shouldReceive('seoStrategy')->andReturn(['content_policy' => ['risk_tier' => 'standard', 'auto_publish' => 'allowed']]));
        $this->app->instance(BlueprintConfigReader::class, $reader);
    }

    private function reply(array $brief): string
    {
        $paragraph = 'Planning a photo booth for your event starts with the guest list, the venue layout and the time you want the booth open. A booth works best when guests can walk up easily, so choose a spot near the action but away from the busiest doorway. Think about how long the line may get during peak moments and leave enough room for a small group to pose together. ';
        $link = $brief['links'][0] ?? null;

        return json_encode([
            'title' => 'Updated guide', 'excerpt' => 'An updated look at planning a photo booth for your event.',
            'meta_description' => 'An updated look at planning a photo booth for your event, with current tips on space, timing and setup that help your guests.',
            'body_markdown' => "## What to plan first\n\n" . str_repeat($paragraph, 7) . ($link !== null ? "\n\nSee our [{$link['anchor']}]({$link['type']}:{$link['uid']}) for options." : '') . "\n",
        ]);
    }

    // ---------------------------------------------------------------- gates and the default

    public function test_nothing_happens_when_autopilot_is_off_or_paused(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->live($t[1], 'First useful article', 'first useful topic');

        $this->assertSame('off', $this->maintainer()->run($t[1])['skipped']);

        app(AutopilotSwitch::class)->enable($t[1], (int) $t[0]->user_id);
        app(AutopilotSwitch::class)->pause($t[1]);
        $this->assertSame('paused_by_owner', $this->maintainer()->run($t[1])['skipped']);
        $this->assertSame(0, Decision::query()->count());
    }

    public function test_an_article_that_needs_nothing_is_left_alone_and_not_reviewed_again_for_a_while(): void
    {
        $t = $this->running();
        $article = $this->live($t[1], 'Well linked article', 'well linked topic', links: 3);
        $before = $article->body;

        $first = $this->maintainer()->run($t[1]);
        $this->assertSame(['leave:' . $article->uid], $first['actions']);
        $this->assertSame('reviewed_unchanged', Decision::query()->where('kind', 'maintain')->first()->reason_code);
        $this->assertSame($before, $article->fresh()->body);
        $this->assertFalse($article->fresh()->hasPendingDraft());

        $this->assertSame([], $this->maintainer()->run($t[1], now()->addDays(30))['actions'], 'recently reviewed');
        $this->assertSame(1, Decision::query()->where('kind', 'maintain')->count());

        $this->assertSame(['leave:' . $article->uid], $this->maintainer()->run($t[1], now()->addDays(61))['actions'], 'reviewed again after the cool-down');
        $this->assertSame(0, $this->fake->callCount(), 'maintenance reviews are free');
        Bus::assertNotDispatched(WriteAutopilotArticleJob::class);
    }

    // ---------------------------------------------------------------- related links (free)

    public function test_an_autopilot_article_that_links_to_little_gets_related_reading_as_a_pending_draft(): void
    {
        $t = $this->running();
        $own = $this->live($t[1], 'Thinly linked article', 'thinly linked topic', links: 1);
        $theirs = $this->live($t[1], 'Owner written article', 'owner written topic', own: false, links: 1);

        $result = $this->maintainer()->run($t[1]);

        $this->assertContains('links:' . $own->uid, $result['actions']);
        $this->assertContains('leave:' . $theirs->uid, $result['actions'], 'an article the owner wrote is never edited');

        $own = $own->fresh();
        $this->assertTrue($own->hasPendingDraft());
        $this->assertStringContainsString('## Related reading', $own->draft_payload['body']);
        $this->assertStringNotContainsString('Related reading', $own->body, 'the live article is untouched until "Publish update"');
        $this->assertFalse($theirs->fresh()->hasPendingDraft());

        $decision = Decision::query()->where('article_id', $own->id)->firstOrFail();
        $this->assertSame([Decision::STATE_AWAITING_APPROVAL, 'links_added', true], [$decision->state, $decision->reason_code, $decision->validation['ready']]);
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_a_validated_update_is_applied_only_when_the_niche_and_trust_ramp_allow_it(): void
    {
        $t = $this->running();
        $own = $this->live($t[1], 'Thinly linked article', 'thinly linked topic', links: 1);
        $this->maintainer()->run($t[1]);

        // Unknown niche: the update waits for the owner (as the article's pending draft).
        $this->maintainer()->run($t[1], now()->addDay());
        $this->assertTrue($own->fresh()->hasPendingDraft());

        // A standard niche, trust ramp over: the next weekly run applies it through the existing "Publish update".
        $this->allowStandardNiche();
        foreach ([1, 2] as $i) {
            $this->live($t[1], "Earlier {$i}", "earlier topic {$i}", links: 3);
        }
        $this->maintainer()->run($t[1], now()->addDays(2));

        $applied = $own->fresh();
        $this->assertFalse($applied->hasPendingDraft());
        $this->assertStringContainsString('Related reading', $applied->body);
        $this->assertSame(ArticleStatus::Published, $applied->status);
        $this->assertSame(Decision::STATE_RESOLVED, Decision::query()->where('article_id', $own->id)->where('reason_code', 'update_applied')->first()?->state);
    }

    // ---------------------------------------------------------------- one rewrite, when facts changed

    private function driftedArticle(Business $business): WebsiteArticle
    {
        $item = CatalogItem::query()->where('business_id', $business->id)->firstOrFail();
        $article = $this->live($business, 'Photo booth pricing explained', 'photo booth pricing explained', links: 3);
        $article->forceFill(['referenced_catalog_uids' => [(string) $item->uid], 'published_at' => now()->subDays(30), 'content_updated_at' => now()->subDays(30)])->save();
        CatalogItem::query()->whereKey($item->id)->update(['updated_at' => now()->subDays(2)]);

        return $article->fresh();
    }

    public function test_changed_facts_start_exactly_one_rewrite_which_waits_as_a_pending_draft(): void
    {
        $t = $this->running();
        $article = $this->driftedArticle($t[1]);

        $result = $this->maintainer()->run($t[1]);

        $this->assertContains('rewrite:' . $article->uid, $result['actions']);
        $decision = Decision::query()->where('kind', 'maintain')->where('decision', 'update')->firstOrFail();
        $this->assertSame(Decision::STATE_BRIEFED, $decision->state);
        $this->assertSame($article->id, $decision->article_id);
        $this->assertNotEmpty($decision->brief['rewrite']['reasons']);
        $this->assertSame($article->body, $decision->brief['rewrite']['current']);
        Bus::assertDispatched(WriteAutopilotArticleJob::class, fn ($job) => $job->decisionId === $decision->id);
        $this->assertSame(0, $this->fake->callCount(), 'starting a rewrite is free; the writer job spends');

        // The queued writer: one strong-route call, saved as the article's pending draft, never the live page.
        $this->fake->queueResult(AiCompletionResult::success($this->reply($decision->brief), 'fake-model', 3000, 1500));
        $done = app(AutopilotArticleWriter::class)->write($decision);

        $this->assertSame(Decision::STATE_AWAITING_APPROVAL, $done->state);
        $this->assertSame('rewrite_ready', $done->reason_code);
        $this->assertSame(1, $this->fake->callCount());
        $fresh = $article->fresh();
        $this->assertTrue($fresh->hasPendingDraft());
        $this->assertSame($article->body, $fresh->body, 'the live article is unchanged until "Publish update"');
        $this->assertStringContainsString('See our', $fresh->draft_payload['body']);
        $this->assertSame(1, WebsiteArticle::query()->where('source', 'autopilot')->count(), 'a rewrite never creates a second article');
    }

    public function test_rewrites_are_capped_per_month_per_article_and_by_the_budget(): void
    {
        $t = $this->running();
        $first = $this->driftedArticle($t[1]);
        config(['ai.business_category_ceilings.content_autopilot.hard_ceiling_microusd' => 1_000]);

        $this->assertNotContains('rewrite:' . $first->uid, $this->maintainer()->run($t[1])['actions'], 'no budget, no rewrite');
        $this->assertSame(0, Decision::query()->where('decision', 'update')->count());

        config(['ai.business_category_ceilings.content_autopilot.hard_ceiling_microusd' => 1_000_000]);
        $this->assertContains('rewrite:' . $first->uid, $this->maintainer()->run($t[1], now()->addDay())['actions']);

        // A second drifted article in the same month waits for the next one.
        $second = $this->live($t[1], 'Another pricing article', 'another pricing article', links: 3);
        $second->forceFill(['referenced_catalog_uids' => $first->referenced_catalog_uids, 'published_at' => now()->subDays(30), 'content_updated_at' => now()->subDays(30)])->save();
        $this->assertNotContains('rewrite:' . $second->uid, $this->maintainer()->run($t[1], now()->addDays(2))['actions']);
        $this->assertSame(1, Decision::query()->where('decision', 'update')->where('reason_code', 'like', 'rewrite_%')->count());
    }

    public function test_an_article_the_owner_wrote_is_never_rewritten(): void
    {
        $t = $this->running();
        $article = $this->driftedArticle($t[1]);
        $article->forceFill(['source' => 'manual'])->save();

        $this->assertNotContains('rewrite:' . $article->uid, $this->maintainer()->run($t[1])['actions']);
        Bus::assertNotDispatched(WriteAutopilotArticleJob::class);
    }

    // ---------------------------------------------------------------- suggestions, never actions

    public function test_duplicates_and_dead_pages_are_suggestions_never_automatic_merges_or_archives(): void
    {
        $t = $this->running();
        $older = $this->live($t[1], 'How much space does a photo booth need?', 'how much space does a photo booth need', own: false, links: 3);
        $newer = $this->draftArticle($t[1], ['title' => 'How much room does a photo booth take up?', 'primary_topic' => 'how much space does a photo booth need']);
        app(ArticleManager::class)->publish((int) $t[1]->customer_id, $t[1], $newer, true);

        $old = $this->live($t[1], 'Retired pricing page guide', 'retired pricing guide', own: false, links: 3);
        $old->forceFill(['supports_page_uid' => (string) Str::uuid(), 'published_at' => now()->subDays(700), 'content_updated_at' => now()->subDays(700)])->save();

        $this->maintainer()->run($t[1]);

        $proposals = Decision::query()->where('decision', 'propose')->get()->keyBy('reason_code');
        $this->assertSame([Decision::STATE_AWAITING_APPROVAL, Decision::STATE_AWAITING_APPROVAL], [$proposals['consolidate']->state, $proposals['archive']->state]);
        $this->assertSame($newer->id, $proposals['consolidate']->article_id, 'only one of the pair raises it');
        $this->assertStringContainsString($older->title, $proposals['consolidate']->needs_input['message']);

        foreach ([$older, $newer, $old] as $article) {
            $this->assertSame(ArticleStatus::Published, $article->fresh()->status, 'nothing is merged or archived automatically');
        }

        // Not raised twice while open, nor again for a long while once dismissed.
        $this->maintainer()->run($t[1], now()->addDays(70));
        $this->assertSame(2, Decision::query()->where('decision', 'propose')->count());
        $proposals['consolidate']->forceFill(['state' => Decision::STATE_RESOLVED])->save();
        $this->maintainer()->run($t[1], now()->addDays(100));
        $this->assertSame(2, Decision::query()->where('decision', 'propose')->count());
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_the_owner_sees_waiting_updates_and_suggestions_and_can_dismiss_a_suggestion(): void
    {
        $t = $this->running();
        $this->authenticateAsSeoCustomer($t[0]);
        $own = $this->live($t[1], 'Thinly linked article', 'thinly linked topic', links: 1);
        $old = $this->live($t[1], 'Retired pricing page guide', 'retired pricing guide', own: false, links: 3);
        $old->forceFill(['supports_page_uid' => (string) Str::uuid(), 'published_at' => now()->subDays(700), 'content_updated_at' => now()->subDays(700)])->save();
        $this->maintainer()->run($t[1]);

        $url = fn (string $name, array $extra = []) => rtrim((string) config('app.url'), '/') . route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$t[2]->uid, $t[1]->uid], $extra), false);
        $html = $this->get($url('autopilot'))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="waiting-updates"', $html);
        $this->assertStringContainsString('Related reading', $html);
        $this->assertStringContainsString('data-role="suggestions"', $html);
        $this->assertStringContainsString('never merges or archives', $html);

        $proposal = Decision::query()->where('decision', 'propose')->firstOrFail();
        $this->post($url('autopilot.suggestion.dismiss', [$proposal->uid]))->assertRedirect($url('autopilot'));
        $this->assertSame(Decision::STATE_RESOLVED, $proposal->fresh()->state);
        $this->assertSame(ArticleStatus::Published, $old->fresh()->status, 'dismissing changes nothing');
        $this->assertStringNotContainsString('data-role="suggestions"', $this->get($url('autopilot'))->getContent());

        $this->post($url('autopilot.suggestion.dismiss', [(string) Str::uuid()]))->assertNotFound();
    }

    public function test_the_weekly_sweep_only_queues_switched_on_businesses(): void
    {
        $on = $this->running();
        $off = $this->photoBoothContentTenant(withBlueprint: true);
        ContentAutopilotSetting::query()->create(['business_id' => $off[1]->id, 'enabled' => false]);

        $this->artisan('content:autopilot-maintain')->expectsOutputToContain('Queued 1 Content Autopilot maintenance review')->assertExitCode(0);

        Bus::assertDispatched(MaintainAutopilotArticlesJob::class, fn ($job) => $job->businessId === $on[1]->id);
        Bus::assertDispatchedTimes(MaintainAutopilotArticlesJob::class, 1);
    }
}
