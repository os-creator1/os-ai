<?php

namespace Tests\Feature\Seo\Content;

use App\Enums\Seo\ArticleStatus;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Models\WebsiteArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO Content Engine V1 — the owner flow over HTTP: SEO → Content (Plan, Articles, Opportunities), the editor,
 * preview, schedule, publish, archive, restore, and the single explicit AI action.
 */
class ContentHttpTest extends TestCase
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

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: \App\Models\Website} */
    private function owner(bool $blueprint = true): array
    {
        $tenant = $this->photoBoothContentTenant(withBlueprint: $blueprint);
        $this->authenticateAsSeoCustomer($tenant[0]);

        return $tenant;
    }

    private function url(array $tenant, string $name, array $extra = []): string
    {
        // Always on the platform host: the URL generator remembers the host of the LAST request, so a request made
        // to the custom domain earlier in a test would otherwise move every later "relative" request onto it.
        return rtrim((string) config('app.url'), '/') . route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$tenant[2]->uid, $tenant[1]->uid], $extra), false);
    }

    private function formFor(array $overrides = []): array
    {
        return array_merge([
            'title' => 'How Much Space Does a Photo Booth Need?',
            'slug' => '',
            'excerpt' => 'A practical look at the floor space, power and layout a photo booth needs at your venue.',
            'body' => $this->goodBody(self::PAGE_WEDDING),
            'meta_description' => 'A practical look at the floor space, power and layout a photo booth needs at your venue, with tips for planning.',
            'primary_topic' => 'how much space does a photo booth need',
            'search_intent' => 'planning',
            'supports_page_uid' => self::PAGE_WEDDING,
            'noindex' => '0',
        ], $overrides);
    }

    public function test_the_content_area_shows_plan_articles_and_opportunities(): void
    {
        $t = $this->owner();

        foreach (['plan', 'articles.index', 'opportunities'] as $name) {
            $html = $this->get($this->url($t, $name))->assertOk()->getContent();
            $this->assertStringContainsString('data-tab="plan"', $html);
            $this->assertStringContainsString('data-tab="articles"', $html);
            $this->assertStringContainsString('data-tab="opportunities"', $html);
        }

        $this->assertSame(0, $this->fake->callCount(), 'no AI call on any Content page');
    }

    public function test_an_empty_articles_page_recommends_topics_instead_of_saying_no_records(): void
    {
        $t = $this->owner();

        $html = $this->get($this->url($t, 'articles.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Start with topics your customers already search for', $html);
        $this->assertStringContainsString('data-role="recommended-opportunities"', $html);
        $this->assertStringContainsString('How much does a photo booth rental cost in Chicago?', $html);
        $this->assertStringNotContainsString('No records found', $html);
        $this->assertSame(3, substr_count($html, 'data-role="create-ai-draft"'));
    }

    public function test_opportunities_show_the_decision_facts_and_never_invented_metrics(): void
    {
        $t = $this->owner();

        $html = $this->get($this->url($t, 'opportunities'))->assertOk()->getContent();

        foreach (['Search intent', 'Target topic', 'Supports', 'Why it matters', 'Not covered', 'Create AI draft', 'Write it myself'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        foreach (['search volume', 'Search volume', 'CPC', 'competition', 'Competition', 'difficulty'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_the_full_owner_flow_create_edit_preview_schedule_publish_archive_restore(): void
    {
        $t = $this->owner(false);
        $business = $t[1];

        // Create.
        $this->get($this->url($t, 'articles.create'))->assertOk()->assertSee('Save draft');
        $response = $this->post($this->url($t, 'articles.store'), $this->formFor());
        $article = WebsiteArticle::query()->where('business_id', $business->id)->sole();
        $response->assertRedirect($this->url($t, 'articles.edit', [$article->uid]));
        $this->assertSame(ArticleStatus::Draft, $article->status);
        $this->assertSame('how-much-space-does-a-photo-booth-need', $article->slug);

        // Editor shows the checklist, the publish panel and internal-link suggestions.
        $edit = $this->get($this->url($t, 'articles.edit', [$article->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="publish"', $edit);
        $this->assertStringContainsString('data-role="analysis"', $edit);
        $this->assertStringContainsString('data-check="duplication"', $edit);
        $this->assertStringContainsString('data-role="link-suggestions"', $edit);
        $this->assertStringContainsString('page:' . self::PAGE_PACKAGES, $edit, 'a stable page reference is offered');
        $this->assertStringNotContainsString('SEO score', $edit);

        // Update (save draft).
        $this->post($this->url($t, 'articles.update', [$article->uid]), $this->formFor(['excerpt' => 'A new, clearer excerpt for this article about booth space.']))
            ->assertRedirect($this->url($t, 'articles.edit', [$article->uid]));
        $this->assertSame('A new, clearer excerpt for this article about booth space.', $article->fresh()->excerpt);

        // Preview: the Website renderer, noindex, banner, never public.
        $preview = $this->get($this->url($t, 'articles.preview', [$article->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('website-preview-banner', $preview);
        $this->assertStringContainsString('noindex, follow', $preview);
        $this->get('http://' . self::DOMAIN . '/blog/' . $article->slug)->assertNotFound();

        // Schedule in the future; the article is still not public.
        $at = now()->addDays(2)->format('Y-m-d\TH:i');
        $this->post($this->url($t, 'articles.schedule', [$article->uid]), $this->formFor() + ['scheduled_at_local' => $at])->assertRedirect();
        $this->assertSame(ArticleStatus::Scheduled, $article->fresh()->status);
        $this->get('http://' . self::DOMAIN . '/blog/' . $article->slug)->assertNotFound();

        // Cancel the schedule, then publish.
        $this->post($this->url($t, 'articles.draft', [$article->uid]))->assertRedirect();
        $this->assertSame(ArticleStatus::Draft, $article->fresh()->status);

        $this->post($this->url($t, 'articles.publish', [$article->uid]), $this->formFor())->assertRedirect();
        $published = $article->fresh();
        $this->assertSame(ArticleStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
        $this->get('http://' . self::DOMAIN . '/blog/' . $published->slug)->assertOk();

        // "Track this topic" adds a plain keyword only; no rank target is created by publishing or tracking.
        $this->post($this->url($t, 'articles.track', [$article->uid]))->assertRedirect();
        $this->assertDatabaseHas('seo_keywords', ['business_id' => $business->id, 'phrase' => 'how much space does a photo booth need']);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('seo_rank_targets')->count());

        // Archive: leaves the blog and sitemap; restore: back to draft.
        $this->post($this->url($t, 'articles.archive', [$article->uid]))->assertRedirect();
        $this->assertSame(ArticleStatus::Archived, $article->fresh()->status);
        $this->get('http://' . self::DOMAIN . '/blog/' . $published->slug)->assertNotFound();

        $this->post($this->url($t, 'articles.restore', [$article->uid]))->assertRedirect();
        $this->assertSame(ArticleStatus::Draft, $article->fresh()->status);
        $this->assertNotNull($article->fresh()->published_at, 'published history is never erased');
    }

    public function test_validation_and_friendly_refusals(): void
    {
        $t = $this->owner(false);

        $this->post($this->url($t, 'articles.store'), $this->formFor(['title' => '']))->assertSessionHasErrors('title');

        $article = $this->draftArticle($t[1]);
        $this->post($this->url($t, 'articles.publish', [$article->uid]), $this->formFor(['body' => 'far too short']))
            ->assertRedirect($this->url($t, 'articles.edit', [$article->uid]))
            ->assertSessionHas('status', 'error');
        $this->assertSame(ArticleStatus::Draft, $article->fresh()->status, 'a refused publish leaves the draft a draft');

        $this->post($this->url($t, 'articles.schedule', [$article->uid]), $this->formFor() + ['scheduled_at_local' => now()->subDay()->format('Y-m-d\TH:i')])
            ->assertSessionHas('status', 'error');
    }

    public function test_changing_a_published_slug_in_the_editor_keeps_the_old_address_redirecting(): void
    {
        $t = $this->owner(false);
        $article = $this->publishedArticle($t[1]);
        $old = $article->slug;

        $this->post($this->url($t, 'articles.update', [$article->uid]), $this->formFor(['slug' => 'booth-space-guide']))->assertRedirect();

        $this->assertSame('booth-space-guide', $article->fresh()->slug);
        $this->get('http://' . self::DOMAIN . '/blog/' . $old)->assertStatus(301);
    }

    public function test_the_ai_draft_is_one_explicit_post_that_creates_a_draft(): void
    {
        $t = $this->owner();
        $engine = app(\App\Library\Seo\Content\ArticleOpportunityEngine::class);
        $opportunity = collect($engine->forBusiness($t[1]))->firstWhere('title', 'How much room does a photo booth need?');

        $this->fake->setDefaultResult(AiCompletionResult::success(json_encode([
            'title' => 'Booth space', 'excerpt' => 'Space and power a booth needs.', 'meta_description' => 'Space and power a booth needs at your venue.',
            'body_markdown' => $this->goodBody(),
        ]), 'gpt-4o-mini', 500, 600));

        $this->get($this->url($t, 'opportunities'))->assertOk();
        $this->assertSame(0, $this->fake->callCount(), 'rendering Opportunities never calls the AI');

        $response = $this->post($this->url($t, 'opportunities.draft'), ['key' => $opportunity['key']]);
        $article = WebsiteArticle::query()->where('business_id', $t[1]->id)->sole();

        $response->assertRedirect($this->url($t, 'articles.edit', [$article->uid]));
        $this->assertSame(1, $this->fake->callCount());
        $this->assertSame(ArticleStatus::Draft, $article->status);
        $this->assertTrue($article->ai_generated);
        $this->assertStringContainsString('AI draft', $this->get($this->url($t, 'articles.edit', [$article->uid]))->getContent());

        // The opportunity now says a draft exists, and a second POST reuses it instead of paying twice.
        $this->post($this->url($t, 'opportunities.draft'), ['key' => $opportunity['key']])->assertRedirect($this->url($t, 'articles.edit', [$article->uid]));
        $this->assertSame(1, $this->fake->callCount());
        $this->assertSame(1, WebsiteArticle::count());
    }

    public function test_write_it_myself_starts_a_prefilled_draft_without_ai(): void
    {
        $t = $this->owner();
        $opportunity = collect(app(\App\Library\Seo\Content\ArticleOpportunityEngine::class)->forBusiness($t[1]))->firstWhere('title', 'Wedding photo booth ideas');

        $this->post($this->url($t, 'opportunities.start'), ['key' => $opportunity['key']])->assertRedirect();

        $article = WebsiteArticle::query()->sole();
        $this->assertSame('Wedding photo booth ideas', $article->title);
        $this->assertSame($opportunity['key'], $article->opportunity_key);
        $this->assertSame(self::PAGE_WEDDING, $article->supports_page_uid);
        $this->assertSame('opportunity', $article->source);
        $this->assertFalse($article->ai_generated);
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_a_business_without_a_website_gets_a_friendly_page(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsSeoCustomer($customer);

        foreach (['plan', 'articles.index', 'opportunities', 'articles.create'] as $name) {
            $this->get(route('customer.workspaces.businesses.seo.content.' . $name, [$workspace->uid, $business->uid]))
                ->assertOk()->assertSee('Create your website first');
        }
    }

    public function test_the_markdown_preview_endpoint_is_safe(): void
    {
        $t = $this->owner(false);

        $json = $this->postJson($this->url($t, 'markdown-preview'), ['body' => "## Hi\n\n<script>alert(1)</script> [x](javascript:alert(2)) [p](page:" . self::PAGE_WEDDING . ")"])->assertOk()->json();

        $this->assertStringContainsString('<h2>Hi</h2>', $json['html']);
        $this->assertStringNotContainsString('<script', $json['html']);
        $this->assertStringNotContainsString('javascript:', $json['html']);
    }
}
