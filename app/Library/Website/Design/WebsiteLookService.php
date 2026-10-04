<?php

namespace App\Library\Website\Design;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Exceptions\Website\InvalidWebsiteAssetException;
use App\Library\Website\WebsiteAssetUploadService;
use App\Models\Website;
use App\Models\WebsiteAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Website V1 final — the owner's safe customisation of a template's look:
 * ONE brand colour, a logo and a hero image. Nothing else about the design
 * (layout, fonts, section order, palette) is ever owner-controlled.
 *
 * The three values live in the Website's `theme` JSON next to the template's
 * own tokens (`brand_color`, `logo_asset_uid`, `hero_asset_uid`), so they
 * survive a draft rebuild and a template switch (see ownerTokens()) and are
 * frozen into every published revision with the rest of the theme. Logo and
 * hero are ordinary Website-owned assets (same magic-byte / size / decode
 * checks as every other upload) with their own durable `purpose`, so they
 * never count toward the gallery or its limits.
 */
final class WebsiteLookService
{
    /** Theme keys that belong to the owner, never to the template. */
    public const OWNER_THEME_KEYS = ['brand_color', 'logo_asset_uid', 'hero_asset_uid'];

    public function __construct(private readonly WebsiteAssetUploadService $uploads) {}

    /**
     * The owner's tokens from a theme, to carry across a template change.
     *
     * @param  ?array<string, mixed>  $theme
     * @return array<string, mixed>
     */
    public static function ownerTokens(?array $theme): array
    {
        return array_intersect_key($theme ?? [], array_flip(self::OWNER_THEME_KEYS));
    }

    /**
     * @throws ValidationException
     */
    public function setBrandColor(Website $website, ?string $value): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            $this->patchTheme($website, ['brand_color' => null]);

            return;
        }

        $hex = BrandColors::normalize($value);

        if ($hex === null) {
            throw ValidationException::withMessages(['brand_color' => ['Enter a colour like #1a56db.']]);
        }

        $this->patchTheme($website, ['brand_color' => $hex]);
    }

    /**
     * @throws ValidationException
     */
    public function setLogo(Website $website, UploadedFile $file, ?string $altText = null): WebsiteAsset
    {
        return $this->replace($website, 'logo_asset_uid', WebsiteAssetPurpose::Logo, $file, $altText ?: trim($website->name . ' logo'));
    }

    /**
     * @throws ValidationException
     */
    public function setHero(Website $website, UploadedFile $file, ?string $altText = null): WebsiteAsset
    {
        return $this->replace($website, 'hero_asset_uid', WebsiteAssetPurpose::Hero, $file, $altText ?: trim($website->name . ' — photo booth'));
    }

    public function removeLogo(Website $website): void
    {
        $this->remove($website, 'logo_asset_uid');
    }

    public function removeHero(Website $website): void
    {
        $this->remove($website, 'hero_asset_uid');
    }

    /**
     * @throws ValidationException
     */
    private function replace(Website $website, string $themeKey, WebsiteAssetPurpose $purpose, UploadedFile $file, string $alt): WebsiteAsset
    {
        try {
            $asset = $this->uploads->store($website, $file, mb_substr($alt, 0, 160), $purpose);
        } catch (InvalidWebsiteAssetException $e) {
            throw ValidationException::withMessages([$purpose->value => [$e->getMessage()]]);
        }

        $previousUid = $website->theme[$themeKey] ?? null;
        $this->patchTheme($website, [$themeKey => $asset->uid]);
        $this->discard($website, $previousUid, $asset->uid);

        return $asset;
    }

    private function remove(Website $website, string $themeKey): void
    {
        $previousUid = $website->theme[$themeKey] ?? null;
        $this->patchTheme($website, [$themeKey => null]);
        $this->discard($website, $previousUid, null);
    }

    /**
     * Drops the replaced file when nothing can still need it. A published
     * asset is permanent by contract (§13.1) and is simply left in place.
     */
    private function discard(Website $website, mixed $previousUid, ?string $keepUid): void
    {
        if (! is_string($previousUid) || $previousUid === '' || $previousUid === $keepUid) {
            return;
        }

        $asset = WebsiteAsset::where('website_id', $website->id)->where('uid', $previousUid)->first();

        if ($asset === null || $asset->first_published_at !== null) {
            return;
        }

        try {
            $this->uploads->delete($website, $asset);
        } catch (ValidationException) {
            // Still referenced somewhere — keep it.
        }
    }

    /**
     * @param  array<string, mixed>  $values  a null value removes the key
     */
    private function patchTheme(Website $website, array $values): void
    {
        DB::transaction(function () use ($website, $values) {
            $locked = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();
            $theme = $locked->theme ?? [];

            foreach ($values as $key => $value) {
                if ($value === null) {
                    unset($theme[$key]);
                } else {
                    $theme[$key] = $value;
                }
            }

            $locked->theme = $theme;
            $locked->save();
            $website->theme = $theme;
        });
    }
}
