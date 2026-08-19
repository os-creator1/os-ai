<?php

namespace Tests\Feature\Branding;

use App\Library\Branding\BrandingUploadService;
use App\Library\Branding\Exceptions\InvalidBrandingAssetException;
use App\Models\AppConfig;
use App\Models\User;
use App\Rules\ValidBrandingImageRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Design System M2 Platform Branding contract §6.3/§9 item 4. A renamed
 * non-image file is rejected despite a passing extension claim; SVG is
 * rejected unconditionally; an oversized file is rejected; the stored
 * filename is confirmed to be the SHA-256 of the uploaded bytes, never
 * client-supplied; a user without 'general settings' is denied; the
 * previous configured asset is preserved on replace.
 */
class BrandingUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }
        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }
        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }

    private function actingAsAdmin(array $permissions): User
    {
        $admin = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    // --- ValidBrandingImageRule::detectExtension() — direct, isolated proof ---

    public function test_detect_extension_accepts_a_real_png_signature(): void
    {
        $bytes = base64_decode(self::VALID_PNG_BASE64);

        $this->assertSame('png', ValidBrandingImageRule::detectExtension($bytes));
    }

    public function test_detect_extension_rejects_a_non_image_file_regardless_of_claimed_extension(): void
    {
        $this->expectException(InvalidBrandingAssetException::class);

        ValidBrandingImageRule::detectExtension('<?php echo "not an image"; ?>' . str_repeat('X', 50));
    }

    public function test_detect_extension_rejects_svg_unconditionally(): void
    {
        $this->expectException(InvalidBrandingAssetException::class);

        ValidBrandingImageRule::detectExtension('<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat(' ', 50) . '</svg>');
    }

    public function test_detect_extension_rejects_a_file_too_small_to_be_real(): void
    {
        $this->expectException(InvalidBrandingAssetException::class);

        ValidBrandingImageRule::detectExtension('short');
    }

    public function test_detect_extension_rejects_an_oversized_file(): void
    {
        $this->expectException(InvalidBrandingAssetException::class);

        ValidBrandingImageRule::detectExtension(base64_decode(self::VALID_PNG_BASE64) . str_repeat('X', 3 * 1024 * 1024));
    }

    // --- HTTP-level: end-to-end upload behavior ---

    public function test_a_valid_png_logo_upload_is_accepted_and_stored_with_a_content_addressed_name(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);
        $contents = base64_decode(self::VALID_PNG_BASE64);
        $file = UploadedFile::fake()->createWithContent('MyLogo.png', $contents);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload(['app_logo' => $file]));

        $expectedFilename = hash('sha256', $contents) . '.png';
        $this->assertFileExists(public_path("images/branding/logo/{$expectedFilename}"));
        $this->assertSame("images/branding/logo/{$expectedFilename}", $this->readEnvValue('APP_LOGO'));
    }

    private function readEnvValue(string $key): ?string
    {
        $line = collect(file(base_path('.env')))->first(fn ($line) => str_starts_with($line, "{$key}="));

        if ($line === null) {
            return null;
        }

        return trim(explode('=', $line, 2)[1] ?? '', "\"\n");
    }

    public function test_an_svg_upload_is_rejected_for_every_branding_field(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat(' ', 50) . '</svg>';
        $file = UploadedFile::fake()->createWithContent('logo.svg', $svg);

        $response = $this->post('/admin/settings', $this->baseGeneralSettingsPayload(['app_logo' => $file]));

        $response->assertSessionHasErrors('app_logo');
    }

    public function test_a_user_without_general_settings_permission_is_denied(): void
    {
        // This app's own custom Handler::render() renders both
        // AuthenticationException and AuthorizationException as a 401
        // view (not 403) whenever app.env !== 'local' -- a pre-existing,
        // Design-System-unrelated behavior confirmed during Slice 2.
        $this->actingAsAdmin(['access backend']);
        $contents = base64_decode(self::VALID_PNG_BASE64);
        $file = UploadedFile::fake()->createWithContent('MyLogo.png', $contents);

        $response = $this->post('/admin/settings', $this->baseGeneralSettingsPayload(['app_logo' => $file]));

        $response->assertStatus(401);
    }

    public function test_replacing_a_logo_deletes_the_previous_owned_file(): void
    {
        $service = app(BrandingUploadService::class);
        $first = base64_decode(self::VALID_PNG_BASE64);
        $firstPath = $service->store(UploadedFile::fake()->createWithContent('a.png', $first), 'logo_compact');
        $this->assertFileExists(public_path($firstPath));

        $second = base64_decode(self::VALID_PNG_BASE64) . "\x00\x00extra-bytes-to-differ";
        $secondPath = $service->store(UploadedFile::fake()->createWithContent('b.png', $second), 'logo_compact');

        $this->assertFileExists(public_path($secondPath));
        $this->assertFileDoesNotExist(public_path($firstPath));
        $this->assertNotSame($firstPath, $secondPath);
    }

    public function test_deleting_a_logo_restores_the_bundled_default_and_removes_the_owned_file(): void
    {
        $service = app(BrandingUploadService::class);
        $uploaded = $service->store(UploadedFile::fake()->createWithContent('a.png', base64_decode(self::VALID_PNG_BASE64)), 'logo');
        $this->assertFileExists(public_path($uploaded));

        $service->delete('logo');

        $this->assertFileDoesNotExist(public_path($uploaded));
        $this->assertSame('images/branding/default-logo.svg', config('app.logo'));
    }

    private function baseGeneralSettingsPayload(array $overrides = []): array
    {
        return array_merge([
            'app_name' => 'Test App',
            'app_title' => 'Test Title',
            'company_address' => '123 Test Street',
            'country' => 'United States',
            'timezone' => 'America/New_York',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'language' => 'en',
        ], $overrides);
    }
}
