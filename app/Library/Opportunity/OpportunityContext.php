<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Library\Opportunity\Exceptions\InvalidOpportunityCandidateException;

/**
 * The closed set of Opportunity contexts (RFC-002 §26, "context_validator").
 *
 * RFC-002's eleven business_advisor types each fire at most once per
 * Business, so their context is always null. The Growth rules (Growth Center
 * lane) fire once per Business *or* once per Business Location, and a
 * Location-scoped finding must be a separate Opportunity per Location so a
 * Selected-Location staff member can be shown exactly their own. That needs
 * exactly one new context shape, and this class is its single owner:
 *
 *   null                              -> Business-wide (validator null/'business')
 *   ['business_location_id' => <id>]  -> one Location (validator 'location')
 *
 * `context_key` — the string persisted on candidates and Opportunities — is
 * derived from the context here and parsed back here, nowhere else. A worker
 * never supplies a key, only the context value, and the manager checks that
 * the Location belongs to the run's Business before it is trusted.
 */
final class OpportunityContext
{
    public const VALIDATOR_BUSINESS = 'business';

    public const VALIDATOR_LOCATION = 'location';

    private const LOCATION_KEY_PREFIX = 'location:';

    /**
     * Checks the SHAPE of a candidate's context against its type's declared
     * validator. Returns the Location id for a Location context, else null.
     * Whether that Location belongs to the Business is the manager's check —
     * it needs the database; this class has none.
     */
    public static function assertShape(mixed $context, ?string $validator): ?int
    {
        if ($validator === null || $validator === self::VALIDATOR_BUSINESS) {
            if ($context !== null) {
                throw new InvalidOpportunityCandidateException('context must be null for this Opportunity type.');
            }

            return null;
        }

        if ($validator !== self::VALIDATOR_LOCATION) {
            throw new InvalidOpportunityCandidateException("Unknown context validator [{$validator}].");
        }

        if ($context === null) {
            return null;
        }

        if (! is_array($context)
            || array_keys($context) !== ['business_location_id']
            || ! is_int($context['business_location_id'])
            || $context['business_location_id'] < 1) {
            throw new InvalidOpportunityCandidateException(
                'context must be null or exactly [business_location_id => positive integer] for this Opportunity type.'
            );
        }

        return $context['business_location_id'];
    }

    public static function keyFor(?int $locationId): ?string
    {
        return $locationId === null ? null : self::LOCATION_KEY_PREFIX . $locationId;
    }

    /**
     * The context value a persisted context_key stands for — the exact
     * inverse of keyFor(), used to recompute a fingerprint at finalization.
     *
     * @return array{business_location_id: int}|null
     */
    public static function contextFromKey(?string $contextKey): ?array
    {
        $locationId = self::locationIdFromKey($contextKey);

        return $locationId === null ? null : ['business_location_id' => $locationId];
    }

    public static function locationIdFromKey(?string $contextKey): ?int
    {
        if ($contextKey === null) {
            return null;
        }

        if (preg_match('/^' . preg_quote(self::LOCATION_KEY_PREFIX, '/') . '([1-9][0-9]{0,18})$/', $contextKey, $m) !== 1) {
            throw new InvalidOpportunityCandidateException('context_key is not a recognised Opportunity context key.');
        }

        return (int) $m[1];
    }
}
