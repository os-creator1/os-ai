<?php

namespace Tests\Unit\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Exceptions\Workspace\BusinessAssignmentRequiresSelectedScopeException;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\InactiveWorkspaceMembershipMutationException;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\InvalidBusinessAccessScopeAssignmentException;
use App\Exceptions\Workspace\OwnerCannotBeMemberException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceAccessDeniedException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Exceptions\Workspace\WorkspaceInvalidIncomingOwnerException;
use App\Exceptions\Workspace\WorkspaceMembershipAlreadyExistsException;
use App\Exceptions\Workspace\WorkspaceMembershipNotFoundException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2A: the 13 new Workspace-domain exceptions.
 * Requirements 1-2 of the Slice 2A test list: every exception exposes safe
 * expected context, and no exception message can ever contain supplied
 * personal or Business-text data.
 */
class WorkspaceM2FoundationExceptionsTest extends TestCase
{
    private const ALL_EXCEPTION_CLASSES = [
        UnauthorizedWorkspaceManagementException::class,
        WorkspaceAccessDeniedException::class,
        InactiveWorkspaceMutationException::class,
        InactiveWorkspaceMembershipMutationException::class,
        OwnerCannotBeMemberException::class,
        WorkspaceMembershipAlreadyExistsException::class,
        InvalidBusinessAccessScopeAssignmentException::class,
        BusinessAssignmentRequiresSelectedScopeException::class,
        BusinessWorkspaceMismatchException::class,
        WorkspaceNotFoundException::class,
        WorkspaceMembershipNotFoundException::class,
        WorkspaceBusinessNotFoundException::class,
        WorkspaceInvalidIncomingOwnerException::class,
    ];

    // 1a. exactly 13 new exceptions exist, all extending RuntimeException.
    public function test_exactly_thirteen_exceptions_extend_runtime_exception(): void
    {
        $this->assertCount(13, self::ALL_EXCEPTION_CLASSES);

        foreach (self::ALL_EXCEPTION_CLASSES as $class) {
            $this->assertTrue(is_subclass_of($class, RuntimeException::class), "{$class} must extend RuntimeException.");
        }
    }

