<?php

namespace App\Library\Coo\Insight;

use App\Library\Ai\Enums\AiRefusalReason;
use App\Models\CooInsight;

/**
 * What one CooInsightGenerator attempt ended as. Carries no prompt, no model
 * output and no figure — only enough to log and to test.
 */
final readonly class CooInsightOutcome
{
    public const GENERATED = 'generated';

    public const REFUSED = 'refused';

    public const BUSINESS_UNAVAILABLE = 'business_unavailable';

    public const AI_DISABLED = 'ai_disabled';

    public const NOT_ENTITLED = 'not_entitled';

    public const DORMANT = 'dormant';

    public const CONDITION_NOT_MET = 'condition_not_met';

    public const ALREADY_CACHED = 'already_cached';

    public const ALREADY_PAID = 'already_paid';

    public const PROVIDER_FAILED = 'provider_failed';

    public const OUTPUT_REJECTED = 'output_rejected';

    private function __construct(
        public string $status,
        public ?AiRefusalReason $refusalReason,
        public ?CooInsight $insight,
    ) {
    }

    public static function generated(CooInsight $insight): self
    {
        return new self(self::GENERATED, null, $insight);
    }

    public static function refused(AiRefusalReason $reason): self
    {
        return new self(self::REFUSED, $reason, null);
    }

    public static function skipped(string $status): self
    {
        return new self($status, null, null);
    }
}
