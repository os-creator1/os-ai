<?php

namespace App\Library\Business;

use App\Models\Business;
use App\Models\BusinessService;
use App\Models\BusinessServiceImage;
use Illuminate\Support\Facades\DB;

/**
 * The one canonical writer of a booth service's photographs. Like
 * BusinessBackdropManager / CatalogItemManager it re-verifies, under lock,
 * that the service really belongs to the given Business, and it attaches
 * every image to the service by its OWN id — never by matching a name — so a
 * renamed service keeps its pictures.
 *
 * The stored files live in the Business image store
 * (`images/business/{uid}/…`); this class owns rows, not files.
 */
final class BusinessServiceImageManager
{
    public const MAX_IMAGES = 6;

    public function __construct(private readonly BusinessImageStore $files)
    {
    }

    /**
     * Makes the service's photos exactly `$images`, in order — the first is the
     * cover. A path already attached keeps its row (and uid); a path no longer
     * listed is detached and its file removed when nothing else uses it.
     *
     * @param  array<int, array{disk: string, path: string, mime_type: string, size: int, width: ?int, height: ?int, alt_text: ?string}>  $images
     */
    public function sync(Business $business, BusinessService $service, array $images): void
    {
        $images = array_slice(array_values($images), 0, self::MAX_IMAGES);
        $removedPaths = [];

        DB::transaction(function () use ($business, $service, $images, &$removedPaths) {
            $locked = BusinessService::whereKey($service->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->business_id !== (int) $business->id) {
                throw new \DomainException('That service does not belong to this Business.');
            }

            $existing = BusinessServiceImage::where('business_service_id', $locked->id)->get()->keyBy('path');
            $wanted = array_column($images, 'path');

            foreach ($existing as $path => $row) {
                if (! in_array($path, $wanted, true)) {
                    $removedPaths[] = $path;
                    $row->delete();
                }
            }

            foreach ($images as $position => $image) {
                $attributes = [
                    'disk' => $image['disk'],
                    'mime_type' => $image['mime_type'],
                    'size' => $image['size'],
                    'width' => $image['width'] ?? null,
                    'height' => $image['height'] ?? null,
                    'alt_text' => $image['alt_text'] ?? null,
                    'position' => $position,
                    'is_cover' => $position === 0,
                ];

                $row = $existing->get($image['path']);

                if ($row !== null) {
                    // A customised alt text already on the row survives a re-apply that carries none.
                    if (($attributes['alt_text'] ?? null) === null) {
                        unset($attributes['alt_text']);
                    }
                    $row->fill($attributes)->save();
                } else {
                    BusinessServiceImage::create($attributes + ['business_service_id' => $locked->id, 'path' => $image['path']]);
                }
            }
        });

        foreach ($removedPaths as $path) {
            $this->files->deleteIfUnreferenced($business, $path);
        }
    }

    /**
     * Whether the service has at least one real photograph.
     */
    public function hasPhoto(BusinessService $service): bool
    {
        return BusinessServiceImage::where('business_service_id', $service->id)->exists();
    }
}
