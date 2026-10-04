<?php

namespace App\Library\GoogleAds;

/**
 * Contract §2 — a Google Ads customer id is exactly 10 digits. The
 * `login-customer-id` header and every request path take the digits only
 * (no hyphens). This is the single place that normalises one, so a value
 * from a request, a provider response or the database is never trusted
 * into a URL or header unchecked.
 */
final class GoogleAdsCustomerId
{
    /**
     * Strips the display hyphens/whitespace Google Ads shows
     * ("123-456-7890") and returns the 10 digits, or null if the value is
     * not exactly a customer id.
     */
    public static function normalize(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $digits = str_replace(['-', ' '], '', trim($value));

        return preg_match('/\A\d{10}\z/', $digits) === 1 ? $digits : null;
    }

    /** `customers/1234567890` (listAccessibleCustomers) → `1234567890`. */
    public static function fromResourceName(mixed $resourceName): ?string
    {
        if (! is_string($resourceName) || ! str_starts_with($resourceName, 'customers/')) {
            return null;
        }

        return self::normalize(substr($resourceName, strlen('customers/')));
    }
}
