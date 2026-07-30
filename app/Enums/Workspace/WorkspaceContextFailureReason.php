<?php

namespace App\Enums\Workspace;

enum WorkspaceContextFailureReason: string
{
    case MultiplePreferredCandidates = 'multiple_preferred_candidates';
    case MultipleFallbackCandidates = 'multiple_fallback_candidates';
    case DanglingWorkspaceReference = 'dangling_workspace_reference';
    case OnboardingBusinessReferenceInvalid = 'onboarding_business_reference_invalid';
    case OnboardingBusinessCustomerMismatch = 'onboarding_business_customer_mismatch';
}
