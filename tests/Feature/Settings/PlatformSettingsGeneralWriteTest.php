<?php

namespace Tests\Feature\Settings;

use App\Models\AppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * B3 Simplified Platform Settings §10/§19 (General allowlist — security
 * blocker). Proves EloquentSettingsRepository::general()'s previous
 * $request->except(...) exclusion-list defect (any submitted key reached
 * the write layer) is closed: submitting a key outside the allowlist
 * alongside a legitimate one only ever mutates the legitimate one.
 */
class PlatformSettingsGeneralWriteTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumeSuperAdminId();
    }

    public function test_valid_app_name_is_saved(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload(['app_name' => 'New App Name']))
            ->assertRedirect();

        $this->assertSame('New App Name', $this->readEnvValue('APP_NAME'));
    }

    public function test_submitting_license_alongside_a_valid_field_does_not_mutate_license(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $original = AppConfig::where('setting', 'license')->value('value');
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload([
            'app_name' => 'Allowlist Probe',
            'license' => 'malicious-value',
        ]))->assertRedirect();

        $this->assertSame($original, AppConfig::where('setting', 'license')->value('value'));
        $this->assertSame('Allowlist Probe', $this->readEnvValue('APP_NAME'));
    }

    public function test_submitting_customer_permissions_alongside_a_valid_field_does_not_mutate_it(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $original = AppConfig::where('setting', 'customer_permissions')->value('value');
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload([
            'app_name' => 'Allowlist Probe 2',
            'customer_permissions' => json_encode(['access_backend', 'view_reports', 'malicious_new_ability']),
        ]))->assertRedirect();

        $this->assertSame($original, AppConfig::where('setting', 'customer_permissions')->value('value'));
    }

    public function test_submitting_password_does_not_reach_any_app_config_row(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload([
            'app_name' => 'Allowlist Probe 3',
            'password' => 'malicious-password',
        ]))->assertRedirect();

        $this->assertNull(AppConfig::where('setting', 'password')->first());
    }

    /**
     * The license-bricking scenario the reconnaissance flagged: setting
     * 'license' to empty would previously redirect every authenticated
     * request to a nonexistent 'verify.license' route. Proves it survives
     * a general-settings save untouched, whatever it currently holds.
     */
    public function test_general_write_never_bricks_the_license_row(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload(['license' => '']))
            ->assertRedirect();

        $this->assertNotEmpty(AppConfig::where('setting', 'license')->value('value'));
    }

    public function test_the_timezone_side_effect_no_longer_mutates_user_id_one(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        // A real row with id=1 -- MySQL's own auto_increment counter is
        // not rolled back by RefreshDatabase's transaction, so an earlier
        // test in this same process can already have consumed it; forced
        // explicitly here so this test exercises the exact hardcoded
        // `User::where('id', 1)` the removed side effect targeted,
        // regardless of run order.
        // updateOrCreate() would silently drop an explicit 'id' on create
        // ('id' is deliberately not in User::$fillable), so this checks
        // existence itself and uses forceCreate() to set it explicitly.
        if (\App\Models\User::find(1)) {
            \App\Models\User::where('id', 1)->update(['timezone' => 'Original/Zone']);
        } else {
            \App\Models\User::forceCreate([
                'id' => 1,
                'first_name' => 'Target',
                'last_name' => 'User',
                'email' => 'target-user-id-one' . uniqid('', true) . '@example.test',
                'status' => true,
                'is_admin' => false,
                'is_customer' => false,
                'active_portal' => 'admin',
                'timezone' => 'Original/Zone',
            ]);
        }
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload(['timezone' => 'Europe/Berlin']))
            ->assertRedirect();

        $this->assertSame('Europe/Berlin', $this->readEnvValue('APP_TIMEZONE'));
        $this->assertSame('Original/Zone', \App\Models\User::find(1)->timezone);
    }

    /**
     * B3 Correction 1 — Platform/Appearance/Advanced are three
     * independent forms that all submit to this same postGeneral()
     * endpoint, each sending only the fields it owns. Seeds distinctive
     * values for every cross-section field this endpoint writes
     * (app_keyword: Platform, footer_company_name/footer_copyright_text:
     * Appearance, custom_script: Advanced), the same way an admin who
     * has previously saved every section at least once would leave them.
     */
    private function seedCrossSectionKeepValues(): void
    {
        $writer = app(\App\Library\Settings\PlatformSettingsEnvWriter::class);
        $writer->set('APP_KEYWORD', 'app.keyword', 'keep-keyword');
        $writer->set('APP_FOOTER_COMPANY_NAME', 'app.footer_company_name', 'Keep Company');
        $writer->set('APP_FOOTER_COPYRIGHT_TEXT', 'app.footer_copyright_text', 'Keep Copyright');

        AppConfig::where('setting', 'custom_script')->update([
            'value' => '<script>window.keepMe=true;</script>',
        ]);
    }

    public function test_saving_platform_section_preserves_appearance_and_advanced_values(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedCrossSectionKeepValues();
        $this->actingAsAdmin(['access backend', 'general settings']);

        // Faithful proxy for the real Platform form (_platform.blade.php):
        // Platform's own fields only -- no footer_company_name,
        // footer_copyright_text, or custom_script.
        $this->post('/admin/settings', $this->baseGeneralSettingsPayload([
            'app_name' => 'Platform Only Save',
            'app_keyword' => 'new-platform-keyword',
        ]))->assertRedirect();

        $this->assertSame('Platform Only Save', $this->readEnvValue('APP_NAME'));
        $this->assertSame('new-platform-keyword', $this->readEnvValue('APP_KEYWORD'));
        $this->assertSame('Keep Company', $this->readEnvValue('APP_FOOTER_COMPANY_NAME'));
        $this->assertSame('Keep Copyright', $this->readEnvValue('APP_FOOTER_COPYRIGHT_TEXT'));
        $this->assertSame('<script>window.keepMe=true;</script>', AppConfig::where('setting', 'custom_script')->value('value'));
    }

    public function test_saving_appearance_section_preserves_platform_and_advanced_values(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedCrossSectionKeepValues();
        $this->actingAsAdmin(['access backend', 'general settings']);

        // Faithful proxy for the real Appearance form (_appearance.blade.php):
        // footer fields only -- no app_keyword or custom_script.
        $this->post('/admin/settings', $this->baseGeneralSettingsPayload([
            'footer_company_name' => 'New Footer Company',
            'footer_copyright_text' => 'New Footer Copyright',
        ]))->assertRedirect();

        $this->assertSame('New Footer Company', $this->readEnvValue('APP_FOOTER_COMPANY_NAME'));
        $this->assertSame('New Footer Copyright', $this->readEnvValue('APP_FOOTER_COPYRIGHT_TEXT'));
        $this->assertSame('keep-keyword', $this->readEnvValue('APP_KEYWORD'));
        $this->assertSame('<script>window.keepMe=true;</script>', AppConfig::where('setting', 'custom_script')->value('value'));
    }

    public function test_saving_advanced_custom_script_preserves_platform_and_appearance_values(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedCrossSectionKeepValues();
        $this->actingAsAdmin(['access backend', 'general settings']);

        // Faithful proxy for the real Advanced custom-script form: only
        // custom_script -- no app_keyword or footer fields.
        $this->post('/admin/settings', $this->baseGeneralSettingsPayload([
            'custom_script' => '<script>window.changed=true;</script>',
        ]))->assertRedirect();

        $this->assertSame('<script>window.changed=true;</script>', AppConfig::where('setting', 'custom_script')->value('value'));
        $this->assertSame('keep-keyword', $this->readEnvValue('APP_KEYWORD'));
        $this->assertSame('Keep Company', $this->readEnvValue('APP_FOOTER_COMPANY_NAME'));
        $this->assertSame('Keep Copyright', $this->readEnvValue('APP_FOOTER_COPYRIGHT_TEXT'));
    }

    public function test_explicit_empty_app_keyword_clears_it(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedCrossSectionKeepValues();
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload([
            'app_keyword' => '',
        ]))->assertRedirect();

        $this->assertSame('', $this->readEnvValue('APP_KEYWORD'));
        // Untouched sections still preserved even on an explicit clear.
        $this->assertSame('Keep Company', $this->readEnvValue('APP_FOOTER_COMPANY_NAME'));
        $this->assertSame('<script>window.keepMe=true;</script>', AppConfig::where('setting', 'custom_script')->value('value'));
    }

    public function test_explicit_empty_footer_company_name_clears_it(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedCrossSectionKeepValues();
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload([
            'footer_company_name' => '',
        ]))->assertRedirect();

        $this->assertSame('', $this->readEnvValue('APP_FOOTER_COMPANY_NAME'));
        $this->assertSame('keep-keyword', $this->readEnvValue('APP_KEYWORD'));
    }

    public function test_explicit_empty_footer_copyright_text_clears_it(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedCrossSectionKeepValues();
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload([
            'footer_copyright_text' => '',
        ]))->assertRedirect();

        $this->assertSame('', $this->readEnvValue('APP_FOOTER_COPYRIGHT_TEXT'));
        $this->assertSame('keep-keyword', $this->readEnvValue('APP_KEYWORD'));
    }

    public function test_explicit_empty_custom_script_clears_it(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedCrossSectionKeepValues();
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post('/admin/settings', $this->baseGeneralSettingsPayload([
            'custom_script' => '',
        ]))->assertRedirect();

        $this->assertSame('', AppConfig::where('setting', 'custom_script')->value('value'));
        $this->assertSame('keep-keyword', $this->readEnvValue('APP_KEYWORD'));
        $this->assertSame('Keep Company', $this->readEnvValue('APP_FOOTER_COMPANY_NAME'));
    }
}
