<?php

namespace App\Enums\Opportunity;

enum OpportunityCompletionPolicy: string
{
    case SystemVerified = 'system_verified';
    case CustomerAttested = 'customer_attested';
    case ExternalVerified = 'external_verified';
}
