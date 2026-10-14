<?php

namespace App\Library\Website\Setup;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Catalog\CatalogFeatureList;
use App\Library\Catalog\CatalogMoney;
use App\Models\Business;
use App\Models\CatalogItem;
use App\Models\QuestionnaireResponse;
use Illuminate\Support\Str;

/**
 * Presentation-only: turns a guided setup's SAVED answers (and the canonical records they point at) into the compact
 * rows the pre-generation Review page shows. It reads — never writes, never calls AI, never fills a default for a value
 * the owner has not given. Every `step` it returns is the questionnaire screen that edits that value.
 */
final class WebsiteReviewPresenter
{
    private const PRICING = [
        'fixed_packages' => 'Fixed package pricing',
        'starting_from' => 'Prices starting from an amount',
        'custom_quote' => 'Custom quote',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forResponse(Business $business, QuestionnaireResponse $response): array
    {
        $steps = collect($response->version->steps())->keyBy('key');
        $answers = (array) ($response->answers ?? []);
        $website = $response->website;

        $label = fn (string $stepKey, mixed $value): string => (string) (($steps[$stepKey]['options'] ?? [])[(string) $value] ?? $value);
        $labels = fn (string $stepKey): array => array_values(array_map(fn ($v) => $label($stepKey, $v), array_filter((array) ($answers[$stepKey] ?? []), 'is_scalar')));
        $rows = fn (string $key): array => array_values(array_filter((array) ($answers[$key] ?? []), fn ($row) => is_array($row) && trim((string) ($row['name'] ?? '')) !== ''));
        $photo = fn (array $row): ?string => ! empty($row['image_path']) ? asset((string) $row['image_path']) : null;

        $booths = array_map(fn (array $row) => [
            'name' => (string) $row['name'],
            'thumb' => $photo($row),
            'description' => Str::limit(trim((string) ($row['description'] ?? '')), 110),
            'visible' => (bool) ($row['visible'] ?? true),
        ], $rows('booth_services'));

        $events = array_values(array_map(fn (array $row) => (string) $row['name'], array_filter($rows('event_types'), fn (array $row) => $row['visible'] ?? true)));

        $backdropCategories = collect($steps['backdrops']['categories'] ?? [])->pluck('label', 'value')->all();
        $backdrops = ($answers['offers_backdrops'] ?? null) === true
            ? array_map(fn (array $row) => [
                'name' => (string) $row['name'],
                'thumb' => $photo($row) ?? (! empty($row['images'][0]['path']) ? asset((string) $row['images'][0]['path']) : null),
                'category' => $backdropCategories[$row['category'] ?? ''] ?? null,
                'available' => ! empty($row['availability']),
            ], $rows('backdrops'))
            : [];

        [$packages, $packageNotice] = $this->packages($business, $response);

        $gallery = $website?->assets()->where('purpose', WebsiteAssetPurpose::Gallery->value)->orderBy('sort_order')->get() ?? collect();
        $faqs = array_values(array_filter(array_map(fn ($row) => is_array($row) ? trim((string) ($row['question'] ?? $row['name'] ?? '')) : '', (array) ($answers['faq_items'] ?? []))));
        $reviews = array_values(array_filter(array_map(
            fn ($row) => is_array($row) && trim((string) ($row['quote'] ?? '')) !== '' ? ['quote' => (string) $row['quote'], 'author' => (string) ($row['author_name'] ?? '')] : null,
            (array) ($answers['testimonials'] ?? [])
        )));

        $base = collect([$answers['base_location']['city'] ?? null, $answers['base_location']['region'] ?? null])->filter()->implode(', ');
        $pricing = self::PRICING[$answers['pricing_strategy'] ?? ''] ?? null;

        return [
            'reviews_count' => count($reviews),
            'about' => [
                'based' => $base,
                'areas' => array_values(array_filter(array_map('strval', array_filter((array) ($answers['service_area_cities'] ?? []), 'is_scalar')))),
                'style' => isset($answers['brand_personality']) ? $label('brand_personality', $answers['brand_personality']) : null,
                'advantages' => $labels('advantages'),
                'reviews' => $reviews,
                'story' => trim((string) ($answers['about_story'] ?? '')),
            ],
            'services' => [
                'booths' => $booths,
                'events' => $events,
                'pricing' => $pricing,
                'packages' => $packages,
                'package_notice' => $packageNotice,
                'addons' => array_values(array_map(fn (array $row) => (string) $row['name'], $rows('addons'))),
                'backdrops' => $backdrops,
                'offers_backdrops' => ($answers['offers_backdrops'] ?? null) === true,
            ],
            'content' => [
                'gallery' => $gallery->take(8)->map(fn ($asset) => ['thumb' => asset($asset->path), 'label' => (string) ($asset->title ?: $asset->alt_text ?: 'Event photo')])->all(),
                'gallery_total' => $gallery->count(),
                'faqs' => $faqs,
            ],
            'contact' => [
                'pricing' => $pricing,
                'offers_backdrops' => ($answers['offers_backdrops'] ?? null) === true,
                'booking' => match ($answers['primary_cta'] ?? null) {
                    'call' => 'Call now',
                    'book_now' => 'Book now',
                    'quote_request' => 'Request a quote',
                    default => null,
                },
                'form_fields' => $labels('contact_form_fields'),
                'phone' => (string) ($answers['phone'] ?? $business->phone),
                'email' => (string) ($answers['email'] ?? $business->email),
                'rights_confirmed' => in_array('confirmed', (array) ($answers['photo_rights'] ?? []), true),
            ],
        ];
    }

    /**
     * Packages are read live from Packages & Products (the answer only stores uids), so the review always shows today's
     * name and price. A selected package that has since been archived is reported, never silently dropped.
     *
     * @return array{0: array<int, array{name: string, price: string, features: string}>, 1: ?string}
     */
    private function packages(Business $business, QuestionnaireResponse $response): array
    {
        $selected = (array) (WizardPresentationAnswers::catalogSelection($response) ?? []);
        $uids = array_values(array_filter($selected, 'is_string'));

        if ($uids === []) {
            return [[], null];
        }

        $items = CatalogItem::whereIn('uid', $uids)
            ->where('business_id', $business->id)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->get()->keyBy('uid');

        $packages = [];

        foreach ($uids as $uid) {
            if (! isset($items[$uid])) {
                continue;
            }

            $item = $items[$uid];
            [$prose, $features] = CatalogFeatureList::split($item->description);

            $packages[] = [
                'name' => $item->name,
                'price' => $item->price_minor !== null ? CatalogMoney::format($item->price_minor, $item->currency_code) : 'Contact for pricing',
                'features' => $features !== [] ? Str::limit(implode(' · ', $features), 90) : Str::limit((string) $prose, 90),
            ];
        }

        $missing = count($uids) - count($packages);
        $notice = $missing > 0
            ? ($missing === 1 ? 'A package you selected is' : $missing . ' packages you selected are') . ' no longer in Packages & Products and will be left out of your website.'
            : null;

        return [$packages, $notice];
    }
}
