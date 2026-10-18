<?php

namespace App\Library\Website\Design;

use App\Library\Catalog\CatalogMoney;
use App\Library\Website\WebsitePageStrategy;
use App\Models\Business;
use App\Models\Website;
use Illuminate\Support\Str;

/**
 * Website V1 final — the content a template PREVIEW card shows. It is the
 * real renderer fed the owner's own Business facts, so a card is what their
 * site will actually look like in that template, not a wireframe.
 *
 * Only real facts are used: the business name and description, their saved
 * services and live packages, their contact details, and their own uploaded
 * photo. Where the owner has not entered anything yet, a few clearly generic
 * niche examples stand in (the preview is labelled "Sample preview") — no
 * testimonials, claims or numbers are ever invented.
 */
final class WebsiteTemplatePreviewBuilder
{
    private const SAMPLE_SERVICES = ['Open-Air Photo Booth', 'Mirror Photo Booth', '360 Photo Booth'];

    public function __construct(private readonly WebsitePageStrategy $strategy) {}

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function homeSections(Business $business, Website $website): array
    {
        $services = $this->strategy->eligibleServices($business)->take(3)
            ->map(fn ($service) => array_filter([
                'name' => Str::limit($service->name, 120, ''),
                'description' => $service->description ? Str::limit($service->description, 140, '') : null,
            ]))->values()->all();

        if ($services === []) {
            $services = array_map(fn (string $name) => ['name' => $name, 'description' => 'Instant sharing, a friendly attendant and a polished print or video.'], self::SAMPLE_SERVICES);
        }

        $packages = $this->strategy->eligibleCatalogItems($business)->take(3)
            ->map(fn ($item) => array_filter([
                'catalog_item_uid' => $item->uid,
                'name' => Str::limit($item->name, 120, ''),
                'description' => $item->description ? Str::limit($item->description, 140, '') : null,
                'price_label' => $item->price_minor !== null && $item->currency_code ? CatalogMoney::format($item->price_minor, $item->currency_code) : null,
                'featured' => (bool) $item->featured,
            ], fn ($value) => $value !== null))->values()->all();

        $firstPhoto = $website->assets()->where('purpose', 'gallery')->orderBy('sort_order')->orderBy('id')->first();

        $sections = [
            ['type' => 'hero', 'data' => [
                'heading' => Str::limit($business->name, 100, ''),
                'subheading' => $business->description ? Str::limit(trim($business->description), 180, '') : 'Photo booth rentals for weddings, corporate events and parties.',
            ]],
            ['type' => 'services', 'data' => ['heading' => 'Our services', 'items' => $services]],
        ];

        if ($firstPhoto !== null) {
            $sections[] = ['type' => 'image_text', 'data' => [
                'heading' => 'Made for your event',
                'body' => 'Every booth, backdrop and print is styled to fit your celebration.',
                'image' => $firstPhoto->uid,
                'image_position' => 'right',
            ]];
        }

        if ($packages !== []) {
            $sections[] = ['type' => 'services', 'data' => ['heading' => 'Packages', 'items' => $packages]];
        }

        $sections[] = ['type' => 'cta', 'data' => ['heading' => 'Ready to check your date?', 'body' => 'Tell us about your event and we will confirm availability.', 'buttons' => [['label' => 'Check availability', 'url' => '#']]]];
        $sections[] = ['type' => 'contact_details', 'data' => ['show_phone' => (bool) $business->phone, 'show_email' => (bool) $business->email, 'show_address' => false]];

        return $sections;
    }

    /**
     * A representative navigation for the preview (links go nowhere).
     *
     * @return array<int, array{uid: string, title: string, is_home: bool, slug: ?string, has_form: bool, url: string}>
     */
    public function navigation(): array
    {
        $page = fn (string $uid, string $title, ?string $slug, bool $home = false, bool $form = false) => ['uid' => $uid, 'title' => $title, 'is_home' => $home, 'slug' => $slug, 'has_form' => $form, 'url' => '#'];

        return [
            $page('home', 'Home', null, true),
            $page('services', 'Services', 'services'),
            $page('packages', 'Packages', 'packages'),
            $page('gallery', 'Gallery', 'gallery'),
            $page('about', 'About', 'photo-booth-about'),
            $page('contact', 'Contact', 'photo-booth-contact', false, true),
        ];
    }
}
