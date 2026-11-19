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
use Tests\TestCase;

/**
 * Slice 3B — technical local SEO of a GENERATED, PUBLISHED website, judged on the rendered HTML, canonical URLs, robots, sitemap
 * and internal links of the real custom-domain response (fake AI only; no network).
 */
class TechnicalLocalSeoTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;
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

    /** A published, generated site: [Website, array<string, string> page slug => page_type]. */
    private function publishedSite(string $niche = 'booth', bool $withAreas = true, bool $withLocation = true, bool $publish = true): array
    {
        [$customer, $business] = $this->entitledTenant(['name' => $niche === 'booth' ? 'Harbor Booths' : 'Petal Tutoring']);
        $names = $niche === 'booth' ? ['Magic Mirror Photo Booth', 'Open-Air Photo Booth', '360 Video Booth'] : ['SAT Preparation', 'Algebra Tutoring', 'Reading Support'];

        foreach ($names as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => Str::slug($name), 'description' => "What {$name} is: a specific, bookable service with its own details, requirements and outcomes for customers.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i + 1]);
        }

        if ($withLocation) {
            (new BusinessLocation())->forceFill(['business_id' => $business->id, 'is_primary' => true, 'service_mode' => 'service_area', 'name' => 'Main', 'city' => 'Petal', 'region' => 'Mississippi', 'country_code' => 'US', 'service_area_cities' => ['Hattiesburg', 'Laurel', 'Biloxi']])->save();
        }
        \Illuminate\Support\Facades\DB::table('businesses')->where('id', $business->id)->update(['phone' => '6015550142', 'email' => 'hello@harborbooths.example']);

        // The owner has already let search engines in, so generated pages are born indexable.
        $website = $this->createWebsite($business->fresh(), ['name' => 'Harbor', 'indexing_released_at' => now()]);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $this->fakeProvider();

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business->fresh(), $website, $template, $customer->user_id, 'idem-3b-' . $niche, null, null, null, null, $withAreas ? self::AREAS : null);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);

        if (! $publish) {
            return [$website->fresh(), $business->fresh()];
        }

        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
        $website->fresh()->domains()->create(['domain' => self::DOMAIN, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 'x', 'verified_at' => now(), 'activated_at' => now()]);

        return [$website->fresh(), $business->fresh()];
    }

    /** Typical model output: hero + services list (names only, as the schema allows) + copy built from the request's own facts. */
    private function fakeProvider(): void
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function (array $messages) {
            $payload = json_decode(collect($messages)->firstWhere('role', 'user')['content'], true);

            return json_encode(['pages' => array_map(fn (array $entry) => $this->page($entry, $payload), $payload['plan'])]);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }

    private function page(array $entry, array $payload): array
    {
        $context = $payload['site_context'] ?? [];
        $city = $context['primary_market']['city'] ?? null;
        $in = $city === null ? '' : ' in ' . $city;
        $contact = collect($payload['site_pages'] ?? [])->firstWhere('page_type', 'contact')['path'] ?? '/photo-booth-contact';
        $key = $entry['page_key'];
        $label = (string) ($entry['entity']['name'] ?? $entry['entity']['area'] ?? ucfirst(str_replace('_', ' ', $entry['page_type'])));
        $unique = hash('sha256', $key) . hash('sha256', $key . 'b');
        $allowsText = in_array('text', $entry['allowed_section_types'] ?? [], true);
        $copy = fn (string $heading, string $body) => $allowsText
            ? ['type' => 'text', 'data' => ['heading' => $heading, 'body' => $body]]
            : ['type' => 'image_text', 'data' => ['heading' => $heading, 'body' => $body, 'image' => null, 'image_position' => 'left']];
        $hero = fn (string $heading) => ['type' => 'hero', 'data' => ['heading' => $heading, 'subheading' => null, 'primary_cta' => ['label' => 'Check availability', 'url' => $contact], 'secondary_cta' => null]];

        switch (true) {
            case $entry['page_type'] === 'home':
                $services = array_column($context['services'] ?? [], 'name');
                $sections = [
                    $hero(($context['primary_service'] ?? 'Our services') . $in),
                    ['type' => 'services', 'data' => ['heading' => 'What we offer', 'items' => array_map(fn ($name) => ['name' => $name, 'description' => 'Book ' . $name . ' for your event.', 'price_label' => null], $services)]],
                    $copy('Where we work', 'We work with customers ' . ($context['areas_served'] ?? [] ? 'in ' . implode(', ', $context['areas_served']) : 'by arrangement') . '.'),
                ];
                break;
            case isset($entry['entity']['area']):
                $facts = $entry['entity']['local_facts'] ?? [];
                $sections = [
                    $hero('Services in ' . $entry['entity']['area']),
                    $copy('Venues and setup in ' . $entry['entity']['area'], 'We have set up at ' . implode(' and ', $facts['venues'] ?? []) . '. ' . ($facts['logistics'] ?? '') . ' Ref ' . $unique),
                    $copy('Booking in ' . $entry['entity']['area'], 'Tell us the date and venue. Ref ' . substr($unique, 10)),
                ];
                break;
            case in_array($entry['page_type'], ['service_detail', 'event_detail'], true):
                $sections = [
                    $hero($label . $in),
                    $copy('What ' . $label . ' is', ($entry['entity']['description'] ?? $label) . ' Details: ' . $unique),
                    $copy('Booking ' . $label, 'Tell us the date and venue to confirm availability. Reference ' . substr($unique, 0, 20)),
                ];
                break;
            default:
                $sections = [$hero($label . ' ' . substr($unique, 0, 8))];
        }

        return ['page_key' => $key, 'title' => $label . ' ' . substr($unique, 0, 6), 'seo_title' => 'SEO ' . $label . ' ' . substr($unique, 0, 10), 'meta_description' => 'Meta ' . $label . ' ' . substr($unique, 0, 12), 'sections' => $sections];
    }

    private function url(string $path = '/'): string
    {
        return 'http://' . self::DOMAIN . $path;
    }

    /** @return array<string, string> slug => html for every page the published snapshot has */
    private function render(Website $website): array
    {
        $pages = [];

        foreach ($website->fresh()->publishedRevision->snapshot['pages'] ?? [] as $page) {
            $path = ! empty($page['is_home']) ? '/' : '/' . $page['slug'];
            $pages[$path] = $this->get($this->url($path))->assertOk()->getContent();
        }

        return $pages;
    }

    /** @return array<int, string> every href path of internal links in the body */
    private function links(string $html): array
    {
        preg_match_all('#href="(?:https://' . preg_quote(self::DOMAIN, '#') . ')?(/[^"\#?]*)"#', $html, $m);

        return array_values(array_unique($m[1]));
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
