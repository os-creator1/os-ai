<?php

/**
 * Standalone runner started as a separate OS process by BusinessServicesEditorConcurrencyTest. Usage:
 *   add         <ownerUserId> <businessId> <startAtMicrotime> <name>
 *   deactivate  <ownerUserId> <businessId> <startAtMicrotime> <name>
 *   primary     <ownerUserId> <businessId> <startAtMicrotime> <name>
 * Waits for the shared start instant, then calls BusinessServiceManager as the controller would; prints "OK" or the
 * exception class (a raw exception, or a hidden deadlock retry, is the failure this test exists to catch).
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expected = getenv('EXPECTED_TEST_DATABASE');

if ($expected === false || $expected === '') {
    fwrite(STDERR, "Refusing to run: EXPECTED_TEST_DATABASE was not handed down.\n");
    exit(3);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expected);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run: ' . $e->getMessage() . "\n");
    exit(3);
}

[, $mode, $userId, $businessId, $startAt, $name] = $argv;

$manager = $app->make(App\Library\Business\BusinessServiceManager::class);
// Loaded BEFORE the race, exactly as a page rendered a moment earlier would be.
$business = App\Models\Business::query()->findOrFail((int) $businessId);
$service = $mode === 'add' ? null : $business->services()->where('name', $name)->first();

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$rollbacks = 0;
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionRolledBack::class, function () use (&$rollbacks) {
    $rollbacks++;
});

try {
    match ($mode) {
        'add' => $manager->add((int) $userId, $business, $name),
        'deactivate' => $manager->deactivate((int) $userId, $business, $service),
        'primary' => $manager->makePrimary((int) $userId, $business, $service),
        default => throw new InvalidArgumentException("Unknown mode [{$mode}]."),
    };

    fwrite(STDOUT, $rollbacks === 0 ? "OK\n" : "RETRIED({$rollbacks})\n");
} catch (Throwable $e) {
    fwrite(STDOUT, get_class($e) . "\n");
    exit(1);
}
