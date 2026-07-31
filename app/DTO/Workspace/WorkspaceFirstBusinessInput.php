<?php

declare(strict_types=1);

namespace App\DTO\Workspace;

use App\Models\Customer;

/**
 * Optional payload for WorkspaceManager::createWorkspace() (RFC-003
 * Milestone 2 domain contract, corrected report §1) that lets the caller
 * create the Workspace and its first Business in one transaction. The
 * Customer is always supplied explicitly — never inferred from the
 * Workspace owner or the acting user — so Business.customer_id remains
 * fully independent from Workspace.owner_user_id.
 */
final readonly class WorkspaceFirstBusinessInput
{
    public function __construct(
        public Customer $customer,
        public array $businessAttributes,
    ) {
    }
}
