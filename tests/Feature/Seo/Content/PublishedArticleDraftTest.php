<?php

namespace Tests\Feature\Seo\Content;

use App\Enums\Seo\ArticleStatus;
use App\Exceptions\Seo\ArticleException;
use App\Library\Website\WebsiteSearchVisibility;
use App\Models\WebsiteArticle;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO Content Engine V1 (release closure) — Published → Edit draft → Preview → Publish update. Save never changes
 * the public article; slug redirects begin only when the update is published; blog indexability follows the
 * Website's own released-for-search state.
 */
class PublishedArticleDraftTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private const HOST = 'http://' . self::DOMAIN;

    private function actor($business): int
    {
        return (int) $business->customer_id;
    }

    public function test_saving_a_published_article_leaves_the_public_page_unchanged(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);
        $url = self::HOST . '/blog/' . $article->slug;
        $before = $this->get($url)->assertOk()->getContent();

        $saved = $this->manager()->update($this->actor($business), $business, $article, [
            'title' => 'A Completely New Headline', 'excerpt' => 'A new excerpt.', 'noindex' => true, 'slug' => 'brand-new-address',
            'body' => $this->goodBody() . "\nA freshly added paragraph.",
        ]);

        // Live columns are untouched; the change is held as a pending draft.
        $live = WebsiteArticle::find($article->id);
        $this->assertSame($article->title, $live->title);
        $this->assertSame($article->slug, $live->slug);
        $this->assertFalse($live->noindex);
        $this->assertSame(ArticleStatus::Published, $live->status);
        $this->assertTrue($live->hasPendingDraft());
        $this->assertSame('A Completely New Headline', $saved->effective()->title);

        // The public article is byte-for-byte what it was, the new slug does not exist, and nothing redirects.
        $this->assertSame($before, $this->get($url)->getContent());
        $this->get(self::HOST . '/blog/brand-new-address')->assertNotFound();
        $this->assertStringNotContainsString('A Completely New Headline', $this->get(self::HOST . '/blog')->getContent());
        $this->assertStringNotContainsString('brand-new-address', $this->get(self::HOST . '/sitemap')->getContent());
        $this->assertSame(0, \App\Models\WebsiteArticleSlugHistory::count(), 'no redirect until the update is published');
    }

    public function test_preview_shows_the_draft_and_publish_update_replaces_the_public_version(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);
        $old = $article->slug;
        $this->manager()->update($this->actor($business), $business, $article, ['title' => 'Updated Planning Headline', 'slug' => 'updated-planning-address']);

        $preview = app(\App\Library\Website\Blog\WebsiteBlogRenderer::class)->renderArticlePreview($website, WebsiteArticle::find($article->id)->effective())->getContent();
        $this->assertStringContainsString('Updated Planning Headline', $preview);
        $this->assertStringNotContainsString('canonical', $preview);

        $this->get(self::HOST . '/blog/' . $old)->assertOk()->assertDontSee('Updated Planning Headline');

        $this->travel(2)->days();
        $updated = $this->manager()->publish($this->actor($business), $business, WebsiteArticle::find($article->id));

        $this->assertFalse($updated->hasPendingDraft());
        $this->assertSame('updated-planning-address', $updated->slug);
        $this->assertTrue($updated->published_at->equalTo($article->published_at), 'the original publish date stays');
        $this->assertTrue($updated->dateModified()->greaterThan($article->published_at));

        $this->get(self::HOST . '/blog/updated-planning-address')->assertOk()->assertSee('Updated Planning Headline');
        $this->get(self::HOST . '/blog/' . $old)->assertStatus(301)->assertRedirect('https://' . self::DOMAIN . '/blog/updated-planning-address');
        $this->assertStringContainsString('/blog/updated-planning-address', $this->get(self::HOST . '/sitemap')->getContent());
    }

    public function test_discard_removes_the_draft_and_archive_stays_explicit(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);
        $actor = $this->actor($business);

        $this->manager()->update($actor, $business, $article, ['title' => 'Throwaway edit']);
        $this->assertTrue(WebsiteArticle::find($article->id)->hasPendingDraft());

        $this->manager()->discardDraft($actor, $business, $article);
        $fresh = WebsiteArticle::find($article->id);
        $this->assertFalse($fresh->hasPendingDraft());
        $this->assertSame($article->title, $fresh->title);

        // An identical save leaves no pending draft at all.
        $this->manager()->update($actor, $business, $fresh, ['title' => $article->title]);
        $this->assertFalse(WebsiteArticle::find($article->id)->hasPendingDraft());

        // Saving never archives; archiving is its own action and drops any pending draft.
        $this->manager()->update($actor, $business, $fresh, ['title' => 'Edit then archive']);
        $this->manager()->archive($actor, $business, WebsiteArticle::find($article->id));
        $archived = WebsiteArticle::find($article->id);
        $this->assertSame(ArticleStatus::Archived, $archived->status);
        $this->assertFalse($archived->hasPendingDraft());
    }

    public function test_publish_update_is_judged_on_the_draft_and_refused_when_it_would_not_pass(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);
        $actor = $this->actor($business);

        $this->manager()->update($actor, $business, $article, ['body' => 'Our award-winning team has 20 years of experience. ' . $this->goodBody()]);

        try {
            $this->manager()->publish($actor, $business, WebsiteArticle::find($article->id));
            $this->fail('an update with an invented claim must not go live');
        } catch (ArticleException $e) {
            $this->assertSame(ArticleException::NOT_READY, $e->reason);
        }

        $this->assertStringNotContainsString('award-winning', $this->get(self::HOST . '/blog/' . $article->slug)->getContent());
    }

    public function test_a_slug_taken_by_another_article_is_refused_when_saving_the_draft(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $other = $this->draftArticle($business, ['title' => 'Another planning note here', 'primary_topic' => 'another planning note']);
        $article = $this->publishedArticle($business);

        $this->expectException(ArticleException::class);
        $this->manager()->update($this->actor($business), $business, $article, ['slug' => $other->slug]);
    }

    // ---------------------------------------------------------------- canonical Website indexing authority

    public function test_blog_indexing_follows_the_websites_own_release_for_search(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $visible = $this->publishedArticle($business);
        $hidden = $this->publishedArticle($business, ['title' => 'Owner hidden planning article', 'primary_topic' => 'owner hidden planning', 'noindex' => true]);

        // The owner has NOT released the website for search: its Home page is still noindex.
        $revision = WebsiteRevision::find($website->published_revision_id);
        $snapshot = $revision->snapshot;
        foreach ($snapshot['pages'] as &$page) {
            $page['seo']['noindex'] = true;
        }
        unset($page);
        $revision->update(['snapshot' => $snapshot]);
        app('cache')->flush();

        $closed = $this->get(self::HOST . '/blog/' . $visible->slug)->assertOk();
        $this->assertStringContainsString('noindex, follow', $closed->getContent());
        $closed->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->assertStringNotContainsString('/blog', $this->get(self::HOST . '/sitemap')->getContent());

        // Released (what "Let search engines find these pages" produces): eligible articles become indexable.
        $snapshot = $revision->fresh()->snapshot;
        foreach ($snapshot['pages'] as &$page) {
            $page['seo']['noindex'] = false;
        }
        unset($page);
        $revision->update(['snapshot' => $snapshot]);
        app('cache')->flush();

        $open = $this->get(self::HOST . '/blog/' . $visible->slug)->assertOk()->assertHeader('X-Robots-Tag', 'index, follow');
        $sitemap = $this->get(self::HOST . '/sitemap')->getContent();
        $this->assertStringContainsString('/blog/' . $visible->slug, $sitemap);

        // An article the owner hid stays noindex and out of the sitemap, released site or not.
        $this->get(self::HOST . '/blog/' . $hidden->slug)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->assertStringNotContainsString('/blog/' . $hidden->slug, $sitemap);
    }

    public function test_the_release_action_and_a_website_rebuild_never_touch_article_owner_intent(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $hidden = $this->publishedArticle($business, ['noindex' => true]);
        $draft = $this->draftArticle($business, ['title' => 'Draft planning article here', 'primary_topic' => 'draft planning article']);
        $before = WebsiteArticle::query()->orderBy('id')->get(['id', 'status', 'noindex', 'slug', 'title', 'body'])->toArray();

        app(WebsiteSearchVisibility::class)->release($website);
        $website->update(['template_key' => 'photo_booth_luxury']);

        $this->assertSame($before, WebsiteArticle::query()->orderBy('id')->get(['id', 'status', 'noindex', 'slug', 'title', 'body'])->toArray());
        $this->assertTrue(WebsiteArticle::find($hidden->id)->noindex);
        $this->assertSame(ArticleStatus::Draft, WebsiteArticle::find($draft->id)->status);
    }
}
