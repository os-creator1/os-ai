<?php

/**
 * Independent-review correction round 5 (items 1 and 2) — standalone
 * runner invoked as a separate OS process, so a Studio mutation's own
 * WebsiteGenerationCoordinator::runExclusive() call and a concurrent
 * beginLease() call race for the SAME Website row's real MySQL lock via
 * genuinely independent database connections. Mirrors
 * tests/Feature/Website/Gallery/Support/concurrent_gallery_upload_runner.php
 * and tests/Feature/Website/GuidedGeneration/Support/
 * concurrent_generation_lease_runner.php's own bootstrap/database-guard/
 * exit-code shape.
 *
 * Usage:
 *   php concurrent_studio_mutation_runner.php lease <websiteId> <holdSeconds> <dbName>
 *   php concurrent_studio_mutation_runner.php upload-asset <websiteId> <holdSeconds> <dbName>
 *   php concurrent_studio_mutation_runner.php delete-asset <websiteId> <assetUid> <holdSeconds> <dbName>
 *   php concurrent_studio_mutation_runner.php store-gallery <websiteId> <assetUidsCsv> <holdSeconds> <dbName>
 *   php concurrent_studio_mutation_runner.php update-page <websiteId> <pageUid> <newTitle> <holdSeconds> <dbName>
 *   php concurrent_studio_mutation_runner.php legacy-commit <websiteId> <holdSeconds> <dbName>
 *
 * `legacy-commit` (independent-review correction round 6) exercises
 * WebsiteGenerationCoordinator::commitFencedLegacyDraft() specifically —
 * the legacy (non-template) AI draft generator's own fenced commit,
 * distinct from runExclusive() even though it locks the same row — by
 * acquiring a lease then immediately committing a single-page batch
 * through it, holding that commit's OWN row lock open for <holdSeconds>.
 *
 * <holdSeconds> sleeps INSIDE the runExclusive()/beginLease() critical
 * section (for `lease`, the sleep happens AFTER the lease is committed,
 * simulating a generation that is genuinely in progress; for every
 * mutation mode, the sleep happens INSIDE the locked transaction, before
 * it commits) — this is what makes the race genuine: the other process
 * must gate on the real row lock (mutation modes) or the real, still-
 * active lease data (lease mode's post-commit hold), never merely on
 * process start order.
 *
 * Prints exactly:
 *   START:<float microtime>
 *   OK:<float microtime>[:<extra>]   -- succeeded
 *   BUSY:<float microtime>            -- GenerationInProgressException
 *   REFUSED:<message>                 -- any other expected domain refusal
 * Any other exception is allowed to propagate (non-zero exit) as a
 * genuine test failure.
 */

require __DIR__ . '/../../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$mode = $argv[1] ?? null;

$dbName = match ($mode) {
    'lease' => $argv[4] ?? null,
    'upload-asset' => $argv[4] ?? null,
    'delete-asset' => $argv[5] ?? null,
    'store-gallery' => $argv[5] ?? null,
    'update-page' => $argv[6] ?? null,
    'legacy-commit' => $argv[4] ?? null,
    default => null,
};

if ($dbName !== null) {
    putenv('DB_DATABASE=' . $dbName);
    $_ENV['DB_DATABASE'] = $dbName;
    $_SERVER['DB_DATABASE'] = $dbName;
}

$app = require __DIR__ . '/../../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');
$actualDatabase = $app->make('db')->connection()->getDatabaseName();

if ($expectedDatabase !== false && $actualDatabase !== $expectedDatabase) {
    fwrite(STDERR, "Refusing to run: connected to '{$actualDatabase}', expected '{$expectedDatabase}'.\n");
    exit(2);
}

use App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator;
use App\Library\Website\Setup\Exceptions\GenerationInProgressException;
use App\Library\Website\WebsiteAssetUploadService;
use App\Library\Website\WebsiteDraftPageService;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsitePage;

$coordinator = $app->make(WebsiteGenerationCoordinator::class);

/**
 * Printed as the FIRST statement inside a mutation's runExclusive()
 * closure — i.e. only once the real MySQL row lock is genuinely held.
 * The test harness polls for this line before starting the competing
 * process, so "which process reaches the lock first" is deterministic
 * (which process WINS the real row-lock race) rather than an accident of
 * which child process happens to finish booting Laravel first.
 */
function signalLocked(): void
{
    echo 'LOCKED:' . microtime(true) . "\n";
    @flush();
}

echo 'START:' . microtime(true) . "\n";
flush();

