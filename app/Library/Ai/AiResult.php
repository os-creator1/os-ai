<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiRefusalReason;
use App\Models\AiUsageLedgerEntry;

/**
 * Contract §10.1 — what `AiGateway::complete()` returns. Callers must
 * handle `refused()`; §11.4 lists what each of the three AI-1 callers
 * does on refusal.
 */
final readonly class AiResult
{
    private function __construct(
        public bool $ok,
        public ?string $content,
        public ?AiRefusalReason $refusalReason,
        public ?AiUsageLedgerEntry $ledgerEntry,
    ) {
    }

    public static function success(string $content, AiUsageLedgerEntry $ledgerEntry): self
    {
        return new self(true, $content, null, $ledgerEntry);
    }

    /**
     * The provider call was made (and recorded), but failed or returned
     * nothing usable. Distinct from refused(): the gateway did not stop
     * the call, the call itself did not produce a result.
     */
    public static function providerFailure(AiUsageLedgerEntry $ledgerEntry): self
    {
        return new self(false, null, null, $ledgerEntry);
    }

    public static function refused(AiRefusalReason $reason, ?AiUsageLedgerEntry $ledgerEntry = null): self
    {
        return new self(false, null, $reason, $ledgerEntry);
    }
}
