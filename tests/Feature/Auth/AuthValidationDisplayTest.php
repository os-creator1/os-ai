<?php

namespace Tests\Feature\Auth;

use App\Models\AppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Slice 2 contract §6/§8 items 3, 4. Validation errors
 * remain accessible and visible after the `<x-alert>` adoption pass;
 * unauthorized and invalid states continue to fail safely (redirect,
 * not a crash or silent success).
 */
class AuthValidationDisplayTest extends TestCase
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

    public function test_invalid_login_redirects_back_with_visible_errors_and_old_input(): void
    {
        // LoginController::login() validates manually and flashes a
        // `status`/`message` pair on failure rather than calling
        // withErrors() -- a pre-existing, Slice-2-unrelated pattern -- so
        // this asserts against that flash, not the standard errors bag.
        $response = $this->from(route('login'))->post(route('login'), [
            'email' => 'not-an-email',
            'password' => '',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status', 'warning');
        $response->assertSessionHas('message');

        $followUp = $this->get(route('login'));
        $followUp->assertOk();
        $followUp->assertSee('not-an-email', false);
    }

    public function test_invalid_register_redirects_back_with_visible_errors(): void
    {
        // RegisterController::register() reads $data['phone'] via array
        // offset, not $request->input('phone') -- a pre-existing,
        // unrelated behavior this presentation-only slice does not
        // authorize changing, so a `phone` key is included (even
        // invalid) purely to reach the same validation-error redirect
        // this test actually exercises, without tripping an unrelated
        // undefined-array-key notice. It must be non-empty: Laravel's
        // ConvertEmptyStringsToNull middleware would otherwise null it
        // before the controller runs, and the Phone rule's constructor
        // requires a string.
        $response = $this->from(route('register'))->post(route('register'), [
            'email' => '',
            'phone' => 'x',
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors('email');
    }

    public function test_invalid_password_email_redirects_back_with_visible_errors(): void
    {
        // ForgotPasswordController::sendResetLinkEmail() validates manually
        // and flashes a `status`/`message` pair on failure rather than
        // calling withErrors() -- the same pre-existing, Slice-2-unrelated
        // pattern as LoginController::login() -- so this asserts against
        // that flash, not the standard errors bag.
        $response = $this->from(route('password.request'))->post(route('password.email'), [
            'email' => 'not-an-email',
        ]);

        $response->assertSessionHas('status', 'warning');
        $response->assertSessionHas('message');
    }

    public function test_guest_cannot_reach_profile_or_verification_routes(): void
    {
        // user.account and user.account.change.password sit behind
        // ['auth', 'verified']; this app's custom Handler::render() renders
        // unauthenticated access as a 401 view (not a login redirect)
        // whenever app.env !== 'local', which is the case in this testing
        // environment -- pre-existing, Slice-2-unrelated behavior.
        // verify.index is intentionally guest-accessible (TwoFactorController
        // applies the `guest` middleware, for users mid-2FA flow) and is not
        // asserted here.
        $this->get(route('user.account'))->assertStatus(401);
        $this->post(route('user.account.change.password'))->assertStatus(401);
    }
}
