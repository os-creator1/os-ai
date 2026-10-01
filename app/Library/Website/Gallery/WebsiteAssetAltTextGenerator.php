<?php

namespace App\Library\Website\Gallery;

use App\Models\Business;
use App\Models\WebsiteAsset;

/**
 * Independent-review correction round — the earlier version of this class
 * spent one AiGateway-budgeted call PER uploaded photo while never
 * actually sending the photograph itself, only the business name, the
 * category tag and the existing title. That is not a real use of vision;
 * it is real money spent to have a model invent generic prose it could
 * not possibly ground in the image. For this correction, alt text is
 * produced deterministically from structured metadata already on hand —
 * zero AI calls:
 *
 *  1. A user-provided description/title always wins verbatim.
 *  2. Otherwise a short, factual sentence is composed from the
 *     Business's name plus whatever category/context tag the photo
 *     carries (booth type, backdrop, event type) — never invented detail.
 *  3. A photo with no usable metadata at all (no title, no category, no
 *     business name) is left with empty alt text — an accurate empty
 *     string is safer than a fabricated generic caption for a genuinely
 *     decorative or context-free image.
 *
 * A real vision-grounded suggestion (sending the actual image bytes to a
 * model, in one bounded batch, only for assets that remain undescribed
 * after this deterministic pass) is an explicitly deferred follow-up —
 * this class's own job is only to prove out the safe, free, always-
 * available baseline first, and to never regenerate an asset's alt text
 * it did not just create.
 */
final class WebsiteAssetAltTextGenerator
{
    public function suggest(Business $business, WebsiteAsset $asset): ?string
    {
        if (is_string($asset->title) && trim($asset->title) !== '') {
            return trim(mb_substr($asset->title, 0, 160));
        }

        $category = is_string($asset->category_tag) ? trim(str_replace(['_', '-'], ' ', $asset->category_tag)) : '';
        $businessName = trim((string) $business->name);

        if ($category === '' && $businessName === '') {
            return null;
        }

        $sentence = $category !== ''
            ? trim($category . ($businessName !== '' ? ' — ' . $businessName : ''))
            : $businessName;

        return trim(mb_substr($sentence, 0, 160));
    }
}
