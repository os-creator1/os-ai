<?php

namespace App\Library\Support;

use App\Library\Support\Exceptions\InvalidImageSignatureException;

/**
 * Pure magic-byte image format detection — no size policy of its own.
 * Extracted from App\Rules\ValidBrandingImageRule, which previously baked
 * its own 2MB branding limit into this same check, so any other caller
 * (Marketing's 4MB poster rule included) silently inherited a policy that
 * was never theirs. Every caller applies its own size limit before or
 * after calling this; this class only ever answers "is this really a PNG,
 * JPEG or WEBP", regardless of how large it is.
 */
final class ImageSignatureDetector
{
    private const MIN_HEADER_BYTES = 12;

    /**
     * Detects the real image type from its first bytes, regardless of
     * claimed extension or MIME type. Returns the safe lowercase extension
     * to use for storage. SVG is never a valid result here — it has no
     * raster magic signature this method recognizes, so any SVG payload
     * (or any non-raster file) falls through to the exception below.
     *
     * @throws InvalidImageSignatureException
     */
    public static function detectExtension(string $binaryContents): string
    {
        if (strlen($binaryContents) < self::MIN_HEADER_BYTES) {
            throw new InvalidImageSignatureException('The uploaded file is too small to be a real image file.');
        }

        $header = substr($binaryContents, 0, 12);

        if (substr($header, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'png';
        }

        if (substr($header, 0, 3) === "\xFF\xD8\xFF") {
            return 'jpg';
        }

        if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
            return 'webp';
        }

        throw new InvalidImageSignatureException(
            'The uploaded file does not have a valid PNG, JPEG, or WEBP signature. ' .
            'Renaming a non-image file to an image extension does not pass validation. SVG uploads are not accepted.'
        );
    }
}
