<?php

namespace App\Repositories\Eloquent;

use App\Models\MarketingContentSettings;
use App\Repositories\Contracts\MarketingContentSettingsRepository;
use Illuminate\Database\QueryException;

class EloquentMarketingContentSettingsRepository extends EloquentBaseRepository implements MarketingContentSettingsRepository
{
    public function __construct(MarketingContentSettings $settings)
    {
        parent::__construct($settings);
    }

    /**
     * Review correction — the app-level half of the singleton guarantee.
     * The database half is the unique `singleton_key` index added to the
     * 2026_10_11_120000 migration; without it, two concurrent first
     * requests could both observe an empty table and both insert a row.
     * With it, at most one concurrent insert can succeed — the loser lands
     * in the catch below and simply re-reads the winner's row rather than
     * surfacing the duplicate-key error or creating a second row.
     */
    public function current(): MarketingContentSettings
    {
        $existing = $this->findSingleton();

        if ($existing !== null) {
            return $existing;
        }

        try {
            /** @var MarketingContentSettings $settings */
            $settings = $this->query()->create(['singleton_key' => MarketingContentSettings::SINGLETON_KEY]);

            return $settings;
        } catch (QueryException $e) {
            $existing = $this->findSingleton();

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    public function update(MarketingContentSettings $settings, array $attributes, ?int $updatedByUserId): MarketingContentSettings
    {
        $settings->fill($attributes);
        $settings->updated_by_user_id = $updatedByUserId;
        $settings->save();

        return $settings;
    }

    private function findSingleton(): ?MarketingContentSettings
    {
        return $this->query()->where('singleton_key', MarketingContentSettings::SINGLETON_KEY)->first();
    }
}
