<?php

namespace Tests\Feature\Seo\Content;

use App\Enums\Seo\ArticleStatus;
use App\Exceptions\Seo\ArticleDraftException;
use App\Exceptions\Seo\ArticleException;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Seo\Content\ArticleAnalyzer;
use App\Library\Seo\Content\ArticleDraftGenerator;
use App\Library\Seo\Content\ArticleOpportunityEngine;
use App\Models\CatalogItem;
use App\Models\WebsiteArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO Content Engine V1 — grounded AI drafting with the fake AI: one call, only the Business's own facts, never
 * published, claims caught, cannibalizing topics refused before any AI call.
 */
class ArticleDraftGeneratorTest extends TestCase
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
    }

    private function reply(array $overrides = []): void
    {
        $this->fake->setDefaultResult(AiCompletionResult::success(
            content: json_encode(array_merge([
                'title' => 'How much space a photo booth needs',
                'excerpt' => 'What floor space, power and layout a booth needs at your venue.',
                'meta_description' => 'What floor space, power and layout a photo booth needs at your venue, with practical planning tips.',
                'body_markdown' => $this->goodBody(),
            ], $overrides)),
            providerModel: 'gpt-4o-mini',
            inputTokens: 900,
            outputTokens: 700,
        ));
    }

    /** @return array<string, mixed> */
    private function opportunity($business, string $title = 'How much room does a photo booth need?'): array
    {
        $opportunity = app(ArticleOpportunityEngine::class)->find($business, 'planning:' . \Illuminate\Support\Str::slug($title));
        $this->assertNotNull($opportunity, 'the opportunity exists for ' . $title);

        return $opportunity;
    }

    public function test_it_creates_one_grounded_draft_and_never_publishes(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $opportunity = $this->opportunity($business);
        $this->reply(['body_markdown' => $this->goodBody($opportunity['supports_page_uid'])]);

        $article = app(ArticleDraftGenerator::class)->generate($business, (int) $customer->user_id, $opportunity);

        $this->assertSame(1, $this->fake->callCount(), 'exactly one AI call');
        $this->assertSame(ArticleStatus::Draft, $article->fresh()->status);
        $this->assertNull($article->published_at);
        $this->assertSame('ai_draft', $article->source);
        $this->assertTrue($article->ai_generated);
        $this->assertSame($opportunity['key'], $article->opportunity_key);
        $this->assertSame($opportunity['title'], $article->title);
        $this->assertSame($opportunity['supports_page_uid'], $article->supports_page_uid);
        $this->assertSame('planning', $article->search_intent);
        $this->assertSame($business->id, $article->business_id);

        $request = $this->fake->requests()[0];
        $this->assertSame(3500, $request->maxOutputTokens);
        $this->assertTrue($request->jsonMode);

        $prompt = json_encode($request->messages);
        foreach (['Essential', 'Signature', 'Luxe', 'USD 699.00', 'USD 949.00', 'USD 1,299.00', 'Jazmin Photo Booth Co.', 'Chicago', 'Wedding Photo Booth Rental'] as $fact) {
            $this->assertStringContainsString($fact, $prompt, "the prompt carries the real fact: {$fact}");
        }
        foreach (['NEVER invent', 'years in business', 'awards', 'statistics', 'customer, event or review counts', 'guarantees', 'NEVER write quotations', 'allowed_links'] as $rule) {
            $this->assertStringContainsString($rule, $prompt, "the prompt states the rule: {$rule}");
        }
    }

    public function test_another_business_data_never_reaches_the_prompt(): void
    {
        [$customer, $a] = $this->photoBoothContentTenant(withBlueprint: true);
        [, $b] = $this->photoBoothContentTenant();
        CatalogItem::create(['business_id' => $b->id, 'type' => \App\Enums\Catalog\CatalogItemType::Package, 'name' => 'SecretPackageOfB', 'price_minor' => 4242, 'currency_code' => 'USD', 'position' => 9]);
        $this->reply();

        app(ArticleDraftGenerator::class)->generate($a, (int) $customer->user_id, $this->opportunity($a));

        $prompt = json_encode($this->fake->requests()[0]->messages);
        $this->assertStringNotContainsString('SecretPackageOfB', $prompt);
        $this->assertStringNotContainsString('42.42', $prompt);
    }

    public function test_the_body_is_normalised_no_h1_images_external_links_or_unlisted_references(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $opportunity = $this->opportunity($business);
        $allowed = $opportunity['supports_page_uid'];

        $body = "# A Sneaky Title\n\n" . $this->goodBody()
            . "\n\nSee [the wedding page](page:{$allowed}) and again [the wedding page](page:{$allowed}), "
            . "[a made up page](page:aaaaaaaa-0000-4000-8000-0000000000ee), [outside](https://evil.example/x) "
            . "and ![pic](https://evil.example/p.png) <b>bold html</b>\n";
        $this->reply(['body_markdown' => $body]);

        $article = app(ArticleDraftGenerator::class)->generate($business, (int) $customer->user_id, $opportunity);

        $this->assertStringNotContainsString("\n# ", "\n" . $article->body);
        $this->assertStringNotContainsString('evil.example', $article->body);
        $this->assertStringNotContainsString('<b>', $article->body);
        $this->assertSame(1, substr_count($article->body, "(page:{$allowed})"), 'an allowed reference is kept once');
        $this->assertStringNotContainsString('aaaaaaaa-0000-4000-8000-0000000000ee', $article->body);
        $this->assertStringContainsString('a made up page', $article->body, 'a refused link keeps its words');
    }

    public function test_invented_claims_are_kept_visible_and_block_publishing(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $opportunity = $this->opportunity($business);
        $this->reply(['body_markdown' => $this->goodBody() . "\n\nOur award-winning team has 15 years of experience and booths start at $450, with a 100% satisfaction guarantee.\n"]);

        $article = app(ArticleDraftGenerator::class)->generate($business, (int) $customer->user_id, $opportunity);

        $this->assertSame(ArticleStatus::Draft, $article->status, 'saved as a draft for the owner to correct');
        $analysis = app(ArticleAnalyzer::class)->analyze($business, $article);
        $this->assertSame('attention', collect($analysis['checks'])->firstWhere('key', 'facts')['status']);

        $blockers = implode(' ', app(ArticleAnalyzer::class)->blockers($business, $article));
        foreach (['$450', 'Awards', 'Years in business', 'Guarantees'] as $needle) {
            $this->assertStringContainsString($needle, $blockers);
        }

        try {
            $this->manager()->publish((int) $customer->user_id, $business, $article);
            $this->fail('publishing must be refused while an invented claim remains');
        } catch (ArticleException $e) {
            $this->assertSame(ArticleException::NOT_READY, $e->reason);
        }

        // A real catalog price is fine.
        $clean = $this->manager()->update((int) $customer->user_id, $business, $article, ['body' => $this->goodBody() . "\n\nThe Signature package is \$949.\n"]);
        $this->assertSame([], app(ArticleAnalyzer::class)->blockers($business, $clean));
    }

    public function test_a_topic_that_cannibalizes_a_money_page_is_refused_before_any_ai_call(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant();
        $this->reply();

        try {
            app(ArticleDraftGenerator::class)->generate($business, (int) $customer->user_id, [
                'key' => 'x', 'title' => 'Photo booth rental chicago', 'primary_topic' => 'photo booth rental chicago', 'search_intent' => 'guide', 'internal_links' => [],
            ]);
            $this->fail('expected a refusal');
        } catch (ArticleDraftException $e) {
            $this->assertSame(ArticleDraftException::CANNIBALIZES, $e->reason);
        }

        $this->assertSame(0, $this->fake->callCount());
        $this->assertSame(0, WebsiteArticle::count());
    }

    public function test_provider_failure_unusable_output_and_short_output_create_nothing(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $opportunity = $this->opportunity($business);

        $this->fake->setDefaultResult(AiCompletionResult::failure());
        try {
            app(ArticleDraftGenerator::class)->generate($business, (int) $customer->user_id, $opportunity);
            $this->fail('expected unavailable');
        } catch (ArticleDraftException $e) {
            $this->assertSame(ArticleDraftException::UNAVAILABLE, $e->reason);
            $this->assertStringNotContainsString('openai', strtolower($e->customerMessage()));
        }

        $this->fake->setDefaultResult(AiCompletionResult::success('not json at all', 'gpt-4o-mini', 10, 10));
        try {
            app(ArticleDraftGenerator::class)->generate($business, (int) $customer->user_id, $opportunity);
            $this->fail('expected unusable');
        } catch (ArticleDraftException $e) {
            $this->assertSame(ArticleDraftException::UNUSABLE, $e->reason);
        }

        $this->reply(['body_markdown' => 'Too short to be an article.']);
        try {
            app(ArticleDraftGenerator::class)->generate($business, (int) $customer->user_id, $opportunity);
            $this->fail('expected unusable');
        } catch (ArticleDraftException $e) {
            $this->assertSame(ArticleDraftException::UNUSABLE, $e->reason);
        }

        $this->assertSame(0, WebsiteArticle::count());
    }
}
