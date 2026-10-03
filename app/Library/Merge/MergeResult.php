<?php

namespace App\Library\Merge;

/**
 * The outcome of rendering one piece of text.
 *
 *   text      the rendered text; every merge token has been replaced
 *   unknown   tokens that name nothing this Business can resolve (typos, tokens
 *             of another Business, retired vocabulary) — rendered as blank
 *   missing   known tokens that had no value for this Contact/context — blank
 *
 * `unknown` is what an editor or preview flags as "Unknown merge field"; the
 * runtime never throws and never leaks the raw `{{...}}` to a customer.
 */
final readonly class MergeResult
{
    /**
     * @param list<string> $unknown
     * @param list<string> $missing
     */
    public function __construct(
        public string $text,
        public array $unknown = [],
        public array $missing = [],
    ) {
    }
}
