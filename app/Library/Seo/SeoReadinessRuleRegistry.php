<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoReadinessState;

/**
 * Contract 18 §5.2 — the CLOSED, deterministic Core readiness checklist.
 *
 * Pure: facts in, items out. No database, no provider call, no clock, no AI.
 * The set of items and every word of their copy is fixed here; nothing
 * customer-supplied is ever echoed, and there is no score, grade or
 * percentage — only facts and, where one exists, the existing screen that
 * fixes it. At most eight items (§5.2); five are defined in Sub-slice 18A.
 *
 * NOT here yet, deliberately: "target keywords defined". Keywords do not
 * exist until the Keywords sub-slice, so that item is added there rather
 * than approximated now.
 */
final class SeoReadinessRuleRegistry
{
    /**
     * @return array<int, SeoReadinessItem> in fixed display order
     */
    public function evaluate(SeoReadinessFacts $facts): array
    {
        return [
            $this->item(
                'website_url_set',
                'Your website address is on file',
                $facts->websiteUrlSet,
                'Add your website address in Business settings so customers and search engines can find you.',
                SeoReadinessItem::FIX_BUSINESS_SETTINGS,
            ),
            $this->item(
                'business_phone_set',
                'Your business phone number is on file',
                $facts->phoneSet,
                'Add a phone number in Business settings so customers can reach you.',
                SeoReadinessItem::FIX_BUSINESS_SETTINGS,
            ),
            $this->locationsItem($facts),
            $this->item(
                'website_published',
                'Your website is published',
                $facts->websitePublished,
                'Publish your website so there is a live version to be found.',
                SeoReadinessItem::FIX_WEBSITE,
            ),
            $this->item(
                'gbp_url_present',
                'Your Google Business Profile link is on file',
                $facts->gbpUrlPresent,
                'Add your Google Business Profile link in Business settings.',
                SeoReadinessItem::FIX_BUSINESS_SETTINGS,
            ),
        ];
    }

    private function item(string $key, string $label, bool $met, string $notMetDetail, string $fix): SeoReadinessItem
    {
        return new SeoReadinessItem(
            $key,
            $label,
            $met ? SeoReadinessState::Met : SeoReadinessState::NotMet,
            $met ? null : $notMetDetail,
            $met ? null : $fix,
        );
    }

    private function locationsItem(SeoReadinessFacts $facts): SeoReadinessItem
    {
        $key = 'locations_have_address_or_service_area';
        $label = 'Every location has an address or a service area';

        if ($facts->locationsTotal === 0) {
            return new SeoReadinessItem($key, $label, SeoReadinessState::NotApplicable, null, null);
        }

        if ($facts->locationsReady === $facts->locationsTotal) {
            return new SeoReadinessItem(
                $key,
                $label,
                SeoReadinessState::Met,
                $facts->locationsTotal . ' of ' . $facts->locationsTotal . ' ' . ($facts->locationsTotal === 1 ? 'location' : 'locations'),
                null,
            );
        }

        return new SeoReadinessItem(
            $key,
            $label,
            SeoReadinessState::NotMet,
            $facts->locationsReady . ' of ' . $facts->locationsTotal . ' locations are ready. Add an address or a service area to the rest.',
            SeoReadinessItem::FIX_BUSINESS_SETTINGS,
        );
    }
}
