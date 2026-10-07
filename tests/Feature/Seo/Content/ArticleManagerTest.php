<?php

namespace Tests\Feature\Seo\Content;

use App\Enums\Seo\ArticleStatus;
use App\Exceptions\Seo\ArticleException;
use App\Library\Seo\Content\ArticleAnalyzer;
use App\Library\Seo\Content\ArticleClaimGuard;
use App\Library\Seo\Content\ArticleMarkdown;
use App\Library\Seo\Content\ArticleSlugger;
use App\Models\WebsiteArticle;
use App\Models\WebsiteAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO Content Engine V1 — the article model and its single write authority: CRUD, status transitions, scheduling,
 * slugs, isolation, the transparent analysis and the unsupported-claim guard.
 */
class ArticleManagerTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private function actor($business): int
    {
        return (int) $business->customer_id;
    }

    public function test_create_makes_a_private_draft_with_a_stable_uid_and_a_clean_slug(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();

        $article = $this->draftArticle($business);

        $this->assertSame(ArticleStatus::Draft, $article->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $article->uid);
        $this->assertSame('how-much-space-does-a-photo-booth-need', $article->slug);
        $this->assertSame($business->id, $article->business_id);
        $this->assertSame($website->id, $article->website_id);
        $this->assertNull($article->published_at);
        $this->assertNotNull($article->topic_signature);
        $this->assertSame('manual', $article->source);
        $this->assertFalse($article->ai_generated);

        $again = $this->draftArticle($business);
        $this->assertSame('how-much-space-does-a-photo-booth-need-2', $again->slug, 'collision-safe');
        $this->assertNotSame($article->uid, $again->uid);
    }

    public function test_status_cannot_be_set_through_a_payload(): void
    {
        [, $business] = $this->photoBoothContentTenant();

        $article = $this->manager()->create($this->actor($business), $business, ['title' => 'Sneaky', 'status' => 'published', 'published_at' => now(), 'business_id' => 999999]);

        $this->assertSame(ArticleStatus::Draft, $article->status);
        $this->assertNull($article->published_at);
        $this->assertSame($business->id, $article->business_id);
    }

    public function test_the_lifecycle_draft_scheduled_published_archived_with_history_kept(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $actor = $this->actor($business);
        $article = $this->draftArticle($business);

        $scheduled = $this->manager()->schedule($actor, $business, $article, now()->addDay());
        $this->assertSame(ArticleStatus::Scheduled, $scheduled->status);
        $this->assertNotNull($scheduled->scheduled_at);

        $back = $this->manager()->backToDraft($actor, $business, $scheduled);
        $this->assertSame(ArticleStatus::Draft, $back->status);
        $this->assertNull($back->scheduled_at);

        $published = $this->manager()->publish($actor, $business, $back);
        $this->assertSame(ArticleStatus::Published, $published->status);
        $firstPublished = $published->published_at;
        $this->assertNotNull($firstPublished);

        $archived = $this->manager()->archive($actor, $business, $published);
        $this->assertSame(ArticleStatus::Archived, $archived->status);
        $this->assertNotNull($archived->archived_at);

        $restored = $this->manager()->backToDraft($actor, $business, $archived);
        $this->assertSame(ArticleStatus::Draft, $restored->status);
        $this->assertTrue($restored->published_at->equalTo($firstPublished), 'the original publish date is never rewritten');

        $republished = $this->manager()->publish($actor, $business, $restored);
        $this->assertTrue($republished->published_at->equalTo($firstPublished), 'no fake publication date on republish');
    }

    public function test_illegal_transitions_are_refused(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $actor = $this->actor($business);
        $published = $this->publishedArticle($business);

        foreach ([
            fn () => $this->manager()->backToDraft($actor, $business, $published),
            fn () => $this->manager()->schedule($actor, $business, $published, now()->addDay()),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a refusal');
            } catch (ArticleException $e) {
                $this->assertSame(ArticleException::BAD_TRANSITION, $e->reason);
            }
        }

        $this->assertSame(ArticleStatus::Published, $published->fresh()->status);
    }

    public function test_scheduling_needs_a_future_time_and_a_ready_article(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $actor = $this->actor($business);

        try {
            $this->manager()->schedule($actor, $business, $this->draftArticle($business), now()->subHour());
            $this->fail('expected a bad schedule');
        } catch (ArticleException $e) {
            $this->assertSame(ArticleException::BAD_SCHEDULE, $e->reason);
        }

        $thin = $this->manager()->create($actor, $business, ['title' => 'Too thin', 'body' => 'Just a few words.']);

        try {
            $this->manager()->schedule($actor, $business, $thin, now()->addDay());
            $this->fail('expected not ready');
        } catch (ArticleException $e) {
            $this->assertSame(ArticleException::NOT_READY, $e->reason);
            $this->assertNotEmpty($e->problems);
        }
    }

    public function test_slug_rules_validity_collisions_reserved_words_and_redirect_history(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $actor = $this->actor($business);
        $a = $this->draftArticle($business);
        $b = $this->draftArticle($business, ['title' => 'Second photo booth planning note', 'primary_topic' => 'second planning note']);

        foreach (['Has Capitals', 'under_score', 'bad--double', '-leading', 'blog', 'sitemap', str_repeat('a', 81), ''] as $bad) {
            if ($bad === '') {
                continue;
            }
            try {
                $this->manager()->update($actor, $business, $a, ['slug' => $bad]);
                $this->fail("slug accepted: {$bad}");
            } catch (ArticleException $e) {
                $this->assertSame(ArticleException::INVALID_SLUG, $e->reason, $bad);
            }
        }

        try {
            $this->manager()->update($actor, $business, $a, ['slug' => $b->slug]);
            $this->fail('collision accepted');
        } catch (ArticleException $e) {
            $this->assertSame(ArticleException::SLUG_TAKEN, $e->reason);
        }

        // A published article that changes slug leaves its old address reserved for it forever.
        $published = $this->manager()->publish($actor, $business, $a);
        $old = $published->slug;
        $this->manager()->update($actor, $business, $published, ['slug' => 'space-for-a-booth']);
        $this->manager()->publish($actor, $business, $published->fresh());
        $this->assertTrue(ArticleSlugger::isTaken($website->id, $old), 'the retired slug stays reserved');

        try {
            $this->manager()->update($actor, $business, $b, ['slug' => $old]);
            $this->fail('a retired slug was handed to another article');
        } catch (ArticleException $e) {
            $this->assertSame(ArticleException::SLUG_TAKEN, $e->reason);
        }

        // It can go back to its own old slug.
        $this->manager()->update($actor, $business, $published->fresh(), ['slug' => $old]);
        $back = $this->manager()->publish($actor, $business, $published->fresh());
        $this->assertSame($old, $back->slug);
        $this->assertFalse(ArticleSlugger::isTaken($website->id, $old, $back->id));
    }

    public function test_references_are_validated_against_this_business(): void
    {
        [, $a] = $this->photoBoothContentTenant();
        [, $b, , $siteB] = $this->photoBoothContentTenant();
        $actor = $this->actor($a);
        $article = $this->draftArticle($a);

        $foreignAsset = WebsiteAsset::create(['website_id' => $siteB->id, 'path' => 'images/websites/x/y.jpg', 'filename' => 'y.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'purpose' => 'gallery']);

        foreach ([['featured_asset_uid' => $foreignAsset->uid], ['supports_page_uid' => 'aaaaaaaa-0000-4000-8000-0000000000ff']] as $bad) {
            try {
                $this->manager()->update($actor, $a, $article, $bad);
                $this->fail('a foreign or unknown reference was accepted');
            } catch (ArticleException $e) {
                $this->assertSame(ArticleException::BAD_REFERENCE, $e->reason);
            }
        }
    }

    public function test_articles_are_scoped_to_their_business(): void
    {
        [, $a] = $this->photoBoothContentTenant();
        [, $b] = $this->photoBoothContentTenant();
        $article = $this->draftArticle($a);

        $this->assertNotNull($this->manager()->find($a, $article->uid));
        $this->assertNull($this->manager()->find($b, $article->uid), 'another Business cannot even find it');

        $this->expectException(ArticleException::class);
        $this->manager()->update($this->actor($b), $b, $article, ['title' => 'Mine now']);
    }

    public function test_a_business_without_a_website_cannot_create_articles(): void
    {
        [, $business] = $this->entitledTenant();

        $this->expectException(ArticleException::class);
        $this->manager()->create($this->actor($business), $business, ['title' => 'No site']);
    }

    public function test_editing_a_published_article_moves_date_modified_but_never_the_publish_date(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $published = $this->publishedArticle($business);
        $when = $published->published_at;

        $this->travel(3)->days();
        $actor = $this->actor($business);
        $this->manager()->update($actor, $business, $published, ['body' => $this->goodBody() . "\nOne more useful paragraph for readers."]);
        $edited = $this->manager()->publish($actor, $business, $published->fresh());

        $this->assertTrue($edited->published_at->equalTo($when));
        $this->assertTrue($edited->dateModified()->greaterThan($when));

        $this->travel(1)->days();
        $this->manager()->update($actor, $business, $edited, ['noindex' => true]);
        $reviewed = $this->manager()->publish($actor, $business, $edited->fresh());
        $this->assertTrue($reviewed->dateModified()->equalTo($edited->dateModified()), 'a settings change is not a content edit');
    }

    public function test_referenced_catalog_items_are_recorded_from_the_text(): void
    {
        [, $business] = $this->photoBoothContentTenant();

        $article = $this->draftArticle($business, ['body' => $this->goodBody() . "\nThe Signature package covers most weddings.\n"]);

        $names = \App\Models\CatalogItem::whereIn('uid', $article->referenced_catalog_uids)->pluck('name')->all();
        $this->assertSame(['Signature'], $names);
    }

    public function test_the_analysis_is_a_plain_good_or_needs_attention_checklist_with_no_score(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $article = $this->draftArticle($business);

        $analysis = app(ArticleAnalyzer::class)->analyze($business, $article);

        $this->assertEqualsCanonicalizing(['title', 'description', 'heading', 'topic', 'links', 'image', 'facts', 'duplication', 'schema', 'indexability'], array_column($analysis['checks'], 'key'));
        $this->assertContains($analysis['overall'], ['good', 'attention']);
        foreach ($analysis['checks'] as $check) {
            $this->assertContains($check['status'], ['good', 'attention']);
            $this->assertArrayNotHasKey('score', $check);
        }
        $this->assertArrayNotHasKey('score', $analysis);

        $by = collect($analysis['checks'])->keyBy('key');
        $this->assertSame('good', $by['title']['status']);
        $this->assertSame('good', $by['description']['status']);
        $this->assertSame('good', $by['topic']['status']);
        $this->assertSame('good', $by['links']['status']);
        $this->assertSame('attention', $by['image']['status'], 'no featured image yet');
        $this->assertSame('attention', $by['indexability']['status'], 'a draft is never indexable');

        $this->assertSame('good', collect(app(ArticleAnalyzer::class)->analyze($business, $this->publishedArticle($business, ['title' => 'Booth planning at your venue', 'primary_topic' => 'booth planning at your venue']))['checks'])->firstWhere('key', 'indexability')['status']);
    }

    public function test_indexability_explains_every_reason(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $analyzer = app(ArticleAnalyzer::class);

        $draft = $this->draftArticle($business);
        $this->assertFalse($analyzer->indexability($draft)[0]);

        $scheduled = $this->manager()->schedule($this->actor($business), $business, $this->draftArticle($business, ['title' => 'Scheduled one about booths', 'primary_topic' => 'scheduled booths']), now()->addDay());
        $this->assertFalse($analyzer->indexability($scheduled)[0], 'scheduled but unpublished is never indexable');

        $published = $this->publishedArticle($business, ['title' => 'Published one about booths', 'primary_topic' => 'published booths']);
        $this->assertTrue($analyzer->indexability($published)[0]);

        $this->manager()->update($this->actor($business), $business, $published, ['noindex' => true]);
        $hidden = $this->manager()->publish($this->actor($business), $business, $published->fresh());
        $this->assertFalse($analyzer->indexability($hidden)[0]);

        $website->domains()->delete();
        $this->assertFalse($analyzer->indexability($published->fresh())[0], 'no live custom domain: not indexable');
    }

    public function test_the_claim_guard_flags_invented_facts_and_accepts_real_prices(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $guard = app(ArticleClaimGuard::class);

        $kinds = fn (string $text) => array_column($guard->hardFindings($business, $text), 'kind');

        $this->assertContains('price', $kinds('Our booths start at $450 for the night.'));
        $this->assertNotContains('price', $kinds('The Signature package is $949.00, Essential is $699 and Luxe is $1,299.'));
        $this->assertContains('years_in_business', $kinds('With 12 years of experience we know weddings.'));
        $this->assertContains('years_in_business', $kinds('Serving Chicago since 2009.'));
        $this->assertContains('award', $kinds('Our award-winning team arrives early.'));
        $this->assertContains('review_score', $kinds('Rated 4.9/5 by guests, a five-star service.'));
        $this->assertContains('customer_count', $kinds('We have served 500+ happy clients.'));
        $this->assertContains('guarantee', $kinds('We offer a satisfaction guarantee.'));
        $this->assertContains('celebrity', $kinds('As seen on national TV with celebrities.'));
        $this->assertSame([], $kinds('A booth needs about ten by ten feet and a standard outlet near the guests.'));

        $soft = array_column($guard->scan($business, '70% of guests love the best booth in Chicago.'), 'kind');
        $this->assertContains('statistic', $soft);
        $this->assertSame([], $guard->hardFindings($business, '70% of guests love it.'), 'soft findings never block');

        $this->assertTrue($guard->hasQuotation("> \"Best night ever\" - a guest"));
    }

    public function test_markdown_is_safe_and_references_resolve_only_when_asked(): void
    {
        $html = (string) ArticleMarkdown::toHtml("# Title\n\n## Section\n\nText with [link](page:" . self::PAGE_WEDDING . ") and <b>raw</b> ![i](x.png)", fn ($type, $uid) => $type === 'page' ? 'https://example.test/page' : null);

        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringContainsString('<h2>Title</h2>', $html);
        $this->assertStringContainsString('<a href="https://example.test/page">link</a>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<img', $html);

        $plain = (string) ArticleMarkdown::toHtml('See [x](page:' . self::PAGE_WEDDING . ')', null);
        $this->assertStringNotContainsString('<a ', $plain);
        $this->assertStringContainsString('See x', $plain);

        $this->assertSame([['level' => 2, 'text' => 'Section']], array_values(array_filter(ArticleMarkdown::headings("# T\n## Section"), fn ($h) => $h['level'] === 2)));
        $this->assertSame(4, ArticleMarkdown::wordCount("## Head\n\nthree little words"));
        $this->assertSame(5, ArticleMarkdown::wordCount("1. first step\n2. second step here"));
    }
}
