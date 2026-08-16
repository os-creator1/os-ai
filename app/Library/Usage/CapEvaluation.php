<?php

namespace App\Library\Usage;

/**
 * RFC-005 §15 — the outcome of evaluating a Business's monthly spend cap
 * or a per-feature limit against a prospective amount. remainingHeadroomMicro
 * is null when no cap/limit is configured (unbounded).
 */
final readonly class CapEvaluation
{
    public function __construct(
        public bool $allowed,
        public ?string $denialReason,
        public ?string $remainingHeadroomMicro,
    ) {
    }
}
