<?php

namespace App\Library\Website\Gallery;

use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\Business;
use App\Models\WebsiteAsset;
use Throwable;

/**
 * Website Builder redesign — a thin wrapper on the existing
 * WebsiteAiGenerationClient seam (the same one guided generation already
 * uses; not a second, competing AI integration) producing an editable
 * DRAFT alt-text suggestion per uploaded gallery photo, from the
 * Business/niche context and the photo's own category tag. Failure is
 * always non-fatal — mirrors every other AI-seam-failure pattern already
 * in this codebase (WebsiteAiDraftGenerator's pause/outage handling): the
 * asset's `alt_text` is simply left blank/editable, never blocking the
 * upload itself.
 */
final class WebsiteAssetAltTextGenerator
{
    public function __construct(private readonly WebsiteAiGenerationClient $client)
    {
    }

    public function suggest(Business $business, WebsiteAsset $asset, ?int $actorUserId = null): ?string
    {
        $messages = [
            ['role' => 'system', 'content' => 'You write short, accurate, descriptive image alt text for accessibility and SEO. Never invent details you cannot know from the given context — describe the general scene, never specific people, brands, or claims. Respond with a single JSON object: {"alt_text": string}. Keep it under 125 characters.'],
            ['role' => 'user', 'content' => json_encode([
                'business_name' => $business->name,
                'category' => $asset->category_tag,
                'existing_title' => $asset->title,
            ])],
        ];

        try {
            $raw = $this->client->complete($messages, $business, $actorUserId);

            if ($raw === null) {
                return null;
            }

            $decoded = json_decode($raw, true);
            $altText = $decoded['alt_text'] ?? null;

            return is_string($altText) && trim($altText) !== '' ? trim(mb_substr($altText, 0, 160)) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
