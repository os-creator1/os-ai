<?php

namespace App\Library\Marketing;

use App\Library\Marketing\Exceptions\InvalidMarketingAssetException;
use App\Library\Support\Exceptions\InvalidImageSignatureException;
use App\Library\Support\ImageSignatureDetector;
use Illuminate\Http\UploadedFile;

/**
 * Stores a testimonial's poster image under a content-hashed filename,
 * mirroring BrandingUploadService's write-then-swap-then-delete shape but
 * keyed to a MarketingTestimonial row rather than an .env-backed platform
 * identity field.
 *
 * Review correction: this used to detect the image signature through
 * ValidBrandingImageRule::detectExtension(), which independently enforces
 * branding's own 2MB limit — silently rejecting a valid 2-4MB marketing
 * poster at the actual storage step even after validation allowed it. Uses
 * the shared, size-agnostic App\Library\Support\ImageSignatureDetector
 * directly instead; size policy for a marketing poster belongs to
 * App\Rules\ValidMarketingImageRule alone, enforced before this is called.
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
            $extension = ImageSignatureDetector::detectExtension($contents);
        } catch (InvalidImageSignatureException $e) {
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
