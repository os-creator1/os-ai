<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * M3 contract §13 step 2 — thrown when the raw webhook body's signature
 * cannot be verified against the configured secret. The controller catches
 * this and returns HTTP 400 with zero row inserted, zero mutation. Never
 * carries the raw body, the signature header, or the webhook secret.
 */
class WebhookSignatureVerificationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Webhook signature verification failed.');
    }
}
