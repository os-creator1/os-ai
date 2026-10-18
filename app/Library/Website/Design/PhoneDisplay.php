<?php

namespace App\Library\Website\Design;

/**
 * Website V1 acceptance — how a phone number READS on a customer's website.
 * A number is stored however the owner typed it ("3125550147"); a public page
 * shows a North American number the way people write it, "(312) 555-0147".
 * Anything that is not clearly a 10-digit (or 1 + 10-digit) number is shown
 * exactly as stored — never guessed at — and the tap-to-call `tel:` address
 * keeps using the digits only.
 */
final class PhoneDisplay
{
    public static function format(?string $phone): string
    {
        $phone = trim((string) $phone);
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
        }

        if (strlen($digits) === 11 && $digits[0] === '1') {
            return sprintf('+1 (%s) %s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7));
        }

        return $phone;
    }

    /** The dialable form for a `tel:` link. */
    public static function dial(?string $phone): string
    {
        return (string) preg_replace('/[^+0-9]/', '', (string) $phone);
    }
}
