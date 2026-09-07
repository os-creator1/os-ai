<?php

namespace App\Rules;

use App\Exceptions\Website\InvalidWebsiteAssetException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Website Generation + Hosting Slice A contract §13. Directly mirrors
 * App\Rules\ValidBrandingImageRule's magic-byte content validation
 * pattern (deliberately in addition to, not instead of, the
 * FormRequest's own extension/MIME rules) with Website's own, separate
 * 8MB ceiling and bounded 4000x4000 dimension cap — Website images are
 * user-content at larger scale than branding assets. SVG is rejected
 * unconditionally, same as branding — no sanitization mechanism is
 * trusted for owner-uploaded SVG in this contract either.
 */
class ValidWebsiteImageRule implements ValidationRule
{
    public const MAX_SIZE_BYTES = 8 * 1024 * 1024; // 8MB, contract §13.

    private const MAX_DIMENSION = 4000;

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

        try {
            self::detectExtension($contents);

            $dimensions = @getimagesizefromstring($contents);

            if ($dimensions === false) {
                throw new InvalidWebsiteAssetException('The uploaded file is not a readable image.');
            }

            [$width, $height] = $dimensions;

            if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
                throw new InvalidWebsiteAssetException(
                    'The uploaded image exceeds the maximum allowed dimensions of ' . self::MAX_DIMENSION . 'x' . self::MAX_DIMENSION . 'px.'
                );
            }
        } catch (InvalidWebsiteAssetException $e) {
            $fail($e->getMessage());
        }
    }

    /**
     * Detects the real image type from its first bytes, regardless of
     * claimed extension or MIME type. SVG is never a valid result here
     * — it has no raster magic signature this method recognizes.
     *
     * @throws InvalidWebsiteAssetException
     */
    public static function detectExtension(string $binaryContents): string
    {
        $length = strlen($binaryContents);

        if ($length < 12) {
            throw new InvalidWebsiteAssetException('The uploaded file is too small to be a real image file.');
        }

        if ($length > self::MAX_SIZE_BYTES) {
            throw new InvalidWebsiteAssetException('The uploaded file exceeds the 8MB Website asset size limit.');
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

        throw new InvalidWebsiteAssetException(
            'The uploaded file does not have a valid PNG, JPEG, or WEBP signature. ' .
            'Renaming a non-image file to an image extension does not pass validation. SVG uploads are not accepted.'
        );
    }
}
