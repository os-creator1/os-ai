<?php

namespace App\Library\Website\GuidedGeneration;

use App\Models\Website;

/**
 * Website Guided Generation contract §8.7, completed by this lane. The
 * ONE place that ever assigns an image to a generated page — the AI
 * text batch never carries an image field (§8.3), and this service
 * never invents one either: it can only ever choose among
 * `WebsiteAsset` rows that already belong to this exact Website. A slot
 * with no eligible asset is left empty and recorded in `warnings`
 * (task instruction: "expose a clear missing-media checklist"), never
 * silently rendered broken and never filled with a placeholder.
 *
 * Deliberately conservative about reuse: only the Home page's own hero
 * ever receives a background image from this binder (assigning it to
 * every generated page as well would be exactly the "the exact same
 * hero image across every page" pattern the task instructs against).
 * Real per-page/per-service photography can still be added afterward
 * by the owner through the ordinary page editor — this binder never
 * blocks that, it only ever decides what a FIRST generation starts
 * with.
 */
final class MediaBindingService
{
    /**
     * @param  array<int, array{page_type: string, title: string, slug: ?string, sections: array}>  $pages
     * @return array{pages: array, warnings: array<int, string>}
     */
    public function bind(Website $website, array $pages): array
    {
        $warnings = [];
        $availableAssetUid = $website->assets()->orderBy('id')->value('uid');

        if ($availableAssetUid === null) {
            $warnings[] = 'No uploaded photos are available yet — every page was generated without a hero image.';

            return ['pages' => $pages, 'warnings' => $warnings];
        }

        $boundHome = false;

        foreach ($pages as $index => $page) {
            if (($page['page_type'] ?? null) !== 'home' || $boundHome) {
                continue;
            }

            foreach ($page['sections'] ?? [] as $sectionIndex => $section) {
                if (($section['type'] ?? null) === 'hero') {
                    $pages[$index]['sections'][$sectionIndex]['data']['background_image'] = $availableAssetUid;
                    $boundHome = true;

                    break;
                }
            }
        }

        if (! $boundHome) {
            $warnings[] = 'The Home page has no hero section to attach a photo to.';
        }

        return ['pages' => $pages, 'warnings' => $warnings];
    }
}
