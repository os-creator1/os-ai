<?php

/**
 * Independent-review correction round 3 (item 10) — standalone runner
 * invoked as a separate OS process by WebsiteGalleryUploadConcurrencyTest,
 * so its upload calls race for the SAME Website row's lock via genuinely
 * independent database connections — something a single PHPUnit process
 * cannot do on its own. Mirrors tests/Feature/Usage/Support/
 * concurrent_slot_agreement_runner.php's own bootstrap/database-guard/
 * exit-code shape.
 *
 * Usage: php concurrent_gallery_upload_runner.php upload <websiteId> <purpose> <sizeBytes>
 *
 * Prints exactly one line: "OK" if the upload was accepted, or
 * "REFUSED: <message>" if WebsiteGalleryManager::uploadMany() threw
 * InvalidWebsiteAssetException. Any other exception is allowed to
 * propagate (non-zero exit), since that is a genuine test failure, not
 * an expected "cap enforced" outcome.
 */

require __DIR__ . '/../../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

if (isset($argv[5]) && $argv[5] !== '') {
    putenv('DB_DATABASE=' . $argv[5]);
    $_ENV['DB_DATABASE'] = $argv[5];
    $_SERVER['DB_DATABASE'] = $argv[5];
}

$app = require __DIR__ . '/../../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');
$actualDatabase = $app->make('db')->connection()->getDatabaseName();

if ($expectedDatabase !== false && $actualDatabase !== $expectedDatabase) {
    fwrite(STDERR, "Refusing to run: connected to '{$actualDatabase}', expected '{$expectedDatabase}'.\n");
    exit(2);
}

[$script, $mode, $websiteId, $purpose, $sizeBytes] = array_pad($argv, 5, null);

if ($mode !== 'upload') {
    fwrite(STDERR, "Unknown mode '{$mode}'.\n");
    exit(2);
}

$website = App\Models\Website::findOrFail((int) $websiteId);
$purposeEnum = App\Enums\Website\WebsiteAssetPurpose::from($purpose);
$sizeBytes = (int) $sizeBytes;

// A genuine, magic-byte-valid 1x1 PNG, padded with trailing random bytes
// so its total size is controllable — WebsiteAssetUploadService::store()
// reads the whole file's bytes to determine size, but
// ValidWebsiteImageRule::detectExtension() and getimagesizefromstring()
// only ever inspect the LEADING signature/IHDR bytes, so trailing
// padding after a complete, valid PNG never invalidates it.
$validPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
$padding = max(0, $sizeBytes - strlen($validPng));
$contents = $validPng . random_bytes($padding);

$tmpPath = sys_get_temp_dir() . '/gallery-race-' . getmypid() . '-' . uniqid('', true) . '.png';
file_put_contents($tmpPath, $contents);

$uploadedFile = new Illuminate\Http\UploadedFile($tmpPath, 'race.png', 'image/png', null, true);

try {
    $manager = $app->make(App\Library\Website\Gallery\WebsiteGalleryManager::class);
    $manager->uploadMany($website, [$uploadedFile], $purposeEnum);
    echo "OK\n";
} catch (App\Exceptions\Website\InvalidWebsiteAssetException $e) {
    echo 'REFUSED: ' . $e->getMessage() . "\n";
} finally {
    @unlink($tmpPath);
}
