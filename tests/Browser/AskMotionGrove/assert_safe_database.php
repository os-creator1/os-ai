<?php
/*
 * Fail-closed guard for reset.sh: argv[1] is the database NAME the MySQL command actually resolved (SELECT DATABASE()).
 * The decision belongs to Tests\Support\TestDatabaseSafety — the repository's single authority — so there is no second policy here.
 * Exit 0 = permitted disposable test database; anything else = refuse, and reset.sh must not run any destructive SQL.
 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Tests\Support\TestDatabaseSafety;

$name = $argv[1] ?? null;
$expected = getenv('EXPECTED_TEST_DATABASE');

try {
    TestDatabaseSafety::assertSafeTestDatabaseName($name);

    if (is_string($expected) && $expected !== '' && $expected !== $name) {
        throw new RuntimeException("Refusing to use database [{$name}]: EXPECTED_TEST_DATABASE is [{$expected}], and the MySQL command must resolve that very database.");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'reset.sh refused: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
