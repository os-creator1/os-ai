<?php

namespace App\Library\Calendar\Zoom;

/** The only thing allowed to reach a customer is a Zoom participant join link. Anything else is treated as missing. */
final class ZoomJoinUrl
{
    public static function valid(?string $url): bool
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $zoomHost = $host === 'zoom.us' || str_ends_with($host, '.zoom.us') || $host === 'zoom.com' || str_ends_with($host, '.zoom.com');

        // Participant links are /j/<id>; the host start link is /s/<id>?zak=… and must never pass.
        return $zoomHost && preg_match('#^/j/\d{9,12}$#', (string) ($parts['path'] ?? '')) === 1;
    }
}
