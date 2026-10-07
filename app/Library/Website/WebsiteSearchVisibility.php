<?php

namespace App\Library\Website;

use App\Models\Website;
use App\Models\WebsitePage;

/**
 * Website V1 closure — the owner's "Let search engines find these pages" decision.
 *
 * Generated pages start hidden from search (`noindex`) until the owner has read them. Releasing them is
 * one deliberate action, and it only releases what is genuinely the platform's default:
 *
 *  - a page the OWNER hid (noindex_explicit) is never released;
 *  - a page with nothing of its own yet (a hero and nothing else) is left hidden;
 *  - everything else that is hidden is released.
 *
 * Nothing goes live by itself: the published site and its sitemap change only when the owner publishes.
 */
final class WebsiteSearchVisibility
{
    /** A hero alone is a title with nothing under it; anything else (copy, services, contact details, a form) is the page's own content. */
    private const CHROME = ['hero'];

    /**
     * @return array{released: int, kept_by_owner: int, kept_thin: int}
     */
    public function release(Website $website): array
    {
        $released = 0;
        $keptByOwner = 0;
        $keptThin = 0;

        // Remembered, so a later rebuild keeps the pages open instead of re-hiding the whole site.
        Website::whereKey($website->id)->update(['indexing_released_at' => now()]);

        foreach ($website->pages()->where('noindex', true)->get() as $page) {
            if ($page->noindex_explicit) {
                $keptByOwner++;
            } elseif (! $this->hasContent($page)) {
                $keptThin++;
            } else {
                WebsitePage::whereKey($page->id)->update(['noindex' => false]);
                $released++;
            }
        }

        return ['released' => $released, 'kept_by_owner' => $keptByOwner, 'kept_thin' => $keptThin];
    }

    private function hasContent(WebsitePage $page): bool
    {
        foreach ($page->sections ?? [] as $section) {
            if (! in_array($section['type'] ?? '', self::CHROME, true)) {
                return true;
            }
        }

        return false;
    }
}
