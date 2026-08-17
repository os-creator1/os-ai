<?php

namespace App\Repositories\Eloquent;

use App\Models\PlatformThemeFont;
use App\Repositories\Contracts\PlatformThemeFontRepository;
use Illuminate\Support\Carbon;

/**
 * §6.16: the actual cross-preset reference check this repository's own
 * deletion guard depends on lives on PlatformThemePresetRepository
 * (findAllReferencingFont) — composed by PlatformThemeManager before
 * calling softDelete() here, since only the preset repository can see
 * every preset referencing a given font.
 */
class EloquentPlatformThemeFontRepository extends EloquentBaseRepository implements PlatformThemeFontRepository
{
    public function __construct(PlatformThemeFont $font)
    {
        parent::__construct($font);
    }

    public function findById(int $id): ?PlatformThemeFont
    {
        return $this->query()->find($id);
    }

    public function findBySafeFilename(string $safeFilename): ?PlatformThemeFont
    {
        return $this->query()->where('safe_filename', $safeFilename)->first();
    }

    public function allOrderedByName(): \Illuminate\Support\Collection
    {
        return $this->query()->orderBy('original_filename')->get();
    }

    public function create(array $attributes): PlatformThemeFont
    {
        /** @var PlatformThemeFont $font */
        $font = $this->make($attributes);
        $font->created_at = Carbon::now();
        $font->save();

        return $font;
    }

    public function softDelete(PlatformThemeFont $font): void
    {
        $font->delete();
    }
}
