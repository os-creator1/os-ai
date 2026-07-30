<?php

namespace App\Enums\Workspace;

enum WorkspaceMembershipRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';
}
