<?php

namespace App\Rules;

use App\Library\Theme\Exceptions\InvalidThemeFontException;
use App\Library\Theme\ThemeFontValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Design System M2 Slice 1 contract §6.9/§9 item 29. Wraps
 * ThemeFontValidator's magic-byte check for use as a FormRequest rule —
 * deliberately in addition to, not instead of, the request's own
 * extension/MIME whitelist rules.
 */
class ValidFontFileRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be an uploaded file.');

            return;
        }

        try {
            (new ThemeFontValidator())->validateContents(
                file_get_contents($value->getRealPath()),
                $value->getClientOriginalExtension()
            );
        } catch (InvalidThemeFontException $e) {
            $fail($e->getMessage());
        }
    }
}
