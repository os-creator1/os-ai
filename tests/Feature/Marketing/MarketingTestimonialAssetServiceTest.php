<?php

namespace Tests\Feature\Marketing;

use App\Library\Marketing\Exceptions\InvalidMarketingAssetException;
use App\Library\Marketing\MarketingTestimonialAssetService;
use App\Models\MarketingTestimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Review correction: MarketingTestimonialAssetService::store() used to
 * write directly into its content-hashed destination with
 * file_put_contents() regardless of whether that path already existed —
 * so a failed/partial re-upload of the identical image could truncate, or
 * (on failed integrity verification) unlink, a file one or more
 * MarketingTestimonial rows already reference. store() now never writes
 * into an existing destination: it reuses a verified match untouched,
 * fails closed on an unverifiable match, and only ever writes a brand-new
 * destination via a same-directory temp file plus a single rename().
 *
 * These tests exercise the real filesystem under
 * public_path('images/marketing/testimonials') — no Storage fake — since
 * that is exactly the path this service owns; each test cleans up after
 * itself.
 */
class MarketingTestimonialAssetServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $directory = public_path('images/marketing/testimonials');
        if (is_dir($directory)) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    private function service(): MarketingTestimonialAssetService
    {
        return app(MarketingTestimonialAssetService::class);
    }

    private function tempArtifactsRemaining(): array
    {
        $directory = public_path('images/marketing/testimonials');

        return array_values(array_filter(
            glob($directory . DIRECTORY_SEPARATOR . '.tmp-*') ?: [],
        ));
    }

    /**
     * Must hold the fake UploadedFile in a local variable rather than
     * chaining ->getRealPath() inline — otherwise it can be garbage
     * collected (and its backing temp file removed) before
     * file_get_contents() runs.
     */
    private function fakeImageBytes(): string
    {
        $file = UploadedFile::fake()->image('poster.png', 400, 300);

        return file_get_contents($file->getRealPath());
    }

    public function test_a_first_upload_stores_the_asset_and_returns_a_content_hashed_path(): void
    {
        $bytes = $this->fakeImageBytes();
        $file = UploadedFile::fake()->createWithContent('poster.png', $bytes);

        $path = $this->service()->store($file);

        $this->assertStringStartsWith('images/marketing/testimonials/', $path);
        $this->assertStringEndsWith('.png', $path);
        $this->assertSame(hash('sha256', $bytes), pathinfo($path, PATHINFO_FILENAME));
        $this->assertFileExists(public_path($path));
        $this->assertSame($bytes, file_get_contents(public_path($path)));
        $this->assertSame([], $this->tempArtifactsRemaining(), 'No temp artifact should remain after a successful store.');
    }

    public function test_an_identical_second_upload_reuses_the_existing_file_without_rewriting_it(): void
    {
        $bytes = $this->fakeImageBytes();

        $firstPath = $this->service()->store(UploadedFile::fake()->createWithContent('first.png', $bytes));
        $fullPath = public_path($firstPath);

        clearstatcache(true, $fullPath);
        $mtimeBefore = filemtime($fullPath);
        $bytesBefore = file_get_contents($fullPath);

        // A filesystem mtime tick can be coarser than this test's own
        // execution time on some runners; sleeping briefly makes an
        // unwanted rewrite's mtime bump detectable rather than
        // coincidentally hidden by clock resolution.
        usleep(1_100_000);

        $secondPath = $this->service()->store(UploadedFile::fake()->createWithContent('second.png', $bytes));

        clearstatcache(true, $fullPath);
        $mtimeAfter = filemtime($fullPath);
        $bytesAfter = file_get_contents($fullPath);

        $this->assertSame($firstPath, $secondPath, 'An identical upload must resolve to the same content-hashed path.');
        $this->assertSame($bytesBefore, $bytesAfter, 'The existing file must remain byte-identical — no destructive rewrite.');
        $this->assertSame($mtimeBefore, $mtimeAfter, 'The existing file must not be touched at all on an identical re-upload.');
        $this->assertSame([], $this->tempArtifactsRemaining());
    }

    public function test_two_testimonials_sharing_a_poster_both_keep_a_valid_file_after_a_reupload(): void
    {
        $bytes = $this->fakeImageBytes();
        $sharedPath = $this->service()->store(UploadedFile::fake()->createWithContent('a.png', $bytes));

        $a = MarketingTestimonial::query()->create([
            'name' => 'Testimonial A',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image_path' => $sharedPath,
            'is_visible' => true,
            'position' => 1,
        ]);
        $b = MarketingTestimonial::query()->create([
            'name' => 'Testimonial B',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image_path' => $sharedPath,
            'is_visible' => true,
            'position' => 2,
        ]);

        // Simulating an operator re-uploading the identical photo while
        // editing one of the two testimonials that already share it.
        $reuploadPath = $this->service()->store(UploadedFile::fake()->createWithContent('reupload.png', $bytes));

        $this->assertSame($sharedPath, $reuploadPath);
        $a->refresh();
        $b->refresh();
        $this->assertSame($sharedPath, $a->poster_image_path);
        $this->assertSame($sharedPath, $b->poster_image_path);
        $this->assertFileExists(public_path($sharedPath));
        $this->assertSame($bytes, file_get_contents(public_path($sharedPath)));
    }

    public function test_a_corrupted_existing_destination_fails_closed_without_being_overwritten_or_deleted(): void
    {
        $bytes = $this->fakeImageBytes();
        $file = UploadedFile::fake()->createWithContent('poster.png', $bytes);

        $path = $this->service()->store($file);
        $fullPath = public_path($path);

        // Simulate the exact anomaly this correction guards against: the
        // content-hashed destination exists but its bytes do not actually
        // match the hash in its own filename (e.g. disk-level corruption,
        // or a hypothetical partial write from before this fix existed).
        $corruptedBytes = 'this is not the real image content';
        file_put_contents($fullPath, $corruptedBytes);

        $this->expectException(InvalidMarketingAssetException::class);

        try {
            $this->service()->store(UploadedFile::fake()->createWithContent('poster-again.png', $bytes));
        } finally {
            // Whether the exception was thrown or not, the corrupted file
            // must still be exactly what we corrupted it to — never
            // unlinked, never partially rewritten by the failed attempt.
            $this->assertFileExists($fullPath);
            $this->assertSame($corruptedBytes, file_get_contents($fullPath));
            $this->assertSame([], $this->tempArtifactsRemaining(), 'This branch never creates a temp file, so none should exist.');
        }
    }
}
