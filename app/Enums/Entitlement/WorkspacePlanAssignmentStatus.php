<?php

namespace App\Enums\Entitlement;

enum WorkspacePlanAssignmentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
}
