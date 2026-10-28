<?php

namespace App\Library\Messaging\DTO;

/**
 * The outcome of one brand status lookup. An HTTP 200 (or the brand's
 * generic `status: OK`) only proves the lookup worked; `state` reflects the
 * carrier's identity verification. `unchanged` means the lookup itself was
 * inconclusive (rate limit, timeout, server error, unreadable body): it is
 * never a final failure and the saved state must be left as it is.
 */
final readonly class BrandStatusResult
{
    public const PROCESSING = 'processing';

    public const VERIFIED = 'verified';

    public const NEEDS_ATTENTION = 'needs_attention';

    public const UNCHANGED = 'unchanged';

    public function __construct(
        public string $state,
        public ?string $tcrBrandId = null,
        /** Short, sanitized, customer-safe explanation; never a raw carrier payload. */
        public ?string $detail = null,
    ) {
    }
}
