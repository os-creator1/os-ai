<?php

namespace App\DTO\BusinessEmail;

/**
 * What a provider adapter needs to put one plain-text email on the wire.
 * Application identity (Business/Contact/Location) is deliberately absent:
 * adapters never see tenancy, only an address, a subject and a body.
 */
final class BusinessEmailOutbound
{
    public function __construct(
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly string $toEmail,
        public readonly string $subject,
        public readonly string $bodyText,
    ) {
    }
}
