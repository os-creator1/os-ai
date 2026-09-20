<?php

namespace App\Exceptions\Seo;

use RuntimeException;

/**
 * A Location or directory that does not exist, belongs to another Business,
 * or is not accessible to the actor. Deliberately one type for every cause so
 * the controller answers 404 for all of them — a guessed id is
 * indistinguishable from a missing one (Contract 18 §10.1, §16 T-SEO-TEN).
 */
final class SeoCitationNotFoundException extends RuntimeException
{
}
