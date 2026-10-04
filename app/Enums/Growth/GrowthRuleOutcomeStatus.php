<?php

namespace App\Enums\Growth;

/**
 * What one rule concluded for one Business in one evaluation.
 *
 *   Finding        the problem was detected (one or more findings)
 *   Passing        the rule was evaluable and found nothing wrong
 *   NotApplicable  its fact domain was unavailable / not connected / not entitled
 *   Insufficient   facts were read but the sample is below the rule's minimum
 *
 * Only Finding and Passing feed the Growth Score. NotApplicable and
 * Insufficient are EXCLUDED — never scored as 0 and never as 100.
 */
enum GrowthRuleOutcomeStatus: string
{
    case Finding = 'finding';
    case Passing = 'passing';
    case NotApplicable = 'not_applicable';
    case Insufficient = 'insufficient';

    public function isScored(): bool
    {
        return $this === self::Finding || $this === self::Passing;
    }
}
