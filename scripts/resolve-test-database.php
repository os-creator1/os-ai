<?php

/**
 * Prints the database LARAVEL ACTUALLY RESOLVES, and refuses unless it is
 * a disposable test database.
 *
 * WHY THIS IS A SEPARATE PROCESS. Validating the name a caller *asked
 * for* is not the same as validating the name the framework will *use*.
 * Between the two sit `.env`, `.env.<APP_ENV>`, a stale
 * bootstrap/cache/config.php, `DATABASE_URL`, and any config override —
 * every one of which can point the connection somewhere else. The only
 * honest check boots the framework under the exact environment the
 * destructive step will run under, and asks it.
 *
 * Booting here rather than inside scripts/test-baseline.php is
 * deliberate: that script sets the environment for its CHILDREN, so a
 * check performed in the parent would be answering a different question.
 *
 * Prints exactly one line on success:
 *
 *     RESOLVED_DATABASE=<name>
 *
 * Exit codes:
 *   0  resolved to a permitted disposable test database
 *   3  resolved to something else, or to nothing
 *   4  the expectation handed down does not match what was resolved
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseSafety;

const RESOLVED_UNSAFE_EXIT_CODE = 3;
const RESOLVED_MISMATCH_EXIT_CODE = 4;

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// A DATABASE_URL silently overrides host/database for the whole
// connection, so its presence alone makes the resolved target
// unverifiable from the individual DB_* variables.
if ((string) env('DATABASE_URL', '') !== '') {
    fwrite(STDERR, "Refusing to run: DATABASE_URL is set, which overrides the individual DB_* settings.\n");
    exit(RESOLVED_UNSAFE_EXIT_CODE);
}

try {
    // Not config('database.connections.mysql.database') — the CONNECTION
    // is what a destructive statement runs against, and it is what this
    // must validate.
    $resolved = TestDatabaseSafety::activeTestDatabase();
} catch (Throwable $e) {
    fwrite(STDERR, 'Refusing to run: ' . $e->getMessage() . "\n");
    exit(RESOLVED_UNSAFE_EXIT_CODE);
}

$expected = (string) (getenv('EXPECTED_TEST_DATABASE') ?: '');

if ($expected !== '' && $expected !== $resolved) {
    fwrite(STDERR, sprintf(
        "Refusing to run: the caller selected [%s] but Laravel resolved [%s]. "
        . "A stale config cache or an environment file is overriding the selection.\n",
        $expected,
        $resolved
    ));
    exit(RESOLVED_MISMATCH_EXIT_CODE);
}

// Proves the connection is genuinely usable and really is that database,
// rather than merely configured to be.
$live = (string) DB::selectOne('SELECT DATABASE() AS db')->db;

if ($live !== $resolved) {
    fwrite(STDERR, sprintf(
        "Refusing to run: the open connection reports [%s] but the configuration says [%s].\n",
        $live,
        $resolved
    ));
    exit(RESOLVED_MISMATCH_EXIT_CODE);
}

fwrite(STDOUT, "RESOLVED_DATABASE={$resolved}\n");

exit(0);
