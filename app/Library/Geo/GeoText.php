<?php

namespace App\Library\Geo;

final class GeoText
{
    private function __construct()
    {
    }

    /** Case-, accent- and punctuation-insensitive form used to compare place names ("Zürich" = "zurich", "St. Louis" = "st louis"). */
    public static function fold(string $value): string
    {
        static $transliterator = null;

        if ($transliterator === null && class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()') ?: false;
        }

        $value = $transliterator ? (string) $transliterator->transliterate($value) : mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
