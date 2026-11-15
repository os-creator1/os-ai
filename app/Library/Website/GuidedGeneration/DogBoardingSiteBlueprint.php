<?php

namespace App\Library\Website\GuidedGeneration;

use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Website\Seo\WebsiteHeadMeta;
use App\Library\Website\Seo\WebsiteSeoText;
use App\Library\Website\Setup\DogBoardingSetup;
use App\Library\Website\WebsiteNicheVocabulary;
use App\Library\Website\WebsitePageStrategy;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Models\Website;
use Illuminate\Support\Str;

/**
 * The deterministic, complete Dog Boarding website, written from the owner's confirmed setup answers with NO AI call.
 *
 * It fills the page set WebsitePageStrategy planned (Home, Services, one page per service such as Dog Boarding and Dog
 * Daycare, Rates & Packages, About Us, Gallery, FAQs, Boarding Policies, Request a Stay) and the existing media binding then
 * attaches the owner's real photographs, rates and form exactly as it does for the Photo Booth writer.
 *
 * TRUTHFULNESS: copy says only what the owner confirmed. It never invents a price, capacity, availability, year, award,
 * certification, staff count, constant-supervision claim, veterinary partnership, review, guarantee or ranking. A stay is
 * always a REQUEST the owner confirms. A section with no real content behind it is omitted, never a blank grid or filler.
 * Private pet-care detail is never written to the site: the form does not ask for it.
 */
final class DogBoardingSiteBlueprint
{
    private const HOME_GALLERY = 6;

    public const HOME_GALLERY_MIN = 3;

    private const HOME_FAQ = 6;

