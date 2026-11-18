<?php

namespace Tests\Unit\Website;

use App\Library\Website\GuidedGeneration\PageContentQuality;
use App\Library\Website\GuidedGeneration\SeoContentFacts;
use PHPUnit\Framework\TestCase;

/**
 * Page-type-aware content completeness, judged from confirmed facts only. Fixtures describe a representative local photo-booth
 * business (Magic Mirror booth, Petal MS, weddings/corporate/parties, areas across Mississippi) as plain data; no prose is
 * asserted, only semantic coverage, structure and conditional behaviour.
 */
class PageContentQualityTest extends TestCase
{
    private const CONTACT = '/photo-booth-contact';

    private function facts(array $override = []): array
    {
        return $override + [
            'market' => ['city' => 'Petal', 'region' => 'Mississippi'],
            'primary_service' => 'Magic Mirror Photo Booth',
            'services' => [
                ['name' => 'Magic Mirror Photo Booth', 'description' => 'A full-length mirror touchscreen booth with animated prompts and instant prints for guests.'],
                ['name' => 'Open-Air Photo Booth', 'description' => null],
                ['name' => '360 Video Booth', 'description' => null],
            ],
            'events' => ['Weddings', 'Corporate Events', 'Parties'],
            'areas' => ['Hattiesburg', 'Laurel', 'Biloxi'],
            'differentiators' => ['Unlimited prints with custom digital overlays'],
        ];
    }

