<?php

namespace App\Library\Forms\Exceptions;

use RuntimeException;

/**
 * A public form that cannot accept a submission right now, for ANY reason:
 * unknown link, disabled deployment, form not Active, archived or foreign
 * Location, suspended or unentitled account.
 *
 * ONE exception, ONE response (the public controller answers 404): a visitor
 * must never be able to tell "this link never existed" from "this form is
 * paused" from "this Business is not entitled" — the same discipline the public
 * booking link and secure document link already follow. The reason is kept on
 * the exception only for logs and tests.
 */
class FormUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('This form is not available: '.$reason);
    }
}
