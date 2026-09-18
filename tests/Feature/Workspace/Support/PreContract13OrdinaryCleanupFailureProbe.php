<?php

namespace Tests\Feature\Workspace\Support;

/**
 * NOT auto-discovered by the normal PHPUnit suite — this filename does not
 * end in "Test.php" (phpunit.xml's <directory suffix="Test.php"> filter),
 * deliberately, matching PreContract13SetupFailureProbe's own precedent.
 * Run only by explicit path, from
 * PreContract13HistoricalTestCaseLifecycleTest's own child-process proof
 * that a cleanup failure during ORDINARY tearDown() (setUp() having
 * already completed successfully) surfaces and fails the test, rather than
 * being silently swallowed into a false green.
 *
 * Its test body genuinely passes — the point is that PHPUnit must still
 * report this test as failed overall, because tearDown()'s own cleanup
 * throws. dropHistoricalDatabase() still performs the REAL drop (via
 * parent::) before throwing its synthetic failure, so running this probe
 * never actually leaks the disposable database it creates.
 */
class PreContract13OrdinaryCleanupFailureProbe extends PreContract13HistoricalTestCase
{
    public const CLEANUP_FAILURE_MARKER = 'DELIBERATE_ORDINARY_CLEANUP_FAILURE_PROBE_MARKER';

    public function test_the_body_itself_passes(): void
    {
        $this->assertTrue(true);
    }

    protected function dropHistoricalDatabase(string $databaseName, string $connectionName): void
    {
        parent::dropHistoricalDatabase($databaseName, $connectionName);

        throw new \RuntimeException(self::CLEANUP_FAILURE_MARKER);
    }
}
