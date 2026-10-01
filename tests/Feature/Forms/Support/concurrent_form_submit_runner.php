<?php

/**
 * Standalone runner invoked as a separate OS process by
 * FormsSubmissionConcurrencyTest, so the concurrency proof exercises genuinely
 * independent database connections racing for one form — something a single
 * PHPUnit process cannot do. Boots the app in the testing environment and, before
 * any write, re-verifies the database it resolved for itself against
 * EXPECTED_TEST_DATABASE (a stale bootstrap config cache could otherwise point it
 * at a different database than the parent).
 *
 * Usage: php concurrent_form_submit_runner.php <deploymentUid> <operationToken> <phone>
 *
 * Prints one JSON line: {"submission_id", "replayed", "events"} where `events`
 * counts FormSubmissionRecorded events THIS process delivered.
 */

require __DIR__.'/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to submit: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to submit: '.$e->getMessage()." Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

[, $deploymentUid, $token, $phone] = $argv;

$events = 0;
Illuminate\Support\Facades\Event::listen(App\Events\Forms\FormSubmissionRecorded::class, function () use (&$events): void {
    $events++;
});

try {
    $result = $app->make(App\Library\Forms\FormSubmissionService::class)->submit($deploymentUid, [
        'your_name' => 'Racer Visitor',
        'phone' => $phone,
        App\Library\Forms\FormSubmissionService::TOKEN_FIELD => $token,
    ]);

    fwrite(STDOUT, json_encode([
        'submission_id' => $result->submission->id,
        'replayed' => $result->replayed,
        'events' => $events,
    ])."\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
