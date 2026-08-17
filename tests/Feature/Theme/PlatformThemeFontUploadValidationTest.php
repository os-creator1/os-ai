<?php

namespace Tests\Feature\Theme;

use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §6.9/§9 item 83. Magic-byte content
 * validation, deliberately in addition to the extension/MIME whitelist:
 * a real WOFF2 signature is accepted, a non-font file renamed to a font
 * extension is rejected, and the safe filename is server-generated.
 */
class PlatformThemeFontUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        Storage::fake('local');
    }

    public function test_a_valid_woff2_signature_is_accepted_and_stored_with_a_content_addressed_name(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $contents = 'wOF2' . str_repeat('X', 200);
        $file = UploadedFile::fake()->createWithContent('MyFont.woff2', $contents);

        $response = $this->post(route('admin.theme-fonts.upload'), ['font' => $file]);
        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors('font');

        $expectedFilename = hash('sha256', $contents) . '.woff2';
        Storage::disk('local')->assertExists("theme-fonts/{$expectedFilename}");
        $this->assertDatabaseHas('platform_theme_fonts', ['safe_filename' => $expectedFilename]);
    }

    public function test_a_non_font_file_renamed_to_a_font_extension_is_rejected(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $contents = '<?php echo "not a font"; ?>' . str_repeat('X', 200);
        $file = UploadedFile::fake()->createWithContent('fake.woff2', $contents);

        $response = $this->post(route('admin.theme-fonts.upload'), ['font' => $file]);

        $response->assertSessionHasErrors('font');
        $this->assertDatabaseCount('platform_theme_fonts', 0);
    }

    public function test_a_valid_woff2_signature_with_the_wrong_extension_is_rejected(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $contents = 'wOF2' . str_repeat('X', 200);
        $file = UploadedFile::fake()->createWithContent('MyFont.woff', $contents);

        $response = $this->post(route('admin.theme-fonts.upload'), ['font' => $file]);

        $response->assertSessionHasErrors('font');
    }

    public function test_a_file_too_small_to_be_a_real_font_is_rejected(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $file = UploadedFile::fake()->createWithContent('tiny.woff2', 'wOF2');

        $response = $this->post(route('admin.theme-fonts.upload'), ['font' => $file]);

        $response->assertSessionHasErrors('font');
    }

    public function test_uploading_the_same_bytes_twice_does_not_create_a_duplicate_row(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $contents = 'wOF2' . str_repeat('X', 200);

        $this->post(route('admin.theme-fonts.upload'), ['font' => UploadedFile::fake()->createWithContent('a.woff2', $contents)]);
        $this->post(route('admin.theme-fonts.upload'), ['font' => UploadedFile::fake()->createWithContent('b.woff2', $contents)]);

        $this->assertDatabaseCount('platform_theme_fonts', 1);
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
}
