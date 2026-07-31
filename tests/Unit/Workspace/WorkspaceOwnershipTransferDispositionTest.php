<?php

namespace Tests\Unit\Workspace;

use App\DTO\Workspace\WorkspaceOwnershipTransferDisposition;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceOwnershipTransferMode;
use App\Exceptions\Workspace\InvalidBusinessAccessScopeAssignmentException;
use ReflectionClass;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2A tests #4-7 for
 * WorkspaceOwnershipTransferDisposition: deactivate() is structurally
 * valid with no scope/IDs; convertToAdmin() accepts selected+empty,
 * normalizes supplied IDs, and rejects all+nonempty.
 */
class WorkspaceOwnershipTransferDispositionTest extends TestCase
{
    // 4. deactivate() state is structurally valid.
    public function test_deactivate_state_is_structurally_valid(): void
    {
        $disposition = WorkspaceOwnershipTransferDisposition::deactivate();

        $this->assertSame(WorkspaceOwnershipTransferMode::Deactivate, $disposition->mode);
        $this->assertNull($disposition->scope);
        $this->assertSame([], $disposition->businessIds);
    }

    // deactivate() cannot even syntactically accept a scope or IDs.
    public function test_deactivate_factory_accepts_no_arguments(): void
    {
        $method = new \ReflectionMethod(WorkspaceOwnershipTransferDisposition::class, 'deactivate');

        $this->assertCount(0, $method->getParameters());
    }

    // 5. convert-to-admin selected + empty is valid.
    public function test_convert_to_admin_selected_with_empty_ids_is_valid(): void
    {
        $disposition = WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::Selected);

        $this->assertSame(WorkspaceOwnershipTransferMode::ConvertToAdmin, $disposition->mode);
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $disposition->scope);
        $this->assertSame([], $disposition->businessIds);
    }

    // 6. convert-to-admin normalizes IDs: integer cast, dedup, ascending sort, zero-based reindex.
    public function test_convert_to_admin_normalizes_supplied_ids(): void
    {
        $disposition = WorkspaceOwnershipTransferDisposition::convertToAdmin(
            WorkspaceBusinessAccessScope::Selected,
            [9, '3', 9, '3', 5]
        );

        $this->assertSame([3, 5, 9], $disposition->businessIds);
        $this->assertSame([0, 1, 2], array_keys($disposition->businessIds));
    }

    // 7. convert-to-admin all + nonempty IDs is rejected.
    public function test_convert_to_admin_all_scope_with_nonempty_ids_is_rejected(): void
    {
        $this->expectException(InvalidBusinessAccessScopeAssignmentException::class);

        WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All, [1, 2]);
    }

    public function test_convert_to_admin_all_scope_with_empty_ids_is_valid(): void
    {
        $disposition = WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope::All);

        $this->assertSame(WorkspaceBusinessAccessScope::All, $disposition->scope);
        $this->assertSame([], $disposition->businessIds);
    }

    // Invalid states are unconstructible: no public constructor exists.
    public function test_constructor_is_private(): void
    {
        $reflection = new ReflectionClass(WorkspaceOwnershipTransferDisposition::class);

        $this->assertTrue($reflection->getConstructor()->isPrivate());
    }

    public function test_mode_and_business_ids_are_readonly(): void
    {
        $reflection = new ReflectionClass(WorkspaceOwnershipTransferDisposition::class);

        $this->assertTrue($reflection->getProperty('mode')->isReadOnly());
        $this->assertTrue($reflection->getProperty('scope')->isReadOnly());
        $this->assertTrue($reflection->getProperty('businessIds')->isReadOnly());
    }
}
