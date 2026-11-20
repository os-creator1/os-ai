<?php

namespace Tests\Feature\Website;

use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\WebsiteNicheVocabulary;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\Feature\Website\Concerns\PublishesGeneratedSites;
use Tests\TestCase;

/**
 * Slice 3C — the fixed pages of a generated website get niche-safe addresses. Photo Booth keeps its original addresses, Dog Boarding
 * its own, every other niche gets neutral ones, and an ESTABLISHED site never has an existing address renamed.
 */
class NicheSafeUrlsTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;
    use PublishesGeneratedSites;
    use RefreshDatabase;

    private const DOMAIN = 'studio-urls.test';

    private const AREAS = [
        ['name' => 'Hattiesburg, MS', 'details' => ['venues' => ['The Hattiesburg Saenger Theater', 'Lake Terrace Convention Center'], 'logistics' => 'About 20 minutes from Petal; we load in two hours before the event.']],
        ['name' => 'Laurel, MS', 'details' => ['venues' => ['The Landrum Center'], 'logistics' => 'About 45 minutes from Petal; parking is behind the hall.']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
        config(['ai.website_content_quality' => true]);
    }

    /** @return array<int, string> */
    private function slugs($website): array
    {
        return collect($website->fresh()->publishedRevision->snapshot['pages'])->pluck('slug')->filter()->values()->all();
    }

    public function test_a_photo_booth_site_keeps_its_original_fixed_addresses_and_links_to_them(): void
    {
        [$website] = $this->publishedSite('booth');
        $pages = $this->render($website);

        foreach (['photo-booth-about', 'photo-booth-contact'] as $slug) {
            $this->assertContains($slug, $this->slugs($website));
        }
        $this->assertNotContains('contact', $this->slugs($website));
        $this->assertContains('/photo-booth-contact', $this->links($pages['/']), 'the home page CTA leads to the real contact address');
        $this->assertContains('/photo-booth-contact', $this->links($pages['/service-magic-mirror-photo-booth']));
    }

    public function test_a_tutoring_site_gets_neutral_addresses_and_every_link_canonical_and_sitemap_entry_uses_them(): void
    {
        [$website, $business] = $this->publishedSite('tutoring');
        $pages = $this->render($website);
        $slugs = $this->slugs($website);

        $this->assertContains('contact', $slugs);
        $this->assertContains('about', $slugs);
        $this->assertSame([], array_values(array_filter($slugs, fn ($slug) => str_contains($slug, 'photo-booth'))), 'no photo-booth address on a tutoring site');
        $this->assertSame(count($slugs), count(array_unique($slugs)), 'no duplicate address');
        $this->assertContains('service-sat-preparation', $slugs, 'service-specific addresses are untouched');

        // Internal links and CTAs go to the final contact address, from the home page, every service page and a location page.
        $this->assertContains('/contact', $this->links($pages['/']));
        foreach (array_filter(array_keys($pages), fn ($path) => str_starts_with($path, '/service-')) as $service) {
            $this->assertContains('/contact', $this->links($pages[$service]), "{$service} leads to /contact");
        }
        // The inquiry panel is niche-neutral: another niche gets it too, pointing at ITS contact address with the neutral wording.
        foreach (array_filter(array_keys($pages), fn ($path) => str_starts_with($path, '/service-')) as $service) {
            $this->assertSame(1, substr_count($pages[$service], 'data-testid="inquiry-panel"'), "{$service} has one inquiry panel");
            preg_match('#<aside\b[^>]*data-testid="inquiry-panel".*?</aside>#s', $pages[$service], $aside);
            $this->assertMatchesRegularExpression('#href="[^"]*/contact"[^>]*>\s*Request a quote\s*</a>#', $aside[0]);
            $this->assertStringNotContainsString('photo-booth', $aside[0]);
        }

        foreach ($pages as $path => $html) {
            $this->assertStringNotContainsString('photo-booth-contact', $html, "{$path} never mentions the photo-booth address");
            $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . ($path === '/' ? '/' : $path) . '">', $html);
        }

        $sitemap = $this->get($this->url('/sitemap.xml'))->assertOk()->getContent();
        $this->assertStringContainsString('https://' . self::DOMAIN . '/contact</loc>', $sitemap);
        $this->assertStringContainsString('https://' . self::DOMAIN . '/about</loc>', $sitemap);
        $this->assertStringNotContainsString('photo-booth', $sitemap);

        // A native blog article's call to action leads to the same final contact address.
        $service = collect($website->fresh()->publishedRevision->snapshot['pages'])->firstWhere('slug', 'service-sat-preparation');
        $paragraph = 'Preparing for an exam starts with the schedule, the study space and the time each week you can protect for practice. A plan works best when the student can start easily, so choose a quiet spot and a regular time. Think about how long each session should be, and leave room for review before the test day arrives. ';
        $manager = app(\App\Library\Seo\Content\ArticleManager::class);
        $article = $manager->create((int) $business->customer_id, $business, [
            'title' => 'How Long Should You Prepare for the SAT?',
            'excerpt' => 'A practical look at the schedule and practice time that SAT preparation needs.',
            'body' => "## What to plan first\n\n" . str_repeat($paragraph, 2) . "\n\n## Choosing the right schedule\n\n" . str_repeat($paragraph, 2),
            'meta_description' => 'A practical look at the schedule and practice time that SAT preparation needs, with tips for planning your weeks.',
            'primary_topic' => 'how long should you prepare for the sat',
            'search_intent' => 'planning',
            'supports_page_uid' => $service['uid'],
        ]);
        $manager->publish((int) $business->customer_id, $business, $article);
        $blog = $this->get($this->url('/blog/' . $article->slug))->assertOk()->getContent();
        $this->assertContains('/contact', $this->links($blog));
        $this->assertStringNotContainsString('photo-booth-contact', $blog);
    }

    public function test_another_niche_gets_the_same_trust_strip_from_its_own_confirmed_facts(): void
    {
        $facts = ['Lessons are one to one', 'Every plan starts with a free assessment', 'Weekend and evening times', 'Progress notes after every session'];
        [$website] = $this->publishedSite('tutoring', true, true, true, $facts);
        $home = $this->get($this->url('/'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($home, 'data-testid="trust-strip"'));
        preg_match('#<section\b[^>]*data-testid="trust-strip".*?</section>#s', $home, $strip);
        preg_match_all('#<li>(.*?)</li>#s', $strip[0], $items);
        $this->assertSame($facts, array_map('trim', $items[1]), 'the tutoring studio\'s own confirmed facts, verbatim');
        $this->assertStringNotContainsString('photo', mb_strtolower($strip[0]), 'nothing photo-booth in a tutoring strip');
    }

    public function test_the_vocabulary_is_chosen_by_template_niche_first_then_by_the_businesss_own_niche(): void
    {
        [, $booth] = $this->entitledTenant(['name' => 'Harbor Booths', 'industry' => 'photo_booth_service']);
        [, $tutor] = $this->entitledTenant(['name' => 'Petal Tutoring', 'industry' => 'professional_services']);

        // A Dog Boarding template always uses Dog Boarding's own addresses (covered end to end by DogBoardingWebsiteJourneyTest).
        $this->assertSame(WebsiteNicheVocabulary::DOG_BOARDING, WebsiteNicheVocabulary::nicheFor($tutor, 'dog_boarding'));
        // Photo Booth keeps the original addresses; any other niche on the Photo Booth template gets the neutral ones.
        $this->assertSame('photo_booth_service', WebsiteNicheVocabulary::nicheFor($booth, 'photo_booth_service'));
        $this->assertSame(WebsiteNicheVocabulary::NEUTRAL, WebsiteNicheVocabulary::nicheFor($tutor, 'photo_booth_service'));
        $this->assertSame('request-a-stay', WebsiteNicheVocabulary::slugFor('contact', WebsiteNicheVocabulary::DOG_BOARDING));
        $this->assertSame('contact', WebsiteNicheVocabulary::slugFor('contact', WebsiteNicheVocabulary::NEUTRAL));
        $this->assertSame('photo-booth-contact', WebsiteNicheVocabulary::slugFor('contact', 'photo_booth_service'));
        // Every address family classifies to the same role, so navigation, the site CTA and SEO intent agree.
        foreach (['photo-booth-contact', 'request-a-stay', 'contact'] as $slug) {
            $this->assertSame('contact', WebsiteNicheVocabulary::roleForSlug($slug));
        }
    }

    public function test_an_established_site_keeps_the_addresses_it_already_has_on_regeneration(): void
    {
        // A published Photo Booth site whose business is later reclassified: its published URLs must not move.
        [$website, $business] = $this->publishedSite('booth');
        $before = $this->slugs($website);
        \Illuminate\Support\Facades\DB::table('businesses')->where('id', $business->id)->update(['industry' => 'professional_services']);
        $this->fakeProvider();

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business->fresh(), $website->fresh(), WebsiteTemplate::findActiveOrFail('photo_booth_modern'), $business->customer_id, 'idem-regen', null, null, null, null, self::AREAS);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);

        $draft = $website->fresh()->pages()->pluck('slug')->filter()->all();
        $this->assertContains('photo-booth-contact', $draft);
        $this->assertContains('photo-booth-about', $draft);
        $this->assertNotContains('contact', $draft, 'an established site is never silently renamed');

        // The published pages, their canonicals and the sitemap are exactly as before.
        $this->assertSame($before, $this->slugs($website));
        $this->get($this->url('/photo-booth-contact'))->assertOk();
        $this->get($this->url('/contact'))->assertNotFound();
        $this->assertStringContainsString('/photo-booth-contact</loc>', $this->get($this->url('/sitemap.xml'))->getContent());
    }

    public function test_the_neutral_vocabulary_never_produces_a_duplicate_or_reserved_address_and_trims_only_the_photo_booth_event_suffix(): void
    {
        $plan = [
            ['page_key' => 'about', 'page_type' => 'about', 'slug' => 'photo-booth-about', 'title' => 'About'],
            ['page_key' => 'contact', 'page_type' => 'contact', 'slug' => 'photo-booth-contact', 'title' => 'Contact'],
            ['page_key' => 'event:a', 'page_type' => 'event_detail', 'slug' => 'wedding-photo-booth-rental', 'title' => 'Weddings'],
            ['page_key' => 'event:b', 'page_type' => 'event_detail', 'slug' => 'contact-photo-booth-rental', 'title' => 'Contact days'],
            ['page_key' => 'service:a', 'page_type' => 'service_detail', 'slug' => 'service-sat-preparation', 'title' => 'SAT Preparation'],
        ];

        $applied = WebsiteNicheVocabulary::apply($plan, WebsiteNicheVocabulary::NEUTRAL);
        $slugs = array_column($applied, 'slug');

        $this->assertSame(['about', 'contact', 'wedding', 'contact-photo-booth-rental', 'service-sat-preparation'], $slugs, 'a trimmed event address that would collide with a fixed page is left as it was');
        $this->assertSame(count($slugs), count(array_unique($slugs)));
        $this->assertSame($plan, WebsiteNicheVocabulary::apply($plan, 'photo_booth_service'), 'Photo Booth is untouched');
    }
}
