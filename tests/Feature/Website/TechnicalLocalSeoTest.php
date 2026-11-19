<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsitePublisher;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\Feature\Website\Concerns\PublishesGeneratedSites;
use Tests\TestCase;

/**
 * Slice 3B — technical local SEO of a GENERATED, PUBLISHED website, judged on the rendered HTML, canonical URLs, robots, sitemap
 * and internal links of the real custom-domain response (fake AI only; no network).
 */
class TechnicalLocalSeoTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;
    use PublishesGeneratedSites;
    use RefreshDatabase;

    private const DOMAIN = 'harborbooths.test';

    private const AREAS = [
        ['name' => 'Hattiesburg, MS', 'details' => ['venues' => ['The Hattiesburg Saenger Theater', 'Lake Terrace Convention Center'], 'logistics' => 'About 20 minutes from Petal; we load in two hours before the event.']],
        ['name' => 'Laurel, MS', 'details' => ['venues' => ['The Landrum Center'], 'logistics' => 'About 45 minutes from Petal; parking is behind the hall.']],
        ['name' => 'Biloxi, MS'],   // no confirmed local fact: never a page
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
        config(['ai.website_content_quality' => true]);
    }

    public function test_every_indexable_page_has_one_self_referencing_canonical_and_matching_robots_and_sitemap(): void
    {
        [$website] = $this->publishedSite();
        $pages = $this->render($website);
        $this->assertGreaterThanOrEqual(8, count($pages));

        $sitemap = $this->get($this->url('/sitemap.xml'))->assertOk()->getContent();
        preg_match_all('#<loc>([^<]+)</loc>#', $sitemap, $m);
        $listed = $m[1];

        foreach ($pages as $path => $html) {
            $expected = 'https://' . self::DOMAIN . ($path === '/' ? '/' : $path);
            $this->assertSame(1, preg_match_all('#<link rel="canonical" href="([^"]+)">#', $html, $c), "{$path}: exactly one canonical");
            $this->assertSame($expected, $c[0] === '' ? null : html_entity_decode(preg_replace('#.*<link rel="canonical" href="([^"]+)">.*#s', '$1', $html)), "{$path}: self-referencing");
            $this->assertSame(1, substr_count($html, '<meta name="robots"'), "{$path}: one robots tag");
            $this->assertSame(1, substr_count($html, '<title>'), "{$path}: one title");
            $this->assertSame(1, substr_count($html, 'name="description"'), "{$path}: one meta description");
            $this->assertSame(1, substr_count($html, '<h1'), "{$path}: one h1");
            $indexable = str_contains($html, '<meta name="robots" content="index, follow">');
            $this->assertSame($indexable, in_array($expected, $listed, true), "{$path}: sitemap lists exactly the indexable pages");
        }

        $this->assertSame(count($listed), count(array_unique($listed)));
        $this->assertStringContainsString('Sitemap: https://' . self::DOMAIN . '/sitemap.xml', $this->get($this->url('/robots.txt'))->getContent());
        $titles = array_map(fn ($html) => preg_replace('#.*<title>(.*?)</title>.*#s', '$1', $html), $pages);
        $this->assertSame(count($titles), count(array_unique($titles)), 'no duplicate titles across pages');
        $descriptions = array_map(fn ($html) => preg_replace('#.*name="description" content="([^"]*)".*#s', '$1', $html), $pages);
        $this->assertSame(count($descriptions), count(array_unique($descriptions)), 'no duplicate meta descriptions across pages');
    }

    public function test_the_home_page_links_to_every_dedicated_service_page_and_each_service_page_leads_to_the_contact_page(): void
    {
        [$website] = $this->publishedSite();
        $pages = $this->render($website);
        $snapshot = $website->fresh()->publishedRevision->snapshot['pages'];
        $servicePaths = collect($snapshot)->filter(fn ($p) => str_starts_with((string) $p['slug'], 'service-'))->map(fn ($p) => '/' . $p['slug'])->values()->all();
        $contactPath = '/' . collect($snapshot)->firstWhere('slug', 'photo-booth-contact')['slug'];
        $this->assertNotEmpty($servicePaths);

        // Contextual links in the page body (not just the site navigation).
        $homeBody = preg_replace('#<(header|nav)\b.*?</\1>#s', '', $pages['/']);
        foreach ($servicePaths as $service) {
            $this->assertContains($service, $this->links($homeBody), "the home page body links to {$service}");
            $this->assertContains($contactPath, $this->links($pages[$service]), "{$service} leads to the contact page");
        }
    }

    public function test_qualified_location_pages_link_to_relevant_services_and_unqualified_areas_get_no_page(): void
    {
        [$website] = $this->publishedSite();
        $pages = $this->render($website);
        $slugs = collect($website->fresh()->publishedRevision->snapshot['pages'])->pluck('slug')->filter()->all();

        $this->assertContains('serving-hattiesburg-ms', $slugs);
        $this->assertContains('serving-laurel-ms', $slugs);
        $this->assertNotContains('serving-biloxi-ms', $slugs, 'an area with no confirmed local fact never becomes a page');

        foreach (['/serving-hattiesburg-ms', '/serving-laurel-ms'] as $area) {
            $body = preg_replace('#<(header|nav)\b.*?</\1>#s', '', $pages[$area]);
            $serviceLinks = array_filter($this->links($body), fn ($path) => str_starts_with($path, '/service-'));
            $this->assertNotEmpty($serviceLinks, "{$area} links to at least one dedicated service page");
            $this->assertStringContainsString('The Landrum Center', $pages['/serving-laurel-ms'], 'confirmed venue facts only');
        }
    }

    public function test_structured_data_is_consistent_and_uses_only_confirmed_facts(): void
    {
        [$website] = $this->publishedSite();
        $pages = $this->render($website);

        foreach ($pages as $path => $html) {
            preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
            $blocks = array_map(fn ($json) => json_decode($json, true), $m[1]);
            $types = array_column($blocks, '@type');
            $this->assertSame(count($types), count(array_unique($types)), "{$path}: no duplicate schema type");
            $this->assertContains('LocalBusiness', $types, "{$path}: LocalBusiness");

            $local = $blocks[array_search('LocalBusiness', $types, true)];
            $this->assertSame('Harbor Booths', $local['name']);
            $this->assertArrayNotHasKey('aggregateRating', $local, 'no invented reviews');
            $this->assertArrayNotHasKey('award', $local, 'no invented awards');
            foreach ((array) ($local['areaServed'] ?? []) as $area) {
                $this->assertContains(is_array($area) ? $area['name'] : $area, ['Petal', 'Hattiesburg', 'Laurel', 'Biloxi', 'Mississippi'], 'areaServed only names confirmed places');
            }
            if ($path !== '/') {
                $crumbs = $blocks[array_search('BreadcrumbList', $types, true)]['itemListElement'];
                $this->assertSame('https://' . self::DOMAIN . '/', $crumbs[0]['item']);
            }
        }
    }

    public function test_the_platform_path_and_owner_preview_are_never_indexable_and_carry_no_canonical(): void
    {
        [$website] = $this->publishedSite();

        // A site WITH a custom domain moves its platform path to the domain (301); the platform path is never an index candidate.
        $this->get(route('public.website.home', $website->public_id))->assertStatus(301);

        $website->domains()->delete();
        $response = $this->get(route('public.website.home', $website->public_id))->assertOk();
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringNotContainsString('rel="canonical"', $response->getContent());
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $response->getContent());
    }

    public function test_before_the_owner_lets_search_engines_in_nothing_is_indexable_or_in_the_sitemap(): void
    {
        [$website] = $this->publishedSite();
        $website->forceFill(['indexing_released_at' => null])->save();
        \App\Models\WebsitePage::where('website_id', $website->id)->update(['noindex' => true]);
        // The published snapshot is what is served: republish the draft as generated (held back until released).
        $website->pages()->update(['noindex' => true]);
        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());

        $this->get($this->url('/'))->assertOk()->assertSee('<meta name="robots" content="noindex, follow">', false);
        $this->get($this->url('/sitemap.xml'))->assertNotFound();
        $this->assertStringNotContainsString('Sitemap:', $this->get($this->url('/robots.txt'))->getContent());
    }

    public function test_a_business_without_confirmed_local_evidence_gets_no_invented_places_or_location_pages(): void
    {
        [$website] = $this->publishedSite('booth', false, false);
        $pages = $this->render($website);

        $this->assertEmpty(array_filter(array_keys($pages), fn ($path) => str_starts_with($path, '/serving-')), 'no location pages without confirmed areas');

        foreach ($pages as $path => $html) {
            preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
            $blocks = array_map(fn ($json) => json_decode($json, true), $m[1]);
            $local = collect($blocks)->firstWhere('@type', 'LocalBusiness');
            $this->assertNotNull($local, "{$path}: still a LocalBusiness entity");
            $this->assertArrayNotHasKey('areaServed', $local, "{$path}: no invented service area");
            $this->assertArrayNotHasKey('address', $local, "{$path}: no invented address");
            $this->assertArrayNotHasKey('aggregateRating', $local);
            $this->assertStringNotContainsString('Hattiesburg', $html);
        }

        // Still technically sound: every page indexable with its own canonical, and the services are reachable from the home page.
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $pages['/']);
        $this->assertNotEmpty(array_filter($this->links($pages['/']), fn ($p) => str_starts_with($p, '/service-')));
    }

    public function test_a_different_niche_gets_the_same_technical_guarantees_from_its_own_services(): void
    {
        [$website] = $this->publishedSite('tutoring');
        $pages = $this->render($website);
        $servicePaths = array_values(array_filter(array_keys($pages), fn ($p) => str_starts_with($p, '/service-')));

        $this->assertSame(['/service-sat-preparation', '/service-algebra-tutoring', '/service-reading-support'], $servicePaths);
        $homeBody = preg_replace('#<(header|nav)\b.*?</\1>#s', '', $pages['/']);
        foreach ($servicePaths as $service) {
            $this->assertContains($service, $this->links($homeBody));
            $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . $service . '">', $pages[$service]);
        }
        foreach ($pages as $html) {
            $this->assertStringNotContainsStringIgnoringCase('Magic Mirror', $html, 'no other niche\'s services leak in');
        }
    }

    public function test_native_blog_articles_link_to_their_service_page_and_share_the_same_canonical_sitemap_and_schema(): void
    {
        [$website, $business] = $this->publishedSite();
        $service = collect($website->fresh()->publishedRevision->snapshot['pages'])->firstWhere('slug', 'service-magic-mirror-photo-booth');
        $paragraph = 'Planning a photo booth for your event starts with the guest list, the venue layout and the time you want the booth open. A booth works best when guests can walk up easily, so choose a spot near the action but away from the busiest doorway. Think about how long the line may get during peak moments, and leave enough room for a small group to pose together. ';
        $manager = app(\App\Library\Seo\Content\ArticleManager::class);
        $article = $manager->create((int) $business->customer_id, $business, [
            'title' => 'How Much Space Does a Photo Booth Need?',
            'excerpt' => 'A practical look at the floor space, power and layout a photo booth needs at your venue.',
            'body' => "## What to plan first\n\n" . str_repeat($paragraph, 2) . "\n\n## Choosing the right setup\n\n" . str_repeat($paragraph, 2),
            'meta_description' => 'A practical look at the floor space, power and layout a photo booth needs at your venue, with tips for planning.',
            'primary_topic' => 'how much space does a photo booth need',
            'search_intent' => 'planning',
            'supports_page_uid' => $service['uid'],
        ]);
        $manager->publish((int) $business->customer_id, $business, $article);

        $html = $this->get($this->url('/blog/' . $article->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . '/blog/' . $article->slug . '">', $html);
        $this->assertContains('/service-magic-mirror-photo-booth', $this->links(preg_replace('#<(header|nav)\b.*?</\1>#s', '', $html)), 'the article links to the service page it supports');
        $this->assertSame(1, substr_count($html, 'data-blog-cta'));
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $types = array_column(array_map(fn ($j) => json_decode($j, true), $m[1]), '@type');
        foreach (['BlogPosting', 'BreadcrumbList', 'LocalBusiness'] as $type) {
            $this->assertContains($type, $types);
        }
        $this->assertSame(count($types), count(array_unique($types)));

        $sitemap = $this->get($this->url('/sitemap.xml'))->getContent();
        $this->assertStringContainsString('https://' . self::DOMAIN . '/blog/' . $article->slug, $sitemap);
        $this->assertStringContainsString('https://' . self::DOMAIN . '/blog</loc>', $sitemap);
        // The service page, in turn, is offered the article as supporting reading.
        $this->assertStringContainsString('/blog/' . $article->slug, $this->get($this->url('/service-magic-mirror-photo-booth'))->getContent());
    }

    public function test_an_unpublished_site_exposes_nothing_publicly(): void
    {
        [$website] = $this->publishedSite('booth', true, true, false);

        $this->assertNull($website->published_revision_id);
        $this->get(route('public.website.home', $website->public_id))->assertNotFound();
    }
}
