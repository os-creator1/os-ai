<?php

/**
 * Standalone runner invoked as a separate OS process by
 * DocumentConcurrencyTest, so the concurrency proofs exercise genuinely
 * independent database connections racing for one document row — something a
 * single PHPUnit process cannot do. Boots the app in the testing environment
 * and, before any write, re-verifies the database it resolved for itself
 * against EXPECTED_TEST_DATABASE (a stale bootstrap config cache could
 * otherwise point it at a different database than the parent).
 *
 * Usage:
 *   php concurrent_document_runner.php send <documentId>
 *   php concurrent_document_runner.php sign <documentId> <displayedVersionUid> <typedName> <signerEmail>
 *   php concurrent_document_runner.php edit <documentId> <body>
 *
 * Prints one JSON line:
 *   {"ok": bool, "signature_id": ?int, "error": ?string, "events": {"sent": int, "signed": int}}
 * where `events` counts the lifecycle events THIS process delivered. A refusal
 * (the manager's ValidationException) is a normal outcome: ok=false, exit 0.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run: ' . $e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

$mode = $argv[1] ?? '';
$documentId = (int) ($argv[2] ?? 0);

$events = ['sent' => 0, 'signed' => 0];
Illuminate\Support\Facades\Event::listen(App\Events\DocumentSent::class, function () use (&$events): void {
    $events['sent']++;
});
Illuminate\Support\Facades\Event::listen(App\Events\DocumentSigned::class, function () use (&$events): void {
    $events['signed']++;
});

$manager = $app->make(App\Library\Documents\DocumentManager::class);
$result = ['ok' => true, 'signature_id' => null, 'error' => null];

try {
    $document = App\Models\BusinessDocument::query()->findOrFail($documentId);

    switch ($mode) {
        case 'send':
            $manager->send($document);
            break;

        case 'sign':
            $signature = $manager->sign($document, [
                'displayed_version_uid' => $argv[3],
                'signer_name' => $argv[4],
                'signer_email' => $argv[5],
                'typed_name' => $argv[4],
                'ip_address' => '203.0.113.7',
                'user_agent' => 'runner',
            ]);
            $result['signature_id'] = (int) $signature->id;
            break;

        case 'edit':
            $manager->edit($document, ['content' => ['body' => $argv[3]]]);
            break;

        default:
            fwrite(STDERR, "Unknown mode [{$mode}]\n");
            exit(2);
    }
} catch (Illuminate\Validation\ValidationException $e) {
    $result['ok'] = false;
    $result['error'] = 'refused: ' . $e->getMessage();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

$result['events'] = $events;
fwrite(STDOUT, json_encode($result) . "\n");
exit(0);
