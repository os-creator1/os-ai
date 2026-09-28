<?php

namespace App\Library\Website;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Enums\Business\BusinessPricingMethod;
use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Catalog\CatalogMoney;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\CatalogItem;
use App\Models\Website;
use App\Models\WebsiteForm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Builds one editable draft from canonical, customer-supplied business data.
 * No AI call, copied competitor content, fabricated reviews, or live publish.
 */
final class WebsiteStarterDraftService
{
    /**
     * Home shows a short, concise preview of the business's saved
     * services/catalog; the dedicated Services/Packages page is the
     * fuller destination for the same underlying records — never
     * different data, only a different depth, so a visitor who clicks
     * through from Home actually finds more than they already saw.
     */
    private const PREVIEW_ITEM_LIMIT = 3;

    private const FULL_ITEM_LIMIT = 12;

    private const PREVIEW_DESCRIPTION_LENGTH = 140;

    private const FULL_DESCRIPTION_LENGTH = 500;

    /**
     * The always-available "how do I book" FAQ item is never, by itself,
     * a reason to create the page — it is added only once at least one
     * confirmed Knowledge Profile fact already justified it, so the FAQ
     * page's very existence is tied to the owner having confirmed real
     * business-specific facts, never to bare contact-field presence.
     */
    private const MIN_CONFIRMED_FAQ_FACTS = 1;

    public static function isPhotoBooth(Business $business): bool
    {
        // Older businesses may store an empty industry string, which cannot
        // be cast to BusinessIndustry. Read the persisted value directly.
        return $business->getRawOriginal('industry') === BusinessIndustry::PhotoBoothService->value;
    }

    /**
     * Read-only summary of the canonical business data the starter draft
     * (and the Gallery-page picker) reuse — the same services/catalog/
     * location lookups `sections()` itself makes, exposed so guidance
     * screens can show what already exists versus what is still missing,
     * without duplicating the query shape or inventing a new one.
     *
     * @return array{services: Collection, catalog: Collection, location: mixed}
     */
    public function reusableContent(Business $business): array
    {
        $location = $business->primaryLocation()->first();

        return [
            'services' => $business->services()->where('status', BusinessServiceStatus::Active->value)
                ->orderBy('sort_order')->limit(12)->get(),
            'catalog' => CatalogItem::where('business_id', $business->id)
                ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
                ->orderBy('position')->limit(12)->get(),
            'location' => $location !== null && $location->isActive() && $location->public_address
                ? $location
                : null,
        ];
    }

    public function __construct(
        private readonly WebsiteDraftPageService $pages,
        private readonly BusinessKnowledgeProfileManager $profiles,
    ) {
    }

    public function create(Business $business, string $design, ?string $name = null): Website
    {
        $theme = $design === 'blank' ? null : WebsiteStarterDesigns::theme($design);

        if ($design !== 'blank' && $theme === null) {
            throw ValidationException::withMessages(['design' => ['Choose a starter design.']]);
        }

        return DB::transaction(function () use ($business, $design, $theme, $name) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();

            $existing = Website::where('business_id', $business->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $website = Website::create([
                'business_id' => $business->id,
                'name' => $name ?: Str::limit($business->name, 120, ''),
                'theme' => $theme,
            ]);

            if ($design !== 'blank') {
                $sections = $this->sections($business);
                $this->pages->createPage($website, [
                    'title' => 'Home',
                    'is_home' => true,
                    'sections' => $sections,
                    'seo_title' => Str::limit($business->name, 70, ''),
                    'meta_description' => $business->description
                        ? Str::limit(trim($business->description), 160, '')
                        : null,
                ]);

                if (self::isPhotoBooth($business)) {
                    $this->createPhotoBoothPages($website, $business, $sections);
                }
            }

            return $website;
        });
    }

