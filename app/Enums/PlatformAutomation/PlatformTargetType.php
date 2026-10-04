<?php

namespace App\Enums\PlatformAutomation;

/** What a Platform run acts on. Every run carries exactly one explicit target. */
enum PlatformTargetType: string
{
    case User = 'user';
    case Workspace = 'workspace';
    case Business = 'business';
    case Subscription = 'subscription';
    case Provider = 'provider';
    /** Platform-operations triggers with no tenant (queue failures, etc.). */
    case Platform = 'platform';
}
