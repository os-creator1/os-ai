<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown for any evidence-contract violation (RFC-002 §27): a forbidden key
 * supplied on a raw evidence payload (e.g. `summary`, `class`, `handler`),
 * or any bound/shape/timestamp/allowlist failure raised by
 * OpportunityEvidenceValidator.
 */
class OpportunityEvidenceValidationException extends OpportunityException
{
}
