<?php

namespace Tests\Unit\Workspace;

use App\Enums\Workspace\WorkspaceOwnershipTransferMode;
use App\Enums\Workspace\WorkspaceTransitionType;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2A test #8: exact backed values for
 * WorkspaceOwnershipTransferMode and WorkspaceTransitionType.
 */
class WorkspaceM2EnumsTest extends TestCase
{
    public function test_workspace_ownership_transfer_mode_exact_values(): void
    {
        $this->assertSame('deactivate', WorkspaceOwnershipTransferMode::Deactivate->value);
        $this->assertSame('convert_to_admin', WorkspaceOwnershipTransferMode::ConvertToAdmin->value);
        $this->assertCount(2, WorkspaceOwnershipTransferMode::cases());
    }

    public function test_workspace_transition_type_exact_values(): void
    {
        $this->assertSame('workspace_ownership_transferred', WorkspaceTransitionType::OwnershipTransferred->value);
        $this->assertSame('business_reassigned_to_workspace', WorkspaceTransitionType::BusinessReassigned->value);
        $this->assertCount(2, WorkspaceTransitionType::cases());
    }
}
