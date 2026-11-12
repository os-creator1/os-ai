<?php

namespace App\Library\Voice\Inbound;

use App\Library\Messaging\E164Normalizer;
use App\Models\BusinessMessagingNumber;

/**
 * Toll-fraud and loop guard for a forwarding destination. A destination must be a valid E.164 number in an allowed
 * country, must not be toll-free, premium-rate or one of the Caribbean/Pacific area codes abused for fraud, and must not
 * be any number this platform hosts (forwarding to ourselves loops). Returns the canonical number, or a reason code.
 */
final class ForwardDestinationPolicy
{
    /** @return array{0: string|null, 1: string|null} [canonical E.164, null] or [null, reason] */
    public function check(string $raw): array
    {
        $raw = trim($raw);
        if (! str_starts_with($raw, '+')) {
            // A US/Canada number typed the way people type it: (650) 555-0142, 650-555-0142 or 1 650 555 0142.
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            if (strlen($digits) === 10) {
                $raw = '+1' . $digits;
            } elseif (strlen($digits) === 11 && $digits[0] === '1') {
                $raw = '+' . $digits;
            }
        }
        $e164 = E164Normalizer::normalize($raw);
        if ($e164 === null) {
            return [null, 'invalid_number'];
        }

        $digits = ltrim($e164, '+');
        $allowed = array_map('strval', (array) config('messaging.voice.inbound.forward_allowed_country_codes'));
        $countryOk = false;
        foreach ($allowed as $code) {
            if ($code !== '' && str_starts_with($digits, $code)) {
                $countryOk = true;
                break;
            }
        }
        if (! $countryOk) {
            return [null, 'country_not_allowed'];
        }

        foreach ((array) config('messaging.voice.inbound.forward_denied_prefixes') as $prefix) {
            if (str_starts_with($digits, (string) $prefix)) {
                return [null, 'destination_restricted'];
            }
        }

        // NANP service/emergency codes (N11) and anything that is not a normal 10-digit subscriber number.
        if (str_starts_with($digits, '1') && (strlen($digits) !== 11 || preg_match('/^1[2-9]\d\d[2-9]\d{6}$/', $digits) !== 1 || substr($digits, 2, 2) === '11' || substr($digits, 4, 2) === '11')) {
            return [null, 'invalid_number'];
        }

        if (BusinessMessagingNumber::query()->where('phone_number', $e164)->exists()) {
            return [null, 'platform_number'];
        }

        return [$e164, null];
    }

    public static function message(string $reason): string
    {
        return match ($reason) {
            'country_not_allowed' => 'Calls can only be forwarded to numbers in supported countries.',
            'destination_restricted' => 'That number cannot be used for forwarding (toll-free, premium-rate and some international numbers are blocked).',
            'platform_number' => 'You cannot forward calls to a MotionGrove number.',
            default => 'Enter a valid phone number, including the area code.',
        };
    }
}
