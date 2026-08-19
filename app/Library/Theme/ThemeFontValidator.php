<?php

namespace App\Library\Theme;

use App\Library\Theme\Exceptions\InvalidThemeFontException;

/**
 * Design System M2 Slice 1 contract §6.9/§9 item 15. Magic-byte content
 * validation — genuinely new capability, nothing in this codebase
 * previously validated any upload's actual binary content (§3.7). This
 * is deliberately separate from, and in addition to, the FormRequest's
 * own extension/MIME whitelist (neither check alone is sufficient).
 */
class ThemeFontValidator
{
    private const WOFF2_MAGIC = "wOF2";
    private const WOFF_MAGIC = "wOFF";

    private const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5MB, matches the FormRequest's own max:5000 rule.

    /**
     * @throws InvalidThemeFontException
     */
    public function validateContents(string $binaryContents, string $claimedExtension): void
    {
        $length = strlen($binaryContents);

        if ($length < 100) {
            throw new InvalidThemeFontException('The uploaded file is too small to be a real font file.');
        }

        if ($length > self::MAX_SIZE_BYTES) {
            throw new InvalidThemeFontException('The uploaded file exceeds the 5MB font size limit.');
        }

        $magic = substr($binaryContents, 0, 4);

        $isWoff2 = $magic === self::WOFF2_MAGIC;
        $isWoff = $magic === self::WOFF_MAGIC;

        if (! $isWoff2 && ! $isWoff) {
            throw new InvalidThemeFontException(
                'The uploaded file does not have a valid WOFF2 or WOFF signature. ' .
                'Renaming a non-font file to a font extension does not pass validation.'
            );
        }

        $extension = strtolower($claimedExtension);
        if ($isWoff2 && $extension !== 'woff2') {
            throw new InvalidThemeFontException('The file content is WOFF2 but the extension is not .woff2.');
        }
        if ($isWoff && $extension !== 'woff') {
            throw new InvalidThemeFontException('The file content is WOFF but the extension is not .woff.');
        }
    }

    public function generateSafeFilename(string $binaryContents, string $extension): string
    {
        return hash('sha256', $binaryContents) . '.' . strtolower($extension);
    }
}
