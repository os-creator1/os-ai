<?php

namespace Tests\Feature\Website;

use App\Library\Website\Blog\WebsiteRelatedArticles;
use App\Models\WebsiteArticle;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Slice 6C — FAQ answers keep their paragraphs and a FAQ shown elsewhere links to the FAQ page at its CURRENT address; commercial pages and
 * the home page point to published, relevant, indexable guides of THIS site only, at the address of the surface they are rendered on.
 */
class FaqAndGuideLinksTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private const HOST = 'http://' . self::DOMAIN;

    private const FAQ_UID = 'aaaaaaaa-0000-4000-8000-000000000020';

    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->flush();
    }

    private function body(string $url): string
    {
        return $this->get($url)->assertOk()->getContent();
    }

    /** Rewrites the published snapshot's pages (test fixture only) and drops the render cache. */
    private function republishPages(\App\Models\Website $website, callable $edit): void
    {
        $revision = WebsiteRevision::query()->findOrFail($website->published_revision_id);
        $snapshot = $revision->snapshot;
        $snapshot['pages'] = array_values($edit($snapshot['pages']));
        $revision->update(['snapshot' => $snapshot]);
        app('cache')->flush();
    }

    private function guide($business, string $title, string $topic, array $extra = []): WebsiteArticle
    {
        return $this->publishedArticle($business, array_merge(['title' => $title, 'primary_topic' => $topic, 'excerpt' => "A short summary of {$title}."], $extra));
    }

    private function faqSection(string $answer = "First paragraph of the answer.\n\nSecond paragraph of the answer."): array
    {
        return ['type' => 'faq', 'data' => ['heading' => 'Questions', 'items' => [['question' => 'How do I book?', 'answer' => $answer], ['question' => 'How long is setup?', 'answer' => 'About an hour.']]]];
    }

    // ------------------------------------------------------------------ guides

    public function test_the_home_page_shows_the_newest_published_guides_as_cards_at_their_current_addresses(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        // An older article that explicitly supports home must not jump ahead of newer posts in the "Latest guides" row.
        $homeSupported = $this->guide($business, 'Older home guide', 'older home guide', ['supports_page_uid' => self::PAGE_HOME]);
        $this->travel(2)->minutes();
        $titles = ['Wedding reception timeline planning', 'Corporate event booth branding ideas', 'Birthday party booth setup tips', 'Graduation party photo ideas'];
        $topics = ['wedding reception timeline planning', 'corporate event booth branding', 'birthday party booth setup', 'graduation party photo ideas'];
        $articles = [];
        foreach ($titles as $i => $title) {
            $articles[] = $this->guide($business, $title, $topics[$i]);
            $this->travel(2)->minutes();
        }

        $home = $this->body(self::HOST . '/');

        $this->assertStringContainsString('data-testid="related-articles"', $home);
        $this->assertStringContainsString('Latest guides', $home);
        foreach ([1, 2, 3] as $newest) {
            $this->assertStringContainsString('<a href="https://' . self::DOMAIN . '/blog/' . $articles[$newest]->slug . '">' . $titles[$newest] . '</a>', $home);
            $this->assertStringContainsString("A short summary of {$titles[$newest]}.", $home, 'a short excerpt');
        }
        $this->assertStringNotContainsString($articles[0]->slug, $home, 'at most three, newest first');
        $this->assertStringNotContainsString($homeSupported->slug, $home, 'an older home-supporting article does not displace newer guides');
        $this->assertSame(3, substr_count($home, '>Read the guide &rarr;</a>'), 'one read link per card');
    }

    public function test_a_site_without_published_guides_has_no_guide_band_and_no_blog_links(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $this->draftArticle($business, ['title' => 'A draft only', 'primary_topic' => 'draft only topic']);

        foreach (['/', '/service-wedding-photo-booth-rental', '/photo-booth-about'] as $path) {
            $html = $this->body(self::HOST . $path);
            $this->assertStringNotContainsString('data-testid="related-articles"', $html, "{$path}: nothing to link");
            $this->assertStringNotContainsString('href="https://' . self::DOMAIN . '/blog', $html, "{$path}: no dead blog link");
        }
    }

    public function test_only_published_indexable_guides_of_this_site_are_ever_linked(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $live = $this->guide($business, 'Live wedding timing guide', 'live wedding timing guide');
        $draft = $this->draftArticle($business, ['title' => 'Secret draft idea', 'primary_topic' => 'secret draft idea']);
        $scheduled = $this->draftArticle($business, ['title' => 'Later scheduled idea', 'primary_topic' => 'later scheduled idea']);
        $this->manager()->schedule((int) $business->customer_id, $business, $scheduled, now()->addDay());
        $archived = $this->guide($business, 'Archived old guide', 'archived old guide');
        $this->manager()->archive((int) $business->customer_id, $business, $archived);
        $hidden = $this->guide($business, 'Hidden from search guide', 'hidden from search guide', ['noindex' => true]);

        // Another tenant's published guide must never appear on this site.
        [, $other] = $this->photoBoothContentTenant();
        $foreign = $this->guide($other, 'Another business guide about weddings', 'another business wedding guide');

        $home = $this->body(self::HOST . '/');

        $this->assertStringContainsString($live->slug, $home);
        foreach ([$draft, $scheduled, $archived, $hidden, $foreign] as $notLinked) {
            $this->assertStringNotContainsString($notLinked->slug, $home, "{$notLinked->title} is not linked");
            $this->assertStringNotContainsString($notLinked->title, $home);
        }
    }

    public function test_a_page_shows_the_guides_that_support_it_and_a_page_none_supports_shows_nothing(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $supporting = $this->guide($business, 'Wedding booth timing guide', 'wedding booth timing guide', ['supports_page_uid' => self::PAGE_WEDDING]);
        $this->guide($business, 'Corporate booth branding guide', 'corporate booth branding guide', ['supports_page_uid' => self::PAGE_CORPORATE]);

        $wedding = $this->body(self::HOST . '/service-wedding-photo-booth-rental');

        $this->assertStringContainsString('Helpful guides', $wedding);
        $this->assertStringContainsString('<a href="https://' . self::DOMAIN . '/blog/' . $supporting->slug . '">Wedding booth timing guide</a>', $wedding);
        $this->assertStringNotContainsString('Corporate booth branding guide', $wedding, 'only the guides that support THIS page');
        $this->assertStringNotContainsString('data-testid="related-articles"', $this->body(self::HOST . '/photo-booth-about'));
    }

    public function test_guide_links_use_the_address_of_the_surface_they_are_rendered_on(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant(withDomain: false);
        $article = $this->guide($business, 'Platform path guide', 'platform path guide');

        $home = $this->body(route('public.website.home', $website->public_id));

        $this->assertStringContainsString(route('public.website.blog.show', [$website->public_id, $article->slug]), $home, 'the platform-path address, not a hard-coded domain');
        $this->assertStringNotContainsString(self::DOMAIN, $home);
    }

    public function test_the_owner_preview_never_resolves_guides(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $this->guide($business, 'Published guide', 'published guide');
        $page = (object) ['uid' => self::PAGE_HOME, 'is_home' => true];
        $nav = [['slug' => 'blog', 'url' => 'https://' . self::DOMAIN . '/blog']];

        $this->assertSame([], app(WebsiteRelatedArticles::class)->forPage($website, $page, $nav, true)['items']);
        $this->assertCount(1, app(WebsiteRelatedArticles::class)->forPage($website, $page, $nav, false)['items']);
        $this->assertSame([], app(WebsiteRelatedArticles::class)->forPage($website, $page, [], false)['items'], 'no live blog entry, no links');
    }

    public function test_home_ranks_guides_supporting_service_pages_first_then_newest_and_falls_back_to_newest(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $page = (object) ['uid' => self::PAGE_HOME, 'is_home' => true];
        $nav = [
            ['slug' => 'blog', 'url' => 'https://' . self::DOMAIN . '/blog'],
            ['uid' => self::PAGE_HOME, 'is_home' => true, 'slug' => 'home'],
            ['uid' => self::PAGE_WEDDING, 'slug' => 'service-wedding-photo-booth-rental'],
            ['uid' => self::PAGE_CORPORATE, 'slug' => 'service-corporate'],
        ];
        $titles = fn () => array_column(app(WebsiteRelatedArticles::class)->forPage($website, $page, $nav, false)['items'], 'title');

        // No relationship data at all: newest first.
        $this->guide($business, 'Plain one', 'plain one', ['supports_page_uid' => null]);
        $this->travel(2)->minutes();
        $this->guide($business, 'Plain two', 'plain two', ['supports_page_uid' => null]);
        $this->travel(2)->minutes();
        $this->guide($business, 'Plain three', 'plain three', ['supports_page_uid' => null]);
        $this->travel(2)->minutes();
        $this->assertSame(['Plain three', 'Plain two', 'Plain one'], $titles(), 'fallback is newest-eligible');

        // Relevant (supports a current service page) outranks newer plain guides; recency orders the relevant ones among themselves.
        $this->guide($business, 'Old wedding', 'old wedding', ['supports_page_uid' => self::PAGE_WEDDING]);
        $this->travel(2)->minutes();
        $this->guide($business, 'New corporate', 'new corporate', ['supports_page_uid' => self::PAGE_CORPORATE]);
        $this->travel(2)->minutes();
        $this->guide($business, 'Newest plain', 'newest plain', ['supports_page_uid' => null]);
        $this->travel(2)->minutes();
        $this->assertSame(['New corporate', 'Old wedding', 'Newest plain'], $titles(), 'relevance first, date breaks ties, newest fills the rest');

        // A stale/unknown page uid or support for home itself is not a service-page relationship.
        $stale = $this->guide($business, 'Stale support', 'stale support', ['supports_page_uid' => null]);
        WebsiteArticle::query()->whereKey($stale->id)->update(['supports_page_uid' => 'aaaaaaaa-0000-4000-8000-0000000000ff']); // page since removed
        $this->guide($business, 'Home support', 'home support', ['supports_page_uid' => self::PAGE_HOME]);
        $this->travel(2)->minutes();
        $this->assertSame(['New corporate', 'Old wedding', 'Home support'], $titles(), 'only current service pages count as relevant; max three');
    }

    public function test_home_relevance_ranking_never_admits_an_ineligible_guide(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $this->guide($business, 'Live plain', 'live plain', ['supports_page_uid' => null]);
        $draft = $this->draftArticle($business, ['title' => 'Relevant draft', 'primary_topic' => 'relevant draft', 'supports_page_uid' => self::PAGE_WEDDING]);
        $hidden = $this->guide($business, 'Relevant hidden', 'relevant hidden', ['supports_page_uid' => self::PAGE_WEDDING, 'noindex' => true]);
        [, $other] = $this->photoBoothContentTenant();
        $this->guide($other, 'Foreign relevant', 'foreign relevant', ['supports_page_uid' => self::PAGE_WEDDING]);
        $page = (object) ['uid' => self::PAGE_HOME, 'is_home' => true];
        $nav = [['slug' => 'blog', 'url' => 'https://' . self::DOMAIN . '/blog'], ['uid' => self::PAGE_WEDDING, 'slug' => 'w']];

        $resolver = app(WebsiteRelatedArticles::class);
        $this->assertSame(['Live plain'], array_column($resolver->forPage($website, $page, $nav, false)['items'], 'title'));
        $this->assertSame([], $resolver->forPage($website, $page, $nav, true)['items'], 'none in Preview');
        $this->assertSame([], $resolver->forPage($website, $page, [['uid' => self::PAGE_WEDDING]], false)['items'], 'no live blog, none');
    }

    // ------------------------------------------------------------------ FAQ

    public function test_faq_answers_keep_their_paragraphs_and_the_stylesheet_preserves_them(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        $this->republishPages($website, function (array $pages) {
            $pages[] = $this->snapshotPage(self::FAQ_UID, 'FAQ', [], [['type' => 'hero', 'data' => ['heading' => 'FAQ', 'subheading' => null, 'primary_cta' => null, 'secondary_cta' => null]], $this->faqSection()], false, 'photo-booth-faq');

            return $pages;
        });

        $html = $this->body(self::HOST . '/photo-booth-faq');

        $this->assertStringContainsString("First paragraph of the answer.\n\nSecond paragraph of the answer.", $html, 'the answer\'s own paragraph break reaches the page');
        $this->assertStringContainsString('<details class="website-faq-item">', $html);
        $this->assertStringContainsString('white-space: pre-line', file_get_contents(public_path('css/website-design.css')));
        $this->assertStringNotContainsString('All questions &amp; answers', $html, 'the FAQ page does not link to itself');
    }

    public function test_a_faq_on_another_page_links_to_the_faq_page_at_its_current_address_and_only_when_one_exists(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        $withFaq = function (string $faqSlug) {
            return function (array $pages) use ($faqSlug) {
                foreach ($pages as &$page) {
                    if (! empty($page['is_home'])) {
                        $page['sections'][] = $this->faqSection();
                    }
                }
                unset($page);
                $pages[] = $this->snapshotPage(self::FAQ_UID, 'FAQ', [], [['type' => 'hero', 'data' => ['heading' => 'FAQ', 'subheading' => null, 'primary_cta' => null, 'secondary_cta' => null]], $this->faqSection()], false, $faqSlug);

                return $pages;
            };
        };

        $this->republishPages($website, $withFaq('photo-booth-faq'));
        $home = $this->body(self::HOST . '/');
        $this->assertStringContainsString('<a href="https://' . self::DOMAIN . '/photo-booth-faq">All questions &amp; answers &rarr;</a>', $home);
        $this->assertSame(1, substr_count($home, 'All questions &amp; answers'));

        // Another niche's FAQ address (Dog Boarding's "faqs") is followed, not a hard-coded route.
        $this->republishPages($website, fn (array $pages) => array_map(fn ($page) => ($page['slug'] ?? null) === 'photo-booth-faq' ? array_merge($page, ['slug' => 'faqs']) : $page, $pages));
        $this->assertStringContainsString('href="https://' . self::DOMAIN . '/faqs">All questions', $this->body(self::HOST . '/'));
        $this->assertStringNotContainsString('photo-booth-faq', $this->body(self::HOST . '/'));

        // A site with no FAQ page shows the FAQ without any such link.
        $this->republishPages($website, fn (array $pages) => array_filter($pages, fn ($page) => ($page['uid'] ?? null) !== self::FAQ_UID));
        $home = $this->body(self::HOST . '/');
        $this->assertStringContainsString('<details class="website-faq-item">', $home);
        $this->assertStringNotContainsString('All questions', $home);
    }
}
