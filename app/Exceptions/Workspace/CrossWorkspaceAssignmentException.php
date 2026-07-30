<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown when a WorkspaceMembership's scoped Business-assignment write would
 * connect it to a Business belonging to a different Workspace (RFC-003 §9.3,
 * §12.3) — no plain foreign key can express that cross-table equality, so it
 * is enforced here, at write time, instead.
 */
class CrossWorkspaceAssignmentException extends RuntimeException
{
}
