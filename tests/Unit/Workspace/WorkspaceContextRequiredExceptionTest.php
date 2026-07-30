<?php

namespace Tests\Unit\Workspace;

use App\Enums\Workspace\WorkspaceContextFailureReason;
use App\Exceptions\Workspace\WorkspaceContextRequiredException;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class WorkspaceContextRequiredExceptionTest extends TestCase
{
    // 16. all five enum values are exact.
    public function test_enum_values_are_exact(): void
    {
        $this->assertSame('multiple_preferred_candidates', WorkspaceContextFailureReason::MultiplePreferredCandidates->value);
        $this->assertSame('multiple_fallback_candidates', WorkspaceContextFailureReason::MultipleFallbackCandidates->value);
        $this->assertSame('dangling_workspace_reference', WorkspaceContextFailureReason::DanglingWorkspaceReference->value);
        $this->assertSame('onboarding_business_reference_invalid', WorkspaceContextFailureReason::OnboardingBusinessReferenceInvalid->value);
        $this->assertSame('onboarding_business_customer_mismatch', WorkspaceContextFailureReason::OnboardingBusinessCustomerMismatch->value);
        $this->assertCount(5, WorkspaceContextFailureReason::cases());
    }

    // 17. duplicate, unsorted and numeric-string IDs normalize to sorted unique integers.
    public function test_candidate_ids_normalize_to_sorted_unique_integers(): void
    {
        $exception = new WorkspaceContextRequiredException(
            1,
            [5, '3', 5, 1, '3', 2],
            WorkspaceContextFailureReason::MultiplePreferredCandidates
        );

        $this->assertSame([1, 2, 3, 5], $exception->candidateWorkspaceIds);
    }

    // 18. candidateWorkspaceIds is zero-indexed.
    public function test_candidate_ids_are_zero_indexed(): void
    {
        $exception = new WorkspaceContextRequiredException(
            1,
            [9, 4],
            WorkspaceContextFailureReason::MultipleFallbackCandidates
        );

        $this->assertSame([0, 1], array_keys($exception->candidateWorkspaceIds));
    }

    // 19. exception fields are readonly.
    public function test_exception_fields_are_readonly(): void
    {
        $reflection = new ReflectionClass(WorkspaceContextRequiredException::class);

        $this->assertTrue($reflection->getProperty('ownerUserId')->isReadOnly());
        $this->assertTrue($reflection->getProperty('candidateWorkspaceIds')->isReadOnly());
        $this->assertTrue($reflection->getProperty('reason')->isReadOnly());
    }

    // 20. the message includes the owner ID, normalized Workspace IDs and reason value.
    public function test_message_includes_owner_id_normalized_ids_and_reason(): void
    {
        $exception = new WorkspaceContextRequiredException(
            42,
            [8, 7],
            WorkspaceContextFailureReason::DanglingWorkspaceReference
        );

        $this->assertStringContainsString('42', $exception->getMessage());
        $this->assertStringContainsString('7, 8', $exception->getMessage());
        $this->assertStringContainsString('dangling_workspace_reference', $exception->getMessage());
    }

    // 21. the message contains no supplied Customer or Business data because none is accepted by the constructor.
    public function test_constructor_accepts_no_customer_or_business_data(): void
    {
        $constructor = new ReflectionMethod(WorkspaceContextRequiredException::class, '__construct');
        $parameterNames = array_map(fn ($parameter) => $parameter->getName(), $constructor->getParameters());

        $this->assertSame(['ownerUserId', 'candidateWorkspaceIds', 'reason'], $parameterNames);
    }

    // 22. empty candidate IDs are supported for invalid onboarding-Business-reference failures.
    public function test_empty_candidate_ids_are_supported(): void
    {
        $exception = new WorkspaceContextRequiredException(
            7,
            [],
            WorkspaceContextFailureReason::OnboardingBusinessReferenceInvalid
        );

        $this->assertSame([], $exception->candidateWorkspaceIds);
        $this->assertStringContainsString('onboarding_business_reference_invalid', $exception->getMessage());
    }
}
