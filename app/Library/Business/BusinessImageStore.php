<?php

namespace App\Library\Business;

use App\Exceptions\Website\InvalidWebsiteAssetException;
use App\Models\Business;
use App\Models\BusinessBackdropImage;
use App\Models\CatalogItemImage;
use App\Rules\ValidWebsiteImageRule;
use Illuminate\Http\UploadedFile;

/**
 * The file store for images a Business OWNS (backdrop and package
 * pictures). Unlike a Website asset it is not tied to one Website, so a
 * rebuilt or deleted website never takes the owner's pictures with it —
 * the reason BusinessBackdropImage / CatalogItemImage exist at all.
 *
 * It reuses the exact validation the Website upload pipeline already
 * trusts (magic-byte type sniffing, real image decode, 8MB ceiling, 4000px
 * dimension cap, SVG refused) and the same content-hashed, never
 * client-named file convention — only the directory differs
 * (`images/business/{business_uid}/`). It owns files, not rows: callers
 * attach the returned attributes through BusinessBackdropManager /
 * CatalogItemManager, the canonical writers of those rows.
 */
final class BusinessImageStore
{
    private const MAX_DIMENSION = 4000;

    /**
     * @return array{disk: string, path: string, mime_type: string, size: int, width: int, height: int}
     *
     * @throws InvalidWebsiteAssetException
     */
    public function store(Business $business, UploadedFile $file): array
    {
        $contents = $file->getRealPath() !== false ? file_get_contents($file->getRealPath()) : false;

        if ($contents === false || $contents === '') {
            throw new InvalidWebsiteAssetException('The uploaded file could not be read.');
        }

        $extension = ValidWebsiteImageRule::detectExtension($contents);
        $dimensions = @getimagesizefromstring($contents);

        if ($dimensions === false) {
            throw new InvalidWebsiteAssetException('The uploaded file is not a readable image.');
        }

        if ($dimensions[0] > self::MAX_DIMENSION || $dimensions[1] > self::MAX_DIMENSION) {
            throw new InvalidWebsiteAssetException('The uploaded image exceeds the maximum allowed dimensions of ' . self::MAX_DIMENSION . 'x' . self::MAX_DIMENSION . 'px.');
        }

        $hash = hash('sha256', $contents);
        $filename = $hash . '.' . $extension;
        $directory = public_path('images/business/' . $business->uid);
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new InvalidWebsiteAssetException('The image directory could not be created.');
        }

        if (file_put_contents($destination, $contents) === false) {
            throw new InvalidWebsiteAssetException('The image could not be stored.');
        }

        $written = file_get_contents($destination);
        if ($written === false || hash('sha256', $written) !== $hash) {
            @unlink($destination);
            throw new InvalidWebsiteAssetException('The image failed integrity verification after storage.');
        }

        // Responsive derivatives next to the original (see ImageVariants); never fails the upload.
        app(\App\Library\Website\Media\ImageVariants::class)->generate($this->relativePath($business, $filename));

        return [
            'disk' => 'public',
            'path' => $this->relativePath($business, $filename),
            'mime_type' => 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension),
            'size' => strlen($contents),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ];
    }

    /**
     * Re-derives a stored image's facts from the FILE itself. A path that
     * arrives from the browser is never trusted for its metadata, and is
     * only honoured when it is a content-hashed file inside THIS
     * Business's own directory.
     *
     * @return ?array{disk: string, path: string, mime_type: string, size: int, width: int, height: int}
     */
    public function describe(Business $business, string $path): ?array
    {
        $prefix = 'images/business/' . $business->uid . '/';

        if (! str_starts_with($path, $prefix) || preg_match('#^[a-f0-9]{64}\.(png|jpg|webp)$#', substr($path, strlen($prefix))) !== 1) {
            return null;
        }

        $absolute = public_path($path);
        $contents = is_file($absolute) ? file_get_contents($absolute) : false;
        $dimensions = $contents !== false ? @getimagesizefromstring($contents) : false;

        if ($contents === false || $dimensions === false) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return [
            'disk' => 'public',
            'path' => $path,
            'mime_type' => 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension),
            'size' => strlen($contents),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ];
    }

    /**
     * Removes the file only when no canonical row still points at it (a
     * freshly uploaded image the owner replaced or removed before ever
     * saving it, or an old image a replacement superseded).
     */
    public function deleteIfUnreferenced(Business $business, string $path): void
    {
        if ($this->describe($business, $path) === null) {
            return;
        }

        if (BusinessBackdropImage::where('path', $path)->exists() || CatalogItemImage::where('path', $path)->exists()) {
            return;
        }

        // A picture the owner just removed or replaced may still be on a PUBLISHED page (a revision freezes the
        // photo's address, and its responsive variants, into the page). Deleting the file under a live or
        // rollback-able revision serves broken images on an indexed site, so a path any of this Business's
        // Website revisions, draft pages or stored assets still names is kept (an orphan costs disk, a gap costs visitors).
        if ($this->referencedByAWebsite($business, $path)) {
            return;
        }

        @unlink(public_path($path));
        app(\App\Library\Website\Media\ImageVariants::class)->delete($path);
    }

    private function referencedByAWebsite(Business $business, string $path): bool
    {
        $websiteIds = \App\Models\Website::where('business_id', $business->id)->pluck('id');

        if ($websiteIds->isEmpty()) {
            return false;
        }

        if (\App\Models\WebsiteAsset::whereIn('website_id', $websiteIds)->where('path', $path)->exists()) {
            return true;
        }

        // JSON text may hold the address with escaped slashes ("images\/business\/...") or plain ones; LOCATE
        // matches the literal text, so no LIKE escaping can go wrong.
        $needles = [$path, str_replace('/', '\\/', $path)];

        foreach ([['website_revisions', 'snapshot'], ['website_pages', 'sections']] as [$table, $column]) {
            foreach ($needles as $needle) {
                if (\Illuminate\Support\Facades\DB::table($table)->whereIn('website_id', $websiteIds)->whereRaw("LOCATE(?, CAST({$column} AS CHAR)) > 0", [$needle])->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
    private function relativePath(Business $business, string $filename): string
    {
        return 'images/business/' . $business->uid . '/' . $filename;
    }
}
