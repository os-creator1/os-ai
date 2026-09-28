<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteSectionType;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileReadMask;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsiteForm;
use Carbon\Carbon;

/**
 * Website Generation + Hosting Slice A contract §11/§14. Builds the
 * self-contained, versioned snapshot JSON stored on a WebsiteRevision.
 * Plain scalars/arrays only — no executable class names, no serialized
 * Eloquent models. `contact_details` (contract §7.3) and the site-wide
 * `localBusiness` facts below are the two deliberate live-read
 * exceptions: their values are resolved from CURRENT Business/Location
 * state and copied into the snapshot at BUILD (publish) time only —
 * the decision of what to display re-reads Business state on the next
 * publish, but the already-published snapshot's values never change
 * themselves, no matter what changes on the Business/Location
 * afterward. This is what makes the public LocalBusiness structured
 * data (App\Library\Website\Seo\WebsiteLocalBusinessStructuredData)
 * safe to build purely from this frozen data — it never reads a live
 * model.
 */
final class WebsiteSnapshotBuilder
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly GoogleBusinessProfileReadMask $addressPredicate,
    ) {}

    public function build(Website $website): array
    {
        $business = $website->business;
        $pages = $website->pages()->orderBy('sort_order')->orderBy('id')->get();
        $referencedAssetUids = [];
        $referencedFormUids = [];

        $pageSnapshots = $pages->map(function ($page) use ($business, &$referencedAssetUids, &$referencedFormUids) {
            $sections = collect($page->sections ?? [])->map(function ($section) use ($business, &$referencedAssetUids, &$referencedFormUids) {
                $type = WebsiteSectionType::tryFrom($section['type'] ?? '');
                $data = $section['data'] ?? [];

                foreach ($this->assetUidsIn($type, $data) as $uid) {
                    $referencedAssetUids[$uid] = true;
                }

                if ($type === WebsiteSectionType::Form && ! empty($data['form_uid'])) {
                    $referencedFormUids[$data['form_uid']] = true;
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

        // Embedded, not live-read: a `form` section's rendered fields and a
        // submission's validation rules both come from this frozen copy, so
        // editing a form after publishing never changes what an already-
        // published page shows or accepts until the next publish.
        $forms = WebsiteForm::where('website_id', $website->id)
            ->whereIn('uid', array_keys($referencedFormUids))
            ->get()
            ->map(fn ($form) => [
                'uid' => $form->uid,
                'name' => $form->name,
                'fields' => $form->fields,
                'submit_label' => $form->submit_label,
            ])->values()->all();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'website' => [
                'name' => $website->name,
                'theme' => $website->theme ?? [],
                'localBusiness' => $this->localBusinessFacts($business, $this->visibleContactFacts($pageSnapshots)),
            ],
            'pages' => $pageSnapshots,
            'assets' => $assets,
            'forms' => $forms,
        ];
    }

    /**
     * Scans every page just built above for its own `contact_details`
     * section(s) — the one place a visitor can actually see a phone,
     * email, or address on this site — and reports which of those three
     * facts at least one section's owner-controlled Show phone/Show
     * email/Show address toggle actually reveals. LocalBusiness JSON-LD
     * must never assert a fact the owner never chose to display, even
     * when it is otherwise a true, saved Business/Location value: a
     * turned-off toggle, or a website with no `contact_details` section
     * at all, means that fact is omitted from structured data too.
     *
     * @param  array<int, array{sections: array<int, array{type: string, data: array}>}>  $pageSnapshots
     * @return array{phone: bool, email: bool, address: bool}
     */
    private function visibleContactFacts(array $pageSnapshots): array
    {
        $visible = ['phone' => false, 'email' => false, 'address' => false];

        foreach ($pageSnapshots as $page) {
            foreach ($page['sections'] as $section) {
                if ($section['type'] !== WebsiteSectionType::ContactDetails->value) {
                    continue;
                }

                $resolved = $section['data']['resolved'] ?? [];
                $visible['phone'] = $visible['phone'] || ! empty($resolved['phone']);
                $visible['email'] = $visible['email'] || ! empty($resolved['email']);
                $visible['address'] = $visible['address'] || ! empty($resolved['address']);
            }
        }

        return $visible;
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

        if ($type === WebsiteSectionType::Services || $type === WebsiteSectionType::Gallery) {
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

    /**
     * Neutral, schema.org-agnostic facts for
     * App\Library\Website\Seo\WebsiteLocalBusinessStructuredData to
     * shape into JSON-LD at render time — this method makes every
     * privacy/currency decision once, here, at publish time; that
     * class only ever formats what it is given.
     *
     * `telephone`/`email`/`address` are each gated behind TWO
     * independent checks, both required: `$visibleContact` (was this
     * fact actually shown to a visitor via a published `contact_details`
     * section's own Show phone/Show email/Show address toggle? — never
     * claim in machine-readable metadata what the page itself doesn't
     * display) AND, for `address` only, the SAME privacy predicate GBP/
     * SEO already rely on (`GoogleBusinessProfileReadMask::
     * addressPermittedForLocation()`) for the CURRENT primary location,
     * at THIS publish — a later change to either takes effect on the
     * next publish, exactly like `contact_details`'s own resolved values.
     *
     * `hours` is always omitted: no component on the published site
     * today ever visibly presents opening hours, so asserting them in
     * structured data would itself be an undisplayed claim.
     *
     * @param  array{phone: bool, email: bool, address: bool}  $visibleContact
     * @return array{name: ?string, telephone: ?string, email: ?string, address: ?array<string, ?string>, hours: ?array}
     */
    private function localBusinessFacts(?Business $business, array $visibleContact): array
    {
        if ($business === null) {
            return ['name' => null, 'telephone' => null, 'email' => null, 'address' => null, 'hours' => null];
        }

        $name = trim((string) $business->name);
        $email = $business->email && filter_var($business->email, FILTER_VALIDATE_EMAIL) ? $business->email : null;

        $location = $business->primaryLocation;
        $address = null;

        if ($visibleContact['address']
            && $location !== null && $location->isActive()
            && $this->addressPredicate->addressPermittedForLocation($location)) {
            $address = [
                'line1' => $location->address_line_1,
                'line2' => $location->address_line_2,
                'city' => $location->city,
                'region' => $location->region,
                'postal_code' => $location->postal_code,
                'country_code' => $location->country_code,
            ];
        }

        return [
            'name' => $name !== '' ? $name : null,
            'telephone' => $visibleContact['phone'] ? ($business->phone ?: null) : null,
            'email' => $visibleContact['email'] ? $email : null,
            'address' => $address,
            'hours' => null,
        ];
    }
}
