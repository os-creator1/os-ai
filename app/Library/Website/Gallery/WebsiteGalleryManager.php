<?php

namespace App\Library\Website\Gallery;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Exceptions\Website\InvalidWebsiteAssetException;
use App\Library\Website\WebsiteAssetUploadService;
use App\Models\Website;
use App\Models\WebsiteAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Website Builder redesign — the wizard's "Show your work" gallery step,
 * and (independent-review correction round 2) the custom-section step's
 * media too. v1 scope (explicitly agreed): multi-file upload with
 * per-file progress (a plain client-side concern — each file is one
 * ordinary WebsiteAssetUploadService::store() call) and up/down
 * reordering plus a single cover flag; a queued responsive-image-variant
 * pipeline is explicitly deferred.
 *
 * Independent-review correction round 2 — every method here now takes an
 * explicit `WebsiteAssetPurpose` and scopes ALL of its reads/writes to
 * that purpose alone: a custom-section image can never be listed,
 * reordered, covered, or removed through the gallery's own operations
 * and vice versa. This is the actual ownership/purpose boundary — never
 * the customer-editable `category_tag`. Also enforces the server-side
 * upload caps the task requires (never merely a UI limit): at most
 * MAX_GALLERY_ASSETS gallery photos, MAX_CUSTOM_SECTION_ASSETS
 * custom-section photos, and MAX_TOTAL_UPLOAD_BYTES of combined
 * customer-uploaded (never a derived package-mirror) storage per
 * Website.
 *
 * Mirrors CatalogItemManager::reorder()'s lock-then-rewrite-position
 * shape, scoped to one Website's assets instead of one Business's
 * catalog items.
 */
final class WebsiteGalleryManager
{
    public const MAX_GALLERY_ASSETS = 24;

    public const MAX_CUSTOM_SECTION_ASSETS = 6;

    /** 120 MB — combined customer-uploaded (gallery + custom-section) storage per Website. Package mirrors are derived, never customer uploads, and never count against this. */
    public const MAX_TOTAL_UPLOAD_BYTES = 120 * 1024 * 1024;

    public function __construct(
        private readonly WebsiteAssetUploadService $uploads,
        private readonly WebsiteAssetAltTextGenerator $altText,
    ) {
    }

    /**
     * Independent-review correction round 3 (item 10) — gallery and
     * custom-section uploads share ONE combined 120MB byte quota
     * (MAX_TOTAL_UPLOAD_BYTES) but used to lock only THIS call's own
     * purpose-scoped asset rows (`lockForUpdate()` on a purpose-filtered
     * query) — two concurrent uploads of DIFFERENT purposes locked
     * disjoint row sets and could therefore both read the same "room
     * remaining" snapshot and both proceed, together exceeding the
     * shared cap. Locking the Website row itself first, before
     * calculating or consuming EITHER the per-purpose count cap or the
     * combined byte quota, serializes every upload for this Website
     * regardless of purpose — the second of two concurrent calls always
     * waits for the first to commit (or roll back) before it can even
     * read the byte total.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, WebsiteAsset>
     * @throws InvalidWebsiteAssetException on the first invalid file, or when a server-enforced cap would be exceeded —
     *         nothing from this call is stored once any file in it would push the Website over a cap.
     */
    public function uploadMany(Website $website, array $files, WebsiteAssetPurpose $purpose, ?string $categoryTag = null): array
    {
        /** @var array<int, WebsiteAsset> $createdAssets */
        $createdAssets = [];

        try {
            return DB::transaction(function () use ($website, $files, $purpose, $categoryTag, &$createdAssets) {
                $lockedWebsite = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();

                $existingCount = $lockedWebsite->assets()->where('purpose', $purpose->value)->count();
                $maxCount = $purpose === WebsiteAssetPurpose::CustomSection ? self::MAX_CUSTOM_SECTION_ASSETS : self::MAX_GALLERY_ASSETS;

                if ($existingCount + count($files) > $maxCount) {
                    throw new InvalidWebsiteAssetException("This Website can have at most {$maxCount} " . ($purpose === WebsiteAssetPurpose::CustomSection ? 'custom-section' : 'gallery') . ' photos.');
                }

                $existingUploadedBytes = (int) $lockedWebsite->assets()
                    ->whereIn('purpose', [WebsiteAssetPurpose::Gallery->value, WebsiteAssetPurpose::CustomSection->value])
                    ->sum('size');
                $incomingBytes = array_sum(array_map(fn (UploadedFile $file) => $file->getSize() ?: 0, $files));

                if ($existingUploadedBytes + $incomingBytes > self::MAX_TOTAL_UPLOAD_BYTES) {
                    throw new InvalidWebsiteAssetException('This Website has reached its total uploaded-photo storage limit.');
                }

                $nextPosition = (int) ($lockedWebsite->assets()->where('purpose', $purpose->value)->max('sort_order') ?? -1) + 1;

                foreach ($files as $file) {
                    $asset = $this->uploads->store($lockedWebsite, $file, null, $purpose);
                    $createdAssets[] = $asset;
                    $asset->forceFill(['sort_order' => $nextPosition, 'category_tag' => $categoryTag])->save();
                    $asset->forceFill(['alt_text' => $this->altText->suggest($lockedWebsite->business, $asset)])->save();
                    $nextPosition++;
                }

                return $createdAssets;
            });
        } catch (\Throwable $e) {
            // Independent-review correction round 3 (item 10) — the
            // transaction above already rolled back every DATABASE row
            // this call created, but WebsiteAssetUploadService::store()
            // writes each file to disk OUTSIDE that transaction (a
            // filesystem write cannot be rolled back by MySQL). Without
            // this, a batch that fails partway through (e.g. the 3rd of
            // 5 files is corrupt, or the batch as a whole trips the byte
            // cap after some files were already stored earlier in the
            // SAME foreach) would leave the earlier files' images
            // orphaned on disk with no corresponding row, forever.
            foreach ($createdAssets as $orphaned) {
                $fullPath = public_path($orphaned->path);

                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }

            throw $e;
        }
    }

