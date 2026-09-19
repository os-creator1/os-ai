<?php

namespace App\Exceptions\Seo;

use RuntimeException;

/**
 * A write refused for a domain reason the actor can be told about: the
 * private-address rule, an unsafe link, an Archived Location, a bad value.
 * Nothing is persisted when this is thrown. `field` names the input the
 * message belongs to, so the controller can attach it to that field.
 */
final class SeoCitationRefusedException extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
