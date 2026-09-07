<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteSectionType;
use App\Models\Website;
use App\Models\WebsiteAsset;
use Carbon\Carbon;

/**
 * Website Generation + Hosting Slice A contract §11/§14. Builds the
 * self-contained, versioned snapshot JSON stored on a WebsiteRevision.
 * Plain scalars/arrays only — no executable class names, no serialized
 * Eloquent models. `contact_details` (contract §7.3) is the one
 * deliberate live-read exception: its boolean flags are copied
 * unchanged, and the Business's CURRENT phone/email/primary-location
 * address are resolved and copied into the snapshot at build time —
 * the decision of what to display re-reads Business state on the next
 * publish, but the already-published snapshot's values never change
 * themselves.
 */
final class WebsiteSnapshotBuilder
{
    public const SCHEMA_VERSION = 1;

    public function build(Website $website): array
    {
        $business = $website->business;
        $pages = $website->pages()->orderBy('sort_order')->get();
        $referencedAssetUids = [];

        $pageSnapshots = $pages->map(function ($page) use ($business, &$referencedAssetUids) {
            $sections = collect($page->sections ?? [])->map(function ($section) use ($business, &$referencedAssetUids) {
                $type = WebsiteSectionType::tryFrom($section['type'] ?? '');
                $data = $section['data'] ?? [];

                foreach ($this->assetUidsIn($type, $data) as $uid) {
                    $referencedAssetUids[$uid] = true;
                }

                if ($type === WebsiteSectionType::ContactDetails) {
                    $data['resolved'] = [
                        'phone' => $data['show_phone'] ? $business?->phone : null,
                        'email' => $data['show_email'] ? $business?->email : null,
                        'address' => $data['show_address'] ? $this->formatAddress($business) : null,
                    ];
                }

                return ['type' => $section['type'], 'data' => $data];
            })->values()->all();

            return [
                'uid' => $page->uid,
                'slug' => $page->slug,
                'is_home' => $page->is_home,
                'title' => $page->title,
                'seo' => [
                    'seo_title' => $page->seo_title,
                    'meta_description' => $page->meta_description,
                    'noindex' => $page->noindex,
                ],
                'sections' => $sections,
            ];
        })->values()->all();

        $assets = WebsiteAsset::where('website_id', $website->id)
            ->whereIn('uid', array_keys($referencedAssetUids))
            ->get()
            ->map(fn ($asset) => [
                'uid' => $asset->uid,
                'url' => $asset->url(),
                'alt_text' => $asset->alt_text,
            ])->values()->all();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'website' => [
                'name' => $website->name,
                'theme' => $website->theme ?? [],
            ],
            'pages' => $pageSnapshots,
            'assets' => $assets,
        ];
    }

    private function assetUidsIn(?WebsiteSectionType $type, array $data): array
    {
        $uids = [];

        if ($type === WebsiteSectionType::Hero && ! empty($data['background_image'])) {
            $uids[] = $data['background_image'];
        }

        if ($type === WebsiteSectionType::ImageText && ! empty($data['image'])) {
            $uids[] = $data['image'];
        }

        if ($type === WebsiteSectionType::Services) {
            foreach (($data['items'] ?? []) as $item) {
                if (! empty($item['image'])) {
                    $uids[] = $item['image'];
                }
            }
        }

        return $uids;
    }

    private function formatAddress($business): ?string
    {
        $location = $business?->primaryLocation;

        if ($location === null) {
            return null;
        }

        return collect([
            $location->address_line_1,
            $location->address_line_2,
            $location->city,
            $location->region,
            $location->postal_code,
        ])->filter()->implode(', ');
    }
}
