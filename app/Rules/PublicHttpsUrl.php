<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Outbound customer-supplied URLs (the account webhook) must be https and resolve only to public
 * addresses, so a tenant cannot aim the platform's server at loopback / link-local / private hosts
 * (cloud metadata, internal admin panels). Use isSafe() again at call time: DNS can change.
 */
class PublicHttpsUrl implements ValidationRule
{
    public static function isSafe(?string $url): bool
    {
        if ( ! is_string($url) || $url === '' || strlen($url) > 2048) {
            return false;
        }

        $parts = parse_url($url);

        if ( ! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = trim($parts['host'], '[]');

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if ( ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ( ! self::isSafe(is_string($value) ? $value : null)) {
            $fail('The :attribute must be a public https URL.');
        }
    }
}
