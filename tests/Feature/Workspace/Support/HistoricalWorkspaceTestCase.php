<?php

namespace Tests\Feature\Workspace\Support;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Base class for historical M1A Workspace tests (RFC-003 M1B Slice 4A)
 * that write through ordinary Eloquent/query-builder calls and need
 * per-test isolation. Uses DatabaseTransactions, not RefreshDatabase:
 * RefreshDatabase would run migrate:fresh on its first use in a process,
 * reapplying every migration (including a future migration 6) and
 * destroying the pre-migration-5 schema the historical runner already
 * prepared. DatabaseTransactions only wraps each test in a transaction —
 * it never migrates.
 */
abstract class HistoricalWorkspaceTestCase extends TestCase
{
    use DatabaseTransactions;
    use VerifiesHistoricalWorkspaceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifyHistoricalWorkspaceSchema();
    }
}
