<?php

namespace App\Library\Business;

/**
 * Whether a state / province / region is a standard part of a postal address in a country. Where it is, a
 * service area or storefront must name it (local SEO targets "Orange, CA", not "Orange"); where it is not (for
 * example Singapore, Monaco, most of Western Europe), a valid city plus country is a complete service market and
 * demanding a region would only push customers to invent one.
 *
 * The list is the countries whose mailing addresses carry an administrative region as a normal line; it is a
 * small explicit allow-list, not a claim that no other country has regions.
 */
final class BusinessAddressRules
{
    private const REGION_REQUIRED = ['US', 'CA', 'AU', 'MX', 'BR', 'IN', 'AR', 'CN', 'JP', 'MY', 'NG', 'IT'];

    public static function regionRequired(?string $countryCode): bool
    {
        return in_array(strtoupper(trim((string) $countryCode)), self::REGION_REQUIRED, true);
    }
}