    private function sitePlan(): array
    {
        return [
            ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'entity' => null],
            ['page_key' => 'contact', 'page_type' => 'contact', 'is_home' => false, 'slug' => 'photo-booth-contact', 'entity' => null],
        ];
    }

    private function hero(string $heading, ?string $sub = null, bool $cta = true): array
    {
        return ['type' => 'hero', 'data' => ['heading' => $heading, 'subheading' => $sub, 'primary_cta' => $cta ? ['label' => 'Check availability', 'url' => self::CONTACT] : null, 'secondary_cta' => null]];
    }

    private function text(string $heading, string $body): array
    {
        return ['type' => 'text', 'data' => ['heading' => $heading, 'body' => $body]];
    }

    private function page(string $key, array $sections): array
    {
        return ['page_key' => $key, 'title' => $key, 'seo_title' => 'SEO ' . $key, 'meta_description' => 'Meta ' . $key, 'sections' => $sections];
    }

    private function problems(array $page, array $planPage, ?array $facts = null): array
    {
        return PageContentQuality::problems($page, $planPage, $facts ?? $this->facts(), $this->sitePlan());
    }

    private function home(): array
    {
        return ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'entity' => null];
    }

    private function richHome(): array
    {
        return $this->page('home', [
            $this->hero('Magic Mirror Photo Booth Rental in Petal, Mississippi', 'Photo booth fun for every celebration.'),
            ['type' => 'services', 'data' => ['heading' => 'Photo booths you can book', 'items' => [
                ['name' => 'Magic Mirror Photo Booth', 'description' => 'Touchscreen mirror with animated prompts.', 'price_label' => null],
                ['name' => 'Open-Air Photo Booth', 'description' => 'Room for big groups.', 'price_label' => null],
                ['name' => '360 Video Booth', 'description' => 'Slow-motion spins.', 'price_label' => null],
            ]]],
            $this->text('What every rental includes', 'Unlimited prints with custom digital overlays, a friendly attendant and online sharing for guests.'),
            $this->text('Where we work', 'We bring the booth from Petal to Hattiesburg, Laurel and Biloxi.'),
        ]);
    }

    // ---------------------------------------------------------------- A / B: the homepage

    public function test_a_generic_two_section_homepage_fails_content_quality(): void
    {
        $page = $this->page('home', [
            $this->hero('Capture Every Moment with Style', 'Photo booths for your event.', cta: false),
            $this->text('Our Amazing Services', 'We offer great photo booths for any occasion.'),
        ]);

        $problems = implode(' | ', $this->problems($page, $this->home()));

        $this->assertStringContainsString('primary market (Petal)', $problems);
        $this->assertStringContainsString('what the business does', $problems);
        $this->assertStringContainsString('generic heading', $problems);
        $this->assertStringContainsString('booking/contact action', $problems);
        $this->assertStringContainsString('informative sections', $problems);
    }

    public function test_a_homepage_that_names_the_service_market_services_areas_and_a_booking_path_passes(): void
    {
        $this->assertSame([], $this->problems($this->richHome(), $this->home()));
    }

    public function test_metadata_keywords_do_not_replace_visible_service_area_and_differentiator_copy(): void
    {
        $page = $this->page('home', [
            $this->hero('Magic Mirror Photo Booth Rental in Petal, Mississippi'),
            $this->text('Equipment', 'Ask us about available equipment for your event.'),
            $this->text('Planning', 'Contact us to discuss the setup and date.'),
        ]);
        $page['seo_title'] = 'Magic Mirror Photo Booth Rental in Petal, Mississippi';
        $page['meta_description'] = 'Open-Air Photo Booth and 360 Video Booth in Hattiesburg and Laurel, with unlimited prints and custom digital overlays.';

        $problems = implode(' | ', $this->problems($page, $this->home()));

        $this->assertStringContainsString('real services', $problems, 'Keywords only in a meta description do not make the visible page informative.');
        $this->assertStringContainsString('where the business works', $problems);
        $this->assertStringContainsString('differentiators', $problems);
    }

    public function test_the_brand_tagline_may_be_secondary_but_not_the_headline(): void
    {
        $page = $this->richHome();
        $page['sections'][0]['data']['heading'] = 'Capture Every Moment with Style';
        $page['sections'][0]['data']['subheading'] = 'Magic Mirror Photo Booth Rental in Petal, Mississippi';

        $this->assertNotEmpty($this->problems($page, $this->home()), 'Keywords in the subheading do not make a good H1.');
    }

    public function test_rules_are_conditional_on_confirmed_facts_so_nothing_is_demanded_that_is_unknown(): void
    {
        $none = ['market' => null, 'primary_service' => null, 'services' => [], 'events' => [], 'areas' => [], 'differentiators' => []];
        $page = $this->page('home', [$this->hero('Photo booth rentals'), $this->text('About the booth', 'A short, honest description from the owner.')]);

        $this->assertSame([], $this->problems($page, $this->home(), $none), 'No market, services, areas or differentiators known: nothing to require.');

        $noDiff = $this->facts(['differentiators' => []]);
        $this->assertStringNotContainsString('differentiator', implode(' ', $this->problems($this->richHome(), $this->home(), $noDiff)));
    }

    public function test_a_confirmed_differentiator_must_be_reflected_but_testimonials_are_never_required(): void
    {
        $page = $this->richHome();
        $page['sections'][2] = $this->text('What every rental includes', 'A friendly attendant and online sharing for guests.');

        $this->assertStringContainsString('differentiators', implode(' ', $this->problems($page, $this->home())));
        $this->assertStringNotContainsString('testimonial', strtolower(implode(' ', $this->problems($this->richHome(), $this->home()))));
    }

    // ---------------------------------------------------------------- C / D: service pages

    private function servicePlan(string $name = 'Magic Mirror Photo Booth', ?string $description = 'A full-length mirror touchscreen booth with animated prompts and instant prints for guests.'): array
    {
        return ['page_key' => 'service:abc', 'page_type' => 'service_detail', 'is_home' => false, 'slug' => 'service-magic-mirror-photo-booth', 'entity' => array_filter(['service_uid' => 'abc', 'name' => $name, 'description' => $description])];
    }

    public function test_a_real_service_gets_an_informative_page_that_explains_it(): void
    {
        $page = $this->page('service:abc', [
            $this->hero('Magic Mirror Photo Booth Rental in Petal'),
            $this->text('What the Magic Mirror booth is', 'A full-length mirror touchscreen booth with animated prompts that guests use to pose and print.'),
            $this->text('What is included', 'Instant prints for guests, a friendly attendant and online sharing, set up at your venue near Petal.'),
        ]);

        $this->assertSame([], $this->problems($page, $this->servicePlan()));
    }

    public function test_a_service_page_of_generic_filler_fails(): void
    {
        $page = $this->page('service:abc', [
            $this->hero('Discover the Magic', cta: false),
            $this->text('An Unforgettable Experience', 'We make your event special with our amazing photo booths.'),
        ]);

        $problems = implode(' | ', $this->problems($page, $this->servicePlan()));

        $this->assertStringContainsString('must name the service', $problems);
        $this->assertStringContainsString('2 informative sections', $problems);
        $this->assertStringContainsString('does not reflect the confirmed description', $problems);
        $this->assertStringContainsString('generic heading', $problems);
        $this->assertStringContainsString('booking/contact action', $problems);
    }

    public function test_a_service_without_a_confirmed_description_is_not_required_to_reflect_one(): void
    {
        $page = $this->page('service:abc', [
            $this->hero('Magic Mirror Photo Booth Rental in Petal'),
            $this->text('Who it suits', 'Weddings, parties and corporate events near Petal.'),
            $this->text('Booking', 'Tell us the date and venue and we will confirm availability.'),
        ]);

        $this->assertSame([], $this->problems($page, $this->servicePlan(description: null)));
    }

    public function test_two_service_pages_that_only_swap_the_service_name_are_near_duplicates(): void
    {
        $body = 'We bring a booth to your event with an attendant, unlimited prints, props and online sharing for every guest at weddings, birthdays and corporate events.';
        $a = $this->page('service:a', [$this->hero('Mirror Booth Rental'), $this->text('What it is', "The Mirror Booth comes with {$body}")]);
        $b = $this->page('service:b', [$this->hero('Open-Air Booth Rental'), $this->text('What it is', "The Open-Air Booth comes with {$body}")]);
        $plans = [
            'service:a' => $this->servicePlan('Mirror Booth', null) + [],
            'service:b' => $this->servicePlan('Open-Air Booth', null),
        ];
        $plans['service:a']['page_key'] = 'service:a';
        $plans['service:b']['page_key'] = 'service:b';

        $this->assertSame([['service:a', 'service:b']], PageContentQuality::nearDuplicates([$a, $b], $plans));

        $b['sections'][1] = $this->text('What it is', 'Built for groups: a wide open frame, a ring light, a prop wall and a shareable gallery link sent after the event.');
        $this->assertSame([], PageContentQuality::nearDuplicates([$a, $b], $plans));
    }

    // ---------------------------------------------------------------- E: location pages

    private function areaPlan(array $local): array
    {
        return ['page_key' => 'area:laurel-ms', 'page_type' => 'location', 'is_home' => false, 'slug' => 'serving-laurel-ms', 'entity' => ['area' => 'Laurel, MS', 'local_facts' => $local]];
    }

    public function test_a_location_page_must_use_its_confirmed_venues_and_travel_details(): void
    {
        $local = ['venues' => ['The Landrum Center', 'Laurel Mercantile Hall'], 'logistics' => 'About 25 minutes from Petal; we load in two hours before the event.'];

        $good = $this->page('area:laurel-ms', [
            $this->hero('Photo Booth Rental in Laurel, MS'),
            $this->text('Venues we know in Laurel', 'We have set up at The Landrum Center and Laurel Mercantile Hall.'),
            $this->text('Travel and setup', 'Laurel is about 25 minutes from Petal and we load in two hours before the event.'),
        ]);
        $this->assertSame([], $this->problems($good, $this->areaPlan($local)));

        $swapOnly = $this->page('area:laurel-ms', [
            $this->hero('Photo Booth Rental in Laurel, MS'),
            $this->text('Serving Laurel', 'We bring our photo booths to Laurel for weddings and parties.'),
            $this->text('Book in Laurel', 'Contact us to reserve a booth in Laurel.'),
        ]);
        $problems = implode(' | ', $this->problems($swapOnly, $this->areaPlan($local)));
        $this->assertStringContainsString('confirmed venues', $problems);
        $this->assertStringContainsString('travel/setup details', $problems);
    }

    // ---------------------------------------------------------------- G: gaps, never fabrication

    public function test_missing_facts_become_actionable_gaps_and_never_content(): void
    {
        $none = ['market' => null, 'primary_service' => null, 'services' => [], 'events' => [], 'areas' => [], 'differentiators' => []];
        $gaps = implode(' | ', SeoContentFacts::gaps($none));

        $this->assertStringContainsString('city (and state)', $gaps);
        $this->assertStringContainsString('at least one active service', $gaps);
        $this->assertStringContainsString('differentiators', $gaps);

        $partial = implode(' | ', SeoContentFacts::gaps($this->facts()));
        $this->assertStringContainsString('Open-Air Photo Booth, 360 Video Booth', $partial, 'Services without a description are named so the owner knows what to add.');
        $this->assertStringNotContainsString('city (and state)', $partial);

        $this->assertSame([], SeoContentFacts::gaps(['market' => ['city' => 'Petal', 'region' => null], 'services' => [['name' => 'A', 'description' => 'x']], 'differentiators' => ['y']]));
    }

    public function test_the_prompt_context_only_carries_what_is_confirmed_and_is_bounded(): void
    {
        $context = SeoContentFacts::promptContext($this->facts());
        $this->assertSame('Magic Mirror Photo Booth', $context['primary_service']);
        $this->assertSame(['city' => 'Petal', 'region' => 'Mississippi'], $context['primary_market']);
        $this->assertArrayNotHasKey('differentiators', $context, 'Differentiators already travel in confirmed_facts.');

        $empty = SeoContentFacts::promptContext(['market' => null, 'primary_service' => null, 'services' => [], 'events' => [], 'areas' => [], 'differentiators' => []]);
        $this->assertSame([], $empty, 'No confirmed fact, nothing in the prompt.');
    }
}
