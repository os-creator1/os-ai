<?php

namespace App\Enums\PlatformAutomation;

enum PlatformAutomationStatus: string
{
    case Draft = 'draft';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Archived = 'archived';
}
