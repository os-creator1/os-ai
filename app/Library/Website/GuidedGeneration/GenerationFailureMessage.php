<?php

namespace App\Library\Website\GuidedGeneration;

/**
 * Website V1 final — what a customer reads when generation did not finish.
 * An attempt's `failure_reason` is written for support and may carry
 * internals (an exception message, a validator complaint); only the few
 * sentences written FOR customers pass through, everything else becomes one
 * calm, honest message. Nothing is lost on failure — answers are saved and
 * "Try again" is always offered.
 */
final class GenerationFailureMessage
{
    public const GENERIC = "We couldn't finish building your website this time. Your answers are saved, so nothing was lost. Please try again in a moment.";

    /** Sentences already written for customers (matched by prefix). */
    private const CUSTOMER_SAFE = [
        'The included AI generation budget is used up for this period.',
        "Website generation isn't available in this environment right now.",
        'This generation was superseded',
    ];

    public static function forCustomer(?string $reason): string
    {
        $reason = trim((string) $reason);

        foreach (self::CUSTOMER_SAFE as $safe) {
            if ($reason !== '' && str_starts_with($reason, $safe)) {
                return $reason;
            }
        }

        return self::GENERIC;
    }
}
