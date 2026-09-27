<?php

namespace App\Library\Website;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Catalog\CatalogMoney;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\CatalogItem;
use App\Models\Website;
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
