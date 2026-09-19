<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Library\Opportunity\Exceptions\CanonicalJsonUnavailableException;
use App\Library\Opportunity\Exceptions\UnsupportedCanonicalValueException;
use App\Library\Support\CanonicalJson as NeutralCanonicalJson;
use App\Library\Support\Exceptions\CanonicalJsonUnavailableException as NeutralCanonicalJsonUnavailableException;
use App\Library\Support\Exceptions\UnsupportedCanonicalValueException as NeutralUnsupportedCanonicalValueException;

/**
 * The canonical JSON primitive shared, unmodified, by fingerprinting
 * (RFC-002 §26) and action hashing (§28).
 *
 * The algorithm now lives in the domain-neutral
 * App\Library\Support\CanonicalJson (extracted, behavior-preservingly, by
 * Implementation Contract 17 §5.3.2/§12.A so Payments & Contracts does not
 * depend semantically on the Opportunity domain). THIS class is the thin
 * adapter that keeps Opportunity exactly as it was: same class name, same
 * public static encode(), same output bytes, and the same exception TYPES —
 * the two neutral exceptions are translated back into their Opportunity
 * counterparts (which extend OpportunityException) with the message
 * preserved, so no existing Opportunity caller or test observes any change.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        try {
            return NeutralCanonicalJson::encode($value);
        } catch (NeutralUnsupportedCanonicalValueException $e) {
            throw new UnsupportedCanonicalValueException($e->getMessage(), (int) $e->getCode(), $e);
        } catch (NeutralCanonicalJsonUnavailableException $e) {
            throw new CanonicalJsonUnavailableException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