    // 2. no constructor across all 13 exceptions accepts a free-form string
    // parameter — every parameter is a numeric identifier, boolean state
    // flag, or closed backed enum, so no supplied Customer/Business name,
    // email, phone, address, or free text can ever reach a message.
    public function test_no_constructor_accepts_a_free_form_string_parameter(): void
    {
        foreach (self::ALL_EXCEPTION_CLASSES as $class) {
            $constructor = new ReflectionMethod($class, '__construct');

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                $this->assertInstanceOf(
                    ReflectionNamedType::class,
                    $type,
                    "{$class}::__construct(\${$parameter->getName()}) must have an explicit, single type."
                );

                $this->assertNotSame(
                    'string',
                    $type->getName(),
                    "{$class}::__construct(\${$parameter->getName()}) must not accept a free-form string."
                );

                $this->assertNotSame(
                    'mixed',
                    $type->getName(),
                    "{$class}::__construct(\${$parameter->getName()}) must not accept an untyped/mixed value."
                );
            }
        }
    }

    public function test_unauthorized_workspace_management_exception(): void
    {
        $exception = new UnauthorizedWorkspaceManagementException(42, 7);

        $this->assertSame(42, $exception->actorUserId);
        $this->assertSame(7, $exception->workspaceId);
        $this->assertStringContainsString('42', $exception->getMessage());
        $this->assertStringContainsString('7', $exception->getMessage());
    }

    public function test_workspace_access_denied_exception(): void
    {
        $exception = new WorkspaceAccessDeniedException(42, 99);

        $this->assertSame(42, $exception->userId);
        $this->assertSame(99, $exception->businessId);
        $this->assertStringContainsString('42', $exception->getMessage());
        $this->assertStringContainsString('99', $exception->getMessage());
    }

    public function test_inactive_workspace_mutation_exception(): void
    {
        $exception = new InactiveWorkspaceMutationException(7);

        $this->assertSame(7, $exception->workspaceId);
        $this->assertStringContainsString('7', $exception->getMessage());
        $this->assertStringContainsString('inactive', $exception->getMessage());
    }

    public function test_inactive_workspace_membership_mutation_exception(): void
    {
        $exception = new InactiveWorkspaceMembershipMutationException(15);

        $this->assertSame(15, $exception->membershipId);
        $this->assertStringContainsString('15', $exception->getMessage());
        $this->assertStringContainsString('inactive', $exception->getMessage());
    }

    public function test_owner_cannot_be_member_exception(): void
    {
        $exception = new OwnerCannotBeMemberException(7, 42);

        $this->assertSame(7, $exception->workspaceId);
        $this->assertSame(42, $exception->ownerUserId);
        $this->assertStringContainsString('7', $exception->getMessage());
        $this->assertStringContainsString('42', $exception->getMessage());
    }

    public function test_workspace_membership_already_exists_exception_active(): void
    {
        $exception = new WorkspaceMembershipAlreadyExistsException(7, 42, true);

        $this->assertSame(7, $exception->workspaceId);
        $this->assertSame(42, $exception->userId);
        $this->assertTrue($exception->isActive);
        $this->assertStringContainsString('(active)', $exception->getMessage());
    }

    public function test_workspace_membership_already_exists_exception_inactive(): void
    {
        $exception = new WorkspaceMembershipAlreadyExistsException(7, 42, false);

        $this->assertFalse($exception->isActive);
        $this->assertStringContainsString('(inactive)', $exception->getMessage());
    }

    public function test_invalid_business_access_scope_assignment_exception(): void
    {
        $exception = new InvalidBusinessAccessScopeAssignmentException(WorkspaceBusinessAccessScope::All, 3);

        $this->assertSame(WorkspaceBusinessAccessScope::All, $exception->scope);
        $this->assertSame(3, $exception->suppliedIdCount);
        $this->assertStringContainsString('all', $exception->getMessage());
        $this->assertStringContainsString('3', $exception->getMessage());
    }

    public function test_business_assignment_requires_selected_scope_exception(): void
    {
        $exception = new BusinessAssignmentRequiresSelectedScopeException(15);

        $this->assertSame(15, $exception->membershipId);
        $this->assertStringContainsString('15', $exception->getMessage());
        $this->assertStringContainsString('selected', $exception->getMessage());
    }

    public function test_business_workspace_mismatch_exception(): void
    {
        $exception = new BusinessWorkspaceMismatchException(99, 7, 8);

        $this->assertSame(99, $exception->businessId);
        $this->assertSame(7, $exception->expectedWorkspaceId);
        $this->assertSame(8, $exception->actualWorkspaceId);
        $this->assertStringContainsString('99', $exception->getMessage());
        $this->assertStringContainsString('7', $exception->getMessage());
        $this->assertStringContainsString('8', $exception->getMessage());
    }

    public function test_workspace_not_found_exception(): void
    {
        $exception = new WorkspaceNotFoundException(7);

        $this->assertSame(7, $exception->workspaceId);
        $this->assertStringContainsString('7', $exception->getMessage());
    }

    public function test_workspace_membership_not_found_exception(): void
    {
        $exception = new WorkspaceMembershipNotFoundException(7, 42);

        $this->assertSame(7, $exception->workspaceId);
        $this->assertSame(42, $exception->userId);
        $this->assertStringContainsString('7', $exception->getMessage());
        $this->assertStringContainsString('42', $exception->getMessage());
    }

    public function test_workspace_business_not_found_exception(): void
    {
        $exception = new WorkspaceBusinessNotFoundException(99);

        $this->assertSame(99, $exception->businessId);
        $this->assertStringContainsString('99', $exception->getMessage());
    }

    public function test_workspace_invalid_incoming_owner_exception(): void
    {
        $exception = new WorkspaceInvalidIncomingOwnerException(42);

        $this->assertSame(42, $exception->userId);
        $this->assertStringContainsString('42', $exception->getMessage());
    }

    // 1b. every property declared directly on the exception itself (not
    // inherited from RuntimeException/Exception, e.g. $message) is readonly.
    public function test_every_exposed_property_is_readonly(): void
    {
        foreach (self::ALL_EXCEPTION_CLASSES as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $this->assertTrue(
                    $property->isReadOnly(),
                    "{$class}::\${$property->getName()} must be readonly."
                );
            }
        }
    }
}