try {
    switch ($mode) {
        case 'lease':
            $website = Website::findOrFail((int) $argv[2]);
            $holdSeconds = (float) $argv[3];
            $token = $coordinator->beginLease($website);
            echo 'OK:' . microtime(true) . ":{$token}\n";
            if ($holdSeconds > 0) {
                usleep((int) ($holdSeconds * 1_000_000));
            }
            break;

        case 'upload-asset':
            $website = Website::findOrFail((int) $argv[2]);
            $holdSeconds = (float) $argv[3];
            $validPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
            $tmpPath = sys_get_temp_dir() . '/studio-race-' . getmypid() . '-' . uniqid('', true) . '.png';
            file_put_contents($tmpPath, $validPng);
            $uploadedFile = new Illuminate\Http\UploadedFile($tmpPath, 'race.png', 'image/png', null, true);

            $coordinator->runExclusive($website, function () use ($app, $website, $uploadedFile, $holdSeconds) {
                signalLocked();
                $app->make(WebsiteAssetUploadService::class)->store($website, $uploadedFile);
                if ($holdSeconds > 0) {
                    usleep((int) ($holdSeconds * 1_000_000));
                }
            });
            @unlink($tmpPath);
            echo 'OK:' . microtime(true) . "\n";
            break;

        case 'delete-asset':
            $website = Website::findOrFail((int) $argv[2]);
            $asset = WebsiteAsset::where('website_id', $website->id)->where('uid', $argv[3])->firstOrFail();
            $holdSeconds = (float) $argv[4];

            $coordinator->runExclusive($website, function () use ($app, $website, $asset, $holdSeconds) {
                signalLocked();
                $app->make(WebsiteAssetUploadService::class)->delete($website, $asset);
                if ($holdSeconds > 0) {
                    usleep((int) ($holdSeconds * 1_000_000));
                }
            });
            echo 'OK:' . microtime(true) . "\n";
            break;

        case 'store-gallery':
            $website = Website::findOrFail((int) $argv[2]);
            $assetUids = array_values(array_filter(explode(',', $argv[3])));
            $holdSeconds = (float) $argv[4];

            $coordinator->runExclusive($website, function () use ($app, $website, $assetUids, $holdSeconds) {
                signalLocked();
                $draftPages = $app->make(WebsiteDraftPageService::class);
                $gallerySection = ['type' => 'gallery', 'data' => [
                    'heading' => 'Gallery',
                    'items' => array_map(fn ($uid) => ['image' => $uid], $assetUids),
                ]];
                $existing = $website->pages()->where('slug', 'gallery')->first();
                if ($existing === null) {
                    $draftPages->createPage($website, [
                        'title' => 'Gallery', 'slug' => 'gallery', 'is_home' => false,
                        'sections' => [$gallerySection], 'noindex' => true,
                    ]);
                } else {
                    $draftPages->updatePage($website, $existing, [
                        'title' => $existing->title, 'slug' => $existing->slug, 'is_home' => $existing->is_home,
                        'sections' => [$gallerySection], 'seo_title' => $existing->seo_title,
                        'meta_description' => $existing->meta_description, 'noindex' => $existing->noindex,
                    ]);
                }
                if ($holdSeconds > 0) {
                    usleep((int) ($holdSeconds * 1_000_000));
                }
            });
            echo 'OK:' . microtime(true) . "\n";
            break;

        case 'update-page':
            $website = Website::findOrFail((int) $argv[2]);
            $page = WebsitePage::where('website_id', $website->id)->where('uid', $argv[3])->firstOrFail();
            $newTitle = $argv[4];
            $holdSeconds = (float) $argv[5];

            $coordinator->runExclusive($website, function () use ($app, $website, $page, $newTitle, $holdSeconds) {
                signalLocked();
                $app->make(WebsiteDraftPageService::class)->updatePage($website, $page, [
                    'title' => $newTitle, 'slug' => $page->slug, 'is_home' => $page->is_home,
                    'sections' => $page->sections ?? [], 'seo_title' => $page->seo_title,
                    'meta_description' => $page->meta_description, 'noindex' => $page->noindex,
                ]);
                if ($holdSeconds > 0) {
                    usleep((int) ($holdSeconds * 1_000_000));
                }
            });
            echo 'OK:' . microtime(true) . "\n";
            break;

        case 'legacy-commit':
            $website = Website::findOrFail((int) $argv[2]);
            $holdSeconds = (float) $argv[3];

            $token = $coordinator->beginLease($website);
            $committed = $coordinator->commitFencedLegacyDraft($website, $token, function (Website $locked) use ($app, $holdSeconds) {
                signalLocked();
                $app->make(WebsiteDraftPageService::class)->createPage($locked, [
                    'title' => 'Home', 'is_home' => true,
                    'sections' => [['type' => 'hero', 'data' => ['heading' => 'Welcome']]],
                ]);
                if ($holdSeconds > 0) {
                    usleep((int) ($holdSeconds * 1_000_000));
                }
            });
            echo 'OK:' . microtime(true) . ':' . ($committed ? '1' : '0') . "\n";
            break;

        default:
            fwrite(STDERR, "Unknown mode '{$mode}'.\n");
            exit(2);
    }
} catch (GenerationInProgressException $e) {
    echo 'BUSY:' . microtime(true) . "\n";
} catch (Illuminate\Validation\ValidationException $e) {
    echo 'REFUSED:' . json_encode($e->errors()) . "\n";
}
