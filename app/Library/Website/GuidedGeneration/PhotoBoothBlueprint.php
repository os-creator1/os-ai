<?php

namespace App\Library\Website\GuidedGeneration;

use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Catalog\CatalogMoney;
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
        $pages = [];

        foreach ($plan as $page) {
            $type = (string) ($page['page_type'] ?? '');

            $sections = match ($type) {
                'home' => $this->home($facts),
                'services_overview' => $this->servicesOverview($facts),
                'service_detail' => $this->serviceDetail($facts, $page),
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
                'seo_title' => $this->seoTitle((string) ($page['title'] ?? ''), $facts, $type === 'home'),
                'meta_description' => $this->metaDescription($facts, $type, (string) ($page['title'] ?? '')),
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

        $hero = $this->hero($f['name'], $this->tagline($f), $this->primaryCta($f), $f['packages'] !== [] ? ['label' => 'View packages', 'url' => '/packages'] : null);
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
                fn (BusinessService $e) => array_filter(['name' => Str::limit($e->name, 120, ''), 'description' => $this->prose($e->description)]),
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
            $sections[] = ['type' => 'services', 'data' => ['heading' => 'Why choose ' . Str::limit($f['name'], 80, ''), 'items' => array_map(fn (string $a) => ['name' => Str::limit($a, 120, '')], array_slice($f['advantages'], 0, 12))]];
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

        $sections = [$this->hero($name, $f['name'] . ($f['city'] !== null ? ' · ' . $f['city'] : ''), $this->primaryCta($f))];

        // The picture beside the copy is this service's OWN photo (bound by id after the pages are written);
        // a service with no photo simply has no picture here — never another booth's.
        $sections[] = ['type' => 'image_text', 'data' => ['heading' => 'About the ' . Str::limit($name, 80, ''), 'body' => Str::limit($body, 3000, ''), 'image' => null, 'image_position' => 'right', 'service_uid' => $uid]];
        $sections[] = $this->closingCta($f);
        $sections[] = $this->contactDetails($f['business']);

        return $sections;
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
            $f['advantages'] === [] ? null : ['type' => 'services', 'data' => ['heading' => 'What you can expect', 'items' => array_map(fn (string $a) => ['name' => Str::limit($a, 120, '')], array_slice($f['advantages'], 0, 12))]],
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
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $page
     */
    private function location(array $f, array $page): array
    {
        $place = (string) ($page['entity']['city'] ?? $page['entity']['area'] ?? $page['title'] ?? $f['city'] ?? '');

        return array_filter([
            $this->hero((string) ($page['title'] ?? 'Serving ' . $place), null, $this->primaryCta($f)),
            ['type' => 'text', 'data' => ['heading' => 'Photo booths for ' . ($place !== '' ? $place : 'your event'), 'body' => $f['name'] . ' brings photo booth experiences to events' . ($place !== '' ? ' in ' . $place : '') . '. Tell us about your event and we\'ll confirm we can be there.']],
            $this->contactDetails($f['business']),
        ]);
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

    /** @param array<string, mixed> $f */
    private function process(array $f): array
    {
        $steps = match ($f['cta']) {
            'call' => ['Call us and tell us about your event: the date, the place and the kind of celebration.', 'Together we choose the booth that fits.', 'We bring the booth and your guests enjoy it.'],
            'book_now' => ['Choose the booth and the date you\'d like.', 'Send your booking request with a few details about your event.', 'We bring the booth and your guests enjoy it.'],
            default => ['Tell us about your event: the date, the place and the kind of celebration.', 'We\'ll follow up with a quote for the booth that fits.', 'We bring the booth and your guests enjoy it.'],
        };

        $body = '';
        foreach ($steps as $i => $step) {
            $body .= ($i + 1) . '. ' . $step . "\n\n";
        }

        return ['type' => 'text', 'data' => ['heading' => 'How booking works', 'body' => trim($body)]];
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
        $city = trim((string) ($business->primaryLocation()->first()?->city ?? '')) ?: null;

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

    /** @param array<string, mixed> $f */
    private function seoTitle(string $pageTitle, array $f, bool $home): string
    {
        $in = $f['city'] !== null ? ' in ' . $f['city'] : '';

        return Str::limit($home ? $f['name'] . ' | Photo Booth Rentals' . $in : trim($pageTitle . ' | ' . $f['name'], ' |'), 70, '');
    }

    /** @param array<string, mixed> $f */
    private function metaDescription(array $f, string $type, string $title): string
    {
        $in = $f['city'] !== null ? ' in ' . $f['city'] : '';
        $booths = $f['services'] !== [] ? ' ' . Str::limit(implode(', ', array_map(fn (BusinessService $s) => $s->name, array_slice($f['services'], 0, 3))), 60, '') . '.' : '';

        $text = match ($type) {
            'home' => "{$f['name']} offers photo booth rentals{$in}." . $booths . ' Request a quote for your event.',
            'faq' => "Answers to common questions about booking {$f['name']}{$in}.",
            'about' => "Learn about {$f['name']}{$in}.",
            'contact' => "Contact {$f['name']}{$in} to ask about your event.",
            'packages' => "Photo booth packages from {$f['name']}{$in}.",
            default => ($title !== '' ? $title . ' — ' : '') . "{$f['name']}{$in}.",
        };

        return Str::limit($text, 160, '');
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
