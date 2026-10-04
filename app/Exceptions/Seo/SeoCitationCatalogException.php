<?php

namespace App\Exceptions\Seo;

use RuntimeException;

/**
 * A Platform Owner catalog / niche-recommendation write refused for a reason
 * the operator can be told about (bad value, duplicate key, unknown directory).
 * Nothing is persisted when this is thrown.
 */
final class SeoCitationCatalogException extends RuntimeException
{
}