    public function __construct(
        private readonly WebsitePageStrategy $strategy,
        private readonly BusinessKnowledgeProfileManager $profiles,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @param  array<string, mixed>  $context  WizardPresentationAnswers::blueprintContext()
     * @return array<int, array{page_key: string, title: string, seo_title: string, meta_description: string, sections: array<int, array<string, mixed>>}>
     */
    public function pages(Business $business, Website $website, array $plan, array $context): array
    {
        $f = $this->facts($business, $website, $context);
        $pages = [];

        foreach ($plan as $page) {
            $type = (string) ($page['page_type'] ?? '');

            $sections = match ($type) {
                'home' => $this->home($f),
                'services_overview' => $this->servicesOverview($f),
                'service_detail' => $this->serviceDetail($f, $page),
                'packages' => $this->rates($f),
                'about' => $this->about($f),
                'faq' => [$this->hero('Frequently asked questions', 'Answers from ' . $f['name'] . ' about stays, requirements and what to expect.', $this->primaryCta($f)), $this->faqSection($f, 30), $this->closingCta($f), $this->contactDetails($business)],
                'gallery' => [$this->hero($f['city'] !== null ? "A look around {$f['name']}" : 'Gallery', 'Photos of the facility and the dogs we care for.'), $this->closingCta($f), $this->contactDetails($business)],
                'contact' => $this->contact($f),
                'custom_section' => [$this->hero((string) ($page['title'] ?? 'Boarding Policies'), 'What we ask of every owner, in our own words.'), $this->contactDetails($business)],
                'location' => $this->location($f, $page),
                default => [$this->hero((string) ($page['title'] ?? $f['name']), null), $this->contactDetails($business)],
            };

            $pages[] = [
                'page_key' => (string) $page['page_key'],
                'title' => (string) ($page['title'] ?? $f['name']),
                'seo_title' => $this->seoTitle($f, $type, $page),
                'meta_description' => $this->metaDescription($f, $type, $page),
                'sections' => array_values(array_filter($sections)),
            ];
        }

        return $pages;
    }

    // ------------------------------------------------------------------ pages

    /** @return array<int, array<string, mixed>|null> */
    private function home(array $f): array
    {
        $s = [];
        $s[] = $this->hero($this->headline($f), $this->subline($f), $this->primaryCta($f), $f['packages'] !== [] ? ['label' => 'See rates', 'url' => '/packages'] : null);

        if ($f['services'] !== []) {
            $s[] = ['type' => 'services', 'data' => ['heading' => 'Boarding and care', 'items' => $this->serviceItems($f, array_slice($f['services'], 0, 6))]];
        }

        if ($f['advantages'] !== []) {
            $s[] = ['type' => 'services', 'data' => ['heading' => 'Why dog owners choose ' . Str::limit($f['name'], 70, ''), 'items' => array_map(fn (string $a) => ['name' => Str::limit($a, 120, '')], array_slice($f['advantages'], 0, 12))]];
        }

        if (count($f['gallery']) >= self::HOME_GALLERY_MIN) {
            $s[] = ['type' => 'gallery', 'data' => ['heading' => 'Where your dog will spend their time', 'items' => array_map(fn (string $uid) => ['image' => $uid], array_slice($f['gallery'], 0, self::HOME_GALLERY))]];
        }

        $routine = DogBoardingSetup::text($f['answers'], 'daily_routine');
        if ($routine !== null) {
            $s[] = ['type' => 'text', 'data' => ['heading' => 'A day with us', 'body' => $routine]];
        }

        $s[] = $this->process($f);

        if ($f['packages'] !== []) {
            $s[] = ['type' => 'services', 'data' => ['heading' => 'Rates', 'items' => $this->packageItems($f, array_slice($f['packages'], 0, 3))]];
        } elseif ($f['services'] !== []) {
            $s[] = $this->pricingNote($f);
        }

        if ($f['testimonials'] !== []) {
            $s[] = ['type' => 'testimonials', 'data' => ['heading' => 'What owners say', 'items' => array_slice($f['testimonials'], 0, 10)]];
        }

        $faq = $this->faqSection($f, self::HOME_FAQ);
        if ($faq !== null) {
            $s[] = $faq;
        }

        $s[] = $this->closingCta($f);
        $s[] = $this->contactDetails($f['business']);

        return $s;
    }

    private function servicesOverview(array $f): array
    {
        return [
            $this->hero('Our services', 'What we offer for dogs' . ($f['city'] !== null ? ' in ' . $f['city'] : '') . ', and how to request a stay.', $this->primaryCta($f)),
            $f['services'] === [] ? null : ['type' => 'services', 'data' => ['heading' => 'Dog care services', 'items' => $this->serviceItems($f, array_slice($f['services'], 0, 12))]],
            $f['addons'] === [] ? null : ['type' => 'services', 'data' => ['heading' => 'Optional add-ons', 'items' => $this->addonItems($f)]],
            $this->closingCta($f),
            $this->contactDetails($f['business']),
        ];
    }

    /** @param array<string, mixed> $page */
    private function serviceDetail(array $f, array $page): array
    {
        $uid = (string) ($page['entity']['service_uid'] ?? '');
        $service = collect($f['services'])->first(fn (BusinessService $s) => $s->uid === $uid);
        $name = (string) ($page['title'] ?? $service?->name ?? 'Dog care');
        $prose = $service !== null ? $this->prose($service->description) : null;
        $features = $service !== null ? \App\Library\Catalog\CatalogFeatureList::split($service->description)[1] : [];

        $body = $prose ?? ($name . ' is offered by ' . $f['name'] . ($f['city'] !== null ? ' in ' . $f['city'] : '') . '.');
        if ($features !== []) {
            $body .= "\n\nIncluded: " . implode(', ', $features) . '.';
        }

        $heading = $f['city'] !== null && stripos($name, (string) $f['city']) === false ? $name . ' in ' . $f['city'] : $name;
        $s = [$this->hero($heading, $f['name'] . ($f['city'] !== null ? ' · ' . $f['city'] : ''), $this->primaryCta($f))];
        $s[] = ['type' => 'image_text', 'data' => ['heading' => 'About ' . Str::limit($name, 80, ''), 'body' => Str::limit($body, 3000, ''), 'image' => null, 'image_position' => 'right', 'service_uid' => $uid]];

        // The owner's own facility and routine answers, under the service they describe; never invented for another.
        $keys = $this->isDaycare($name)
            ? ['daily_routine' => 'A typical day', 'group_play_policy' => 'Group play', 'outdoor_spaces' => 'Outdoor space', 'supervision_approach' => 'Supervision']
            : ['facility_description' => 'Our facility', 'sleeping_arrangements' => 'Where dogs sleep', 'indoor_spaces' => 'Indoor spaces', 'outdoor_spaces' => 'Outdoor spaces', 'daily_routine' => 'A typical day', 'supervision_approach' => 'Supervision', 'group_play_policy' => 'Group play'];
        foreach (DogBoardingSetup::items($f['answers'], $keys) as $item) {
            $s[] = ['type' => 'text', 'data' => ['heading' => $item['label'], 'body' => $item['text']]];
        }

        $eligibility = DogBoardingSetup::text($f['answers'], 'service_eligibility');
        if ($eligibility !== null) {
            $s[] = ['type' => 'text', 'data' => ['heading' => 'Who this is for', 'body' => $eligibility]];
        }

        $s[] = $this->closingCta($f);
        $s[] = $this->contactDetails($f['business']);

        return $s;
    }

    private function rates(array $f): array
    {
        $s = [$this->hero('Rates & packages', 'What ' . $f['name'] . ' charges and what each option includes.', $this->primaryCta($f))];

        if ($f['packages'] !== []) {
            $s[] = ['type' => 'services', 'data' => ['heading' => 'Rates', 'items' => $this->packageItems($f, $f['packages'])]];
        } else {
            $s[] = $this->pricingNote($f);
        }

        if ($f['addons'] !== []) {
            $s[] = ['type' => 'services', 'data' => ['heading' => 'Optional add-ons', 'items' => $this->addonItems($f)]];
        }

        foreach (DogBoardingSetup::items($f['answers'], ['additional_dog_rate' => 'Additional dogs', 'holiday_rates' => 'Holiday and peak-season rates', 'deposit_policy' => 'Deposits']) as $item) {
            $s[] = ['type' => 'text', 'data' => ['heading' => $item['label'], 'body' => $item['text']]];
        }

        $s[] = $this->closingCta($f);
        $s[] = $this->contactDetails($f['business']);

        return $s;
    }

    private function about(array $f): array
    {
        $paragraphs = [$f['about']];

        foreach ([
            'staff_description' => null,
            'care_philosophy' => 'Our approach to care',
        ] as $key => $label) {
            $text = DogBoardingSetup::text($f['answers'], $key);
            if ($text !== null) {
                $paragraphs[] = ($label !== null ? $label . ': ' : '') . $text;
            }
        }

        $credentials = DogBoardingSetup::text($f['answers'], 'credentials_note');
        if ($credentials !== null) {
            $paragraphs[] = 'Credentials and memberships: ' . $credentials;
        }

        $who = DogBoardingSetup::text($f['answers'], 'accepted_breeds');

        return [
            $this->hero('About ' . $f['name'], $f['city'] !== null ? 'Dog care in ' . $f['city'] : null, $this->primaryCta($f)),
            ['type' => 'text', 'data' => ['heading' => 'Who we are', 'body' => implode("\n\n", array_filter($paragraphs))]],
            $who === null ? null : ['type' => 'text', 'data' => ['heading' => 'Dogs we care for', 'body' => $who]],
            $f['testimonials'] === [] ? null : ['type' => 'testimonials', 'data' => ['heading' => 'What owners say', 'items' => array_slice($f['testimonials'], 0, 10)]],
            $this->contactDetails($f['business']),
        ];
    }

    private function contact(array $f): array
    {
        $intro = 'Tell us about your dog and the dates you have in mind. This is a request, not a confirmed booking: we will review it and contact you to confirm the details.';
        $hours = DogBoardingSetup::hoursLine($f['answers']);
        $body = $intro . ($hours !== null ? "\n\n" . $hours : '');

        $s = [
            $this->hero('Request a stay', ($f['city'] !== null ? $f['name'] . ' · ' . $f['city'] : $f['name']), $f['cta'] === 'call' ? $this->primaryCta($f) : null),
            ['type' => 'text', 'data' => ['heading' => 'How a request works', 'body' => $body]],
        ];

        if (($f['answers']['offers_meet_greet'] ?? null) === true) {
            $s[] = ['type' => 'text', 'data' => ['heading' => 'Meet and greet', 'body' => 'Would you like to see where your dog would stay first? Mention it in your request and we will arrange a time with you. A visit is not scheduled until we confirm it.']];
        }

        $s[] = $this->contactDetails($f['business']);

        return $s;
    }

    /** @param array<string, mixed> $page */
    private function location(array $f, array $page): array
    {
        $place = trim((string) ($page['entity']['area'] ?? $page['entity']['city'] ?? '')) ?: ($f['city'] ?? 'your area');
        $names = implode(', ', array_map(fn (BusinessService $s) => $s->name, array_slice($f['services'], 0, 3)));

        return [
            $this->hero('Dog boarding near ' . $place, $f['name'], $this->primaryCta($f)),
            ['type' => 'text', 'data' => ['heading' => 'Care for dogs from ' . $place, 'body' => $f['name'] . ' looks after dogs for owners in ' . $place . ($names !== '' ? ': ' . $names . '.' : '.') . ' Send a stay request and we will get back to you to confirm the details.']],
            $this->contactDetails($f['business']),
        ];
    }

    // --------------------------------------------------------------- sections

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

    /** How a stay request works. Every step is true of MotionGrove's workflow: nothing is instant or guaranteed. */
    private function process(array $f): array
    {
        $steps = [
            ['Send a request', 'Tell us about your dog, the dates you have in mind and what you need.'],
            ['We review it', 'We look at your request and contact you with any questions. A request is not a booking.'],
        ];

        if (($f['answers']['offers_meet_greet'] ?? null) === true) {
            $steps[] = ['Meet and greet', 'Where we offer one, you can meet us and see the facility before deciding.'];
        }

        $steps[] = ['We confirm your stay', 'Your stay is booked once we confirm it with you.'];

        return ['type' => 'services', 'data' => ['heading' => 'How booking works', 'items' => array_map(
            fn (array $step, int $i) => ['name' => ($i + 1) . '. ' . $step[0], 'description' => $step[1]],
            $steps,
            array_keys($steps),
        )]];
    }

    private function pricingNote(array $f): array
    {
        return ['type' => 'text', 'data' => [
            'heading' => 'Pricing',
            'body' => 'Rates depend on your dog, your dates and the service you need, so we do not publish a single price. Send a stay request and we will tell you what it would cost.',
        ]];
    }

    private function faqSection(array $f, int $limit): ?array
    {
        $items = array_slice($f['faq'], 0, $limit);

        return $items === [] ? null : ['type' => 'faq', 'data' => ['heading' => 'Frequently asked questions', 'items' => $items]];
    }

    private function closingCta(array $f): array
    {
        return ['type' => 'cta', 'data' => [
            'heading' => Str::limit('Planning a stay with ' . $f['name'] . '?', 120, ''),
            'body' => 'Send a request with your dog\'s details and your dates. We will review it and confirm with you.',
            'buttons' => [$this->primaryCta($f)],
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

    /** @return array{label: string, url: string} */
    private function primaryCta(array $f): array
    {
        $phone = preg_replace('/[^+0-9]/', '', (string) $f['business']->phone);

        if ($f['cta'] === 'call' && $phone !== '') {
            return ['label' => 'Call us', 'url' => 'tel:' . $phone];
        }

        // Every other goal leads to the request form: a calendar is never assumed connected, and nothing is instant.
        return ['label' => $f['cta'] === 'consultation_booking' ? 'Request a meet & greet' : 'Request a stay', 'url' => '/' . WebsiteNicheVocabulary::slugFor('contact', WebsiteNicheVocabulary::DOG_BOARDING)];
    }

    // -------------------------------------------------------------- item lists

    /** @param list<BusinessService> $services */
    private function serviceItems(array $f, array $services): array
    {
        return array_map(function (BusinessService $service) use ($f) {
            $price = null;

            if ($f['pricing'] === 'starting_from' && $service->starting_price !== null && $service->currency_code) {
                $price = Str::limit('From ' . strtoupper($service->currency_code) . ' ' . $service->starting_price, 40, '');
            }

            return array_filter([
                'name' => Str::limit($service->name, 120, ''),
                'description' => $this->prose($service->description, true),
                'price_label' => $price,
                'service_uid' => $service->uid,
            ], fn ($v) => $v !== null && $v !== '');
        }, $services);
    }

    /** @param list<CatalogItem> $packages */
    private function packageItems(array $f, array $packages): array
    {
        return array_map(fn ($item) => array_filter([
            'catalog_item_uid' => $item->uid,
            'name' => Str::limit($item->name, 120, ''),
            'description' => $item->description ? Str::limit($item->description, 500, '') : null,
            'price_prefix' => $f['pricing'] === 'starting_from' ? 'From' : null,
            'quote_label' => 'Contact for pricing',
        ], fn ($v) => $v !== null && $v !== ''), $packages);
    }

    private function addonItems(array $f): array
    {
        return array_map(fn ($item) => array_filter([
            'catalog_item_uid' => $item->uid,
            'name' => Str::limit($item->name, 120, ''),
            'description' => $item->description ? Str::limit($item->description, 300, '') : null,
            'quote_label' => 'Contact for pricing',
        ], fn ($v) => $v !== null && $v !== ''), array_slice($f['addons'], 0, 12));
    }

    // ------------------------------------------------------------------ facts

    /** @return array<string, mixed> */
    private function facts(Business $business, Website $website, array $context): array
    {
        $cta = in_array($context['cta'] ?? null, ['quote_request', 'consultation_booking', 'call', 'calendar_booking'], true) ? $context['cta'] : 'quote_request';
        $pricing = in_array($context['pricing'] ?? null, ['fixed_packages', 'starting_from', 'custom_quote'], true) ? $context['pricing'] : null;

        $services = $this->strategy->eligibleServices($business)->all();
        $items = $pricing === 'custom_quote' ? collect() : $this->strategy->selectedCatalogItems($business, $context['catalog_uids'] ?? null);
        $packages = $items->filter(fn ($item) => $item->type->value === 'package')->values()->all();
        $addons = CatalogItem::where('business_id', $business->id)->where('type', 'product')->where('lifecycle_state', 'active')->orderBy('position')->get()->all();

        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $confirmed = $profile === null ? [] : $this->profiles->completenessCheck($business)->presentFieldKeys;
        $advantages = $profile !== null && in_array(BusinessKnowledgeProfileFieldKey::Differentiators->value, $confirmed, true)
            ? array_values(array_filter(array_map(fn ($a) => trim((string) $a), (array) $profile->differentiators))) : [];
        $testimonials = $profile !== null && in_array(BusinessKnowledgeProfileFieldKey::Testimonials->value, $confirmed, true)
            ? array_values((array) $profile->testimonials) : [];

        $answers = (array) ($context['answers'] ?? []);
        $primaryLocation = $business->primaryLocation()->first();
        $city = trim((string) ($primaryLocation?->city ?? '')) ?: null;
        $areas = collect(array_merge($city !== null ? [$city] : [], (array) ($primaryLocation?->service_area_cities ?? [])))
            ->map(fn ($entry) => trim((string) Str::before((string) $entry, ',')))
            ->filter()->unique(fn ($place) => mb_strtolower($place))->values()->all();

        $customerFaq = (array) ($context['customer_faq'] ?? []);
        $faq = $customerFaq !== [] ? $customerFaq : array_map(fn (array $i) => ['question' => $i['question'], 'answer' => $i['answer']], DogBoardingSetup::faqDefault($business, $answers));

        $name = trim((string) $business->name);
        $about = trim((string) ($context['about'] ?? '')) ?: DogBoardingSetup::aboutDefault($business, $answers);

        return [
            'business' => $business,
            'name' => $name,
            'city' => $city,
            'areas' => $areas,
            'template' => (string) $website->template_key,
            'cta' => $cta,
            'pricing' => $pricing,
            'services' => $services,
            'packages' => $packages,
            'addons' => $addons,
            'advantages' => $advantages,
            'testimonials' => $testimonials,
            'faq' => $faq,
            'gallery' => $website->assets()->where('purpose', WebsiteAssetPurpose::Gallery->value)->orderBy('sort_order')->orderBy('id')->pluck('uid')->all(),
            'about' => $about,
            'answers' => $answers,
        ];
    }

    // -------------------------------------------------------------------- SEO

    private function isBoarding(string $name): bool
    {
        return preg_match('/board|stay|kennel|overnight|puppy/i', $name) === 1;
    }

    private function isDaycare(string $name): bool
    {
        return preg_match('/day\s*care|daycare/i', $name) === 1;
    }

    /** What the business really offers, from its own services: "dog boarding", "dog daycare" or "dog boarding and daycare". */
    private function offer(array $f): string
    {
        $boarding = false;
        $daycare = false;

        foreach ($f['services'] as $service) {
            $boarding = $boarding || ($this->isBoarding($service->name) && ! $this->isDaycare($service->name));
            $daycare = $daycare || $this->isDaycare($service->name);
        }

        return match (true) {
            $boarding && $daycare => 'dog boarding and daycare',
            $daycare => 'dog daycare',
            default => 'dog boarding',
        };
    }

    private function headline(array $f): string
    {
        $offer = $this->offer($f);
        $in = $f['city'] !== null ? ' in ' . $f['city'] : '';

        $headline = match ($f['template']) {
            'dog_boarding_fresh' => Str::ucfirst($offer) . $in,
            'dog_boarding_playful' => $f['city'] !== null ? 'Where dogs stay and play in ' . $f['city'] : Str::ucfirst($offer),
            default => 'Comfortable, caring ' . $offer . $in,
        };

        return Str::limit($headline, 120, '');
    }

    private function subline(array $f): string
    {
        $line = $f['name'] . ' looks after dogs' . ($f['city'] !== null ? ' in ' . $f['city'] : '') . '. Send a request and we will confirm your stay with you.';
        $areas = array_slice($f['areas'], 0, 3);

        if (count($areas) >= 2) {
            $last = array_pop($areas);
            $line = $f['name'] . ' cares for dogs from ' . implode(', ', $areas) . ' and ' . $last . '. Send a request and we will confirm your stay with you.';
        }

        return $line;
    }

    private function seoTitle(array $f, string $type, array $page): string
    {
        $city = $f['city'];
        $in = $city !== null ? ' in ' . $city : '';
        $title = trim((string) ($page['title'] ?? ''));
        $offer = str_replace(' And ', ' & ', ucwords($this->offer($f)));

        $subject = match ($type) {
            'home' => $offer . $in,
            'services_overview' => 'Dog Care Services' . $in,
            'service_detail' => ($title === '' ? 'Dog Boarding' : $title) . ($city !== null && stripos($title, $city) === false ? $in : ''),
            'packages' => 'Dog Boarding Rates' . $in,
            'faq' => 'Dog Boarding FAQs' . $in,
            'gallery' => 'Facility Photos' . $in,
            'about' => 'About ' . $f['name'],
            'contact' => 'Request a Stay' . $in,
            'custom_section' => $title !== '' ? $title . $in : 'Boarding Policies',
            'location' => 'Dog Boarding near ' . ($page['entity']['area'] ?? $page['entity']['city'] ?? $city ?? 'You'),
            default => $title !== '' ? $title : $f['name'],
        };

        if (WebsiteHeadMeta::mentionsBrand($subject, $f['name'])) {
            return WebsiteSeoText::fit($subject, 70);
        }

        $withBrand = $subject . ' | ' . $f['name'];

        return mb_strlen($withBrand) <= 70 ? $withBrand : WebsiteSeoText::fit($subject, 70);
    }

    private function metaDescription(array $f, string $type, array $page): string
    {
        $in = $f['city'] !== null ? ' in ' . $f['city'] : '';
        $offer = $this->offer($f);
        $title = trim((string) ($page['title'] ?? ''));
        $names = implode(', ', array_map(fn (BusinessService $s) => $s->name, array_slice($f['services'], 0, 3)));
        $ask = ' Send a stay request and we will confirm the details.';

        $text = match ($type) {
            'home' => "{$f['name']} offers {$offer}{$in}." . ($names !== '' ? " Services include {$names}." : '') . $ask,
            'services_overview' => "Every dog care service {$f['name']} offers{$in}, and how a stay request works.",
            'service_detail' => $this->serviceSentence($f, $page) ?? "{$title} from {$f['name']}{$in}.{$ask}",
            'packages' => "Rates and what is included from {$f['name']}{$in}. Prices depend on your dog and dates, so ask for the details.",
            'faq' => "Answers to common questions about boarding your dog with {$f['name']}{$in}: requirements, check-in and how requests work.",
            'about' => "Meet {$f['name']}, the team caring for dogs{$in}.",
            'contact' => "Request a stay with {$f['name']}{$in}. Requests are reviewed and confirmed by the owner; this is not an instant booking.",
            'gallery' => "Photos of the facility and dogs at {$f['name']}{$in}.",
            'custom_section' => "{$title} for dogs staying with {$f['name']}{$in}: requirements, check-in and cancellations in the owner's own words.",
            'location' => "Dog boarding for owners near " . ($page['entity']['area'] ?? $page['entity']['city'] ?? 'you') . " from {$f['name']}.{$ask}",
            default => ($title !== '' ? $title . ' — ' : '') . "{$f['name']}{$in}.",
        };

        return WebsiteSeoText::fit($text, 160);
    }

    private function serviceSentence(array $f, array $page): ?string
    {
        $uid = (string) ($page['entity']['service_uid'] ?? '');
        $service = collect($f['services'])->first(fn (BusinessService $s) => $s->uid === $uid);
        $prose = $service !== null ? $this->prose($service->description) : null;

        if ($prose === null) {
            return null;
        }

        $first = preg_match('/^.+?[.!?](?=\s|$)/us', $prose, $m) === 1 ? $m[0] : $prose;
        $in = $f['city'] !== null ? ' in ' . $f['city'] : '';

        return rtrim($first, '.!? ') . ". Offered by {$f['name']}{$in}.";
    }

    private function prose(?string $description, bool $keepFeatures = false): ?string
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        return Str::limit(trim($keepFeatures ? $description : \App\Library\Catalog\CatalogFeatureList::split($description)[0] ?? $description), 500, '');
    }
}
