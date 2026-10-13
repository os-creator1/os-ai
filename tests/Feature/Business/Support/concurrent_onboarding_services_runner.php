<?php

/**
 * Standalone runner started as a separate OS process by OnboardingServicesConcurrencyTest, so the test races
 * genuinely independent database connections. Usage:
 *   php concurrent_onboarding_services_runner.php <customerUserId> <startAtMicrotime> <serviceName>
 * Waits until the shared start instant, then submits the Services step exactly as a browser would, and prints
 * "OK" or the exception class (a raw exception is the failure this test exists to catch).
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

[, $userId, $startAt, $name] = $argv;

$customer = App\Models\Customer::query()->where('user_id', (int) $userId)->firstOrFail();
$manager = $app->make(App\Library\Business\OnboardingManager::class);
$onboarding = $manager->start($customer);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $manager->saveServicesStep($onboarding, $customer, [['name' => $name, 'is_primary' => true]]);
    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDOUT, get_class($e) . "\n");
    exit(1);
}
