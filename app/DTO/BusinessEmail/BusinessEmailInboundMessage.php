<?php

namespace App\DTO\BusinessEmail;

/** One normalized message read from a connected mailbox. Provider shapes stop at the adapter. */
final class BusinessEmailInboundMessage
{
    /**
     * @param list<string> $labels provider labels, e.g. INBOX / SENT
     */
    public function __construct(
        public readonly string $providerMessageId,
        public readonly ?string $providerThreadId,
        public readonly ?string $internetMessageId,
        public readonly ?string $inReplyTo,
        public readonly ?string $references,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly string $toEmail,
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly ?\DateTimeInterface $receivedAt,
        public readonly array $labels = [],
    ) {
    }
}
