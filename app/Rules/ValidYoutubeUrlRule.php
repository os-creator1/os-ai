<?php

namespace App\Rules;

use App\Library\Marketing\YoutubeUrlParser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Only rejects a value that LOOKS like a YouTube link but does not carry a
 * safely parseable video ID — an arbitrary non-YouTube video URL (Vimeo,
 * a direct file link, etc.) is left to the plain `url` rule and is not this
 * rule's concern.
 */
class ValidYoutubeUrlRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (! YoutubeUrlParser::isYoutubeUrl($value)) {
            return;
        }

        if (YoutubeUrlParser::extractVideoId($value) === null) {
            $fail('The :attribute must be a valid YouTube video link.');
        }
    }
}
