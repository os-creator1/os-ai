<?php

namespace App\Enums\Workspace;

/**
 * The two dispositions available for a Workspace's previous owner during
 * transferOwnership() (RFC-003 Milestone 2 domain contract, corrected
 * report §10). Carried by WorkspaceOwnershipTransferDisposition, never
 * passed as a free-form string.
 */
enum WorkspaceOwnershipTransferMode: string
{
    case Deactivate = 'deactivate';
    case ConvertToAdmin = 'convert_to_admin';
}
