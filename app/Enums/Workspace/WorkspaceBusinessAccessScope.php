<?php

namespace App\Enums\Workspace;

enum WorkspaceBusinessAccessScope: string
{
    case All = 'all';
    case Selected = 'selected';
}
