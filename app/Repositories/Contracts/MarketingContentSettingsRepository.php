<?php

namespace App\Repositories\Contracts;

use App\Models\MarketingContentSettings;

/**
 * Public Marketing Homepage contract, review correction — the repository
 * boundary for the singleton hero-copy settings row, replacing direct
 * Eloquent calls from MarketingContentController and HomeController.
 */
interface MarketingContentSettingsRepository extends BaseRepository
{
    /**
     * Resolves the one settings row, creating it on first access.
     * Race-safe: see EloquentMarketingContentSettingsRepository.
     */
    public function current(): MarketingContentSettings;

    public function update(MarketingContentSettings $settings, array $attributes, ?int $updatedByUserId): MarketingContentSettings;
}
