<?php

namespace App\Exceptions\BusinessEmail;

use App\Enums\BusinessEmail\BusinessEmailFailureCategory;
use RuntimeException;

/**
 * BusinessEmailSender refused BEFORE creating any row or calling any
 * provider (no account, foreign/unknown Contact, no usable address, invalid
 * content, abuse bound). Zero side effects; the category is stable so a
 * future Automation step can record exactly why it did not send.
 */
final class BusinessEmailSendRefusedException extends RuntimeException
{
    public function __construct(public readonly BusinessEmailFailureCategory $category)
    {
        parent::__construct('business_email_refused:' . $category->value);
    }

    public function userMessage(): string
    {
        return $this->category->customerMessage();
    }
}
