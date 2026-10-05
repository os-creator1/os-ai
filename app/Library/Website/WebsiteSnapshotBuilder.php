<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteSectionType;
use App\Library\Website\Seo\WebsiteAddressPrivacyGate;
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
 * exceptions: their VALUES are resolved from CURRENT Business/Location
 * state and copied into the snapshot at BUILD (publish) time only — a
 * phone/email change, or a change to WHICH address would be shown,
 * takes effect only on the next publish, and the already-published
 * snapshot's values never change themselves in the meantime.
 *
 * The address's PERMISSION to be shown at all (contract §7.5) is the
 * one narrow exception to that freeze: it is re-checked live, on every
 * public request, by App\Library\Website\Seo\WebsiteAddressPrivacyGate
 * — a business revoking `public_address` must stop showing its street
 * address on the very next request, even on an already-published
 * revision or one reached through rollback, never only "on the next
 * publish". This class never applies that live check to what it
 * BAKES INTO a snapshot at publish time (so a permitted, frozen address
 * value is exactly what the render path's own live gate then decides
 * whether to actually reveal); it exists here only so a frozen `false`
 * decision at publish time is never later "upgraded" to `true` by
 * something this snapshot's own values wouldn't otherwise support.
 */
final class WebsiteSnapshotBuilder
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly WebsiteAddressPrivacyGate $privacyGate,
        private readonly WebsiteCatalogReferences $catalogReferences,
        private readonly \App\Library\Website\Media\WebsiteMediaPayload $media,
    ) {}

    public function build(Website $website): array
    {
        $business = $website->business;
        $pages = $website->pages()->orderBy('sort_order')->orderBy('id')->get();
        $referencedAssetUids = [];
        $referencedFormUids = [];

        $pageSnapshots = $pages->map(function ($page) use ($website, $business, &$referencedAssetUids, &$referencedFormUids) {
            // Package blocks are resolved from Packages & Products at
            // publish time (name + price), then frozen into this immutable
            // revision exactly like `contact_details` values are.
            $sections = collect($this->media->enrichSections($this->catalogReferences->resolveSections($page->sections ?? [], (int) $website->business_id)))->map(function ($section) use ($business, &$referencedAssetUids, &$referencedFormUids) {
                $type = WebsiteSectionType::tryFrom($section['type'] ?? '');
                $data = $section['data'] ?? [];

                foreach ($this->assetUidsIn($type, $data) as $uid) {
                    $referencedAssetUids[$uid] = true;
                }

                if ($type === WebsiteSectionType::Form && ! empty($data['form_uid'])) {
                    $referencedFormUids[$data['form_uid']] = true;
                }

                if ($type === WebsiteSectionType::ContactDetails) {
                    // `show_address` is the owner's display preference,
                    // not a privacy override: an address this business/
                    // location combination must never surface (the same
                    // GoogleBusinessProfileReadMask predicate the
                    // LocalBusiness JSON-LD gate below relies on) is
                    // withheld from the visible HTML too, even when the
                    // owner checked Show address.
                    $data['resolved'] = [
                        'phone' => $data['show_phone'] ? $business?->phone : null,
                        'email' => $data['show_email'] ? $business?->email : null,
                        'address' => ($data['show_address'] && $this->addressPermitted($business)) ? $this->formatAddress($business) : null,
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

        // The owner's logo and hero image are site chrome, not sections: they are
        // referenced from the theme, so they are carried into the revision explicitly.
        foreach (['logo_asset_uid', 'hero_asset_uid'] as $themeKey) {
            $chromeUid = $website->theme[$themeKey] ?? null;
            if (is_string($chromeUid) && $chromeUid !== '') {
                $referencedAssetUids[$chromeUid] = true;
            }
        }

        $assets = WebsiteAsset::where('website_id', $website->id)
            ->whereIn('uid', array_keys($referencedAssetUids))
            ->get()
            // Frozen with the revision: the exact derivative URLs this version serves. An asset that
            // predates responsive images gets its derivatives made now (additive, original untouched).
            ->map(fn ($asset) => $this->media->forAsset($asset, ensure: true))->values()->all();

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
                'contact' => $this->chromeContact($business, $this->visibleContactFacts($pageSnapshots)),
                'localBusiness' => $this->localBusinessFacts($business, $this->visibleContactFacts($pageSnapshots)),
            ],
            'pages' => $pageSnapshots,
            'assets' => $assets,
            'forms' => $forms,
            // Old address -> new address for pages renamed since the live revision
            // (see WebsiteRedirectMap): the public renderers 301 these instead of 404ing.
            'redirects' => (new \App\Library\Website\Seo\WebsiteRedirectMap())->compute(
                $website->published_revision_id !== null
                    ? \App\Models\WebsiteRevision::find($website->published_revision_id)?->snapshot
                    : null,
                $pageSnapshots,
            ),
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

    /**
     * The phone/email the template's header strip and footer may show —
     * frozen at publish time, and only the facts the owner chose to display
     * somewhere on the site (the same rule LocalBusiness JSON-LD follows).
     *
     * @param  array{phone: bool, email: bool, address: bool}  $visible
     * @return array{phone: ?string, email: ?string}
     */
    private function chromeContact(?Business $business, array $visible): array
    {
        // Key order matters: MySQL JSON stores object keys length-then-alphabetically, and a
        // revision's snapshot is compared byte-for-byte after a round trip.
        return [
            'email' => $visible['email'] ? ($business?->email ?: null) : null,
            'phone' => $visible['phone'] ? ($business?->phone ?: null) : null,
        ];
    }

    private function assetUidsIn(?WebsiteSectionType $type, array $data): array
    {
        $uids = [];

        if ($type === WebsiteSectionType::CustomSection) {
            foreach (($data['images'] ?? []) as $imageUid) {
                if (is_string($imageUid) && $imageUid !== '') {
                    $uids[] = $imageUid;
                }
            }
        }

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
     * Delegates to the one shared, LIVE address-privacy gate
     * (App\Library\Website\Seo\WebsiteAddressPrivacyGate) that render
     * time also calls on every public request — see that class for why
     * the permission check itself is never baked into the snapshot,
     * unlike the resolved VALUES this method's two callers freeze.
     */
    private function addressPermitted(?Business $business): bool
    {
        return $this->privacyGate->currentlyPermitsAddress($business);
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
     * display), AND, for `address` only, the SAME privacy predicate
     * (WebsiteAddressPrivacyGate) already applied above when resolving
     * `contact_details`. This method's own address here is still only a
     * FROZEN, publish-time value — the render path re-checks that SAME
     * gate again, live, on every public request, so a permission
     * revoked afterward still withholds the address even though this
     * value never changes until the next publish.
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

        if ($visibleContact['address'] && $this->addressPermitted($business)) {
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
