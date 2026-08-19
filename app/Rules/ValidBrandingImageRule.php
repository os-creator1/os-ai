<?php

namespace App\Rules;

use App\Library\Branding\Exceptions\InvalidBrandingAssetException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Design System M2 Platform Branding contract §6.3/§11 item 6. Magic-byte
 * content validation for every branding upload field — mirrors
 * App\Rules\ValidFontFileRule / App\Library\Theme\ThemeFontValidator's
 * pattern precisely: deliberately in addition to, not instead of, the
 * FormRequest's own extension/MIME whitelist rules. SVG is rejected
 * unconditionally for every field — no sanitization mechanism is trusted
 * for owner-uploaded SVG in this contract.
 */
class ValidBrandingImageRule implements ValidationRule
{
    private const MAX_SIZE_BYTES = 2 * 1024 * 1024; // 2MB, every branding field.

    /**
     * Per-field {max width, max height} bounds, §6.3. Chosen to
     * comfortably exceed the existing AppConfig::uploadFile() render-time
     * resize (150x26 logo, 32x32 favicon) without permitting an
     * unreasonably large upload.
     */
    private const DIMENSION_BOUNDS = [
        'logo' => [800, 200],
        'logo_compact' => [200, 200],
        'logo_dark' => [800, 200],
        'favicon' => [512, 512],
        'auth_illustration' => [1600, 1600],
        'installer_illustration' => [1600, 1600],
    ];

    public function __construct(private readonly string $field)
    {
    }

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
            $extension = self::detectExtension($contents);

            [$maxWidth, $maxHeight] = self::DIMENSION_BOUNDS[$this->field]
                ?? throw new InvalidBrandingAssetException('Unknown branding field.');

            $dimensions = @getimagesizefromstring($contents);

            if ($dimensions === false) {
                throw new InvalidBrandingAssetException('The uploaded file is not a readable image.');
            }

            [$width, $height] = $dimensions;

            if ($width > $maxWidth || $height > $maxHeight) {
                throw new InvalidBrandingAssetException(
                    "The uploaded image exceeds the maximum allowed dimensions of {$maxWidth}x{$maxHeight}px."
                );
            }
        } catch (InvalidBrandingAssetException $e) {
            $fail($e->getMessage());
        }
    }

    /**
     * Detects the real image type from its first bytes, regardless of
     * claimed extension or MIME type. Returns the safe lowercase
     * extension to use for storage. SVG is never a valid result here —
     * it has no raster magic signature this method recognizes, so any
     * SVG payload (or any non-raster file) falls through to the
     * exception below.
     *
     * @throws InvalidBrandingAssetException
     */
    public static function detectExtension(string $binaryContents): string
    {
        $length = strlen($binaryContents);

        if ($length < 12) {
            throw new InvalidBrandingAssetException('The uploaded file is too small to be a real image file.');
        }

        if ($length > self::MAX_SIZE_BYTES) {
            throw new InvalidBrandingAssetException('The uploaded file exceeds the 2MB branding asset size limit.');
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

        throw new InvalidBrandingAssetException(
            'The uploaded file does not have a valid PNG, JPEG, or WEBP signature. ' .
            'Renaming a non-image file to an image extension does not pass validation. SVG uploads are not accepted.'
        );
    }
}
