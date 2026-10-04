<?php

namespace App\Exceptions\Documents;

use RuntimeException;

/**
 * Implementation Contract 17B §3 — a payment plan failed validation or cannot
 * be compiled to a schedule. One customer-facing message per field.
 */
final class InvalidDocumentPaymentPlanException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors  field => message
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct($errors === [] ? 'Invalid payment plan.' : (string) reset($errors));
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
