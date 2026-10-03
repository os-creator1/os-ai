<?php

namespace App\Library\Marketing;

use App\Library\Marketing\Exceptions\InvalidMarketingAssetException;
use App\Library\Support\Exceptions\InvalidImageSignatureException;
use App\Library\Support\ImageSignatureDetector;
use Illuminate\Http\UploadedFile;

/**
 * Stores a testimonial's poster image under a content-hashed filename —
 * the destination path IS the content's own sha256, so an identical
 * re-upload always resolves to the same, already-correct file.
 *
 * Review correction: this used to detect the image signature through
 * ValidBrandingImageRule::detectExtension(), which independently enforces
 * branding's own 2MB limit — silently rejecting a valid 2-4MB marketing
 * poster at the actual storage step even after validation allowed it. Uses
 * the shared, size-agnostic App\Library\Support\ImageSignatureDetector
 * directly instead; size policy for a marketing poster belongs to
 * App\Rules\ValidMarketingImageRule alone, enforced before this is called.
 *
 * Review correction (content-addressed storage safety): a content-hashed
 * destination is immutable once valid, and one or more MarketingTestimonial
 * rows may already reference it. store() used to write straight into that
 * final path with file_put_contents() regardless of whether it already
 * existed — a failed or partial re-upload of the identical image could
 * truncate, or (on integrity-check failure) unlink, a file existing rows
 * still depend on. It now never writes into an existing destination at
 * all: an existing destination that verifies is reused untouched; a new
 * destination is written to a same-directory temp file, fully verified,
 * and only then moved into place with a single rename() — never streamed
 * directly into the final content-addressed name.
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

        $contentHash = hash('sha256', $contents);
        $safeFilename = "{$contentHash}.{$extension}";
        $directory = public_path('images/marketing/testimonials');
        $destination = $directory . DIRECTORY_SEPARATOR . $safeFilename;
        $relativePath = "images/marketing/testimonials/{$safeFilename}";

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new InvalidMarketingAssetException('The marketing asset directory could not be created.');
        }

        // Case A: the content-hashed destination already exists. Its name
        // IS the hash this exact upload also produced, so if it verifies
        // it is already the correct asset — reused as-is, never rewritten,
        // never truncated. If it exists but does NOT verify, that is an
        // anomaly this service did not create; rather than risk destroying
        // a file existing rows may reference, this fails closed and
        // touches nothing.
        if (is_file($destination)) {
            return $this->reuseOrFailClosed($destination, $relativePath, $contentHash);
        }

        // Case B: no destination yet. Write to a temp file in the same
        // directory, verify it completely, then move it into place with
        // one atomic rename — never stream directly into the final name.
        return $this->writeNewFileAtomically($directory, $destination, $relativePath, $contents, $contentHash);
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

    /**
     * @throws InvalidMarketingAssetException
     */
    private function reuseOrFailClosed(string $destination, string $relativePath, string $expectedHash): string
    {
        $existingContents = file_get_contents($destination);

        if ($existingContents !== false && hash('sha256', $existingContents) === $expectedHash) {
            return $relativePath;
        }

        throw new InvalidMarketingAssetException(
            'A stored marketing asset failed integrity verification and could not be safely replaced. No file was modified.'
        );
    }

    /**
     * @throws InvalidMarketingAssetException
     */
    private function writeNewFileAtomically(
        string $directory,
        string $destination,
        string $relativePath,
        string $contents,
        string $expectedHash,
    ): string {
        $tempPath = $directory . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(16));

        if (file_put_contents($tempPath, $contents) === false) {
            @unlink($tempPath);
            throw new InvalidMarketingAssetException('The marketing asset could not be stored.');
        }

        $writtenContents = file_get_contents($tempPath);
        if ($writtenContents === false || hash('sha256', $writtenContents) !== $expectedHash) {
            @unlink($tempPath);
            throw new InvalidMarketingAssetException('The marketing asset failed integrity verification after storage.');
        }

        if (@rename($tempPath, $destination)) {
            return $relativePath;
        }

        // rename() is not a guaranteed overwrite-if-exists on every
        // platform (notably Windows) — it can only fail here because the
        // destination now exists, which means a concurrent identical
        // upload won the race. That is success (the destination the
        // content hash names is already correctly in place), not failure;
        // anything else about the destination fails closed. Either way
        // this attempt's own temp file is cleaned up — never the
        // destination.
        @unlink($tempPath);

        if (is_file($destination)) {
            return $this->reuseOrFailClosed($destination, $relativePath, $expectedHash);
        }

        throw new InvalidMarketingAssetException('The marketing asset could not be finalized on disk.');
    }
}
