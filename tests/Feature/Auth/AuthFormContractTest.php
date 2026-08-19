<?php

namespace Tests\Feature\Auth;

use App\Models\AppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Slice 2 contract §6/§8 item 2. Every form's action,
 * method, CSRF protection, and exact input `name` attributes must
 * survive the component-adoption pass byte-for-byte -- this is a
 * behavioral-contract proof, not a visual one, since §6's own
 * preservation requirement is meaningless without it.
 */
class AuthFormContractTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_login_form_preserves_its_exact_behavioral_contract(): void
    {
        $html = $this->get(route('login'))->getContent();

        $this->assertStringContainsString('action="' . route('login') . '"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertMatchesRegularExpression('/name="_token"/', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="remember"', $html);
    }

    public function test_register_form_preserves_its_exact_behavioral_contract(): void
    {
        // RegisterController::showRegistrationForm() carries a pre-existing,
        // Slice-2-unrelated geo-IP + seeded-country-table gate that redirects
        // instead of rendering in a bare test database, so this asserts
        // against the view source directly (the same approach
        // AuthDesignSystemContentTest already uses) rather than depending on
        // that unrelated gate being open.
        $source = file_get_contents(base_path('resources/views/auth/register.blade.php'));

        $this->assertStringContainsString("route('register')", $source);
        $this->assertStringContainsString('method="POST"', $source);
        $this->assertStringContainsString('@csrf', $source);
        $this->assertStringContainsString('name="email"', $source);
        $this->assertStringContainsString('name="password"', $source);
        $this->assertStringContainsString('name="password_confirmation"', $source);
        $this->assertStringContainsString('name="first_name"', $source);
        $this->assertStringContainsString('name="phone"', $source);
        $this->assertStringContainsString('name="address"', $source);
        $this->assertStringContainsString('name="city"', $source);
    }

    public function test_password_email_form_preserves_its_exact_behavioral_contract(): void
    {
        $html = $this->get(route('password.request'))->getContent();

        $this->assertStringContainsString('action="' . route('password.email') . '"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertMatchesRegularExpression('/name="_token"/', $html);
        $this->assertStringContainsString('name="email"', $html);
    }
}