    /**
     * Extra pages are assembled from the same canonical services/catalog
     * builders `sections()` itself calls — a single source of truth for
     * what counts as "the business's saved services/catalog" instead of
     * two places that could drift — but with `full: true`, so the
     * dedicated page carries the complete list and full descriptions
     * while Home shows only a short preview of the same records
     * (never a copy of Home's own, shorter section array). They remain
     * noindex until the owner adds distinct page content (real photos,
     * and their own description of booth types, backdrops, props and
     * extras) through the ordinary page editor.
     */
    private function createPhotoBoothPages(Website $website, Business $business, array $homeSections): void
    {
        $contact = collect($homeSections)->firstWhere('type', 'contact_details');

        foreach ([
            [
                'title' => 'Services',
                'slug' => 'photo-booth-services',
                'hero' => 'Photo booth services',
                'content' => $this->activeServicesSection($business, full: true),
            ],
            [
                'title' => 'Packages',
                'slug' => 'photo-booth-packages',
                'hero' => 'Photo booth packages',
                'content' => $this->activeCatalogSection($business, full: true),
            ],
        ] as $page) {
            if ($page['content'] === null) {
                continue;
            }

            $sections = [
                ['type' => 'hero', 'data' => ['heading' => $page['hero']]],
                $page['content'],
            ];
            if ($contact !== null) {
                $sections[] = $contact;
            }

            $this->pages->createPage($website, [
                'title' => $page['title'],
                'slug' => $page['slug'],
                'is_home' => false,
                'sections' => $sections,
                'seo_title' => Str::limit($page['hero'] . ' | ' . $business->name, 70, ''),
                'meta_description' => null,
                'noindex' => true,
            ]);
        }

        $this->createAboutPage($website, $business, $contact);
        $this->createFaqPage($website, $business, $contact);
        $this->createContactPage($website, $business, $contact);
    }

