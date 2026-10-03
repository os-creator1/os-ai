<?php

namespace App\Rules;

use App\Library\Support\Exceptions\InvalidImageSignatureException;
use App\Library\Support\ImageSignatureDetector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Magic-byte content validation for marketing-content image uploads
 * (testimonial poster images). A small sibling of ValidBrandingImageRule
 * rather than an extension of it — this is a distinct asset purpose
 * (per-testimonial poster frames, not platform brand identity) with its
 * own, wider dimension bounds and its own 4MB size limit, so branding's
 * field map and 2MB policy are never touched.
 *
 * Review correction: this used to call ValidBrandingImageRule::
 * detectExtension() for its signature check, which independently enforced
 * branding's 2MB limit — silently rejecting a valid 2-4MB marketing poster
 * before this rule's own (already-passed) 4MB check ever mattered. It now
 * calls the shared, size-agnostic App\Library\Support\ImageSignatureDetector
 * directly — the one piece of pure, field-independent magic-byte logic —
 * rather than a branding-specific wrapper around it.
 */
class ValidMarketingImageRule implements ValidationRule
{
    private const MAX_SIZE_BYTES = 4 * 1024 * 1024; // 4MB — a poster frame, not an icon.
    private const MAX_WIDTH = 2400;
    private const MAX_HEIGHT = 2400;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be an uploaded file.');

            return;
        }

        $contents = file_get_contents($value->getRealPath());

        if ($contents === false || $contents === '') {
            $fail('The :attribute could not be read.');

            return;
        }

        if (strlen($contents) > self::MAX_SIZE_BYTES) {
            $fail('The uploaded image exceeds the 4MB size limit.');

            return;
        }

        try {
            ImageSignatureDetector::detectExtension($contents);

            $dimensions = @getimagesizefromstring($contents);

            if ($dimensions === false) {
                throw new InvalidImageSignatureException('The uploaded file is not a readable image.');
            }

            [$width, $height] = $dimensions;

            if ($width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
                throw new InvalidImageSignatureException(
                    'The uploaded image exceeds the maximum allowed dimensions of ' . self::MAX_WIDTH . 'x' . self::MAX_HEIGHT . 'px.'
                );
            }
        } catch (InvalidImageSignatureException $e) {
            $fail($e->getMessage());
        }
    }
}
