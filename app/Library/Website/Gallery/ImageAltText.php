<?php

namespace App\Library\Website\Gallery;

/**
 * Safe default alt text for an image the owner has not described. Built
 * only from facts already on hand — the item's own name, its category
 * label and the Business name — never from invented detail and never from
 * SEO phrases: "White floral backdrop", not "best cheap photo booth
 * rental wedding backdrop". Every image therefore always has a sensible
 * alt the owner may edit, and an auto value never counts as "custom".
 */
final class ImageAltText
{
    public const MAX_LENGTH = 160;

    private function __construct()
    {
    }

    public static function suggest(?string $name, ?string $categoryLabel = null, ?string $businessName = null): string
    {
        $name = self::clean($name);
        $category = self::clean($categoryLabel);
        $business = self::clean($businessName);

        if ($name !== '') {
            // Don't repeat the category when the name already says it
            // ("Floral backdrop" + "Backdrop").
            $text = ($category !== '' && mb_stripos($name, $category) === false)
                ? $name . ' — ' . mb_strtolower($category)
                : $name;
        } elseif ($category !== '') {
            $text = $business !== '' ? $category . ' — ' . $business : $category;
        } elseif ($business !== '') {
            $text = $business . ' photo';
        } else {
            $text = 'Photo';
        }

        return mb_substr($text, 0, self::MAX_LENGTH);
    }

    private static function clean(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(['_', '-'], ' ', (string) $value)));
    }
}
