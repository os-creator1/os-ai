<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Models\BusinessBackdrop;
use App\Models\BusinessServiceImage;
use App\Models\QuestionnaireResponse;
use App\Models\Website;

/**
 * The strict photograph policy of the guided Photo Booth builder. A draft may
 * be generated with no photos at all; PUBLISHING needs real photographs of what
 * the business actually offers:
 *
 *  - a real homepage hero photograph;
 *  - a relevant real photograph for every booth service shown on the website;
 *  - a real photograph for every backdrop offered.
 *
 * What it deliberately does NOT require: a separate photo per package (a
 * package uses its own photo, else the photo of the booth it is linked to,
 * else a designed text-only card), and gallery or About images.
 *
 * Scope: only websites built through the guided questionnaire (one whose
 * service cards take photos). An older site was never asked for these photos,
 * so the policy never retroactively blocks a site that was built before it
 * existed.
 */
final class WebsiteMediaRequirements
{
    public function __construct(private readonly WebsitePageStrategy $strategy)
    {
    }

    public function appliesTo(Website $website): bool
    {
        $response = QuestionnaireResponse::where('website_id', $website->id)->latest('id')->first();

        if ($response === null) {
            return false;
        }

        foreach ($response->version->steps() as $step) {
            if (($step['target_module'] ?? null) === 'business_service' && in_array('cover', (array) ($step['row_fields'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{key: string, title: string, detail: string, step: string}> each unmet requirement;
     *         `step` is the setup question where the photo is added ('gallery', 'booth_services', 'backdrops')
     */
    public function missing(Website $website): array
    {
        if (! $this->appliesTo($website)) {
            return [];
        }

        $missing = [];
        $business = $website->business;

        if (! $this->hasHomepageHero($website)) {
            $missing[] = [
                'key' => 'hero',
                'title' => 'Homepage cover photo',
                'detail' => 'Your homepage needs a real cover photo. Upload one of your event photos and choose it as the cover.',
                'step' => 'gallery',
            ];
        }

        $services = $this->strategy->eligibleServices($business);
        $withPhoto = BusinessServiceImage::whereIn('business_service_id', $services->pluck('id'))->pluck('business_service_id')->all();

        foreach ($services as $service) {
            if (! in_array($service->id, $withPhoto, true)) {
                $missing[] = [
                    'key' => 'service:' . $service->uid,
                    'title' => 'Photo needed: ' . $service->name,
                    'detail' => '"' . $service->name . '" is shown on your website, so it needs a real photo of that booth. Add one — or hide this booth from your website.',
                    'step' => 'booth_services',
                ];
            }
        }

        $backdrops = BusinessBackdrop::where('business_id', $business->id)->where('availability', true)->with('images')->orderBy('position')->get();

        foreach ($backdrops as $backdrop) {
            if ($backdrop->images->isEmpty()) {
                $missing[] = [
                    'key' => 'backdrop:' . $backdrop->uid,
                    'title' => 'Photo needed: ' . $backdrop->name,
                    'detail' => 'The backdrop "' . $backdrop->name . '" has no photo, so it cannot be shown. Add a photo — or remove it from your backdrops.',
                    'step' => 'backdrops',
                ];
            }
        }

        return $missing;
    }

    /**
     * The homepage hero must show an actual uploaded photograph: the owner's
     * chosen cover/hero (or a gallery photo bound to it) — never an empty
     * background, and never a derived package or service copy.
     */
    private function hasHomepageHero(Website $website): bool
    {
        $home = $website->pages()->where('is_home', true)->first();

        if ($home === null) {
            return false;
        }

        // The owner's own hero image (Brand & look) is site chrome the hero shows in preference to anything else.
        $ownerHero = $website->theme['hero_asset_uid'] ?? null;
        if (is_string($ownerHero) && $ownerHero !== '' && $website->assets()->where('uid', $ownerHero)->where('purpose', WebsiteAssetPurpose::Hero->value)->exists()) {
            return true;
        }

        foreach ((array) ($home->sections ?? []) as $section) {
            if (($section['type'] ?? null) !== 'hero') {
                continue;
            }

            $uid = $section['data']['background_image'] ?? null;

            if (is_string($uid) && $uid !== '') {
                return $website->assets()
                    ->where('uid', $uid)
                    ->whereIn('purpose', [WebsiteAssetPurpose::Gallery->value, WebsiteAssetPurpose::Hero->value])
                    ->exists();
            }
        }

        return false;
    }
}
