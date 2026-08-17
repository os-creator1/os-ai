<?php

namespace App\Repositories\Eloquent;

use App\Models\PlatformThemePreset;
use App\Repositories\Contracts\PlatformThemePresetRepository;
use Illuminate\Support\Collection;

class EloquentPlatformThemePresetRepository extends EloquentBaseRepository implements PlatformThemePresetRepository
{
    public function __construct(PlatformThemePreset $preset)
    {
        parent::__construct($preset);
    }

    public function findByUid(string $uid): ?PlatformThemePreset
    {
        return $this->query()->where('uid', $uid)->first();
    }

    public function findActive(): ?PlatformThemePreset
    {
        return $this->query()->where('status', PlatformThemePreset::STATUS_ACTIVE)->first();
    }

    public function findFactory(): ?PlatformThemePreset
    {
        return $this->query()->where('is_factory', true)->first();
    }

    public function lockFactoryForUpdate(): ?PlatformThemePreset
    {
        return $this->query()->where('is_factory', true)->lockForUpdate()->first();
    }

    public function allOrderedByName(): Collection
    {
        return $this->query()->orderBy('name')->get();
    }

    public function findAllReferencingFont(int $fontId): Collection
    {
        return $this->query()->where('font_uploaded_id', $fontId)->get();
    }

    public function create(array $attributes): PlatformThemePreset
    {
        /** @var PlatformThemePreset $preset */
        $preset = $this->make($attributes);
        $preset->save();

        return $preset;
    }

    public function update(PlatformThemePreset $preset, array $attributes): PlatformThemePreset
    {
        $preset->fill($attributes);
        $preset->save();

        return $preset;
    }

    public function delete(PlatformThemePreset $preset): void
    {
        $preset->delete();
    }
}
