<?php

namespace Tests\Feature\Auth;

use App\Library\Branding\BrandingPresenter;
use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Customer Experience Slice 2 — T-AUTH-1 (contract §9.1, §24; brief §3-§5,
 * §10, §11). Every customer authentication screen renders the neutral
 * AI Business OS identity (or the owner's platform name) through the
 * branding seam, references no inherited login-v2*.svg / Vuexy
 * illustration, never renders a broken image, and meets the
 * accessibility floor for a sign-in form. Rendered-DOM assertions, not
 * source-string ones.
 */
class AuthNeutralBrandingTest extends TestCase
{
    use RefreshDatabase;

    private const INHERITED_MARKERS = [
        'login-v2',
        'images/pages/',
        'create-account.svg',
        'forgot-password-v2',
        'reset-password-v2',
        'two-steps-verification',
        'not-authorized',
        'Ultimate SMS',
        'Vuexy',
        'Codeglen',
        'Test Title',
        'Keep Company',
        'Keep Copyright',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        config(['app.name' => 'AI Business OS', 'app.auth_illustration' => null]);
        Cache::forget(BrandingPresenter::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(BrandingPresenter::CACHE_KEY);
        parent::tearDown();
    }

    /**
     * Every real customer authentication screen, keyed by a label, as
     * rendered HTML.
     *
     * @return array<string, string>
     */
    private function renderedScreens(): array
    {
        $screens = [
            'login' => $this->get(route('login'))->assertOk(),
            'forgot-password' => $this->get(route('password.request'))->assertOk(),
            'reset-password' => $this->get(route('password.reset', 'dummy-token'))->assertOk(),
            'two-factor' => $this->get(route('verify.index'))->assertOk(),
            'two-factor-backup' => $this->get(route('verify.backup'))->assertOk(),
            'accept-invitation' => $this->get(route('sub_account.accept', 'dummy-token'))->assertOk(),
        ];

        $html = array_map(fn (TestResponse $response) => $response->getContent(), $screens);

        $html['verify-email'] = $this->actingAs($this->createAuthUser())
            ->get(route('verification.notice'))
            ->assertOk()
            ->getContent();

        // register.blade.php is rendered directly: RegisterController's
        // geo-IP + seeded-country gate is unrelated to branding.
        $html['register'] = view('auth.register', $this->registerViewData())->render();

        return $html;
    }

    public function test_every_auth_screen_renders_the_neutral_ai_business_os_identity(): void
    {
        foreach ($this->renderedScreens() as $screen => $html) {
            $this->assertStringContainsString('AI Business OS', $html, "{$screen} must carry the product identity.");
            $this->assertStringContainsString('data-role="auth-brand-panel"', $html, "{$screen} renders the typographic panel.");
            $this->assertStringContainsString('data-brand-source="neutral"', $html, "{$screen} is on the neutral identity.");
            $this->assertMatchesRegularExpression('/<title>[^<]*AI Business OS<\/title>/', $html, "{$screen} names the brand in its document title.");
        }
    }

    public function test_no_auth_screen_references_an_inherited_illustration_or_vendor_string(): void
    {
        foreach ($this->renderedScreens() as $screen => $html) {
            foreach (self::INHERITED_MARKERS as $marker) {
                $this->assertStringNotContainsStringIgnoringCase($marker, $html, "{$screen} still references \"{$marker}\".");
            }
        }
    }

    public function test_no_auth_screen_renders_a_broken_or_empty_image(): void
    {
        foreach ($this->renderedScreens() as $screen => $html) {
            $this->assertDoesNotMatchRegularExpression('/<img[^>]+src=["\']\s*["\']/', $html, "{$screen} has an empty image source.");

            preg_match_all('/<img[^>]+src="([^"]+)"/', $html, $matches);

            foreach ($matches[1] as $src) {
                $path = parse_url($src, PHP_URL_PATH) ?? '';

                if (! str_starts_with($path, '/images/')) {
                    continue;
                }

                $this->assertFileExists(public_path(ltrim($path, '/')), "{$screen} references a missing image: {$src}");
            }
        }
    }

    public function test_the_neutral_panel_works_with_no_image_at_all(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        preg_match('/<div[^>]+data-role="auth-brand-panel".*?<\/div>\s*<\/div>/s', $html, $panel);

        $this->assertNotEmpty($panel, 'The brand panel is rendered.');
        $this->assertStringNotContainsString('<img', $panel[0], 'The neutral panel depends on no image.');
        $this->assertStringContainsString('AI Business OS', $panel[0]);
        $this->assertStringContainsString('Contacts', $panel[0]);
        $this->assertStringContainsString('Google Business Profile', $panel[0]);
    }

    public function test_every_auth_screen_has_one_primary_heading_and_labelled_inputs(): void
    {
        foreach ($this->renderedScreens() as $screen => $html) {
            $this->assertSame(1, preg_match_all('/<h1\b/i', $html), "{$screen} must have exactly one <h1>.");

            preg_match_all('/<input\b[^>]*\btype="(?:email|password|number|text)"[^>]*>/i', $html, $inputs);

            foreach ($inputs[0] as $input) {
                if (! preg_match('/\bid="([^"]+)"/', $input, $id)) {
                    continue;
                }

                if (str_contains($input, 'type="hidden"')) {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/<label\b[^>]*\bfor="' . preg_quote($id[1], '/') . '"/',
                    $html,
                    "{$screen}: input #{$id[1]} has no visible <label for>."
                );
            }
        }
    }

    public function test_password_visibility_controls_are_buttons_with_an_accessible_name_and_state(): void
    {
        $screens = $this->renderedScreens();
        $withToggles = 0;

        foreach ($screens as $screen => $html) {
            preg_match_all('/<button\b[^>]*data-role="password-toggle"[^>]*>/', $html, $toggles);
            preg_match_all('/<span\b[^>]*class="[^"]*input-group-text[^"]*"/', $html, $legacySpans);

            $this->assertCount(0, $legacySpans[0], "{$screen} still renders a non-focusable password toggle.");

            foreach ($toggles[0] as $toggle) {
                $withToggles++;
                $this->assertStringContainsString('type="button"', $toggle);
                $this->assertStringContainsString('aria-pressed="false"', $toggle);
                $this->assertMatchesRegularExpression('/aria-label="[^"]+"/', $toggle);
                $this->assertMatchesRegularExpression('/aria-controls="[^"]+"/', $toggle);
            }
        }

        $this->assertGreaterThanOrEqual(5, $withToggles, 'login, reset, invitation and register carry password toggles.');
        $this->assertStringContainsString('data-role="password-toggle"', $screens['login']);
        $this->assertStringContainsString('[data-role="password-toggle"]', $screens['login'], 'The guest layout ships the state script.');
    }

    public function test_no_auth_screen_uses_a_positive_tabindex_that_would_break_visual_order(): void
    {
        foreach ($this->renderedScreens() as $screen => $html) {
            $this->assertDoesNotMatchRegularExpression('/tabindex="[1-9]/', $html, "{$screen} forces a tab order.");
        }
    }

    /**
     * The sign-in controller reports a failed attempt as a flashed message
     * (a toast, out of this slice's reach); the form renders it as an
     * inline summary with role="alert" that the email field points at, so
     * the error is understandable and programmatically associated.
     */
    public function test_a_failed_sign_in_renders_an_understandable_error_summary_tied_to_the_field(): void
    {
        $this->from(route('login'))->post(route('login'), ['email' => 'not-an-email', 'password' => 'x'])
            ->assertRedirect(route('login'));

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div[^>]+(?:id="auth-flash"[^>]*role="alert"|role="alert"[^>]*id="auth-flash")/', $html);
        $this->assertStringContainsString('The email must be a valid email address.', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+id="email"[^>]+aria-describedby="[^"]*auth-flash[^"]*"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+id="email"[^>]+aria-invalid="true"/', $html);
        $this->assertStringContainsString('value="not-an-email"', $html, 'Old input is repopulated.');
        $this->assertStringNotContainsString('locale.', $html);
    }

    /**
     * A field-level validation error (a ViewErrorBag entry, as the
     * framework's validate() produces) renders as an alert the input's
     * aria-describedby names, on every form that owns the field.
     */
    public function test_a_field_validation_error_is_associated_with_its_field(): void
    {
        $errors = new \Illuminate\Support\ViewErrorBag();
        $errors->put('default', new \Illuminate\Support\MessageBag(['email' => ['The email must be a valid email address.'], 'password' => ['The password is required.']]));

        foreach (['auth.passwords.email' => 'email', 'auth.passwords.reset' => 'password'] as $view => $field) {
            $html = view($view, ['errors' => $errors, 'token' => 'dummy-token', 'email' => null])->render();

            $this->assertMatchesRegularExpression('/<div[^>]+(?:id="' . $field . '-error"[^>]*role="alert"|role="alert"[^>]*id="' . $field . '-error")/', $html, $view);
            $this->assertMatchesRegularExpression('/<input[^>]+id="' . $field . '"[^>]+aria-describedby="' . $field . '-error"/', $html, $view);
            $this->assertMatchesRegularExpression('/<input[^>]+id="' . $field . '"[^>]+aria-invalid="true"/', $html, $view);
        }
    }

    public function test_a_sent_reset_link_is_announced_inline_as_a_status(): void
    {
        $html = $this->withSession(['status' => 'success', 'message' => 'We have emailed your password reset link!'])
            ->get(route('password.request'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div[^>]+(?:id="auth-flash"[^>]*role="status"|role="status"[^>]*id="auth-flash")/', $html);
        $this->assertStringContainsString('We have emailed your password reset link!', $html);
    }

    public function test_the_owner_platform_name_is_respected_when_configured(): void
    {
        config(['app.name' => 'Harbor Lane Platform']);
        Cache::forget(BrandingPresenter::CACHE_KEY);

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('Harbor Lane Platform', $html);
        $this->assertStringContainsString('data-brand-source="platform"', $html);
        $this->assertStringNotContainsString('login-v2', $html);
        $this->assertMatchesRegularExpression('/<title>[^<]*Harbor Lane Platform<\/title>/', $html);
    }

    public function test_an_owner_configured_illustration_renders_as_a_decorative_image(): void
    {
        config(['app.auth_illustration' => 'images/branding/default-logo.svg']);
        Cache::forget(BrandingPresenter::CACHE_KEY);

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<img[^>]+src="[^"]*images\/branding\/default-logo\.svg"[^>]*alt=""[^>]*role="presentation"/', $html);
        $this->assertStringNotContainsString('data-role="auth-brand-panel"', $html);
        $this->assertStringNotContainsString('login-v2', $html);
    }

    public function test_the_register_screen_keeps_its_form_contract(): void
    {
        $html = view('auth.register', $this->registerViewData())->render();

        foreach (['name="email"', 'name="password"', 'name="password_confirmation"', 'name="first_name"', 'name="phone"', 'name="address"', 'name="city"'] as $field) {
            $this->assertStringContainsString($field, $html);
        }

        $this->assertStringContainsString('action="' . route('register') . '"', $html);
        $this->assertStringContainsString('<x-ds-icon', file_get_contents(base_path('resources/views/auth/register.blade.php')));
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

    private function registerViewData(): array
    {
        return [
            'pageConfigs' => ['blankPage' => true],
            'languages' => collect(),
            'plans' => collect(),
            'payment_methods' => collect(),
            'countries' => collect(),
            'errors' => new \Illuminate\Support\ViewErrorBag(),
        ];
    }

    private function createAuthUser(): User
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'auth-branding-test' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }
}
