<?php

namespace App\Library\Website\GuidedGeneration;

use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Catalog\CatalogMoney;
use App\Library\Website\EventPageUsefulness;
use App\Library\Website\Seo\WebsiteHeadMeta;
use App\Library\Website\Seo\WebsiteSeoText;
use App\Library\Website\Setup\WizardContentDefaults;
use App\Library\Website\WebsitePageStrategy;
use App\Enums\Website\WebsiteAssetPurpose;
use App\Models\Business;
use App\Models\BusinessBackdrop;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessService;
use App\Models\Website;
use Illuminate\Support\Str;

/**
 * The deterministic, complete Photo Booth website — written from the owner's
 * confirmed facts and a fixed layout blueprint, with NO AI call.
 *
 * A guided setup must always end in a genuinely complete commercial site, even
 * when AI generation is switched off, out of budget or returns something
 * unusable. So the page set the planner chose (WebsitePageStrategy, unchanged)
 * is filled here with complete, safe default copy, and the existing media
 * binding (hero, galleries, backdrops, package/service photos, forms, the
 * owner's own FAQ) then attaches the real pictures exactly as it does for AI
 * output. The layout is data, not a model's choice: the critical homepage
 * sections can never be "forgotten".
 *
 * Copy says only what the owner confirmed: no invented prices, reviews,
 * guarantees, years in business, awards, staff, policies or photographs.
 * Sections that have no real content behind them are omitted — never a blank
 * grid, an empty frame or filler.
 *
 * @phpstan-type Context array{tone: string, cta: string, pricing: ?string, about: ?string, customer_faq: ?array<int, array{question: string, answer: string}>, catalog_uids: ?array<int, string>, package_links: array<string, string>}
 */
final class PhotoBoothBlueprint
{
    private const HOME_PACKAGES = 3;

    private const HOME_GALLERY = 6;

    private const HOME_BACKDROPS = 6;

    /** A homepage gallery strip is only worth showing with at least this many real photos. */
    public const HOME_GALLERY_MIN = 3;

    private const HOME_FAQ = 6;

