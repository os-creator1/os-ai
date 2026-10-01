<?php

namespace App\Exceptions\BusinessEmail;

use RuntimeException;

/** A conditional UPDATE lost its optimistic-lock race; never blindly retried. */
final class BusinessEmailConcurrencyException extends RuntimeException
{
    public function __construct(public readonly int $accountId)
    {
        parent::__construct('business_email_account_concurrency:' . $accountId);
    }

    public function userMessage(): string
    {
        return 'Another change to your email connection was in progress. Please try again.';
    }
}
