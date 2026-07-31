<?php

namespace Tests\Feature\Workspace\Support;

use Tests\TestCase;

/**
 * Base class for schema-mutating enforcement-migration tests (RFC-003 M1B
 * Slice 4B). Deliberately does not use RefreshDatabase/DatabaseTransactions
 * — these tests exercise migration 6's up()/down() directly, including
 * full migrate:fresh/rollback cycles, which neither trait supports safely.
 * Every test gets a freshly prepared post-migration-5/pre-migration-6
 * schema via VerifiesEnforcementWorkspaceDatabase, always against the
 * generated enforcement database — never ultimatesms_testing.
 */
abstract class EnforcementWorkspaceTestCase extends TestCase
{
    use VerifiesEnforcementWorkspaceDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareEnforcementDatabase();
    }
}
