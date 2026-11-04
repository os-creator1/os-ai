<?php

namespace App\Library\BusinessEmail\Contracts;

use App\DTO\BusinessEmail\BusinessEmailInboundBatch;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;

/**
 * Implemented only by adapters whose capabilities().inboundSync is true.
 * Same rules as BusinessEmailProvider: throw only BusinessEmailProviderException,
 * never log a token or body, never run inside a transaction.
 */
interface InboundBusinessEmailProvider
{
    /** The mailbox read permission the stored grant must include. */
    public function inboundScope(): string;

    /**
     * Reads new inbox messages. A null $cursor means the first sync (bounded
     * to recent mail); an expired cursor is handled the same way internally.
     * The returned cursor must only be persisted after the messages are stored.
     *
     * @throws BusinessEmailProviderException
     */
    public function fetchInbound(string $accessToken, ?string $cursor, int $limit): BusinessEmailInboundBatch;
}
