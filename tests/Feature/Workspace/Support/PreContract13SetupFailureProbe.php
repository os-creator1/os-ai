<?php

namespace Tests\Feature\Workspace\Support;

/**
 * NOT auto-discovered by the normal PHPUnit suite — this filename does not
 * end in "Test.php" (phpunit.xml's <directory suffix="Test.php"> filter),
 * deliberately, exactly like this same directory's existing
 * concurrent_*_runner.php / run_*_suite.php child-process helpers. Run only
 * by explicit path, from PreContract13HistoricalTestCaseLifecycleTest's own
 * child-process proof that a setUp() failure occurring AFTER the disposable
 * database already exists still gets cleaned up, and that the ORIGINAL
 * exception — never a secondary cleanup failure — is what PHPUnit reports.
 *
 * Deliberately a genuinely separate PHPUnit process, not an in-process
 * assertion: a test cannot observe its own class's setUp() throwing from
 * within its own test method (the method body never runs), so proving this
 * lifecycle honestly requires watching PHPUnit's real reported outcome for
 * a distinct test run.
 */
class PreContract13SetupFailureProbe extends PreContract13HistoricalTestCase
{
    public const FAILURE_MARKER = 'DELIBERATE_SETUP_FAILURE_PROBE_MARKER';

    public const UNREACHABLE_BODY_MARKER = 'PROBE_TEST_BODY_MUST_NEVER_RUN';

    protected function afterHistoricalDatabaseCreated(): void
    {
        throw new \RuntimeException(self::FAILURE_MARKER);
    }

    public function test_deliberately_fails_after_database_creation(): void
    {
        // If this body ever runs, afterHistoricalDatabaseCreated() failed
        // to abort setUp() before it — that is itself the defect under
        // test, so fail loudly with a distinct, greppable marker rather
        // than silently passing.
        $this->fail(self::UNREACHABLE_BODY_MARKER);
    }
}
