<?php

/**
 * Standalone runner started as a separate OS process by OnboardingServicesConcurrencyTest, so the test races
 * genuinely independent database connections. Usage:
 *   services       <customerUserId> <startAtMicrotime> <serviceName>   submit the Services step
 *   complete       <customerUserId> <startAtMicrotime>                 press Finish
 *   hold-business  <businessId> <seconds>                              hold the Business row lock
 * Waits until the shared start instant, then acts exactly as a browser would, and prints "OK" or the exception
 * class (a raw exception is the failure this test exists to catch).
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

$mode = $argv[1] ?? '';

if ($mode === 'hold-business') {
    // Test-only direction-forcing primitive (no production sleep): hold the Business row lock so the racing
    // processes both queue behind it, in a known order, before either can take its second lock.
    [, , $businessId, $seconds] = $argv;
    Illuminate\Support\Facades\DB::beginTransaction();
    Illuminate\Support\Facades\DB::table('businesses')->where('id', (int) $businessId)->lockForUpdate()->first();
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);
    sleep((int) $seconds);
    Illuminate\Support\Facades\DB::commit();
    fwrite(STDOUT, "OK\n");
    exit(0);
}

[, , $userId, $startAt] = $argv;

$customer = App\Models\Customer::query()->where('user_id', (int) $userId)->firstOrFail();
$manager = $app->make(App\Library\Business\OnboardingManager::class);
// Loaded BEFORE the race, exactly as a browser form submitted a moment earlier would be.
$onboarding = $manager->start($customer);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

// A deadlock victim's transaction is rolled back and retried; counting rollbacks makes a hidden retry visible.
$rollbacks = 0;
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionRolledBack::class, function () use (&$rollbacks) {
    $rollbacks++;
});

try {
    if ($mode === 'services') {
        $manager->saveServicesStep($onboarding, $customer, [['name' => $argv[4], 'is_primary' => true]]);
    } elseif ($mode === 'complete') {
        $manager->complete($onboarding, $customer);
    } else {
        throw new InvalidArgumentException("Unknown mode [{$mode}].");
    }

    fwrite(STDOUT, $rollbacks === 0 ? "OK\n" : "RETRIED({$rollbacks})\n");
} catch (Throwable $e) {
    fwrite(STDOUT, get_class($e) . "\n");
    exit(1);
}
