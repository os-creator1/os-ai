<?php

namespace Tests\Feature\Workspace\Support;

use Tests\TestCase;

/**
 * Base class for historical M1A Workspace concurrency tests (RFC-003 M1B
 * Slice 4A) that require real, committed rows visible to a genuinely
 * separate child OS process. Deliberately does not use
 * DatabaseTransactions/RefreshDatabase — an open, never-committed
 * transaction would hide fixture rows from that child process entirely.
 * Each concurrency test is responsible for its own explicit cleanup
 * (tearDown()), matching the existing WorkspaceBackfillV1ConcurrencyTest
 * convention.
 */
abstract class HistoricalWorkspaceConcurrencyTestCase extends TestCase
{
    use VerifiesHistoricalWorkspaceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifyHistoricalWorkspaceSchema();
    }
}
