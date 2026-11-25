<?php

namespace Tests\Feature\Website\Acceptance;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Website\GuidedGeneration\ExistingPageFactCandidates;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\BusinessBackdrop;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\Feature\Website\Concerns\PublishesGeneratedSites;
use Tests\TestCase;

/**
 * Recovery — a real regeneration of a Kaleb-like site (migrated pages, no completed guided setup) produced the same thin home page.
 * Root cause: the deterministic Photo Booth composition (booths, packages, events, gallery/backdrop previews, how booking works,
 * benefits, reviews, FAQ, one closing call to action) ran only for owners who had completed the guided questionnaire; any other
 * rebuild fell to the AI-written path. This drives the owner's real Studio "rebuild" route over the canonical records.
 *
 * Fake AI only. It proves structure and honesty (what is rendered, linked, indexed, and what is reported missing), not copy quality.
 */
class GeneratorRecoveryAcceptanceTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;
    use PublishesGeneratedSites;
    use RefreshDatabase;

    private const DOMAIN = 'recovery-booths.test';

    private const AREAS = [
        ['name' => 'Hattiesburg, MS', 'details' => ['venues' => ['The Hattiesburg Saenger Theater'], 'logistics' => 'About 20 minutes from Petal; we load in two hours early.']],
        ['name' => 'Laurel, MS', 'details' => ['venues' => ['The Landrum Center'], 'logistics' => 'About 45 minutes from Petal; parking is behind the hall.']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
        config(['ai.website_content_quality' => true]);
    }

    /** Everything a Kaleb-like owner has in the canonical records, beyond what publishedSite() creates (3 booths, Petal, 3 areas). */
    private function enrich(Website $website, $business, $customer): void
    {
        foreach (['Weddings' => 'A wedding reception with a mirror booth, prints for every guest and an attendant.', 'Corporate Events' => 'Branded prints and digital sharing for company parties and conferences.'] as $name => $description) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'kind' => BusinessService::KIND_EVENT, 'description' => $description, 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 100]);
        }
        CatalogItem::create(['business_id' => $business->id, 'type' => 'package', 'name' => 'Magic Mirror Experience', 'description' => 'Two hours with an attendant, custom print design and digital sharing.', 'position' => 0, 'created_by_user_id' => $customer->user_id]);

        $backdrop = BusinessBackdrop::create(['business_id' => $business->id, 'name' => 'Gold Sequin', 'availability' => true, 'position' => 0]);
        $backdrop->images()->create(['disk' => 'public', 'path' => 'images/backdrops/gold.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'alt_text' => 'Gold sequin backdrop', 'position' => 0]);

        foreach (range(1, 6) as $n) {   // the Gallery page needs real photography to fill it (WebsitePageStrategy::MIN_GALLERY_ASSETS)
            WebsiteAsset::create(['website_id' => $website->id, 'path' => "images/websites/recovery/{$n}.jpg", 'filename' => "{$n}.jpg", 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'purpose' => 'gallery', 'sort_order' => $n, 'alt_text' => "Photo booth at event {$n}"]);
        }

        app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->updateFields($business, [
            'testimonials' => [['quote' => 'The mirror booth was the hit of our reception.', 'author_name' => 'Dana R.']],
            'differentiators' => ['An attendant stays with the booth for the whole event'],
        ], 'manual_edit', (int) $customer->user_id, true);
    }

    private function rebuildUrls($business, $workspace): array
    {
        return [
            'rebuild' => route('customer.workspaces.businesses.website.rebuild', [$workspace->uid, $business->uid]),
            'preview' => route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]),
            'publish' => route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]),
            'studio' => route('customer.workspaces.businesses.website.studio.show', [$workspace->uid, $business->uid]),
        ];
    }

    /** The owner's Studio "rebuild" for a site that never completed the guided setup. The AI is never needed for a Photo Booth rebuild. */
    private function rebuildWithoutAi(Website $website, array $urls): WebsiteGuidedGenerationAttempt
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldNotReceive('complete');
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post($urls['rebuild'], ['template_key' => 'photo_booth_modern', 'confirm_rebuild' => '1'])->assertRedirect();

        return $website->guidedGenerationAttempts()->latest('id')->firstOrFail();
    }

    public function test_a_full_facts_photo_booth_rebuild_without_a_guided_setup_is_substantively_complete_end_to_end(): void
    {
        [$website, $business] = $this->publishedSite('booth', true, true, true, ['An attendant stays with the booth for the whole event']);
        $customer = Customer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $workspace = Workspace::query()->findOrFail($business->workspace_id);
        $this->authenticateAsCustomer($customer);
        $this->enrich($website, $business, $customer);
        $urls = $this->rebuildUrls($business, $workspace);

        $thinHome = collect($website->fresh()->publishedRevision->snapshot['pages'])->firstWhere('is_home', true);

        $attempt = $this->rebuildWithoutAi($website, $urls);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);

        // ---- the draft home page is composed from the confirmed records, not a thin AI skeleton
        $home = $website->fresh()->pages()->where('is_home', true)->sole();
        $types = array_column($home->sections, 'type');
        $this->assertGreaterThan(count($thinHome['sections']), count($types), 'richer than the thin AI home page');
        foreach (['hero', 'services', 'gallery', 'backdrops', 'testimonials', 'faq', 'cta', 'contact_details'] as $needed) {
            $this->assertContains($needed, $types, "the home page carries a {$needed} section: " . json_encode($types));
        }
        $headings = array_map(fn ($s) => $s['data']['heading'] ?? '', $home->sections);
        $this->assertContains('Events we cover', $headings);
        $this->assertStringContainsString('Petal', (string) $home->sections[0]['data']['heading'], 'the H1 names the primary market');

        // ---- the confirmed facts are used verbatim and nothing else is invented
        $text = json_encode($home->sections, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('The mirror booth was the hit of our reception.', $text);
        $this->assertStringContainsString('An attendant stays with the booth for the whole event', $text);
        $this->assertStringContainsString('Hattiesburg', $text, 'the confirmed service areas are on the home page');
        foreach (['award-winning', 'years of experience', 'five-star', 'licensed and insured', 'as seen on'] as $invented) {
            $this->assertStringNotContainsStringIgnoringCase($invented, $text);
        }

        // ---- every service card on the home page links to a real service page of this site
        $slugs = $website->fresh()->pages()->pluck('slug')->filter()->all();
        foreach (['gallery', 'backdrops', 'services'] as $page) {
            $this->assertContains($page, $slugs, "the {$page} page exists");
        }
        $this->assertGreaterThanOrEqual(3, count(array_filter($slugs, fn ($slug) => str_starts_with((string) $slug, 'service-'))));

        // ---- publish, then read the real public renderer
        $website->fresh()->domains()->first() ?: $this->fail('the site has its custom domain');
        $this->post($urls['publish'])->assertRedirect();
        $this->get($urls['studio'])->assertOk();
        app('cache')->flush();

        $public = $this->get('http://' . self::DOMAIN . '/')->assertOk()->getContent();
        $this->assertSame(1, substr_count($public, '<h1'), 'one main heading');
        $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . '/">', $public);
        $this->assertStringContainsString('LocalBusiness', $public);

        // ---- Gallery and Backdrops are two separate, normal destinations in the header (neither is the other's dropdown)
        preg_match('#<nav id="wd-nav".*?</nav>#s', $public, $nav);
        $this->assertNotEmpty($nav, 'the header navigation is rendered');
        foreach (['/gallery', '/backdrops'] as $path) {
            $this->assertMatchesRegularExpression('#<li class="wd-nav-item\s+">\s*<a class="wd-nav-link" href="[^"]*' . preg_quote($path, '#') . '"#', $nav[0], "{$path} is a plain top-level link");
        }
        $this->get('http://' . self::DOMAIN . '/gallery')->assertOk();
        $this->get('http://' . self::DOMAIN . '/backdrops')->assertOk();

        // ---- sitemap, and the native blog is untouched (no published article: no blog page, no blog link)
        $sitemap = $this->get('http://' . self::DOMAIN . '/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('https://' . self::DOMAIN . '/gallery</loc>', $sitemap);
        $this->get('http://' . self::DOMAIN . '/blog')->assertNotFound();
        $this->assertNotContains('blog', $slugs, 'no WebsitePage at the reserved blog slug');
        $this->assertStringNotContainsString('href="https://' . self::DOMAIN . '/blog"', $public);
    }

    public function test_incomplete_facts_are_reported_honestly_and_nothing_is_invented(): void
    {
        [$website, $business] = $this->publishedSite('booth', false, false, true, []);
        $customer = Customer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $workspace = Workspace::query()->findOrFail($business->workspace_id);
        $this->authenticateAsCustomer($customer);
        $urls = $this->rebuildUrls($business, $workspace);

        $attempt = $this->rebuildWithoutAi($website, $urls);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, 'generation itself works');
        $warnings = implode(' | ', (array) $attempt->warnings);
        $this->assertStringContainsString('add the city', $warnings, 'the missing primary market is reported');
        $this->assertStringContainsString('what genuinely sets you apart', $warnings, 'missing differentiators are reported');

        $home = $website->fresh()->pages()->where('is_home', true)->sole();
        $headings = array_map(fn ($s) => strtolower((string) ($s['data']['heading'] ?? '')), $home->sections);
        $text = strtolower(json_encode($home->sections));
        $this->assertNotContains('areas we serve', $headings, 'no service-area hub without confirmed areas');
        $this->assertNotContains('what customers say', $headings, 'no testimonials without confirmed ones');
        foreach (['petal', 'hattiesburg', 'laurel', 'award-winning', 'years of experience'] as $invented) {
            $this->assertStringNotContainsString($invented, $text, "nothing about '{$invented}' appears without a confirmed fact");
        }

        // The Studio health list says generation is not the same as SEO-ready.
        $health = app(\App\Library\Website\WebsiteHealthChecker::class)->check($website->fresh(), ['answers' => '/answers', 'confirm_areas' => '/confirm']);
        $readiness = collect($health['checks'])->firstWhere('key', 'content_readiness');
        $this->assertSame('warn', $readiness['status']);
        $this->assertStringContainsString('Content gap: add the city', implode(' ', $readiness['items']));
    }

    public function test_existing_page_facts_are_candidates_the_owner_must_confirm_and_never_overwrite_the_business_records(): void
    {
        [$website, $business] = $this->publishedSite('booth', true, true, true, []);
        $customer = Customer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $workspace = Workspace::query()->findOrFail($business->workspace_id);
        $this->authenticateAsCustomer($customer);
        $confirmUrl = route('customer.workspaces.businesses.website.studio.confirm-areas', [$workspace->uid, $business->uid]);

        // A migrated "Service Areas" page lists places; the canonical location already holds Hattiesburg, Laurel and Biloxi.
        $this->subPage($website, 'service-areas', ['title' => 'Service Areas', 'sections' => [
            $this->section('hero'),
            ['type' => 'text', 'data' => ['heading' => 'Where we work', 'body' => "Hattiesburg\nGulfport\nOcean Springs\nCall us today\nPetal"]],
        ]]);
        // Another business's page must never be read.
        [, $other] = $this->entitledTenant();
        $foreign = $this->createWebsite($other);
        $this->subPage($foreign, 'service-areas', ['sections' => [$this->section('hero'), ['type' => 'text', 'data' => ['heading' => 'x', 'body' => "Tupelo\nMobile"]]]]);

        $names = array_column(ExistingPageFactCandidates::forWebsite($website->fresh())['service_areas'], 'name');
        $this->assertEqualsCanonicalizing(['Gulfport', 'Ocean Springs'], $names, 'only places not already recorded; prose lines and the base city are not candidates; nothing from another tenant');

        $before = $business->primaryLocation()->first()->service_area_cities;
        $this->post($confirmUrl, ['areas' => ['Gulfport', 'Tupelo']])->assertRedirect();   // Tupelo is not a candidate for this site
        $after = $business->primaryLocation()->first()->fresh()->service_area_cities;
        $this->assertSame($before, array_slice($after, 0, count($before)), 'the owner\'s existing areas stay, first and unchanged');
        $this->assertContains('Gulfport', $after);
        $this->assertNotContains('Tupelo', $after, 'an arbitrary name is never accepted');
        $this->assertCount(count($before) + 1, $after);
    }

    public function test_a_failed_regeneration_for_a_second_niche_leaves_the_published_site_untouched(): void
    {
        [$website, $business] = $this->publishedSite('tutoring', true, true, true, []);
        $customer = Customer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $workspace = Workspace::query()->findOrFail($business->workspace_id);
        $this->authenticateAsCustomer($customer);
        $urls = $this->rebuildUrls($business, $workspace);
        $revision = $website->fresh()->published_revision_id;
        $draftBefore = $website->fresh()->pages()->get(['id', 'title', 'slug', 'sections'])->toArray();

        // The provider produces nothing (both tries): the non-Photo-Booth niche keeps its AI path, and the failure changes nothing.
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('complete')->andReturn(null);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post($urls['rebuild'], ['template_key' => 'photo_booth_modern', 'confirm_rebuild' => '1'])->assertRedirect();

        $attempt = $website->guidedGenerationAttempts()->latest('id')->firstOrFail();
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame($draftBefore, $website->fresh()->pages()->get(['id', 'title', 'slug', 'sections'])->toArray());
        $this->assertSame($revision, $website->fresh()->published_revision_id);
        $this->get('http://' . self::DOMAIN . '/')->assertOk();
    }

    public function test_the_reconciliation_report_flags_duplicate_intent_and_keeps_every_legacy_page(): void
    {
        [$website, $business] = $this->publishedSite('booth', true, true, true, []);
        $customer = Customer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $this->enrich($website, $business, $customer);
        // Owner-authored pages from before: an event page at its own address, a legal page, and a booking page.
        foreach (['wedding-booth' => 'Wedding Booth', 'privacy-policy' => 'Privacy Policy', 'check-availability' => 'Check availability'] as $slug => $title) {
            $this->subPage($website, $slug, ['title' => $title, 'sections' => [$this->section('hero'), $this->section('text')]]);
        }
        $template = \App\Models\WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $plan = app(\App\Library\Website\WebsitePageStrategy::class)->buildPlan($business->fresh(), $template, $website->fresh());

        $report = app(\App\Library\Website\GuidedGeneration\DraftPageReconciler::class)->reconcile($website->fresh(), $business->fresh(), $plan);

        $kept = array_column($report['preserve'], 'slug');
        foreach (['wedding-booth', 'privacy-policy', 'check-availability'] as $legacy) {
            $this->assertContains($legacy, $kept, "{$legacy} is kept untouched, never deleted");
        }
        $this->assertSame([], array_values(array_intersect(array_column($report['remove'], 'slug'), ['wedding-booth', 'privacy-policy', 'check-availability'])));
        $pairs = collect($report['duplicate_intent'])->map(fn ($d) => $d['planned'] . '<->' . $d['existing'])->all();
        $this->assertContains('wedding-photo-booth-rental<->wedding-booth', $pairs, 'the new event page and the owner\'s wedding page target the same thing: reported for review');
        $this->assertEmpty(array_filter($pairs, fn ($pair) => str_contains($pair, 'privacy-policy')), 'legal pages are never compared');
    }

    /** @return array<string, string> FAQ answers by key, as the canonical rebuild's FAQ defaults would write them */
    private function canonicalFaq($business): array
    {
        $context = \App\Library\Website\Setup\WizardPresentationAnswers::canonicalBlueprintContext($business->fresh());
        $items = app(\App\Library\Website\Setup\WizardContentDefaults::class)->faq($business->fresh(), $context['answers']);

        return collect($items)->pluck('answer', 'key')->all();
    }

    public function test_the_canonical_faq_never_contradicts_the_confirmed_booking_and_pricing_or_the_active_packages(): void
    {
        [, $business] = $this->publishedSite('booth', true, true, true, []);
        $customer = Customer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $profiles = app(\App\Library\Business\BusinessKnowledgeProfileManager::class);
        $set = fn (array $fields) => $profiles->updateFields($business, $fields, 'manual_edit', (int) $customer->user_id, true);
        $package = CatalogItem::create(['business_id' => $business->id, 'type' => 'package', 'name' => 'Magic Mirror Experience', 'description' => 'Two hours with an attendant.', 'price_minor' => 84900, 'currency_code' => 'USD', 'position' => 0, 'created_by_user_id' => $customer->user_id]);

        // Call booking + fixed packages (an active, priced package exists): the FAQ says to call and that packages carry prices.
        $set(['primary_conversion_goal' => 'call', 'pricing_method' => 'fixed']);
        $faq = $this->canonicalFaq($business);
        $this->assertStringContainsString('Give us a call at 6015550142', $faq['faq_booking']);
        $this->assertStringNotContainsString('quote request form', $faq['faq_booking']);
        $this->assertStringContainsString('packages are listed with their prices', $faq['faq_cost']);
        $this->assertStringContainsString('Magic Mirror Experience', $faq['faq_included'], 'the FAQ names the very package the page lists');

        // Quote-only pricing: the page lists no packages, so the FAQ must not point at them or at prices.
        $set(['pricing_method' => 'quote_only']);
        $faq = $this->canonicalFaq($business);
        $this->assertStringContainsString('custom quotes', $faq['faq_cost']);
        $this->assertStringNotContainsString('Magic Mirror Experience', $faq['faq_included']);

        // "Fixed" pricing but no active package: nothing to point at, so no claim that packages are listed.
        $set(['pricing_method' => 'fixed']);
        $package->forceFill(['lifecycle_state' => 'archived'])->save();
        $faq = $this->canonicalFaq($business);
        $this->assertStringNotContainsString('packages are listed', $faq['faq_cost']);
        $this->assertStringNotContainsString('Magic Mirror Experience', $faq['faq_included']);

        // A "book online" goal without a bookable Calendar type: no "Book Now" button exists, so the FAQ never promises one.
        $set(['primary_conversion_goal' => 'calendar_booking']);
        $faq = $this->canonicalFaq($business);
        $this->assertStringNotContainsString('Book Now', $faq['faq_booking']);
        $this->assertSame('quote_request', \App\Library\Website\Setup\WizardPresentationAnswers::canonicalBlueprintContext($business->fresh())['cta']);
    }

    public function test_reviews_already_confirmed_in_the_profile_are_not_recovery_candidates_and_readiness_clears_them(): void
    {
        [$website, $business] = $this->publishedSite('booth', true, true, true, []);
        $customer = Customer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $quote = 'The mirror booth was the hit of our reception.';
        $this->homePage($website->fresh() ?: $website, []);   // no-op guard: the site keeps a home page
        $this->subPage($website, 'reviews', ['title' => 'Reviews', 'sections' => [$this->section('hero'), ['type' => 'testimonials', 'data' => ['heading' => 'Kind words', 'items' => [
            ['quote' => '  ' . strtoupper($quote), 'author_name' => 'Dana R.'],
            ['quote' => 'A second review only the old page has.', 'author_name' => 'Sam T.'],
        ]]]]]);
        $links = ['answers' => '/answers', 'confirm_areas' => '/confirm'];
        $readiness = fn () => collect(app(\App\Library\Website\WebsiteHealthChecker::class)->check($website->fresh(), $links)['checks'])->firstWhere('key', 'content_readiness');

        // Nothing confirmed yet: both reviews are candidates and readiness says so.
        $this->assertCount(2, ExistingPageFactCandidates::forWebsite($website->fresh())['testimonials']);
        $this->assertStringContainsString('2 review(s)', implode(' ', $readiness()['items']));

        // The owner confirms one in the Knowledge Profile: it is no longer a candidate; only the other remains.
        app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->updateFields($business, ['testimonials' => [['quote' => $quote, 'author_name' => 'Dana R.']]], 'manual_edit', (int) $customer->user_id, true);
        $left = ExistingPageFactCandidates::forWebsite($website->fresh())['testimonials'];
        $this->assertSame(['A second review only the old page has.'], array_column($left, 'quote'));
        $this->assertStringContainsString('1 review(s)', implode(' ', $readiness()['items']));

        // Both confirmed: no candidate and the readiness check no longer mentions reviews.
        app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->updateFields($business, ['testimonials' => [['quote' => $quote, 'author_name' => 'Dana R.'], ['quote' => 'A second review only the old page has.', 'author_name' => 'Sam T.']]], 'manual_edit', (int) $customer->user_id, true);
        $this->assertSame([], ExistingPageFactCandidates::forWebsite($website->fresh())['testimonials']);
        $this->assertStringNotContainsString('review(s)', implode(' ', $readiness()['items'] ?? []));
    }

    /** @return array{faq: string, items: array<int, array<string, mixed>>} the written cost answer and the package cards the home page lists */
    private function costAnswerAndPackageCards(Website $website): array
    {
        $draft = $website->fresh()->pages()->get();
        $faq = collect($draft->firstWhere('slug', 'photo-booth-faq')->sections)->firstWhere('type', 'faq');
        $answer = collect($faq['data']['items'])->first(fn ($item) => str_contains(strtolower($item['question']), 'cost'))['answer'];
        $packages = collect($draft->firstWhere('is_home', true)->sections)->first(fn ($section) => ($section['data']['heading'] ?? '') === 'Packages');

        return ['faq' => $answer, 'items' => $packages['data']['items'] ?? []];
    }

    public function test_the_faq_cost_answer_matches_what_the_package_cards_will_show_fully_partly_or_not_priced(): void
    {
        [$website, $business] = $this->publishedSite('booth', true, true, true, []);
        $customer = Customer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $workspace = Workspace::query()->findOrFail($business->workspace_id);
        $this->authenticateAsCustomer($customer);
        $urls = $this->rebuildUrls($business, $workspace);
        app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->updateFields($business, ['pricing_method' => 'fixed', 'primary_conversion_goal' => 'call'], 'manual_edit', (int) $customer->user_id, true);
        $make = fn (string $name, ?int $price) => CatalogItem::create(['business_id' => $business->id, 'type' => 'package', 'name' => $name, 'description' => "{$name} with an attendant.", 'price_minor' => $price, 'currency_code' => $price === null ? null : 'USD', 'position' => 0, 'created_by_user_id' => $customer->user_id]);

        // 1. No package has a published price: the cards read "Contact for pricing", so the FAQ says quotes, never that prices are listed.
        $make('Mirror Basic', null);
        $make('Mirror Plus', null);
        $this->rebuildWithoutAi($website, $urls);
        $unpriced = $this->costAnswerAndPackageCards($website);
        $this->assertStringContainsString('custom quotes', $unpriced['faq']);
        $this->assertStringNotContainsString('listed with their prices', $unpriced['faq']);
        $this->assertSame(['Contact for pricing', 'Contact for pricing'], array_column($unpriced['items'], 'quote_label'));
        $this->assertSame([], array_filter(array_column($unpriced['items'], 'price_prefix')), 'no "From" without a price');

        // 2. Some packages are priced: the priced card reads "From $X", the other asks for a quote, and the FAQ says exactly that.
        \App\Models\CatalogItem::where('business_id', $business->id)->where('name', 'Mirror Plus')->update(['price_minor' => 129900, 'currency_code' => 'USD']);
        \App\Models\CatalogItem::where('business_id', $business->id)->where('name', 'Mirror Basic')->update(['price_minor' => null, 'currency_code' => null]);
        $this->rebuildWithoutAi($website, $urls);
        $partly = $this->costAnswerAndPackageCards($website);
        $this->assertStringContainsString('Pricing starts from', $partly['faq']);
        $this->assertStringContainsString('USD 1,299.00', $partly['faq']);
        $this->assertStringNotContainsString('listed with their prices', $partly['faq']);
        $this->assertStringNotContainsString('custom quotes', $partly['faq'], 'a business with published prices is not described as quote-only');
        $this->assertSame(['From'], array_values(array_unique(array_filter(array_column($partly['items'], 'price_prefix')))));

        // 3. Every active package is priced: prices are listed, and the FAQ says so.
        \App\Models\CatalogItem::where('business_id', $business->id)->where('name', 'Mirror Basic')->update(['price_minor' => 79900, 'currency_code' => 'USD']);
        $this->rebuildWithoutAi($website, $urls);
        $fully = $this->costAnswerAndPackageCards($website);
        $this->assertStringContainsString('listed with their prices', $fully['faq']);
        $this->assertStringNotContainsString('custom quotes', $fully['faq']);
        $this->assertSame([], array_filter(array_column($fully['items'], 'price_prefix')), 'listed prices carry no "From"');
    }
}
