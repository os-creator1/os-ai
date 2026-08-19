<?php

namespace App\Repositories\Contracts;

use App\Models\PlatformThemeFont;
use Illuminate\Support\Collection;

/**
 * Design System M2 Slice 1 contract §9 item 9.
 */
interface PlatformThemeFontRepository extends BaseRepository
{
    public function findById(int $id): ?PlatformThemeFont;

    public function findBySafeFilename(string $safeFilename): ?PlatformThemeFont;

    public function allOrderedByName(): Collection;

    public function create(array $attributes): PlatformThemeFont;

    public function softDelete(PlatformThemeFont $font): void;
}
