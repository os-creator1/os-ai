<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\PhoneNumberType;

/**
 * Text messaging setup/number/compliance hub — STATE 1's "Get a phone
 * number" form, translated to a provider-neutral search. Deliberately
 * only the customer concepts the target names: country, an optional
 * preferred area code/region, and local-vs-toll-free. No provider search
 * parameter (rate center, number class, feature bitmask, ...) is ever
 * accepted here.
 */
final readonly class NumberSearchCriteria
{
    public function __construct(
        public string $countryCode,
        public PhoneNumberType $numberType,
        public ?string $areaCode = null,
    ) {
    }
}
