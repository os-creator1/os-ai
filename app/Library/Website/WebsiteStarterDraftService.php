<?php

namespace App\Library\Website;

use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Catalog\CatalogMoney;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\CatalogItem;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Builds one editable draft from canonical, customer-supplied business data.
 * No AI call, copied competitor content, fabricated reviews, or live publish.
 */
final class WebsiteStarterDraftService
{
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
                $this->pages->createPage($website, [
                    'title' => 'Home',
                    'is_home' => true,
                    'sections' => $this->sections($business),
                    'seo_title' => Str::limit($business->name, 70, ''),
                    'meta_description' => $business->description
                        ? Str::limit(trim($business->description), 160, '')
                        : null,
                ]);
            }

            return $website;
        });
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

        $services = $business->services()->where('status', BusinessServiceStatus::Active->value)
            ->orderBy('sort_order')->limit(12)->get();
        if ($services->isNotEmpty()) {
            $sections[] = ['type' => 'services', 'data' => [
                'heading' => 'Our services',
                'items' => $services->map(fn ($service) => array_filter([
                    'name' => Str::limit($service->name, 120, ''),
                    'description' => $service->description ? Str::limit($service->description, 500, '') : null,
                    'price_label' => $service->starting_price !== null && $service->currency_code
                        ? Str::limit('From ' . strtoupper($service->currency_code) . ' ' . $service->starting_price, 40, '')
                        : null,
                ], fn ($value) => $value !== null && $value !== ''))->all(),
            ]];
        }

        $catalog = CatalogItem::where('business_id', $business->id)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->orderBy('position')->limit(12)->get();
        if ($catalog->isNotEmpty()) {
            $sections[] = ['type' => 'services', 'data' => [
                'heading' => 'Packages & products',
                'items' => $catalog->map(fn ($item) => array_filter([
                    'name' => Str::limit($item->name, 120, ''),
                    'description' => $item->description ? Str::limit($item->description, 500, '') : null,
                    'price_label' => $item->price_minor !== null && $item->currency_code
                        ? Str::limit(CatalogMoney::format($item->price_minor, $item->currency_code), 40, '')
                        : null,
                ], fn ($value) => $value !== null && $value !== ''))->all(),
            ]];
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
