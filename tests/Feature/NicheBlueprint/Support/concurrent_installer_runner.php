<?php

/**
 * Standalone runner invoked as a separate OS process by
 * NicheBlueprintInstallerConcurrencyTest, so §13.9's scenarios exercise
 * genuinely independent database connections racing for the same Business row
 * lock — something a single PHPUnit process cannot do on its own, and which a
 * sequential simulation would not prove at all.
 *
 * Boots the app in the testing environment so it shares the same database and
 * .env.testing credentials as the parent PHPUnit process, following
 * concurrent_blueprint_runner.php's exact bootstrap / database-guard /
 * exit-code shape.
 *
 * REGISTERS THE TEST ADAPTERS. BlueprintComponentAdapterRegistry ships empty
 * (Slice 20A) and a child process gets a fresh container, so without this
 * every child install would no-op — not because the race was won, but because
 * `adapterFor()` threw. That would turn every assertion below into a vacuous
 * pass, so the registration happens before any mode runs.
 *
 * Usage: php concurrent_installer_runner.php <mode> <args...>
 *
 * Modes:
 *   install <businessId> [barrierFile]
 *     One full `installForBusiness()` run. With <barrierFile>, the child boots
 *     the framework first, prints "READY" (flushed), and then spins until the
 *     parent creates that file. Bootstrap is the slow, variable part of a PHP
 *     subprocess; moving it BEFORE the barrier is what turns "start two
 *     processes and hope" into a real, tight collision — both children enter
 *     `installForBusiness()` within a fraction of a millisecond of each other,
 *     with no production sleep and no instrumentation of the engine.
 *
 *   hold-then <lockSpecs> <holdSeconds> <delegateMode> <delegateArgs...>
 *     The deterministic direction-forcing primitive, identical in shape to
 *     concurrent_blueprint_runner.php's: opens one real transaction, takes an
 *     explicit `SELECT ... FOR UPDATE` on each row named in <lockSpecs> (a
 *     '|'-delimited list of 'table:column:id' triples, in the order given),
 *     prints "LOCKED" (flushed) so the parent can confirm the lock is genuinely
 *     held before releasing the racer, sleeps <holdSeconds>, then runs
 *     <delegateMode> inside that SAME transaction. <lockSpecs> must be the
 *     exact prefix of the delegate's own production lock sequence — for the
 *     installer that is always the `businesses` row, which §7.2 step 1 locks
 *     first — so the delegate's own re-acquisition is a no-op and its real
 *     logic proceeds undisturbed. No production sleep exists; the sleep lives
 *     entirely here.
 *
 * Output (STDOUT), parsed by the parent:
 *   READY                  bootstrapped, waiting on the barrier file
 *   LOCKED                 hold-then has the explicit row lock
 *   BEGIN <microtime>      immediately before installForBusiness()
 *   RESULT abort=<reason|-> installed=<n> unentitled=<n> unavailable=<n> failed=<n> already=<n>
 *   END <microtime>        immediately after installForBusiness() returned
 *   COMMITTED <microtime>  hold-then's outer transaction has committed
 *
 * Exit codes:
 *   0  the run completed (a losing racer also exits 0 — losing is a no-op,
 *      not an error)
 *   2  an unexpected error
 *   3  wrong database — refused before touching anything
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;

$expected = getenv('EXPECTED_TEST_DATABASE');

if (! is_string($expected) || trim($expected) === '') {
    fwrite(STDERR, "EXPECTED_TEST_DATABASE was not handed down; refusing to run.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

$actual = Illuminate\Support\Facades\DB::connection()->getDatabaseName();

if ($actual !== $expected) {
    fwrite(STDERR, "Refusing to run against [{$actual}]; expected [{$expected}].\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

Tests\Support\TestDatabaseSafety::assertSafeTestDatabaseName($actual);

// The registry ships empty; a child container needs the same test adapters the
// parent registered, or every install would no-op for the wrong reason.
$registry = app(App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry::class);

if (! $registry->has(Tests\Feature\NicheBlueprint\Support\TestInstallingComponentAdapter::TYPE)) {
    $registry->register(new Tests\Feature\NicheBlueprint\Support\TestInstallingComponentAdapter());
}

if (! $registry->has(Tests\Feature\NicheBlueprint\Support\TestThrowingComponentAdapter::TYPE)) {
    $registry->register(new Tests\Feature\NicheBlueprint\Support\TestThrowingComponentAdapter());
}

$argv = $_SERVER['argv'];
$mode = $argv[1] ?? '';
$args = array_slice($argv, 2);

function emit(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    flush();
}

/**
 * Blocks until the parent creates $barrierFile. PHP caches stat() results, so
 * the loop must clear that cache or it would spin on a stale "missing" answer
 * forever.
 */
function awaitBarrier(string $barrierFile): void
{
    emit('READY');

    $deadline = microtime(true) + 60.0;

    while (microtime(true) < $deadline) {
        clearstatcache(true, $barrierFile);

        if (file_exists($barrierFile)) {
            return;
        }

        usleep(200);
    }

    throw new RuntimeException("Barrier file [{$barrierFile}] never appeared.");
}

/** @return callable():void */
function delegate(string $mode, array $args): callable
{
    return match ($mode) {
        'install' => function () use ($args): void {
            $business = App\Models\Business::query()->whereKey((int) $args[0])->firstOrFail();

            if (isset($args[1]) && $args[1] !== '') {
                awaitBarrier((string) $args[1]);
            }

            emit('BEGIN ' . microtime(true));

            $result = app(App\Library\NicheBlueprint\NicheBlueprintInstaller::class)->installForBusiness($business);

            emit(sprintf(
                'RESULT abort=%s installed=%d unentitled=%d unavailable=%d failed=%d already=%d',
                $result->abortReason ?? '-',
                $result->installed,
                $result->skippedUnentitled,
                $result->skippedUnavailable,
                $result->failed,
                $result->alreadyDecided,
            ));

            emit('END ' . microtime(true));
        },
        default => throw new InvalidArgumentException("Unknown mode [{$mode}]."),
    };
}

try {
    if ($mode === 'hold-then') {
        $lockSpecs = (string) ($args[0] ?? '');
        $holdSeconds = (float) ($args[1] ?? 0);
        $delegateMode = (string) ($args[2] ?? '');
        $delegateArgs = array_slice($args, 3);

        Illuminate\Support\Facades\DB::transaction(function () use ($lockSpecs, $holdSeconds, $delegateMode, $delegateArgs): void {
            foreach (array_filter(explode('|', $lockSpecs)) as $spec) {
                [$table, $column, $id] = explode(':', $spec);

                Illuminate\Support\Facades\DB::table($table)->where($column, $id)->lockForUpdate()->get();
            }

            emit('LOCKED');

            usleep((int) ($holdSeconds * 1_000_000));

            delegate($delegateMode, $delegateArgs)();
        });

        emit('COMMITTED ' . microtime(true));

        exit(0);
    }

    delegate($mode, $args)();
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(2);
}
