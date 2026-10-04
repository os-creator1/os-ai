<?php

namespace App\Library\Website;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Library\Catalog\CatalogMoney;
use App\Models\CatalogItem;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Support\Str;

/**
 * Packages & Products is the ONE pricing/product truth. A Website package
 * block (a `services` section item) therefore carries the canonical
 * `catalog_item_uid` it was written for, and the item's NAME and PRICE are
 * resolved from the catalog whenever the website is rendered for a person
 * who matters: the editor's preview, and every publish snapshot — never
 * trusted from whatever text was copied into the section at generation
 * time. (Same precedent as `contact_details`: its values are read live at
 * publish and frozen into that immutable revision.)
 *
 * A published revision is immutable by contract, so it cannot change after
 * the fact. Instead {@see self::staleness()} reports when the catalog has
 * moved on since the last publish — an archived package, or an edited one —
 * so Studio can say plainly that the live site is out of sync and offer the
 * existing, safe sync path (publish again).
 *
 * The AI-written description stays page copy (owners are told to put
 * per-package detail on the page); only identity and price are resolved.
 */
final class WebsiteCatalogReferences
{
    /** WebsiteSectionValidator's own bound for a services item's price_label. */
    private const PRICE_LABEL_MAX = 40;

    private const NAME_MAX = 120;

    /**
     * Stamps `catalog_item_uid` on package items AI wrote by matching the
     * name it was given verbatim from the catalog (the uid itself is never
     * left to AI to echo). Items already carrying one are untouched.
     *
     * @param  array<int, array<string, mixed>>  $pages  pages with `page_type` and `sections`
     * @return array<int, array<string, mixed>>
     */
    public function stampPackagePages(array $pages, int $businessId): array
    {
        $byName = CatalogItem::where('business_id', $businessId)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->get()
            ->keyBy(fn (CatalogItem $item) => mb_strtolower(trim($item->name)));

        if ($byName->isEmpty()) {
            return $pages;
        }

        foreach ($pages as $pageIndex => $page) {
            if (($page['page_type'] ?? null) !== 'packages') {
                continue;
            }

            foreach ($page['sections'] ?? [] as $sectionIndex => $section) {
                if (($section['type'] ?? null) !== 'services') {
                    continue;
                }

                foreach ($section['data']['items'] ?? [] as $itemIndex => $item) {
                    if (! empty($item['catalog_item_uid'])) {
                        continue;
                    }

                    $match = $byName->get(mb_strtolower(trim((string) ($item['name'] ?? ''))));

                    if ($match !== null) {
                        $pages[$pageIndex]['sections'][$sectionIndex]['data']['items'][$itemIndex]['catalog_item_uid'] = $match->uid;
                    }
                }
            }
        }

        return $pages;
    }

    /**
     * Live-resolves the referenced package items of a page's sections.
     * Items whose package was archived (or no longer exists) are dropped —
     * a section left with no items disappears — so a removed package never
     * keeps showing on the site. Items without a reference are untouched.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    public function resolveSections(array $sections, int $businessId): array
    {
        $uids = $this->referencedUids($sections);

        if ($uids === []) {
            return $sections;
        }

        $live = CatalogItem::where('business_id', $businessId)
            ->whereIn('uid', $uids)
            ->get()
            ->keyBy('uid');

        $resolved = [];

        foreach ($sections as $section) {
            if (($section['type'] ?? null) !== 'services' || ! is_array($section['data']['items'] ?? null)) {
                $resolved[] = $section;

                continue;
            }

            $items = [];

            foreach ($section['data']['items'] as $item) {
                $uid = $item['catalog_item_uid'] ?? null;

                if (! is_string($uid) || $uid === '') {
                    $items[] = $item;

                    continue;
                }

                $catalogItem = $live->get($uid);

                if ($catalogItem === null || $catalogItem->isArchived()) {
                    continue;
                }

                $item['name'] = Str::limit($catalogItem->name, self::NAME_MAX, '');

                if ($catalogItem->price_minor !== null && $catalogItem->currency_code) {
                    $item['price_label'] = Str::limit(CatalogMoney::format($catalogItem->price_minor, $catalogItem->currency_code), self::PRICE_LABEL_MAX, '');
                } else {
                    $item['price_label'] = null;
                }

                $items[] = $item;
            }

            if ($items === []) {
                continue; // every package in it was removed from the catalog
            }

            $section['data']['items'] = $items;
            $resolved[] = $section;
        }

        return $resolved;
    }

    /**
     * Has the catalog moved on since the website was last published?
     * Compute-on-read: no schema, no event, nothing to keep in sync.
     *
     * @return array{out_of_sync: bool, changed: array<int, string>, removed: array<int, string>}
     */
    public function staleness(Website $website): array
    {
        $none = ['out_of_sync' => false, 'changed' => [], 'removed' => []];

        $revision = $website->published_revision_id !== null
            ? WebsiteRevision::find($website->published_revision_id)
            : null;

        if ($revision === null) {
            return $none;
        }

        $snapshotSections = [];
        foreach ($revision->snapshot['pages'] ?? [] as $page) {
            foreach ($page['sections'] ?? [] as $section) {
                $snapshotSections[] = $section;
            }
        }

        $uids = $this->referencedUids($snapshotSections);

        if ($uids === []) {
            return $none;
        }

        $live = CatalogItem::where('business_id', $website->business_id)->whereIn('uid', $uids)->get()->keyBy('uid');
        $changed = [];
        $removed = [];

        foreach ($uids as $uid) {
            $item = $live->get($uid);

            if ($item === null || $item->isArchived()) {
                $removed[] = $item?->name ?? 'A package';
            } elseif ($item->updated_at !== null && $revision->created_at !== null && $item->updated_at->gt($revision->created_at)) {
                $changed[] = $item->name;
            }
        }

        return ['out_of_sync' => $changed !== [] || $removed !== [], 'changed' => $changed, 'removed' => $removed];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, string>
     */
    private function referencedUids(array $sections): array
    {
        $uids = [];

        foreach ($sections as $section) {
            if (($section['type'] ?? null) !== 'services') {
                continue;
            }

            foreach ($section['data']['items'] ?? [] as $item) {
                if (is_string($item['catalog_item_uid'] ?? null) && $item['catalog_item_uid'] !== '') {
                    $uids[$item['catalog_item_uid']] = true;
                }
            }
        }

        return array_keys($uids);
    }
}