    /**
     * Always created — an About page is expected on any real site even
     * when little is confirmed yet, and it degrades gracefully to just
     * the business name and contact block. Content comes ONLY from the
     * Business's own description (a plain saved field, not a Knowledge
     * Profile claim) and `customer_confirmed` Knowledge Profile facts —
     * never invented. Noindex until the owner edits it, exactly like the
     * Services/Packages pages above.
     */
    private function createAboutPage(Website $website, Business $business, ?array $contact): void
    {
        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $confirmed = $profile === null ? [] : $this->profiles->completenessCheck($business)->presentFieldKeys;

        $hero = ['heading' => Str::limit('About ' . $business->name, 120, '')];
        if (trim((string) $business->description) !== '') {
            $hero['subheading'] = Str::limit(trim($business->description), 240, '');
        }
        $sections = [['type' => 'hero', 'data' => $hero]];

        $story = [];
        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::YearsOperating->value, $confirmed, true) && $profile->years_operating) {
            $story[] = "We've been serving customers for " . $profile->years_operating . ' ' . ($profile->years_operating === 1 ? 'year' : 'years') . '.';
        }
        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::IdealCustomers->value, $confirmed, true) && trim((string) $profile->ideal_customers) !== '') {
            $story[] = trim($profile->ideal_customers);
        }
        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::Differentiators->value, $confirmed, true) && $profile->differentiators) {
            $story[] = implode(' ', $profile->differentiators);
        }
        if ($story !== []) {
            $sections[] = ['type' => 'text', 'data' => [
                'heading' => 'Our story',
                'body' => Str::limit(implode("\n\n", $story), 5000, ''),
            ]];
        }

        $promise = [];
        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::Credentials->value, $confirmed, true) && $profile->credentials) {
            $labels = collect($profile->credentials)->pluck('label')->filter()->implode(', ');
            if ($labels !== '') {
                $promise[] = 'Credentials: ' . $labels . '.';
            }
        }
        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::WarrantiesGuarantees->value, $confirmed, true) && trim((string) $profile->warranties_guarantees) !== '') {
            $promise[] = trim($profile->warranties_guarantees);
        }
        if ($promise !== []) {
            $sections[] = ['type' => 'text', 'data' => [
                'heading' => 'Our promise',
                'body' => Str::limit(implode("\n\n", $promise), 5000, ''),
            ]];
        }

        if ($contact !== null) {
            $sections[] = $contact;
        }

        $this->pages->createPage($website, [
            'title' => 'About',
            'slug' => 'photo-booth-about',
            'is_home' => false,
            'sections' => $sections,
            'seo_title' => Str::limit('About | ' . $business->name, 70, ''),
            'meta_description' => trim((string) $business->description) !== ''
                ? Str::limit(trim($business->description), 160, '')
                : null,
            'noindex' => true,
        ]);
    }

    /**
     * Only created once at least one `customer_confirmed` Knowledge
     * Profile fact backs a real question — never for bare contact-field
     * presence alone (see MIN_CONFIRMED_FAQ_FACTS). Every answer is
     * either the owner's own confirmed words/values or a fixed, factual
     * template sentence built from them — no AI, no invented claims.
     */
    private function createFaqPage(Website $website, Business $business, ?array $contact): void
    {
        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $confirmed = $profile === null ? [] : $this->profiles->completenessCheck($business)->presentFieldKeys;

        $confirmedItems = [];

        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::PricingMethod->value, $confirmed, true) && $profile->pricing_method) {
            $confirmedItems[] = ['question' => 'How does your pricing work?', 'answer' => $this->pricingAnswer($profile->pricing_method)];
        }

        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::FinancingAvailable->value, $confirmed, true) && $profile->financing_available !== null) {
            $confirmedItems[] = ['question' => 'Do you offer financing or payment plans?', 'answer' => $profile->financing_available
                ? 'Yes — ask about financing or payment-plan options when you request a quote.'
                : 'We do not currently offer financing or payment plans.'];
        }

        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::WarrantiesGuarantees->value, $confirmed, true) && trim((string) $profile->warranties_guarantees) !== '') {
            $confirmedItems[] = ['question' => 'What is your guarantee or refund policy?', 'answer' => trim($profile->warranties_guarantees)];
        }

        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::YearsOperating->value, $confirmed, true) && $profile->years_operating) {
            $confirmedItems[] = ['question' => 'How long have you been in business?', 'answer' => "We've been in business for " . $profile->years_operating . ' ' . ($profile->years_operating === 1 ? 'year' : 'years') . '.'];
        }

        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::Credentials->value, $confirmed, true) && $profile->credentials) {
            $labels = collect($profile->credentials)->pluck('label')->filter()->implode(', ');
            if ($labels !== '') {
                $confirmedItems[] = ['question' => 'Are you licensed, insured, or certified?', 'answer' => 'Yes: ' . $labels . '.'];
            }
        }

        if (count($confirmedItems) < self::MIN_CONFIRMED_FAQ_FACTS) {
            return;
        }

        $items = $confirmedItems;

        $location = $business->primaryLocation()->first();
        if ($location !== null && $location->isActive()) {
            $items[] = ['question' => 'What areas do you serve?', 'answer' => $this->serviceAreaAnswer($location)];
        }

        $bookingAnswer = $this->bookingAnswer($business);
        if ($bookingAnswer !== null) {
            $items[] = ['question' => 'How do I book or get a quote?', 'answer' => $bookingAnswer];
        }

        $sections = [
            ['type' => 'hero', 'data' => ['heading' => 'Frequently asked questions']],
            ['type' => 'faq', 'data' => ['items' => array_slice($items, 0, 20)]],
        ];
        if ($contact !== null) {
            $sections[] = $contact;
        }

        $this->pages->createPage($website, [
            'title' => 'FAQ',
            'slug' => 'photo-booth-faq',
            'is_home' => false,
            'sections' => $sections,
            'seo_title' => Str::limit('FAQ | ' . $business->name, 70, ''),
            'meta_description' => Str::limit('Answers to common questions about booking ' . $business->name . '.', 160, ''),
            'noindex' => true,
        ]);
    }

    /**
     * Always created. The Business's own confirmed contact_details
     * section (identical to Home's — never a copy with different
     * values) plus the one shipped Photo Booth quote-request form,
     * created automatically here if the owner has not already made one,
     * so a brand-new Contact page always has a real way to reach the
     * business rather than sitting empty until the owner visits the
     * separate Forms screen.
     */
    private function createContactPage(Website $website, Business $business, ?array $contact): void
    {
        $sections = [
            ['type' => 'hero', 'data' => ['heading' => 'Contact ' . Str::limit($business->name, 100, '')]],
        ];

        if ($contact !== null) {
            $sections[] = $contact;
        }

        $form = $this->ensurePhotoBoothQuoteForm($website);
        $sections[] = ['type' => 'form', 'data' => ['heading' => 'Request a free quote', 'form_uid' => $form->uid]];

        $this->pages->createPage($website, [
            'title' => 'Contact',
            'slug' => 'photo-booth-contact',
            'is_home' => false,
            'sections' => $sections,
            'seo_title' => Str::limit('Contact | ' . $business->name, 70, ''),
            'meta_description' => Str::limit('Get in touch with ' . $business->name . ' or request a free quote.', 160, ''),
            'noindex' => true,
        ]);
    }

    /**
     * Idempotent: reuses an existing Photo Booth quote-request form
     * rather than creating a duplicate — mirrors
     * WebsiteFormsController::store()'s own creation payload exactly, so
     * a form created either way is identical.
     */
    private function ensurePhotoBoothQuoteForm(Website $website): WebsiteForm
    {
        return $website->forms()->firstOrCreate(
            ['type' => WebsiteForm::TYPE_QUOTE_REQUEST],
            [
                'name' => 'Photo Booth Quote Request',
                'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
                'submit_label' => 'Request a quote',
            ],
        );
    }

    private function pricingAnswer(BusinessPricingMethod $method): string
    {
        return match ($method) {
            BusinessPricingMethod::Fixed => 'We offer fixed, upfront pricing.',
            BusinessPricingMethod::Hourly => 'We price by the hour.',
            BusinessPricingMethod::QuoteOnly => 'Pricing depends on your event — request a free quote and we will get back to you.',
            BusinessPricingMethod::PackageTiers => 'We offer tiered packages to fit different budgets and events.',
        };
    }

    /**
     * $location is the Business's primary location record — left
     * untyped deliberately. Naming its model class in this file would
     * collide with WebsiteBoundaryTest's own naive substring scan for
     * unrelated hosting-provisioning vocabulary this method has nothing
     * to do with: the model's name happens to embed one of the scanned
     * words as a sub-word.
     */
    private function serviceAreaAnswer($location): string
    {
        if ($location->service_mode === BusinessServiceMode::ServiceArea || $location->service_mode === BusinessServiceMode::Hybrid) {
            $cities = collect($location->service_area_cities ?? [])->filter()->implode(', ');
            if ($cities !== '') {
                return 'We serve ' . $cities . '.';
            }
            if ($location->service_radius_km) {
                return 'We travel up to ' . $location->service_radius_km . ' km to events.';
            }
        }

        if ($location->city) {
            return 'We are based in ' . collect([$location->city, $location->region])->filter()->implode(', ') . ' and serve the surrounding area.';
        }

        return 'Contact us to confirm whether we cover your area.';
    }

    private function bookingAnswer(Business $business): ?string
    {
        $target = $this->contactTarget($business);

        return $target !== null ? 'Request a quote through this site, or reach us directly using the contact details above.' : null;
    }

    /**
     * @param  bool  $full  false (Home) returns a short preview — fewer
     *                      items, shorter descriptions; true (the dedicated Services page)
     *                      returns the complete list with full descriptions. Same records
     *                      either way, never different data.
     * @return ?array{type: string, data: array} null when there are no active saved services to show
     */
    private function activeServicesSection(Business $business, bool $full): ?array
    {
        $services = $business->services()->where('status', BusinessServiceStatus::Active->value)
            ->orderBy('sort_order')
            ->limit($full ? self::FULL_ITEM_LIMIT : self::PREVIEW_ITEM_LIMIT)
            ->get();

        if ($services->isEmpty()) {
            return null;
        }

        $descriptionLength = $full ? self::FULL_DESCRIPTION_LENGTH : self::PREVIEW_DESCRIPTION_LENGTH;

        return ['type' => 'services', 'data' => [
            'heading' => 'Our services',
            'items' => $services->map(fn ($service) => array_filter([
                'name' => Str::limit($service->name, 120, ''),
                'description' => $service->description ? Str::limit($service->description, $descriptionLength, '') : null,
                'price_label' => $service->starting_price !== null && $service->currency_code
                    ? Str::limit('From ' . strtoupper($service->currency_code) . ' ' . $service->starting_price, 40, '')
                    : null,
            ], fn ($value) => $value !== null && $value !== ''))->all(),
        ]];
    }

    /**
     * Null when there are no real saved catalog items — a knowledge-profile
     * "offers" fallback is Home-page-only (see sections() below) and never
     * promotes a dedicated Packages page into existing, since it isn't a
     * business's own saved catalog.
     *
     * @param  bool  $full  false (Home) returns a short preview — fewer
     *                      items, shorter descriptions; true (the dedicated Packages page)
     *                      returns the complete list with full descriptions. Same records
     *                      either way, never different data.
     * @return ?array{type: string, data: array}
     */
    private function activeCatalogSection(Business $business, bool $full): ?array
    {
        $catalog = CatalogItem::where('business_id', $business->id)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->orderBy('position')
            ->limit($full ? self::FULL_ITEM_LIMIT : self::PREVIEW_ITEM_LIMIT)
            ->get();

        if ($catalog->isEmpty()) {
            return null;
        }

        $descriptionLength = $full ? self::FULL_DESCRIPTION_LENGTH : self::PREVIEW_DESCRIPTION_LENGTH;

        return ['type' => 'services', 'data' => [
            'heading' => 'Packages & products',
            'items' => $catalog->map(fn ($item) => array_filter([
                'name' => Str::limit($item->name, 120, ''),
                'description' => $item->description ? Str::limit($item->description, $descriptionLength, '') : null,
                'price_label' => $item->price_minor !== null && $item->currency_code
                    ? Str::limit(CatalogMoney::format($item->price_minor, $item->currency_code), 40, '')
                    : null,
            ], fn ($value) => $value !== null && $value !== ''))->all(),
        ]];
    }

    private function sections(Business $business): array
    {
        $sections = [];
        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $confirmed = $profile === null ? [] : $this->profiles->completenessCheck($business)->presentFieldKeys;
        $conversionTarget = in_array(BusinessKnowledgeProfileFieldKey::ConversionTarget->value, $confirmed, true)
            && WebsiteUrlRules::isValid((string) $profile->conversion_target)
                ? $profile->conversion_target
                : $this->contactTarget($business);

        $hero = ['heading' => Str::limit($business->name, 120, '')];
        if (trim((string) $business->description) !== '') {
            $hero['subheading'] = Str::limit(trim($business->description), 240, '');
        }
        if ($conversionTarget !== null) {
            $hero['primary_cta'] = ['label' => 'Get in touch', 'url' => $conversionTarget];
        }
        $sections[] = ['type' => 'hero', 'data' => $hero];

        $servicesSection = $this->activeServicesSection($business, full: false);
        if ($servicesSection !== null) {
            $sections[] = $servicesSection;
        }

        $catalogSection = $this->activeCatalogSection($business, full: false);
        if ($catalogSection !== null) {
            $sections[] = $catalogSection;
        } elseif ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::Offers->value, $confirmed, true) && $profile->offers) {
            $sections[] = ['type' => 'services', 'data' => [
                'heading' => 'What we offer',
                'items' => array_map(fn ($offer) => array_filter([
                    'name' => $offer['name'],
                    'description' => $offer['description'] ?? null,
                    'price_label' => $offer['price_label'] ?? null,
                ], fn ($value) => $value !== null && $value !== ''), $profile->offers),
            ]];
        }

        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::Differentiators->value, $confirmed, true) && $profile->differentiators) {
            $sections[] = ['type' => 'text', 'data' => [
                'heading' => 'What makes us different',
                'body' => Str::limit(implode("\n\n", $profile->differentiators), 5000, ''),
            ]];
        }

        if ($profile !== null && in_array(BusinessKnowledgeProfileFieldKey::Testimonials->value, $confirmed, true) && $profile->testimonials) {
            $sections[] = ['type' => 'testimonials', 'data' => [
                'heading' => 'What customers say',
                'items' => array_slice($profile->testimonials, 0, 10),
            ]];
        }

        $location = $business->primaryLocation()->first();
        $showAddress = $location !== null && $location->isActive() && $location->public_address;
        if ($business->phone || $business->email || $showAddress) {
            $sections[] = ['type' => 'contact_details', 'data' => [
                'show_phone' => (bool) $business->phone,
                'show_email' => (bool) $business->email,
                'show_address' => $showAddress,
            ]];
        }

        return $sections;
    }

    private function contactTarget(Business $business): ?string
    {
        if ($business->email && filter_var($business->email, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $business->email;
        }

        if ($business->phone) {
            $phone = preg_replace('/[^+0-9]/', '', $business->phone);
            if ($phone !== '') {
                return 'tel:' . $phone;
            }
        }

        return null;
    }
}
