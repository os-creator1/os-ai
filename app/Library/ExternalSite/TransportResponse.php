<?php

namespace App\Library\ExternalSite;

/**
 * What came back. `error` is a short reason code (timeout, connect_failed,
 * tls_failed, too_large, content_type_not_allowed, ip_mismatch, transport_error)
 * or null; the body is only the bytes read within the bound.
 */
final class TransportResponse
{
    /** @param array<string, string> $headers lowercase names, last value wins */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $error = null,
        public readonly ?string $primaryIp = null,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', (string) $this->header('content-type'))[0]));
    }
}
