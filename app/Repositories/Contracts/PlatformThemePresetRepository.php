<?php

namespace App\Repositories\Contracts;

use App\Models\PlatformThemePreset;
use Illuminate\Support\Collection;

/**
 * Design System M2 Slice 1 contract §9 item 7.
 */
interface PlatformThemePresetRepository extends BaseRepository
{
    public function findByUid(string $uid): ?PlatformThemePreset;

    public function findActive(): ?PlatformThemePreset;

    public function findFactory(): ?PlatformThemePreset;

    /**
     * Locks the Factory row for update — the stable mutex target every
     * activation acquires before touching anything else (§6.14).
     */
    public function lockFactoryForUpdate(): ?PlatformThemePreset;

    public function allOrderedByName(): Collection;

    /**
     * Every preset (any status) referencing this uploaded font, for the
     * cross-preset deletion guard (§6.16).
     */
    public function findAllReferencingFont(int $fontId): Collection;

    public function create(array $attributes): PlatformThemePreset;

    public function update(PlatformThemePreset $preset, array $attributes): PlatformThemePreset;

    public function delete(PlatformThemePreset $preset): void;
}
