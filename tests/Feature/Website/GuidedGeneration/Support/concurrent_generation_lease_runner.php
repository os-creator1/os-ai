<?php

/**
 * Independent-review correction round 4 (item 1) — standalone runner
 * invoked as a separate OS process by
 * WebsiteGenerationLeaseConcurrencyTest, so two simultaneous
 * beginLease() calls for the SAME Website race for its row lock via
 * genuinely independent database connections — something a single
 * PHPUnit process cannot do on its own. Mirrors
 * tests/Feature/Website/Gallery/Support/concurrent_gallery_upload_runner.php's
 * own bootstrap/database-guard/exit-code shape.
 *
 * Usage: php concurrent_generation_lease_runner.php lease <websiteId> <dbName>
 *
 * Prints exactly one line: "OK:<token>" if the lease was acquired, or
 * "BUSY" if WebsiteGenerationCoordinator::beginLease() threw
 * GenerationInProgressException — the one friendly, non-blocking outcome
 * a genuine simultaneous caller must see. Any other exception is allowed
 * to propagate (non-zero exit) as a genuine test failure, since a
 * LockTimeoutException or any hang here is exactly what the non-blocking
 * SELECT...FOR UPDATE design is supposed to make impossible.
 */

require __DIR__ . '/../../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

if (isset($argv[3]) && $argv[3] !== '') {
    putenv('DB_DATABASE=' . $argv[3]);
    $_ENV['DB_DATABASE'] = $argv[3];
    $_SERVER['DB_DATABASE'] = $argv[3];
}

$app = require __DIR__ . '/../../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');
$actualDatabase = $app->make('db')->connection()->getDatabaseName();

if ($expectedDatabase !== false && $actualDatabase !== $expectedDatabase) {
    fwrite(STDERR, "Refusing to run: connected to '{$actualDatabase}', expected '{$expectedDatabase}'.\n");
    exit(2);
}

[$script, $mode, $websiteId] = array_pad($argv, 3, null);

if ($mode !== 'lease') {
    fwrite(STDERR, "Unknown mode '{$mode}'.\n");
    exit(2);
}

$website = App\Models\Website::findOrFail((int) $websiteId);

try {
    $coordinator = $app->make(App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator::class);
    $token = $coordinator->beginLease($website);
    echo "OK:{$token}\n";
} catch (App\Library\Website\Setup\Exceptions\GenerationInProgressException $e) {
    echo "BUSY\n";
}
