<?php

namespace Tests\Feature\Seo\Content;

use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Website\WebsiteSlugRules;
use App\Models\WebsiteArticle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO Content Engine V1 — the public blog, through the real HTTP kernel on the custom domain and the
 * platform path: one renderer, drafts never public, canonical/robots/sitemap/schema/redirects, no AI call.
 */
class PublicBlogTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private const HOST = 'http://' . self::DOMAIN;

    protected function setUp(): void
    {
        parent::setUp();

        app('cache')->flush();
    }

    private function body(string $url): string
    {
        $response = $this->get($url);
        $response->assertOk();

        return $response->getContent();
    }

    public function test_a_published_article_renders_through_the_website_layout_with_full_seo(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business, ['author_name' => 'Jazmin Rivera']);

        $response = $this->get(self::HOST . '/blog/' . $article->slug);
        $response->assertOk();
        $html = $response->getContent();

        $response->assertHeader('X-Robots-Tag', 'index, follow');
        $this->assertSame(1, substr_count($html, '<h1'), 'exactly one H1');
        $this->assertStringContainsString('<h1 class="blog-article-title">How Much Space Does a Photo Booth Need?</h1>', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . '/blog/' . $article->slug . '">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
        $this->assertStringContainsString('article:published_time', $html);
        $this->assertStringContainsString('wd wd-', $html, 'rendered by the template design, not a second renderer');
        $this->assertStringContainsString('<meta name="description" content="A practical look at the floor space', $html);

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $blocks = array_map(fn ($json) => json_decode($json, true), $m[1]);
        $types = array_column($blocks, '@type');
        $this->assertContains('BlogPosting', $types);
        $this->assertContains('BreadcrumbList', $types);

        $post = $blocks[array_search('BlogPosting', $types, true)];
        $this->assertSame('How Much Space Does a Photo Booth Need?', $post['headline']);
        $this->assertSame('https://' . self::DOMAIN . '/blog/' . $article->slug, $post['mainEntityOfPage']['@id']);
        $this->assertSame($article->published_at->toIso8601String(), $post['datePublished']);
        $this->assertSame('Person', $post['author']['@type']);
        $this->assertSame('Jazmin Rivera', $post['author']['name']);
        $this->assertSame('Jazmin Photo Booth Co.', $post['publisher']['name']);
        $this->assertArrayNotHasKey('aggregateRating', $post);
        $this->assertArrayNotHasKey('review', $post);

        $crumbs = $blocks[array_search('BreadcrumbList', $types, true)]['itemListElement'];
        $this->assertSame(['Home', 'Blog', 'How Much Space Does a Photo Booth Need?'], array_column($crumbs, 'name'));

        // The supporting page link and a single CTA band are present.
        $this->assertStringContainsString('href="https://' . self::DOMAIN . '/service-wedding-photo-booth-rental"', $html);
        $this->assertSame(1, substr_count($html, 'data-blog-cta'));
    }

    public function test_author_defaults_to_the_business_and_is_never_a_person(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $this->body(self::HOST . '/blog/' . $article->slug), $m);
        $post = collect($m[1])->map(fn ($j) => json_decode($j, true))->firstWhere('@type', 'BlogPosting');

        $this->assertSame('Organization', $post['author']['@type']);
        $this->assertSame('Jazmin Photo Booth Co.', $post['author']['name']);
    }

    public function test_drafts_scheduled_and_archived_articles_are_never_public(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $live = $this->publishedArticle($business, ['title' => 'Live article about photo booth planning']);
        $draft = $this->draftArticle($business, ['title' => 'Secret draft topic', 'primary_topic' => 'secret draft topic']);
        $scheduled = $this->draftArticle($business, ['title' => 'Later scheduled topic', 'primary_topic' => 'later scheduled topic']);
        $this->manager()->schedule((int) $business->customer_id, $business, $scheduled, now()->addDay());
        $archived = $this->publishedArticle($business, ['title' => 'Archived photo booth guide', 'primary_topic' => 'archived guide']);
        $this->manager()->archive((int) $business->customer_id, $business, $archived);

        foreach ([$draft, $scheduled, $archived] as $hidden) {
            $this->get(self::HOST . '/blog/' . $hidden->slug)->assertNotFound();
        }

        $index = $this->body(self::HOST . '/blog');
        $this->assertStringContainsString('Live article about photo booth planning', $index);
        foreach (['Secret draft topic', 'Later scheduled topic', 'Archived photo booth guide'] as $title) {
            $this->assertStringNotContainsString($title, $index);
        }

        $sitemap = $this->body(self::HOST . '/sitemap');
        $this->assertStringContainsString('/blog/' . $live->slug, $sitemap);
        foreach ([$draft, $scheduled, $archived] as $hidden) {
            $this->assertStringNotContainsString('/blog/' . $hidden->slug, $sitemap);
        }
    }

    public function test_the_blog_index_is_a_404_until_there_is_a_published_article_and_nav_only_then_links_it(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $this->draftArticle($business);

        $this->get(self::HOST . '/blog')->assertNotFound();
        $this->assertStringNotContainsString('href="https://' . self::DOMAIN . '/blog"', $this->body(self::HOST . '/'));

        $this->publishedArticle($business, ['title' => 'Planning your photo booth setup', 'primary_topic' => 'planning photo booth setup']);

        $index = $this->body(self::HOST . '/blog');
        $this->assertSame(1, substr_count($index, '<h1'));
        $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . '/blog">', $index);
        $this->assertStringContainsString('href="https://' . self::DOMAIN . '/blog"', $this->body(self::HOST . '/'));
    }

    public function test_the_blog_index_paginates(): void
    {
        [, $business] = $this->photoBoothContentTenant();

        foreach (range(1, 11) as $n) {
            $this->publishedArticle($business, ['title' => "Photo booth planning note number {$n}", 'primary_topic' => "unique topic {$n} words" . str_repeat('x', $n)]);
        }

        $first = $this->body(self::HOST . '/blog');
        $this->assertSame(9, substr_count($first, '<article class="blog-card">'));
        $this->assertStringContainsString('rel="next" href="https://' . self::DOMAIN . '/blog?page=2"', $first);

        $second = $this->body(self::HOST . '/blog?page=2');
        $this->assertSame(2, substr_count($second, '<article class="blog-card">'));
        $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . '/blog?page=2">', $second);

        $this->get(self::HOST . '/blog?page=3')->assertNotFound();
        $this->get(self::HOST . '/blog?page=abc')->assertOk();
    }

    public function test_an_owner_noindex_article_is_noindex_and_out_of_the_sitemap_and_schema(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business, ['noindex' => true]);
        $this->publishedArticle($business, ['title' => 'Another indexable article about booths', 'primary_topic' => 'another indexable booths']);

        $response = $this->get(self::HOST . '/blog/' . $article->slug);
        $response->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
        $html = $response->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        $this->assertStringNotContainsString('"BlogPosting"', $html);

        $this->assertStringNotContainsString('/blog/' . $article->slug, $this->body(self::HOST . '/sitemap'));
    }

    public function test_the_blog_cannot_bypass_a_website_the_owner_has_not_opened_to_search(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);

        // The owner has NOT released the site: the published Home page is still noindex.
        $revision = \App\Models\WebsiteRevision::find($website->published_revision_id);
        $snapshot = $revision->snapshot;
        foreach ($snapshot['pages'] as &$page) {
            if ($page['is_home']) {
                $page['seo']['noindex'] = true;
            }
        }
        unset($page);
        $revision->update(['snapshot' => $snapshot]);
        app('cache')->flush();

        $this->get(self::HOST . '/blog/' . $article->slug)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->assertStringContainsString('noindex, follow', $this->body(self::HOST . '/blog'));
        $this->assertStringNotContainsString('/blog', $this->body(self::HOST . '/sitemap'));
    }

    public function test_the_platform_path_serves_the_blog_noindex_with_the_custom_domain_canonical(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);

        $url = route('public.website.blog.show', [$website->public_id, $article->slug]);
        $response = $this->get($url);
        $response->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
        $html = $response->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . '/blog/' . $article->slug . '">', $html);
        $this->assertStringNotContainsString('"BlogPosting"', $html);

        $this->get(route('public.website.blog.index', $website->public_id))->assertOk();
        $this->assertStringContainsString('/blog/' . $article->slug, $this->body(route('public.website.sitemap', $website->public_id)));
    }

    public function test_a_platform_only_site_has_no_canonical_and_no_preview_urls_leak(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant(withDomain: false);
        $article = $this->publishedArticle($business);

        $html = $this->body(route('public.website.blog.show', [$website->public_id, $article->slug]));
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('/preview', $html);
    }

    public function test_changing_a_published_slug_301s_the_old_address_and_archiving_ends_it(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);
        $old = $article->slug;

        $this->manager()->update((int) $business->customer_id, $business, $article, ['slug' => 'photo-booth-space-requirements']);

        $this->get(self::HOST . '/blog/' . $old)->assertStatus(301)->assertRedirect('https://' . self::DOMAIN . '/blog/photo-booth-space-requirements');
        $this->get(self::HOST . '/blog/photo-booth-space-requirements')->assertOk();

        // A second change chains from the ORIGINAL address too.
        $this->manager()->update((int) $business->customer_id, $business, $article->fresh(), ['slug' => 'how-much-room-for-a-booth']);
        $this->get(self::HOST . '/blog/' . $old)->assertStatus(301)->assertRedirect('https://' . self::DOMAIN . '/blog/how-much-room-for-a-booth');

        $this->manager()->archive((int) $business->customer_id, $business, $article->fresh());
        $this->get(self::HOST . '/blog/' . $old)->assertNotFound();
        $this->get(self::HOST . '/blog/how-much-room-for-a-booth')->assertNotFound();
        $this->assertStringNotContainsString('how-much-room-for-a-booth', $this->body(self::HOST . '/sitemap'));
    }

    public function test_a_draft_slug_change_leaves_no_redirect(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $draft = $this->draftArticle($business);
        $this->manager()->update((int) $business->customer_id, $business, $draft, ['slug' => 'renamed-draft']);

        $this->assertSame(0, \App\Models\WebsiteArticleSlugHistory::count());
    }

    public function test_one_business_never_serves_or_redirects_to_another_businesss_articles(): void
    {
        [, $a] = $this->photoBoothContentTenant();
        $this->publishedArticle($a, ['slug' => 'shared-slug']);

        [, $b, , $siteB] = $this->photoBoothContentTenant(withDomain: false);
        $this->assertNotSame(WebsiteArticle::first()->business_id, $b->id);

        // Business B has no such article: its platform path 404s on A's slug.
        $this->get(route('public.website.blog.show', [$siteB->public_id, 'shared-slug']))->assertNotFound();
        $this->get(route('public.website.blog.index', $siteB->public_id))->assertNotFound();
    }

    public function test_internal_references_resolve_only_to_live_indexable_targets(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $liveTarget = $this->publishedArticle($business, ['title' => 'A live target article about booths', 'primary_topic' => 'live target booths']);
        $draftTarget = $this->draftArticle($business, ['title' => 'A draft target article about walls', 'primary_topic' => 'draft target walls']);

        $body = $this->goodBody()
            . "\n\n[wedding page](page:" . self::PAGE_WEDDING . ") and [hidden page](page:" . self::PAGE_NOINDEX . ")"
            . " and [live one](article:{$liveTarget->uid}) and [draft one](article:{$draftTarget->uid})"
            . " and [foreign](page:aaaaaaaa-0000-4000-8000-0000000000ff)\n";
        $article = $this->publishedArticle($business, ['title' => 'Linking article about photo booths', 'primary_topic' => 'linking photo booths', 'body' => $body]);

        $page = $this->body(self::HOST . '/blog/' . $article->slug);
        preg_match('#<div class="blog-prose">(.*?)</div>\s*</article>#s', $page, $m);
        $html = $m[1] ?? '';
        $this->assertNotSame('', $html, 'the article body rendered');

        $this->assertStringContainsString('<a href="https://' . self::DOMAIN . '/service-wedding-photo-booth-rental">wedding page</a>', $html);
        $this->assertStringContainsString('<a href="https://' . self::DOMAIN . '/blog/' . $liveTarget->slug . '">live one</a>', $html);
        foreach (['hidden-page', $draftTarget->slug, 'aaaaaaaa-0000-4000-8000-0000000000ff', 'page:', 'article:'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "the article body never links to or leaks {$forbidden}");
        }
        $this->assertStringContainsString('hidden page', $html, 'an unresolvable link falls back to plain text');
    }

    public function test_body_html_and_unsafe_links_are_neutralised(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $body = $this->goodBody() . "\n\n<script>alert(1)</script> <img src=x onerror=alert(2)> [bad](javascript:alert(3)) ![pic](https://evil.example/x.png)\n\n# Sneaky H1\n";
        $article = $this->publishedArticle($business, ['body' => $body]);

        $html = $this->body(self::HOST . '/blog/' . $article->slug);

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertSame(1, substr_count($html, '<h1'), 'a body H1 is demoted; the page keeps exactly one H1');
    }

    public function test_reading_the_blog_makes_no_ai_or_provider_call(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);

        $fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $fake);
        Http::preventStrayRequests();

        // Build the platform URL before any custom-domain request: the URL generator keeps the last request's host.
        $platformUrl = route('public.website.blog.show', [$website->public_id, $article->slug]);

        $this->get($platformUrl)->assertOk();
        $this->get(self::HOST . '/blog')->assertOk();
        $this->get(self::HOST . '/blog/' . $article->slug)->assertOk();
        $this->get(self::HOST . '/sitemap')->assertOk();

        $this->assertSame(0, $fake->callCount());
    }

    public function test_blog_is_a_reserved_slug_for_pages(): void
    {
        $this->assertFalse(WebsiteSlugRules::isValid('blog'));
    }

    public function test_every_template_renders_the_index_and_article(): void
    {
        foreach (['photo_booth_modern', 'photo_booth_editorial', 'photo_booth_luxury', 'photo_booth_conversion'] as $template) {
            [, $business, , $website] = $this->photoBoothContentTenant(templateKey: $template);
            $article = $this->publishedArticle($business);

            $slugBody = $this->body(self::HOST . '/blog/' . $article->slug);
            $this->assertStringContainsString('wd-' . substr($template, strlen('photo_booth_')), $slugBody, $template);
            $this->assertSame(1, substr_count($slugBody, '<h1'), $template);
            $this->assertStringContainsString('data-wd-header', $slugBody, $template);
            $this->assertStringContainsString('blog-prose', $slugBody, $template);

            $index = $this->body(self::HOST . '/blog');
            $this->assertSame(1, substr_count($index, '<h1'), $template);

            // The next loop iteration creates a fresh Business, so clear the shared host mapping.
            \App\Models\WebsiteDomain::query()->delete();
            $this->contentTenantCount = 0;
            app('cache')->flush();
        }
    }

    public function test_the_preview_shows_a_draft_noindex_with_a_banner_and_never_a_canonical(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $draft = $this->draftArticle($business);

        $response = app(\App\Library\Website\Blog\WebsiteBlogRenderer::class)->renderArticlePreview($website, $draft);
        $html = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('website-preview-banner', $html);
        $this->assertStringContainsString('noindex, follow', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('"BlogPosting"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }
}
