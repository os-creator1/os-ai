<?php

namespace App\Library\Website\Gallery;

use App\Exceptions\Website\InvalidWebsiteAssetException;
use App\Library\Website\WebsiteAssetUploadService;
use App\Models\Website;
use App\Models\WebsiteAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Website Builder redesign — the wizard's "Show your work" gallery step.
 * v1 scope (explicitly agreed): multi-file upload with per-file progress
 * (a plain client-side concern — each file is one ordinary
 * WebsiteAssetUploadService::store() call) and up/down reordering plus a
 * single cover flag; a queued responsive-image-variant pipeline is
 * explicitly deferred.
 *
 * Mirrors CatalogItemManager::reorder()'s lock-then-rewrite-position
 * shape, scoped to one Website's assets instead of one Business's
 * catalog items.
 */
final class WebsiteGalleryManager
{
    public function __construct(private readonly WebsiteAssetUploadService $uploads)
    {
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, WebsiteAsset>
     * @throws InvalidWebsiteAssetException on the first invalid file — callers that want
     *         "store what succeeded, report what failed" should call store() per-file themselves instead
     */
    public function uploadMany(Website $website, array $files, ?string $categoryTag = null): array
    {
        return DB::transaction(function () use ($website, $files, $categoryTag) {
            $nextPosition = (int) ($website->assets()->max('sort_order') ?? -1) + 1;
            $created = [];

            foreach ($files as $file) {
                $asset = $this->uploads->store($website, $file);
                $asset->forceFill(['sort_order' => $nextPosition, 'category_tag' => $categoryTag])->save();
                $created[] = $asset;
                $nextPosition++;
            }

            return $created;
        });
    }

    /**
     * @param  list<string>  $orderedUids  every one of this Website's asset uids, exactly once
     */
    public function reorder(Website $website, array $orderedUids): void
    {
        DB::transaction(function () use ($website, $orderedUids) {
            $assets = WebsiteAsset::where('website_id', $website->id)->lockForUpdate()->get()->keyBy('uid');

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
     * own single-cover clear-then-set) rather than a DB constraint.
     */
    public function setCover(Website $website, WebsiteAsset $asset): void
    {
        abort_unless($asset->website_id === $website->id, 404);

        DB::transaction(function () use ($website, $asset) {
            WebsiteAsset::where('website_id', $website->id)->update(['is_cover' => false]);
            $asset->forceFill(['is_cover' => true])->save();
        });
    }

    public function setTitleAndCategory(Website $website, WebsiteAsset $asset, ?string $title, ?string $categoryTag): void
    {
        abort_unless($asset->website_id === $website->id, 404);

        $asset->forceFill(['title' => $title, 'category_tag' => $categoryTag])->save();
    }
}
