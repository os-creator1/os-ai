<?php

namespace Tests\Feature\Settings;

use App\Library\Settings\PlatformSettingsEnvWriter;
use function App\Helpers\load_env_from_file;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B3 Simplified Platform Settings §11/§19 (.env write safety). Exercises
 * the hardened write_env()/format_dotenv_value() (app/Helpers/
 * namespaced_helpers.php) and PlatformSettingsEnvWriter directly against
 * a scratch .env file — never the developer's real .env.
 */
class PlatformSettingsEnvWriteSafetyTest extends TestCase
{
    use RefreshDatabase;

    private string $scratchEnvPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratchEnvPath = storage_path('framework/testing/scratch-' . uniqid('', true) . '.env');
        @mkdir(dirname($this->scratchEnvPath), 0777, true);
        file_put_contents($this->scratchEnvPath, "APP_NAME=Laravel\nEXISTING_KEY=original\n");

        app()->useEnvironmentPath(dirname($this->scratchEnvPath));
        app()->loadEnvironmentFrom(basename($this->scratchEnvPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->scratchEnvPath);
        parent::tearDown();
    }

    public function test_a_value_containing_a_double_quote_and_newline_cannot_inject_a_second_variable(): void
    {
        $writer = new PlatformSettingsEnvWriter();

        $writer->set('APP_TITLE', null, "Safe\"\nMALICIOUS_KEY=evil");

        $envs = load_env_from_file($this->scratchEnvPath);

        $this->assertArrayHasKey('APP_TITLE', $envs);
        $this->assertArrayNotHasKey('MALICIOUS_KEY', $envs);
        // The written line must still be exactly one key=value pair --
        // load_env_from_file() only recognizes lines starting with a
        // KEY=, so a raw, unescaped newline embedded in the value would
        // have produced a second, independently-parseable line.
        $rawContents = file_get_contents($this->scratchEnvPath);
        $this->assertSame(1, substr_count($rawContents, "\nAPP_TITLE="), 'APP_TITLE must appear as exactly one line.');
    }

    public function test_updating_one_key_does_not_corrupt_another_key_whose_name_is_a_substring(): void
    {
        file_put_contents($this->scratchEnvPath, "APP_LOGO=images/original-logo.png\nAPP_COMPACT_LOGO=images/original-compact.png\n");

        $writer = new PlatformSettingsEnvWriter();
        $writer->set('APP_LOGO', null, 'images/new-logo.png');

        $envs = load_env_from_file($this->scratchEnvPath);

        $this->assertSame('"images/new-logo.png"', $envs['APP_LOGO']);
        $this->assertSame('images/original-compact.png', $envs['APP_COMPACT_LOGO'], 'A substring-matching key must never be corrupted by an update to a different key.');
    }

    public function test_write_env_creates_the_file_when_it_does_not_exist_yet(): void
    {
        @unlink($this->scratchEnvPath);
        $this->assertFileDoesNotExist($this->scratchEnvPath);

        $writer = new PlatformSettingsEnvWriter();
        $writer->set('APP_NAME', null, 'Created Fresh');

        $this->assertFileExists($this->scratchEnvPath);
        $this->assertSame('"Created Fresh"', load_env_from_file($this->scratchEnvPath)['APP_NAME'] ?? null);
    }

    public function test_env_writer_refreshes_in_process_config_immediately(): void
    {
        $writer = new PlatformSettingsEnvWriter();

        $writer->set('OPENAI_MODEL', 'services.openai.model', 'gpt-5-probe');

        $this->assertSame('gpt-5-probe', config('services.openai.model'));
    }
}
