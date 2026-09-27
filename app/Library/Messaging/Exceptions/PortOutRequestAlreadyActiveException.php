<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Phone Numbers + A2P lane — thrown when a Business's number already has
 * an unresolved port-out request. MySQL's own UNIQUE index on
 * business_messaging_number_port_out_requests.active_number_id is the
 * actual enforcement; this exception is the application-level signal
 * PortOutRequestManager::request() raises when that constraint (or its own
 * pre-check) refuses a second concurrent or repeat request.
 */
class PortOutRequestAlreadyActiveException extends RuntimeException
{
}
