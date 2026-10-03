<?php

namespace App\Rules;

use App\Library\Branding\Exceptions\InvalidBrandingAssetException;
use App\Library\Support\Exceptions\InvalidImageSignatureException;
use App\Library\Support\ImageSignatureDetector;
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
     * Branding's own 2MB policy, then the shared, size-agnostic magic-byte
     * signature check (App\Library\Support\ImageSignatureDetector) — kept
     * as a public static method here since BrandingUploadService and this
     * class's own validate() both already call it by this name.
     *
     * Review correction: this used to bake the 2MB size limit directly
     * into the shared magic-byte check, so any other caller (Marketing's
     * 4MB poster rule included) silently inherited branding's size policy
     * too. Signature detection is now size-policy-agnostic; every caller
     * enforces its own limit.
     *
     * @throws InvalidBrandingAssetException
     */
    public static function detectExtension(string $binaryContents): string
    {
        if (strlen($binaryContents) > self::MAX_SIZE_BYTES) {
            throw new InvalidBrandingAssetException('The uploaded file exceeds the 2MB branding asset size limit.');
        }

        try {
            return ImageSignatureDetector::detectExtension($binaryContents);
        } catch (InvalidImageSignatureException $e) {
            throw new InvalidBrandingAssetException($e->getMessage());
        }
    }
}