    /**
     * @param  list<string>  $orderedUids  every one of this Website's asset uids OF THIS PURPOSE, exactly once — an asset of a different purpose is never affected by this call
     */
    public function reorder(Website $website, WebsiteAssetPurpose $purpose, array $orderedUids): void
    {
        DB::transaction(function () use ($website, $purpose, $orderedUids) {
            $assets = WebsiteAsset::where('website_id', $website->id)->where('purpose', $purpose->value)->lockForUpdate()->get()->keyBy('uid');

            if (count($orderedUids) !== $assets->count()
                || count(array_unique($orderedUids)) !== count($orderedUids)
                || array_diff($orderedUids, $assets->keys()->all()) !== []) {
                throw new InvalidWebsiteAssetException('The order must list every photo of this Website exactly once.');
            }

            foreach ($orderedUids as $position => $uid) {
                /** @var WebsiteAsset $asset */
                $asset = $assets[$uid];

                if ($asset->sort_order !== $position) {
                    $asset->forceFill(['sort_order' => $position])->save();
                }
            }
        });
    }

    /**
     * Single-cover invariant enforced here, at the application layer —
     * matching this codebase's existing convention (CatalogItemManager's
     * price/currency co-nullable check, CatalogItemManager::attachImage()'s
     * own single-cover clear-then-set) rather than a DB constraint. Scoped
     * to gallery-purpose assets: "cover" only ever means the homepage
     * hero candidate, never a custom-section or package-mirror image.
     */
    public function setCover(Website $website, WebsiteAsset $asset): void
    {
        $this->assertOwnedAndPurpose($website, $asset, WebsiteAssetPurpose::Gallery);

        DB::transaction(function () use ($website, $asset) {
            WebsiteAsset::where('website_id', $website->id)->where('purpose', WebsiteAssetPurpose::Gallery->value)->update(['is_cover' => false]);
            $asset->forceFill(['is_cover' => true])->save();
        });
    }

    /**
     * Independent-review correction round 2 — updating the customer-
     * facing title also refreshes an AUTOGENERATED alt text (one this
     * class/WebsiteAssetAltTextGenerator produced, never one the owner
     * typed themselves) so the deterministic alt-text policy stays true
     * after metadata changes, without ever overwriting an alt text the
     * owner explicitly edited (WebsiteAsset.alt_text_is_custom).
     */
    /**
     * @param  ?string  $customAltText  a non-blank value here is the owner's own explicit alt text and is never
     *                                  regenerated again; blank (the ordinary case) leaves an already-custom alt
     *                                  text untouched, or regenerates a still-autogenerated one from the new title/category
     */
    public function setTitleAndCategory(Website $website, WebsiteAsset $asset, WebsiteAssetPurpose $purpose, ?string $title, ?string $categoryTag, ?string $customAltText = null): void
    {
        $this->assertOwnedAndPurpose($website, $asset, $purpose);

        $updates = ['title' => $title, 'category_tag' => $categoryTag];

        if ($customAltText !== null && trim($customAltText) !== '') {
            $updates['alt_text'] = trim(mb_substr($customAltText, 0, 160));
            $updates['alt_text_is_custom'] = true;
        } elseif (! $asset->alt_text_is_custom) {
            $preview = (clone $asset)->forceFill($updates);
            $updates['alt_text'] = $this->altText->suggest($website->business, $preview);
        }

        $asset->forceFill($updates)->save();
    }

    private function assertOwnedAndPurpose(Website $website, WebsiteAsset $asset, WebsiteAssetPurpose $purpose): void
    {
        abort_unless($asset->website_id === $website->id && $asset->purpose === $purpose, 404);
    }
}
