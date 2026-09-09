<?php

namespace Tests\Feature\Settings\Concerns;

use App\Models\AppConfig;
use App\Models\Language;
use App\Models\User;

/**
 * Shared fixtures for the B3 Simplified Platform Settings test suite,
 * mirroring the ensureRequiredAppConfigRowsExist()/actingAsAdmin()
 * pattern already established across tests/Feature/Branding,
 * tests/Feature/Theme, and tests/Feature/Dashboards.
 */
trait SettingsTestHelpers
{
    /**
     * Consumes users.id === 1 (EloquentAccountRepository::hasPermission()'s
     * own repository-wide inherited super-admin Gate bypass) so this
     * file's own actors are never accidentally granted every permission
     * by landing on the first auto-increment id.
     */
    protected function consumeSuperAdminId(): void
    {
        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    protected function actingAsAdmin(array $permissions): User
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

    /**
     * Every app_config row SettingsController::general() reads while
     * rendering the page (regardless of which section tabs are visible),
     * seeded the same conditional-create way every other settings-
     * adjacent test in this repository seeds exactly the rows its own
     * render path touches.
     */
    protected function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', [
            'license', 'customer_permissions', 'custom_script', 'company_address', 'php_bin_path',
        ])->pluck('setting')->all();

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

        if (! in_array('company_address', $existing, true)) {
            AppConfig::create(['setting' => 'company_address', 'value' => 'Test Address']);
        }

        if (! in_array('php_bin_path', $existing, true)) {
            AppConfig::create(['setting' => 'php_bin_path', 'value' => '/usr/bin/php']);
        }

        if (Language::where('code', 'en')->doesntExist()) {
            Language::create(['name' => 'English', 'code' => 'en', 'iso_code' => 'us', 'status' => true]);
        }
    }

    /**
     * The exact minimum payload PostGeneralRequest::rules() requires,
     * matching BrandingUploadValidationTest's own established fixture
     * shape.
     */
    protected function baseGeneralSettingsPayload(array $overrides = []): array
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

    protected function baseSystemEmailPayload(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'smtp',
            'host' => 'smtp.example.test',
            'port' => '587',
            'encryption' => 'tls',
            'username' => 'mailer@example.test',
            'password' => 'original-secret-password',
            'from_email' => 'noreply@example.test',
            'from_name' => 'Example',
        ], $overrides);
    }

    protected function baseAuthenticationPayload(array $overrides = []): array
    {
        return array_merge([
            'client_registration' => '1',
            'registration_verification' => '1',
            'client_can_delete_account' => '1',
            'client_can_create_subaccount' => '1',
            'client_can_delete_subaccount' => '1',
            'captcha_in_login' => '0',
            'captcha_in_client_registration' => '0',
            'two_factor' => '0',
            'two_factor_send_by' => 'email',
            'login_with_facebook' => '0',
            'login_with_twitter' => '0',
            'login_with_google' => '0',
            'login_with_github' => '0',
        ], $overrides);
    }

    protected function baseAiSettingsPayload(array $overrides = []): array
    {
        return array_merge([
            'api_key' => 'sk-original-secret-key',
            'model' => 'gpt-4o',
            'organization' => '',
            'project' => '',
            'role' => 'user',
        ], $overrides);
    }

    /**
     * Read one key back out of the environment file this test process is
     * actually using.
     *
     * THE IMPLEMENTATION DELIBERATELY NO LONGER LIVES HERE. The copy
     * this trait used to carry read `base_path('.env')` — the file
     * App\Helpers\write_env() never touches under APP_ENV=testing,
     * because the application's environment file is `.env.testing`
     * there. The settings suite was therefore asserting against a file
     * its own writes had not reached, and was really reading whatever
     * the developer's `.env` happened to contain. It also decoded with
     * `trim($value, "\"\n")`, which cannot strip the trailing `\r` of a
     * CRLF line and so left the closing quote attached — the origin of
     * actual values such as `AI Business OS"`.
     *
     * The single implementation is now
     * Tests\Support\UsesTemporaryEnvironmentFile::readActiveEnvValue(),
     * which Tests\TestCase applies to every test in this repository. It
     * addresses the disposable copy the writers write, and decodes the
     * exact quoting App\Helpers\format_dotenv_value() produces.
     *
     * This thin delegate exists so the settings suite keeps calling
     * `readEnvValue()`, and so the shared name is not declared on
     * Tests\TestCase itself: Tests\Feature\Branding\BrandingUploadValidationTest
     * carries its own PRIVATE readEnvValue(), and PHP fatals when a
     * subclass narrows an inherited protected method to private. Naming
     * the trait method differently keeps this remediation inside its
     * allowlist instead of forcing an edit to that unrelated file.
     *
     * @see \Tests\Support\UsesTemporaryEnvironmentFile::readActiveEnvValue()
     */
    protected function readEnvValue(string $key): ?string
    {
        return $this->readActiveEnvValue($key);
    }
}
