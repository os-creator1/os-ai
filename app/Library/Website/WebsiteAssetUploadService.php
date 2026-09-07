<?php

namespace App\Library\Website;

use App\Exceptions\Website\InvalidWebsiteAssetException;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Rules\ValidWebsiteImageRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Website Generation + Hosting Slice A contract §13. Directly mirrors
 * App\Library\Branding\BrandingUploadService's security pattern: server-
 * generated, content-hashed filenames (never client-derived), magic-byte
 * MIME validation re-run independently of the FormRequest rule, real
 * image decode, write-then-verify integrity check, and a fully server-
 * built storage path with no user-controlled segment.
 */
final class WebsiteAssetUploadService
{
    /**
     * @throws InvalidWebsiteAssetException
     */
    public function store(Website $website, UploadedFile $file, ?string $altText = null): WebsiteAsset
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false || $contents === '') {
            throw new InvalidWebsiteAssetException('The uploaded file could not be read.');
        }

        // Re-validated here, independent of the FormRequest's own rule —
        // this service never trusts that validation already ran upstream.
        $extension = ValidWebsiteImageRule::detectExtension($contents);
        $dimensions = @getimagesizefromstring($contents);

        if ($dimensions === false) {
            throw new InvalidWebsiteAssetException('The uploaded file is not a readable image.');
        }

        $contentHash = hash('sha256', $contents);
        $safeFilename = $contentHash . '.' . $extension;
        $directory = public_path("images/websites/{$website->uid}");
        $destination = $directory . DIRECTORY_SEPARATOR . $safeFilename;
        $relativePath = "images/websites/{$website->uid}/{$safeFilename}";

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new InvalidWebsiteAssetException('The Website asset directory could not be created.');
        }

        if (file_put_contents($destination, $contents) === false) {
            throw new InvalidWebsiteAssetException('The Website asset could not be stored.');
        }

        // Post-write integrity check — fail closed rather than persist a
        // row pointing at a file that is not confirmed correct.
        $writtenContents = file_get_contents($destination);
        if ($writtenContents === false || hash('sha256', $writtenContents) !== $contentHash) {
            @unlink($destination);
            throw new InvalidWebsiteAssetException('The Website asset failed integrity verification after storage.');
        }

        return $website->assets()->create([
            'disk' => 'public',
            'path' => $relativePath,
            'mime_type' => 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension),
            'size' => strlen($contents),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'alt_text' => $altText,
            'content_hash' => $contentHash,
        ]);
    }

    /**
     * Contract §13.1 — an asset with a non-null first_published_at can
     * never be deleted in Slice A, permanently, regardless of whether it
     * is still part of the current published revision. A never-published
     * asset may be deleted only if no current draft page references it.
     *
     * @throws ValidationException
     */
    public function delete(Website $website, WebsiteAsset $asset): void
    {
        abort_unless($asset->website_id === $website->id, 404);

        if ($asset->first_published_at !== null) {
            throw ValidationException::withMessages([
                'asset' => ['This asset has been published and can never be deleted.'],
            ]);
        }

        if ($this->isReferencedByDraft($website, $asset->uid)) {
            throw ValidationException::withMessages([
                'asset' => ['This asset is referenced by a current draft page and cannot be deleted.'],
            ]);
        }

        $fullPath = public_path($asset->path);

        $asset->delete();

        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    private function isReferencedByDraft(Website $website, string $assetUid): bool
    {
        foreach ($website->pages()->pluck('sections') as $sections) {
            if ($this->sectionsReferenceAsset($sections ?? [], $assetUid)) {
                return true;
            }
        }

        return false;
    }

    private function sectionsReferenceAsset(array $sections, string $assetUid): bool
    {
        $needle = json_encode($assetUid);

        foreach ($sections as $section) {
            if (str_contains(json_encode($section['data'] ?? []), $needle)) {
                return true;
            }
        }

        return false;
    }
}
