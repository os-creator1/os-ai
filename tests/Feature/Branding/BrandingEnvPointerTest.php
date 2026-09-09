<?php

namespace Tests\Feature\Branding;

use App\Library\Branding\BrandingUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

use function App\Helpers\write_env;

/**
 * BrandingUploadService persists its .env pointer through the hardened
 * PlatformSettingsEnvWriter seam, not AppConfig::setEnv().
 *
 * WHAT WAS BROKEN. AppConfig::setEnv() rewrites base_path('.env')
 * directly — ignoring app()->environmentFilePath(), so under
 * APP_ENV=testing it edited a file nothing reads — and it matches its key
 * with a case-insensitive SUBSTRING search over each line:
 *
 *     stristr($line, $key) ? "$key=\"$value\"" : $line
 *
 * That has two consequences this suite pins down:
 *
 *   1. A key that is not already present is never appended, so the value
 *      is silently discarded. Neither .env nor .env.example ships an
 *      APP_LOGO line, so the FIRST logo upload persisted nothing at all —
 *      in production, not only in tests.
 *   2. Writing APP_LOGO would also rewrite any line merely CONTAINING
 *      that text, so a similarly-prefixed key was corrupted. The service's
 *      own docblock records that the compact/dark keys were named
 *      APP_COMPACT_LOGO / APP_DARK_LOGO specifically to dodge it.
 *
 * write_env() addresses app()->environmentFilePath(), matches the key
 * exactly, appends it when missing, escapes the value, and mirrors it
 * into the in-process config() repository.
 *
 * Every write here lands in the disposable environment file Tests\TestCase
 * installs, so the developer's own .env is never touched.
 */
class BrandingEnvPointerTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private BrandingUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BrandingUploadService::class);
    }

    protected function tearDown(): void
    {
        foreach (['logo', 'logo_compact', 'logo_dark', 'favicon'] as $field) {
            $directory = public_path("images/branding/{$field}");

            if (is_dir($directory)) {
                File::deleteDirectory($directory);
            }
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // First upload — the case that used to persist nothing at all
    // -----------------------------------------------------------------

    public function test_the_first_upload_appends_a_key_that_did_not_exist(): void
    {
        $this->assertNull(
            $this->readEnvValue('APP_LOGO'),
            'Precondition: the environment must not already define APP_LOGO.'
        );

        $relative = $this->service->store($this->pngFile(), 'logo');

        $this->assertSame($relative, $this->readEnvValue('APP_LOGO'), 'The first upload must APPEND the missing key.');
        $this->assertSame($relative, config('app.logo'), 'Runtime configuration must be updated in the same request.');
        $this->assertFileExists(public_path($relative));
    }

    public function test_a_replacement_upload_updates_the_existing_key_in_place(): void
    {
        $first = $this->service->store($this->pngFile(), 'logo');
        $second = $this->service->store($this->pngFile("\x00differing-bytes"), 'logo');

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $this->readEnvValue('APP_LOGO'));
        $this->assertSame($second, config('app.logo'));

        $this->assertSame(
            1,
            substr_count((string) File::get($this->app->environmentFilePath()), "\nAPP_LOGO="),
            'A replacement must update the existing line, never append a duplicate.'
        );
    }

    public function test_deleting_resets_the_key_to_the_bundled_default(): void
    {
        $this->service->store($this->pngFile(), 'logo');

        $this->service->delete('logo');

        $this->assertSame('images/branding/default-logo.svg', $this->readEnvValue('APP_LOGO'));
        $this->assertSame('images/branding/default-logo.svg', config('app.logo'));
    }

    public function test_deleting_an_optional_field_clears_it_rather_than_removing_the_key(): void
    {
        $this->service->store($this->pngFile(), 'logo_dark');

        $this->service->delete('logo_dark');

        // No bundled default exists for this field, so the key stays
        // present with an empty value — a genuinely different state from
        // "never configured", and one the reader must still see.
        $this->assertSame('', $this->readEnvValue('APP_DARK_LOGO'));
        $this->assertNull(config('app.logo_dark'));
    }

    // -----------------------------------------------------------------
    // Similarly-prefixed keys — the substring-match corruption
    // -----------------------------------------------------------------

    public function test_writing_one_branding_key_never_corrupts_a_similarly_prefixed_key(): void
    {
        // Deliberately adversarial: keys that a substring matcher would
        // rewrite when asked to update APP_LOGO.
        write_env('APP_LOGO_DECOY', 'decoy-value');
        write_env('MY_APP_LOGO', 'other-value');
        write_env('APP_COMPACT_LOGO', 'compact-value');

        $relative = $this->service->store($this->pngFile(), 'logo');

        $this->assertSame($relative, $this->readEnvValue('APP_LOGO'));
        $this->assertSame('decoy-value', $this->readEnvValue('APP_LOGO_DECOY'), 'APP_LOGO_DECOY was corrupted.');
        $this->assertSame('other-value', $this->readEnvValue('MY_APP_LOGO'), 'MY_APP_LOGO was corrupted.');
        $this->assertSame('compact-value', $this->readEnvValue('APP_COMPACT_LOGO'), 'APP_COMPACT_LOGO was corrupted.');
    }

    public function test_every_other_variable_survives_a_branding_write(): void
    {
        $path = $this->app->environmentFilePath();
        $before = $this->parseEnv((string) File::get($path));

        $this->service->store($this->pngFile(), 'favicon');

        $after = $this->parseEnv((string) File::get($path));

        foreach ($before as $key => $value) {
            if ($key === 'APP_FAVICON') {
                continue;
            }

            $this->assertArrayHasKey($key, $after, "[{$key}] disappeared from the environment file.");
            $this->assertSame($value, $after[$key], "[{$key}] was modified by an unrelated branding write.");
        }
    }

    // -----------------------------------------------------------------
    // Failure / rollback
    // -----------------------------------------------------------------

    public function test_a_rejected_upload_writes_no_pointer_and_leaves_the_previous_one_intact(): void
    {
        $original = $this->service->store($this->pngFile(), 'logo');

        try {
            // An SVG is rejected unconditionally by
            // ValidBrandingImageRule::detectExtension().
            $this->service->store(
                UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
                'logo'
            );
            $this->fail('An SVG upload must be rejected.');
        } catch (\App\Library\Branding\Exceptions\InvalidBrandingAssetException) {
            // expected
        }

        $this->assertSame($original, $this->readEnvValue('APP_LOGO'), 'A rejected upload must not move the pointer.');
        $this->assertSame($original, config('app.logo'));
        $this->assertFileExists(public_path($original), 'A rejected upload must not delete the previous file.');
    }

    public function test_an_unknown_field_writes_nothing_at_all(): void
    {
        $before = (string) File::get($this->app->environmentFilePath());

        try {
            $this->service->store($this->pngFile(), 'not_a_branding_field');
            $this->fail('An unknown branding field must be rejected.');
        } catch (\App\Library\Branding\Exceptions\InvalidBrandingAssetException) {
            // expected
        }

        $this->assertSame(
            $before,
            (string) File::get($this->app->environmentFilePath()),
            'A rejected field must leave the environment file byte-identical.'
        );
    }

    // -----------------------------------------------------------------

    private function pngFile(string $suffix = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'logo.png',
            base64_decode(self::VALID_PNG_BASE64) . $suffix
        );
    }

    /**
     * @return array<string, string>
     */
    private function parseEnv(string $contents): array
    {
        $values = [];

        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }

        return $values;
    }
}
