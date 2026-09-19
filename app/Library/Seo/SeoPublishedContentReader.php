<?php

namespace App\Library\Seo;

use App\Enums\Website\WebsiteStatus;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteRevision;

/**
 * Contract 18 §9.1 — the SINGLE reader of the published Website snapshot for
 * SEO. The Core content counts, keyword coverage and the audit all read
 * through here so the snapshot is parsed in exactly one place.
 *
 * READ-ONLY, and structurally so: it selects a Website and one immutable
 * revision and returns value objects. It has no write path to `websites`,
 * `website_pages`, `website_revisions` or `website_assets` (Contract 18 §12,
 * Website contract §17.1 — SEO never writes website pages), makes no network
 * call and fetches no URL. It reads the PUBLISHED revision, never the mutable
 * draft pages, so what SEO reports is exactly what the public site serves.
 *
 * Constant cost: two queries (the Business's Website, then its published
 * revision), independent of page count.
 *
 * Returns null when the Business has no published Website — a Website that
 * is a draft, archived, or has no published revision is "not published".
 */
final class SeoPublishedContentReader
{
    public function forBusiness(Business $business): ?SeoPublishedContent
    {
        $website = Website::query()->where('business_id', $business->id)->first();

        if ($website === null
            || $website->status !== WebsiteStatus::Published
            || $website->published_revision_id === null) {
            return null;
        }

        $revision = WebsiteRevision::query()
            ->where('website_id', $website->id)
            ->where('id', $website->published_revision_id)
            ->first();

        $snapshot = $revision?->snapshot;

        if (! is_array($snapshot)) {
            return null;
        }

        $pages = [];

        foreach ((is_array($snapshot['pages'] ?? null) ? $snapshot['pages'] : []) as $page) {
            if (! is_array($page)) {
                continue;
            }

            $seo = is_array($page['seo'] ?? null) ? $page['seo'] : [];

            $pages[] = new SeoPublishedPage(
                uid: (string) ($page['uid'] ?? ''),
                slug: isset($page['slug']) ? (string) $page['slug'] : null,
                isHome: (bool) ($page['is_home'] ?? false),
                title: (string) ($page['title'] ?? ''),
                seoTitle: isset($seo['seo_title']) ? (string) $seo['seo_title'] : null,
                metaDescription: isset($seo['meta_description']) ? (string) $seo['meta_description'] : null,
                noindex: (bool) ($seo['noindex'] ?? false),
                sections: is_array($page['sections'] ?? null) ? $page['sections'] : [],
            );
        }

        $assets = [];

        foreach ((is_array($snapshot['assets'] ?? null) ? $snapshot['assets'] : []) as $asset) {
            if (is_array($asset) && isset($asset['uid'])) {
                $assets[] = [
                    'uid' => (string) $asset['uid'],
                    'alt_text' => isset($asset['alt_text']) ? (string) $asset['alt_text'] : null,
                ];
            }
        }

        return new SeoPublishedContent((int) $website->id, (int) $revision->id, $pages, $assets);
    }
}
