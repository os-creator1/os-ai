<?php

namespace App\Exceptions\Documents;

use RuntimeException;

/**
 * Implementation Contract 17B §2 — a block document failed validation.
 *
 * Carries ONE message per offending path (`blocks.3.data.runs.0.href`) so the
 * editor can point at the exact field. Messages are customer-facing and never
 * echo the rejected value back (it may be hostile markup).
 */
final class InvalidDocumentBlocksException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors  path => message
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct($errors === [] ? 'Invalid document blocks.' : (string) reset($errors));
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
