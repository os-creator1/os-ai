<?php

namespace App\Enums\Business;

enum BusinessStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
}