    public function __construct(
        private readonly WebsitePageStrategy $strategy,
        private readonly WizardContentDefaults $defaults,
        private readonly BusinessKnowledgeProfileManager $profiles,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan  WebsitePageStrategy::buildPlan() (AI-unfillable sections not removed)
     * @param  array<string, mixed>  $context
     * @return array<int, array{page_key: string, title: string, seo_title: string, meta_description: string, sections: array<int, array<string, mixed>>}>
     */
    public function pages(Business $business, Website $website, array $plan, array $context): array
    {
        $facts = $this->facts($business, $website, $context);
        // What the plan decided about neighbouring pages: the events that have a page (their home cards link to it) and the
        // packages page (an event page points on to it).
        $facts['event_page_uids'] = collect($plan)->where('page_type', 'event_detail')->map(fn ($page) => (string) ($page['entity']['service_uid'] ?? ''))->filter()->values()->all();
        $facts['packages_slug'] = collect($plan)->firstWhere('page_type', 'packages')['slug'] ?? null;
        $pages = [];

        foreach ($plan as $page) {
            $type = (string) ($page['page_type'] ?? '');

            $sections = match ($type) {
                'home' => $this->home($facts),
                'services_overview' => $this->servicesOverview($facts),
                'service_detail' => $this->serviceDetail($facts, $page),
                'event_detail' => $this->eventDetail($facts, $page),
                'packages' => $this->packagesPage($facts),
                'about' => $this->about($facts),
                'faq' => $this->faqPage($facts),
                'gallery' => [$this->hero($page['title'] ?? 'Gallery', 'A look at the booths and the events we love being part of.'), $this->closingCta($facts), $this->contactDetails($business)],
                'contact' => [$this->hero('Contact ' . $facts['name'], $facts['cta'] === 'call' ? 'Call us, or send a few details about your event.' : 'Tell us about your event and we\'ll get back to you.'), $this->contactDetails($business)],
                'backdrops' => [$this->hero('Backdrops', 'Choose the look that fits your event.'), $this->closingCta($facts), $this->contactDetails($business)],
                'custom_section' => [$this->hero((string) ($page['title'] ?? $facts['name']), null)],
                'location' => $this->location($facts, $page),
                default => [$this->hero((string) ($page['title'] ?? $facts['name']), null), $this->contactDetails($business)],
            };

            $pages[] = [
                'page_key' => (string) $page['page_key'],
                'title' => (string) ($page['title'] ?? $facts['name']),
                'seo_title' => $this->seoTitle($facts, $type, $page),
                'meta_description' => $this->metaDescription($facts, $type, $page),
                'sections' => array_values(array_filter($sections)),
            ];
        }

        return $pages;
    }

    // ------------------------------------------------------------------ pages

    /**
     * Home: hero, booth cards, packages (or how pricing works), events, gallery preview, backdrop preview, how
     * booking works, benefits, reviews, FAQ, ONE closing call to action, contact details.
     *
     * @param  array<string, mixed>  $f
     * @return array<int, array<string, mixed>|null>
     */
    private function home(array $f): array
    {
        $sections = [];

        $hero = $this->hero($this->heroHeadline($f), $this->heroSubline($f), $this->primaryCta($f), $f['packages'] !== [] ? ['label' => 'View packages', 'url' => '/packages'] : null);
        $sections[] = $hero;

        if ($f['services'] !== []) {
            $sections[] = ['type' => 'services', 'data' => ['heading' => 'Our photo booths', 'items' => $this->serviceItems($f, array_slice($f['services'], 0, 6))]];
        }

        if ($f['packages'] !== []) {
            $sections[] = ['type' => 'services', 'data' => ['heading' => 'Packages', 'items' => $this->packageItems($f, array_slice($f['packages'], 0, self::HOME_PACKAGES))]];
        } elseif ($f['pricing'] === 'custom_quote' || $f['services'] !== []) {
            $sections[] = $this->quoteSection($f);
        }

        if ($f['events'] !== []) {
            $sections[] = ['type' => 'services', 'data' => ['heading' => 'Events we cover', 'items' => array_map(
                // An event type that has its own page links to it by its stable id (the renderer finds the page by identity).
                fn (BusinessService $e) => array_filter(['name' => Str::limit($e->name, 120, ''), 'description' => $this->prose($e->description), 'service_uid' => in_array($e->uid, $f['event_page_uids'] ?? [], true) ? $e->uid : null]),
                array_slice($f['events'], 0, 12)
            )]];
        }

        if (count($f['gallery']) >= self::HOME_GALLERY_MIN) {
            $sections[] = ['type' => 'gallery', 'data' => ['heading' => 'Photos', 'items' => array_map(fn (string $uid) => ['image' => $uid], array_slice($f['gallery'], 0, self::HOME_GALLERY))]];
        }

        if ($f['backdrops'] !== []) {
            $sections[] = ['type' => 'backdrops', 'data' => ['heading' => 'Backdrops', 'items' => array_slice($f['backdrops'], 0, self::HOME_BACKDROPS)]];
        }

        $sections[] = $this->process($f);

        if ($f['advantages'] !== []) {
            $sections[] = ['type' => 'services', 'data' => ['heading' => 'Why choose ' . Str::limit($f['name'], 80, ''), 'items' => $this->advantageItems(array_slice($f['advantages'], 0, 12))]];
        }

        if ($f['testimonials'] !== []) {
            $sections[] = ['type' => 'testimonials', 'data' => ['heading' => 'What customers say', 'items' => array_slice($f['testimonials'], 0, 10)]];
        }

        $faq = array_slice($f['faq'], 0, self::HOME_FAQ);
        if ($faq !== []) {
            $sections[] = ['type' => 'faq', 'data' => ['heading' => 'Frequently asked questions', 'items' => $faq]];
        }

        $sections[] = $this->closingCta($f);
        $sections[] = $this->contactDetails($f['business']);

        return $sections;
    }

    /** @param array<string, mixed> $f */
    private function servicesOverview(array $f): array
    {
        return array_filter([
            $this->hero('Our photo booths', 'Every experience we offer, and what makes each one different.', $this->primaryCta($f)),
            $f['services'] === [] ? null : ['type' => 'services', 'data' => ['heading' => 'Photo booth experiences', 'items' => $this->serviceItems($f, array_slice($f['services'], 0, 12))]],
            $this->closingCta($f),
            $this->contactDetails($f['business']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $page
     */
    private function serviceDetail(array $f, array $page): array
    {
        $uid = (string) ($page['entity']['service_uid'] ?? '');
        $service = collect($f['services'])->first(fn (BusinessService $s) => $s->uid === $uid);
        $name = (string) ($page['title'] ?? $service?->name ?? 'Photo booth');
        $prose = $service !== null ? $this->prose($service->description) : null;
        $features = $service !== null ? \App\Library\Catalog\CatalogFeatureList::split($service->description)[1] : [];

        $body = $prose ?? ($name . ' is one of the photo booth experiences ' . $f['name'] . ' offers' . ($f['city'] !== null ? ' in ' . $f['city'] : '') . '.');
        if ($features !== []) {
            $body .= "\n\nIncluded: " . implode(', ', $features) . '.';
        }

        // The page's main heading names the booth and, once, where it is offered; the brand sits in the subheading.
        $heading = $f['city'] !== null && stripos($name, (string) $f['city']) === false ? $name . ' in ' . $f['city'] : $name;
        $sections = [$this->hero($heading, $f['name'] . ($f['city'] !== null ? ' · ' . $f['city'] : ''), $this->primaryCta($f))];

        // The picture beside the copy is this service's OWN photo (bound by id after the pages are written);
        // a service with no photo simply has no picture here — never another booth's.
        $sections[] = ['type' => 'image_text', 'data' => ['heading' => 'About the ' . Str::limit($name, 80, ''), 'body' => Str::limit($body, 3000, ''), 'image' => null, 'image_position' => 'right', 'service_uid' => $uid]];
        $sections[] = $this->closingCta($f);
        $sections[] = $this->contactDetails($f['business']);

        return $sections;
    }

    /**
     * An event-type page ("Wedding photo booth rental in Spokane"): the owner's own description of that event type, the booths
     * they offer (linked to their pages), any of their own FAQ answers and customer quotes that actually mention the event, and
     * the way on to packages and the quote. Nothing about the event is written for them: no venues, past events, reviews, prices
     * or guarantees. The page exists only because EventPageUsefulness found the owner's own words to put on it.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $page
     */
    private function eventDetail(array $f, array $page): array
    {
        $uid = (string) ($page['entity']['service_uid'] ?? '');
        $event = collect($f['events'])->first(fn (BusinessService $e) => $e->uid === $uid);
        $name = (string) ($page['title'] ?? $event?->name ?? 'Events');
        $label = EventPageUsefulness::label($name) ?? $name;
        $in = $f['city'] !== null ? ' in ' . $f['city'] : '';
        $words = EventPageUsefulness::words($name);
        $mentions = fn (string $text) => array_intersect($words, \App\Library\Website\Seo\WebsitePageIntentClassifier::tokens($text, false)) !== [];

        [$prose, $features] = $event !== null ? \App\Library\Catalog\CatalogFeatureList::split($event->description) : [null, []];
        $body = trim((string) $prose);
        if ($features !== []) {
            $body .= "\n\n" . 'Included: ' . implode(', ', $features) . '.';
        }
        $heading = 'Photo booths for ' . mb_strtolower($name);

        // The owner's own photo of this event type, when they gave one, sits beside the copy; otherwise the copy stands alone
        // (an empty photo slot would be removed along with its text).
        $copy = $event !== null && $event->coverImage() !== null
            ? ['type' => 'image_text', 'data' => ['heading' => Str::limit($heading, 120, ''), 'body' => Str::limit($body, 3000, ''), 'image' => null, 'image_position' => 'right', 'service_uid' => $uid]]
            : ['type' => 'text', 'data' => ['heading' => Str::limit($heading, 120, ''), 'body' => Str::limit($body, 3000, '')]];

        $booths = array_map(fn (BusinessService $s) => ['name' => Str::limit($s->name, 120, ''), 'service_uid' => $s->uid], array_slice($f['services'], 0, 4));

        // Only the owner's own answers and quotes that talk about this event; a generic default is not shown as if it were about it.
        $faq = array_values(array_filter((array) $f['customer_faq'], fn ($item) => $mentions((string) ($item['question'] ?? '') . ' ' . (string) ($item['answer'] ?? ''))));
        $quotes = array_values(array_filter((array) $f['testimonials'], fn ($item) => $mentions((string) ($item['quote'] ?? ''))));

        $cta = ['type' => 'cta', 'data' => [
            'heading' => Str::limit('Planning ' . (preg_match('/^[aeiou]/i', $label) ? 'an ' : 'a ') . mb_strtolower($label) . ' event? Check your date', 120, ''),
            'body' => 'Tell us about your event and we\'ll help you choose the right booth.',
            'buttons' => array_values(array_filter([$this->primaryCta($f), $f['packages_slug'] !== null ? ['label' => 'See packages', 'url' => '/' . $f['packages_slug']] : null])),
        ]];

        return array_filter([
            $this->hero($label . ' photo booth rental' . $in, $f['name'] . ($f['city'] !== null ? ' · ' . $f['city'] : ''), $this->primaryCta($f)),
            $copy,
            $booths === [] ? null : ['type' => 'services', 'data' => ['heading' => Str::limit('Booths for ' . mb_strtolower($name), 120, ''), 'items' => $booths]],
            $quotes === [] ? null : ['type' => 'testimonials', 'data' => ['heading' => 'What customers say', 'items' => array_slice($quotes, 0, 3)]],
            $faq === [] ? null : ['type' => 'faq', 'data' => ['heading' => Str::limit('Questions about ' . mb_strtolower($name), 120, ''), 'items' => array_slice($faq, 0, 6)]],
            $cta,
            $this->contactDetails($f['business']),
        ]);
    }

    /** @param array<string, mixed> $f */
    private function packagesPage(array $f): array
    {
        return array_filter([
            $this->hero('Packages', $f['pricing'] === 'starting_from' ? 'Prices start from the amounts shown — your final quote depends on your event.' : 'Choose the package that fits your event.', $this->primaryCta($f)),
            $f['packages'] === [] ? $this->quoteSection($f) : ['type' => 'services', 'data' => ['heading' => 'Packages', 'items' => $this->packageItems($f, array_slice($f['packages'], 0, 12))]],
            $f['addons'] === [] ? null : ['type' => 'services', 'data' => ['heading' => 'Add-ons', 'items' => array_map(
                fn ($a) => array_filter(['name' => Str::limit($a->name, 120, ''), 'description' => $this->prose($a->description), 'price_label' => $a->price_minor !== null && $a->currency_code ? Str::limit(CatalogMoney::format($a->price_minor, $a->currency_code), 40, '') : null]),
                array_slice($f['addons'], 0, 12)
            )]],
            $this->closingCta($f),
            $this->contactDetails($f['business']),
        ]);
    }

    /** @param array<string, mixed> $f */
    private function about(array $f): array
    {
        $text = Str::limit($f['about'], 1000, '');

        return array_filter([
            $this->hero('About ' . $f['name'], null),
            ['type' => 'text', 'data' => ['heading' => 'Our story', 'body' => $text]],
            $f['advantages'] === [] ? null : ['type' => 'services', 'data' => ['heading' => 'What you can expect', 'items' => $this->advantageItems(array_slice($f['advantages'], 0, 12))]],
            $f['testimonials'] === [] ? null : ['type' => 'testimonials', 'data' => ['heading' => 'What customers say', 'items' => array_slice($f['testimonials'], 0, 10)]],
            $this->contactDetails($f['business']),
        ]);
    }

    /** @param array<string, mixed> $f */
    private function faqPage(array $f): array
    {
        $sections = [$this->hero('Frequently asked questions', 'Quick answers about booking ' . $f['name'] . '.')];

        // The owner's own questions are appended verbatim by the media binding; defaults are only needed when they
        // wrote none (a FAQ page is never left with nothing on it).
        if ($f['customer_faq'] === []) {
            $sections[] = ['type' => 'faq', 'data' => ['heading' => 'Frequently asked questions', 'items' => $f['faq']]];
        }

        $sections[] = $this->contactDetails($f['business']);

        return $sections;
    }

    /**
     * A place page says only what the owner confirmed about THAT place: the saved location's own service cities and radius, or
     * the local details they gave (venues they have worked at, how serving it really works, event photos from there). Nothing
     * is added to make it longer, and the booths are listed by name (linking to their own pages) so the page repeats no
     * paragraph that appears on another page.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $page
     */
    private function location(array $f, array $page): array
    {
        $entity = is_array($page['entity'] ?? null) ? $page['entity'] : [];
        $place = $this->placeOf($f, $page) ?? (string) ($page['title'] ?? $f['city'] ?? '');
        $region = trim((string) ($entity['region'] ?? ''));
        $local = (array) ($entity['local_facts'] ?? []);
        $paragraphs = [$f['name'] . ' brings its photo booths to events in ' . $place . ($region !== '' ? ', ' . $region : '') . '.'];

        $covered = array_values(array_filter(array_map(fn ($city) => trim((string) Str::before((string) $city, ',')), (array) ($entity['service_area_cities'] ?? []))));
        if ($covered !== []) {
            $paragraphs[] = 'From ' . $place . ' we also serve ' . $this->joinList(array_slice($covered, 0, 8)) . '.';
        }

        if (! empty($entity['service_radius_km'])) {
            $paragraphs[] = 'We serve events within about ' . (int) $entity['service_radius_km'] . ' km of ' . $place . '.';
        }

        if (! empty($local['venues'])) {
            $paragraphs[] = 'Venues in or near ' . $place . ' we have worked at: ' . implode('; ', (array) $local['venues']) . '.';
        }

        if (! empty($local['logistics'])) {
            $paragraphs[] = (string) $local['logistics'];
        }

        $photos = array_values(array_intersect((array) ($local['photos'] ?? []), $f['gallery']));
        $booths = array_map(fn (BusinessService $s) => ['name' => Str::limit($s->name, 120, ''), 'service_uid' => $s->uid], array_slice($f['services'], 0, 4));

        return array_filter([
            $this->hero('Photo booth rental in ' . $place, $f['name'] . ($f['city'] !== null && $f['city'] !== $place ? ' · based in ' . $f['city'] : ''), $this->primaryCta($f)),
            ['type' => 'text', 'data' => ['heading' => 'Photo booths for events in ' . $place, 'body' => implode("\n\n", $paragraphs)]],
            $booths === [] ? null : ['type' => 'services', 'data' => ['heading' => 'Booths available in ' . $place, 'items' => $booths]],
            $photos === [] ? null : ['type' => 'gallery', 'data' => ['heading' => 'Events in ' . $place, 'items' => array_map(fn (string $uid) => ['image' => $uid], array_slice($photos, 0, 6))]],
            $this->contactDetails($f['business']),
        ]);
    }

    /** "A", "A and B", "A, B and C". */
    private function joinList(array $items): string
    {
        $items = array_values($items);
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items) . ' and ' . $last;
    }

    // --------------------------------------------------------------- sections

    /**
     * @param  ?array{label: string, url: string}  $primary
     * @param  ?array{label: string, url: string}  $secondary
     * @return array<string, mixed>
     */
    private function hero(string $heading, ?string $subheading, ?array $primary = null, ?array $secondary = null): array
    {
        $data = ['heading' => Str::limit($heading, 120, '')];

        if ($subheading !== null && trim($subheading) !== '') {
            $data['subheading'] = Str::limit($subheading, 240, '');
        }

        if ($primary !== null) {
            $data['primary_cta'] = $primary;
        }

        if ($secondary !== null) {
            $data['secondary_cta'] = $secondary;
        }

        return ['type' => 'hero', 'data' => $data];
    }

    /**
     * The owner's confirmed advantages. The wizard's standard choices carry a one-line plain restatement so a card is
     * never a bare title; a custom advantage is shown exactly as written.
     *
     * @param  array<int, string>  $advantages
     * @return array<int, array<string, string>>
     */
    private function advantageItems(array $advantages): array
    {
        $describe = [
            'custom print designs' => 'Prints designed around your event, so every strip is a keepsake.',
            'digital photo sharing' => 'Guests get their photos on their phones right away.',
            'professional attendant' => 'A friendly attendant runs the booth and helps your guests.',
            'custom backdrops' => 'Backdrops chosen or made to match your event.',
            'unlimited sessions' => 'Guests can go back as often as they like during your booking.',
            'premium equipment' => 'Quality cameras and lighting for photos that look great.',
            'setup and teardown handled for you' => 'We set up before your event and pack down after.',
            'props included' => 'A box of fun props is part of every booking.',
        ];

        return array_map(function (string $a) use ($describe) {
            $item = ['name' => Str::limit($a, 120, '')];
            $text = $describe[Str::lower(trim($a))] ?? null;

            return $text === null ? $item : $item + ['description' => $text];
        }, $advantages);
    }

    /** @param array<string, mixed> $f */
    private function process(array $f): array
    {
        $steps = match ($f['cta']) {
            'call' => [
                ['Call us', 'Tell us about your event: the date, the place and the kind of celebration.'],
                ['Choose your booth', 'Together we choose the booth that fits.'],
                ['Enjoy the booth', 'We bring the booth and your guests enjoy it.'],
            ],
            'book_now' => [
                ['Pick your booth and date', 'Choose the booth and the date you\'d like.'],
                ['Send your request', 'Share a few details about your event.'],
                ['Enjoy the booth', 'We bring the booth and your guests enjoy it.'],
            ],
            default => [
                ['Tell us about your event', 'The date, the place and the kind of celebration.'],
                ['Get your quote', 'We\'ll follow up with a quote for the booth that fits.'],
                ['Enjoy the booth', 'We bring the booth and your guests enjoy it.'],
            ],
        };

        return ['type' => 'services', 'data' => ['heading' => 'How booking works', 'items' => array_map(
            fn (array $step, int $i) => ['name' => ($i + 1) . '. ' . $step[0], 'description' => $step[1]],
            $steps,
            array_keys($steps),
        )]];
    }

    /**
     * Quote-only pricing (or no packages yet): a plain statement of how pricing works — never an invented price or a
     * made-up package.
     *
     * @param  array<string, mixed>  $f
     */
    private function quoteSection(array $f): array
    {
        return ['type' => 'text', 'data' => [
            'heading' => 'Custom quotes',
            'body' => 'Every event is different, so we quote each one individually. Tell us about your event — the date, the place and the kind of celebration — and we\'ll put a quote together for you.',
        ]];
    }

    /** @param array<string, mixed> $f */
    private function closingCta(array $f): array
    {
        $primary = $this->primaryCta($f);

        return ['type' => 'cta', 'data' => [
            'heading' => Str::limit('Ready to book ' . $f['name'] . '?', 120, ''),
            'body' => 'Tell us about your event and we\'ll help you choose the right booth.',
            'buttons' => [$primary],
        ]];
    }

    private function contactDetails(Business $business): array
    {
        $location = $business->primaryLocation()->first();
        $showAddress = $location !== null && $location->isActive() && $location->public_address;

        return ['type' => 'contact_details', 'data' => [
            'show_phone' => trim((string) $business->phone) !== '',
            'show_email' => trim((string) $business->email) !== '',
            'show_address' => (bool) $showAddress,
        ]];
    }

    /**
     * The action visitors take: every page's button leads to something real — the contact page and its form, or the
     * owner's own phone number. (The site-wide resolver still falls back if a page is not on the site.)
     *
     * @param  array<string, mixed>  $f
     * @return array{label: string, url: string}
     */
    private function primaryCta(array $f): array
    {
        $phone = preg_replace('/[^+0-9]/', '', (string) $f['business']->phone);

        if ($f['cta'] === 'call' && $phone !== '') {
            return ['label' => 'Call now', 'url' => 'tel:' . $phone];
        }

        return ['label' => $f['cta'] === 'book_now' ? 'Book now' : 'Request a quote', 'url' => '/photo-booth-contact'];
    }

    // -------------------------------------------------------------- item lists

    /**
     * @param  array<string, mixed>  $f
     * @param  array<int, BusinessService>  $services
     * @return array<int, array<string, mixed>>
     */
    private function serviceItems(array $f, array $services): array
    {
        return array_map(function (BusinessService $service) use ($f) {
            $price = null;

            // Only a price the owner entered, and only when they chose to show "starting from" prices.
            if ($f['pricing'] === 'starting_from' && $service->starting_price !== null && $service->currency_code) {
                $price = Str::limit('From ' . strtoupper($service->currency_code) . ' ' . $service->starting_price, 40, '');
            }

            return array_filter([
                'name' => Str::limit($service->name, 120, ''),
                'description' => $this->prose($service->description, true),
                'price_label' => $price,
                // The stable id the service's own photo is attached by (MediaBindingService::mirrorServiceImages()).
                'service_uid' => $service->uid,
            ], fn ($v) => $v !== null && $v !== '');
        }, $services);
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<int, \App\Models\CatalogItem>  $packages
     * @return array<int, array<string, mixed>>
     */
    private function packageItems(array $f, array $packages): array
    {
        return array_map(fn ($item) => array_filter([
            'catalog_item_uid' => $item->uid,
            'name' => Str::limit($item->name, 120, ''),
            'description' => $item->description ? Str::limit($item->description, 500, '') : null,
            // Resolved live from Packages & Products at render time; these only shape how a price (or its absence) reads.
            'price_prefix' => $f['pricing'] === 'starting_from' ? 'From' : null,
            'quote_label' => 'Contact for pricing',
            // The booth this package visibly pairs with (the owner's explicit choice), for the photo fallback.
            'link_service_key' => $f['package_links'][$item->uid] ?? null,
        ], fn ($v) => $v !== null && $v !== ''), $packages);
    }

    // ------------------------------------------------------------------ facts

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function facts(Business $business, Website $website, array $context): array
    {
        $tone = in_array($context['tone'] ?? null, ['playful', 'elegant', 'bold', 'warm'], true) ? $context['tone'] : 'warm';
        $cta = in_array($context['cta'] ?? null, ['quote_request', 'call', 'book_now'], true) ? $context['cta'] : 'quote_request';
        $pricing = in_array($context['pricing'] ?? null, ['fixed_packages', 'starting_from', 'custom_quote'], true) ? $context['pricing'] : null;

        $services = $this->strategy->eligibleServices($business)->all();
        $events = $this->strategy->eligibleEventTypes($business)->all();

        $catalogUids = $context['catalog_uids'] ?? null;
        $packages = $pricing === 'custom_quote' ? [] : $this->strategy->selectedCatalogItems($business, $catalogUids)
            ->filter(fn ($item) => $item->type->value === 'package')->values()->all();
        $addons = \App\Models\CatalogItem::where('business_id', $business->id)->where('type', 'product')->where('lifecycle_state', 'active')->orderBy('position')->get()->all();

        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $confirmed = $profile === null ? [] : $this->profiles->completenessCheck($business)->presentFieldKeys;
        $advantages = $profile !== null && in_array(BusinessKnowledgeProfileFieldKey::Differentiators->value, $confirmed, true)
            ? array_values(array_filter(array_map(fn ($a) => trim((string) $a), (array) $profile->differentiators))) : [];
        $testimonials = $profile !== null && in_array(BusinessKnowledgeProfileFieldKey::Testimonials->value, $confirmed, true)
            ? array_values((array) $profile->testimonials) : [];

        $answers = (array) ($context['answers'] ?? []);
        $primaryLocation = $business->primaryLocation()->first();
        $city = trim((string) ($primaryLocation?->city ?? '')) ?: null;
        // The owner's saved service areas, as bare place names ("Santa Ana, CA" -> "Santa Ana"), base city first.
        $areas = collect(array_merge($city !== null ? [$city] : [], (array) ($primaryLocation?->service_area_cities ?? [])))
            ->map(fn ($entry) => trim((string) Str::before((string) $entry, ',')))
            ->filter()
            ->unique(fn ($place) => mb_strtolower($place))
            ->values()
            ->all();

        $customerFaq = (array) ($context['customer_faq'] ?? []);
        $faq = $customerFaq !== [] ? $customerFaq : array_map(fn (array $i) => ['question' => $i['question'], 'answer' => $i['answer']], $this->defaults->faq($business, $answers));

        $gallery = $website->assets()->where('purpose', WebsiteAssetPurpose::Gallery->value)->orderBy('sort_order')->orderBy('id')->pluck('uid')->all();

        $backdrops = BusinessBackdrop::where('business_id', $business->id)->where('availability', true)->orderBy('position')->with('images')->get()
            ->map(fn (BusinessBackdrop $b) => [
                'name' => $b->name,
                'description' => $b->description,
                'availability' => true,
                'images' => $b->images->map(fn ($image) => ['url' => $image->url(), 'alt_text' => $image->alt_text])->all(),
            ])->filter(fn (array $item) => $item['images'] !== [])->values()->all();

        $name = trim((string) $business->name);
        $about = trim((string) ($context['about'] ?? '')) ?: $this->defaults->about($business, $answers + ['brand_personality' => $tone, 'primary_cta' => $cta, 'pricing_strategy' => $pricing]);

        return [
            'business' => $business,
            'name' => $name,
            'city' => $city,
            'areas' => $areas,
            'template' => (string) $website->template_key,
            'tone' => $tone,
            'cta' => $cta,
            'pricing' => $pricing,
            'services' => $services,
            'events' => $events,
            'packages' => $packages,
            'addons' => $addons,
            'advantages' => $advantages,
            'testimonials' => $testimonials,
            'customer_faq' => $customerFaq,
            'faq' => $faq,
            'gallery' => $gallery,
            'backdrops' => $backdrops,
            'about' => $about,
            'package_links' => (array) ($context['package_links'] ?? []),
        ];
    }

    /**
     * The home page's main headline: what the business does and where, from the confirmed city (the brand name is
     * already in the header and the title). Each design words it its own way; an owner can edit it like any text.
     *
     * @param  array<string, mixed>  $f
     */
    private function heroHeadline(array $f): string
    {
        $city = $f['city'];

        $headline = match ($f['template']) {
            'photo_booth_editorial' => $city !== null ? "Photo booth rentals in {$city}" : 'Photo booth rentals for your event',
            'photo_booth_luxury' => $city !== null ? "Photo booths for events in {$city}" : 'Photo booths for events worth remembering',
            'photo_booth_conversion' => $city !== null ? "Book a photo booth in {$city}" : 'Book a photo booth for your event',
            default => $city !== null ? "Photo booth rentals in {$city}" : 'Photo booth rentals for your event',
        };

        return Str::limit($headline, 120, '');
    }

    /** @param array<string, mixed> $f */
    private function heroSubline(array $f): string
    {
        $line = $this->tagline($f);
        $areas = array_slice($f['areas'], 0, 3);

        if (count($areas) >= 2) {
            $last = array_pop($areas);
            $line = $this->tagline($f) . ' Serving ' . implode(', ', $areas) . ' and ' . $last . '.';
        }

        return $line;
    }

    /** @param array<string, mixed> $f */
    private function tagline(array $f): string
    {
        $in = $f['city'] !== null ? ' in ' . $f['city'] : '';

        return match ($f['tone']) {
            'playful' => "Photo booth rentals{$in} that get everyone laughing, posing and sharing.",
            'elegant' => "Refined photo booth experiences{$in} for events worth remembering.",
            'bold' => "High-energy photo booth rentals{$in} that make your event the one people talk about.",
            default => "Friendly photo booth rentals{$in} that make your celebration feel personal.",
        };
    }

    /**
     * The page's title tag: what the page is about first, the brand after it, never the brand twice, and never cut in the
     * middle of a word. The wording follows the page's own search intent (its type and, for a booth or place, its subject).
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $page  the plan entry
     */
    private function seoTitle(array $f, string $type, array $page): string
    {
        $city = $f['city'];
        $in = $city !== null ? ' in ' . $city : '';
        $title = trim((string) ($page['title'] ?? ''));
        $place = $this->placeOf($f, $page);

        $subject = match ($type) {
            'home' => 'Photo Booth Rentals' . $in,
            'service_detail' => $title === '' ? 'Photo Booth Rental' . $in : (stripos($title, 'rental') !== false ? $title : $title . ' Rental') . $in,
            'event_detail' => (EventPageUsefulness::label($title) ?? 'Event') . ' Photo Booth Rental' . $in,
            'location' => 'Photo Booth Rental in ' . ($place ?? $city ?? 'Your Area'),
            'packages' => 'Photo Booth Packages' . $in,
            'faq' => 'Photo Booth Rental FAQs',
            'gallery' => 'Photo Booth Gallery' . $in,
            'backdrops' => 'Photo Booth Backdrops' . $in,
            'about' => 'About ' . $f['name'],
            'contact' => 'Contact ' . $f['name'] . ' | Request a Quote',
            'services_overview' => 'Photo Booths' . $in,
            default => $title !== '' ? $title : $f['name'],
        };

        // The brand follows unless the subject already carries it ("About Acme Booths") or it would not fit.
        if (WebsiteHeadMeta::mentionsBrand($subject, $f['name'])) {
            return WebsiteSeoText::fit($subject, 70);
        }

        $withBrand = $subject . ' | ' . $f['name'];

        return mb_strlen($withBrand) <= 70 ? $withBrand : WebsiteSeoText::fit($subject, 70);
    }

    /**
     * A useful one- or two-sentence summary of THIS page, written only from facts the owner confirmed. It ends on a full
     * sentence (WebsiteSeoText::fit), and never repeats another page's description because each names its own subject.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $page
     */
    private function metaDescription(array $f, string $type, array $page): string
    {
        $in = $f['city'] !== null ? ' in ' . $f['city'] : '';
        $names = fn (array $services, int $n) => implode(', ', array_map(fn (BusinessService $s) => $s->name, array_slice($services, 0, $n)));
        $ask = match ($f['cta']) {
            'call' => ' Call to talk through your event.',
            'book_now' => ' Book your date online.',
            default => ' Request a quote for your date.',
        };
        $title = trim((string) ($page['title'] ?? ''));
        $place = $this->placeOf($f, $page);

        $text = match ($type) {
            'home' => "{$f['name']} offers photo booth rentals{$in}." . ($f['services'] !== [] ? ' Choose from ' . $names($f['services'], 3) . '.' : '') . $ask,
            'service_detail' => $this->serviceSentence($f, $page) ?? "Rent the {$title} from {$f['name']}{$in}.{$ask}",
            'event_detail' => $this->serviceSentence($f, $page, $f['events']) ?? ((EventPageUsefulness::label($title) ?? $title) . " photo booth rental from {$f['name']}{$in}.{$ask}"),
            'location' => "Photo booth rental for events in {$place} from {$f['name']}." . ($f['services'] !== [] ? ' Choose from ' . $names($f['services'], 3) . '.' : '') . $ask,
            'packages' => "Photo booth packages from {$f['name']}{$in}: compare what each includes and how pricing works.",
            'faq' => "Answers to common questions about booking {$f['name']}{$in}: timing, setup, space and what is included.",
            'about' => "Meet {$f['name']}, the team behind the photo booths{$in}.",
            'contact' => "Contact {$f['name']}{$in} to ask about your event or check a date.",
            'gallery' => "Photos of the photo booths and events from {$f['name']}{$in}.",
            'backdrops' => "The backdrops available with {$f['name']}'s photo booths{$in}.",
            'services_overview' => "Every photo booth {$f['name']} offers{$in}, and what makes each one different.",
            default => ($title !== '' ? $title . ' — ' : '') . "{$f['name']}{$in}.",
        };

        return WebsiteSeoText::fit($text, 160);
    }

    /** The first full sentence of the owner's own description of this booth, plus the next step; null when they wrote none. */
    private function serviceSentence(array $f, array $page, ?array $pool = null): ?string
    {
        $uid = (string) ($page['entity']['service_uid'] ?? '');
        $service = collect($pool ?? $f['services'])->first(fn (BusinessService $s) => $s->uid === $uid);
        $prose = $service !== null ? $this->prose($service->description) : null;

        if ($prose === null) {
            return null;
        }

        $first = preg_match('/^.+?[.!?](?=\s|$)/us', $prose, $m) === 1 ? $m[0] : $prose;
        $in = $f['city'] !== null ? ' in ' . $f['city'] : '';

        return rtrim($first, '.!? ') . ". Rent it from {$f['name']}{$in}.";
    }

    /** The place a location page is about, from the plan's entity (its area, else its saved location's city). */
    private function placeOf(array $f, array $page): ?string
    {
        $entity = is_array($page['entity'] ?? null) ? $page['entity'] : [];
        $place = trim((string) ($entity['area'] ?? $entity['city'] ?? ''));

        return $place !== '' ? $place : null;
    }

    /** The prose part of a description (bullet features are shown as their own list by the services component). */
    private function prose(?string $description, bool $keepFeatures = false): ?string
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        return Str::limit(trim($keepFeatures ? $description : \App\Library\Catalog\CatalogFeatureList::split($description)[0] ?? $description), 500, '');
    }
}
