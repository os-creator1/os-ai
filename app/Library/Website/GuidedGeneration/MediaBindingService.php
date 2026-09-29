<?php

namespace App\Library\Website\GuidedGeneration;

use App\Models\Website;

/**
 * Website Guided Generation contract §8.7, completed by this lane,
 * strengthened by acceptance-correction Blocker 9. The ONE place that
 * ever assigns an image to a generated page — the AI text batch never
 * carries an image field (§8.3), and this service never invents one
 * either: it can only ever choose among `WebsiteAsset` rows that already
 * belong to this exact Website (never a cross-Website reference). A
 * slot with no eligible asset is left empty and recorded in `warnings`
 * (task instruction: "expose a clear missing-media checklist"), never
 * silently rendered broken and never filled with a placeholder.
 *
 * Bounded purposes, in deterministic upload-order:
 *  - home hero (the first uploaded asset only — never reused as any
 *    other page's hero, so the same photo does not repeat as the
 *    hero on every page);
 *  - each `image_text` section on any OTHER page (service_detail pages,
 *    in the current template manifests) — a distinct, round-robined
 *    asset per slot, cycling only once every asset has been used at
 *    least once;
 *  - the Gallery page's own `gallery` section — built ENTIRELY here
 *    from every real uploaded asset, never by AI: the section validator
 *    categorically rejects any `image` value in AI-authored content
 *    (§8.3), so a 'gallery' section is never something the guided AI
 *    client asks for or the output validator accepts — this service is
 *    the only source of that section's content.
 *
 * `about`/team photography is deliberately NOT a supported purpose yet:
 * the `about` page_type's own manifest does not allow any image-bearing
 * section type today, so there is no bounded slot to attach one to
 * without inventing a new section primitive — out of this correction's
 * scope (see docs/automation/WEBSITE-GENERATOR-SEO-COMPLETION-NOTE.md).
 */
final class MediaBindingService
{
    /**
     * @param  array<int, array{page_key: string, page_type: string, is_home: bool, slug: ?string, title: string, seo_title: ?string, meta_description: ?string, sections: array}>  $pages
     * @return array{pages: array, warnings: array<int, string>}
     */
    public function bind(Website $website, array $pages): array
    {
        $assets = $website->assets()->orderBy('id')->get();
        $warnings = [];

        if ($assets->isEmpty()) {
            $warnings[] = 'No uploaded photos are available yet — every page was generated without any photography.';

            return ['pages' => $this->fillGalleryFromAssets($pages, $assets->all(), $warnings), 'warnings' => $warnings];
        }

        $heroAssetUid = $assets->first()->uid;
        $remainingPool = $assets->slice(1)->values();
        // Every asset is still eligible for the round-robin pool at
        // least once, even when only one photo exists in total — a
        // service page's own inline photo is not "the hero repeated on
        // every page" merely because it is the only photo available.
        $roundRobinPool = $remainingPool->isNotEmpty() ? $remainingPool : $assets;
        $cursor = 0;

        $boundHome = false;
        $imageTextSlotsSeen = 0;

        foreach ($pages as $index => $page) {
            foreach ($page['sections'] ?? [] as $sectionIndex => $section) {
                $type = $section['type'] ?? null;

                if ($type === 'hero' && ($page['page_type'] ?? null) === 'home' && ! $boundHome) {
                    $pages[$index]['sections'][$sectionIndex]['data']['background_image'] = $heroAssetUid;
                    $boundHome = true;

                    continue;
                }

                if ($type === 'image_text' && empty($section['data']['image'])) {
                    $imageTextSlotsSeen++;

                    // roundRobinPool is never empty once $assets itself
                    // is non-empty (see the fallback above), so every
                    // slot is always filled with SOMETHING real — the
                    // only open question is whether it had to repeat.
                    $asset = $roundRobinPool[$cursor % $roundRobinPool->count()];
                    $pages[$index]['sections'][$sectionIndex]['data']['image'] = $asset->uid;
                    $cursor++;
                }
            }
        }

        if (! $boundHome) {
            $warnings[] = 'The Home page has no hero section to attach a photo to.';
        }

        // A real, meaningful signal (some slot necessarily repeats a
        // photo already used elsewhere) rather than "a slot went
        // unfilled," which never happens once at least one asset exists.
        // Two ways this happens: only one asset exists in total (every
        // inline slot then necessarily repeats the hero's own photo), or
        // there are more inline slots than the non-hero pool can cover
        // distinctly.
        $onlyOneAssetTotal = $remainingPool->isEmpty();
        if ($imageTextSlotsSeen > 0 && ($onlyOneAssetTotal || $imageTextSlotsSeen > $roundRobinPool->count())) {
            $warnings[] = 'Not enough distinct uploaded photos for every photo slot — some photos repeat across pages.';
        }

        return ['pages' => $this->fillGalleryFromAssets($pages, $assets->all(), $warnings), 'warnings' => $warnings];
    }

    /**
     * The Gallery page's `gallery` section is always constructed here
     * from real assets, never merged with anything AI wrote (AI is
     * never asked for, and the output validator never accepts, a
     * 'gallery' section — see class docblock). A Gallery page with zero
     * usable assets gets no gallery section at all rather than an empty
     * or fabricated one; WebsitePageStrategy::galleryEligible() already
     * keeps this page out of the plan below the minimum-photo bar, so
     * this is only ever a defensive fallback (e.g. photos deleted
     * between plan-building and commit).
     *
     * @param  array<int, \App\Models\WebsiteAsset>  $assets
     */
    private function fillGalleryFromAssets(array $pages, array $assets, array &$warnings): array
    {
        foreach ($pages as $index => $page) {
            if (($page['page_type'] ?? null) !== 'gallery') {
                continue;
            }

            if ($assets === []) {
                $warnings[] = 'The Gallery page has no uploaded photos yet.';

                continue;
            }

            $items = array_map(fn ($asset) => ['image' => $asset->uid], array_slice($assets, 0, 24));
            $pages[$index]['sections'][] = ['type' => 'gallery', 'data' => ['heading' => 'Photos', 'items' => $items]];
        }

        return $pages;
    }
}
