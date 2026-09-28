<?php

namespace App\Library\Marketing;

use App\Library\Branding\Exceptions\InvalidBrandingAssetException;
use App\Library\Marketing\Exceptions\InvalidMarketingAssetException;
use App\Rules\ValidBrandingImageRule;
use Illuminate\Http\UploadedFile;

/**
 * Stores a testimonial's poster image under a content-hashed filename,
 * mirroring BrandingUploadService's write-then-swap-then-delete shape but
 * keyed to a MarketingTestimonial row rather than an .env-backed platform
 * identity field.
 */
class MarketingTestimonialAssetService
{
    public function store(UploadedFile $file): string
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false || $contents === '') {
            throw new InvalidMarketingAssetException('The uploaded file could not be read.');
        }

        try {
            $extension = ValidBrandingImageRule::detectExtension($contents);
        } catch (InvalidBrandingAssetException $e) {
            throw new InvalidMarketingAssetException($e->getMessage());
        }

        $safeFilename = hash('sha256', $contents) . '.' . $extension;
        $directory = public_path('images/marketing/testimonials');
        $destination = $directory . DIRECTORY_SEPARATOR . $safeFilename;
        $relativePath = "images/marketing/testimonials/{$safeFilename}";

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new InvalidMarketingAssetException('The marketing asset directory could not be created.');
        }

        if (file_put_contents($destination, $contents) === false) {
            throw new InvalidMarketingAssetException('The marketing asset could not be stored.');
        }

        $writtenContents = file_get_contents($destination);
        if ($writtenContents === false || hash('sha256', $writtenContents) !== hash('sha256', $contents)) {
            @unlink($destination);
            throw new InvalidMarketingAssetException('The marketing asset failed integrity verification after storage.');
        }

        return $relativePath;
    }

    public function deleteIfOwned(?string $relativePath): void
    {
        if (blank($relativePath) || ! str_starts_with($relativePath, 'images/marketing/testimonials/')) {
            return;
        }

        $fullPath = public_path($relativePath);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}
