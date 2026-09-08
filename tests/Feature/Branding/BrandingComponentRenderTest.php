<?php

namespace Tests\Feature\Branding;

use App\Library\Branding\BrandingPresenter;
use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Design System M2 Platform Branding contract §10/§9 item 3. HTTP-level:
 * login, register, verify, and every errors/* view render 200, contain
 * no literal "Ultimate SMS"/"Codeglen" string, and never render an
 * <img> tag with an empty src attribute for the logo/favicon/
 * illustration components.
 */
class BrandingComponentRenderTest extends TestCase
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

    private function assertCleanBrandingHtml(string $html): void
    {
        $this->assertStringNotContainsStringIgnoringCase('Ultimate SMS', $html);
        $this->assertStringNotContainsStringIgnoringCase('Codeglen', $html);
        $this->assertDoesNotMatchRegularExpression('/<img[^>]+src=["\']\s*["\']/', $html);
    }

    public function test_login_page_renders_clean(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $this->assertCleanBrandingHtml($response->getContent());
    }

    public function test_verify_page_renders_clean(): void
    {
        $response = $this->get(route('verify.index'));

        $response->assertOk();
        $this->assertCleanBrandingHtml($response->getContent());
    }

    public function test_every_error_view_renders_clean(): void
    {
        // $errors is normally auto-shared by Laravel's own
        // ShareErrorsFromSession middleware for a real HTTP response;
        // rendering the view directly (bypassing the HTTP pipeline, to
        // avoid needing to trigger a genuine framework exception for
        // each status) requires supplying the same empty bag by hand.
        $errors = new \Illuminate\Support\ViewErrorBag();

        foreach (['401', '403', '404', '419', '429', '500', '503'] as $code) {
            $html = view("errors.{$code}", ['exception' => new \Exception('test'), 'errors' => $errors])->render();

            $this->assertStringNotContainsStringIgnoringCase('Ultimate SMS', $html, "errors.{$code}");
            $this->assertStringNotContainsStringIgnoringCase('Codeglen', $html, "errors.{$code}");
            $this->assertDoesNotMatchRegularExpression('/<img[^>]+src=["\']\s*["\']/', $html, "errors.{$code}");
        }
    }

    /**
     * Design System M2 Platform Branding correction round 2. Every auth
     * surface must fall back to its own bundled illustration (not a
     * single generic one) when app.auth_illustration is unconfigured.
     */
    /**
     * Customer Experience Slice 2 (contract §9.1, T-AUTH-1) supersedes the
     * Platform Branding correction-round-2 rule above: with
     * app.auth_illustration unconfigured every auth surface renders the
     * neutral AI Business OS typographic panel and references no bundled
     * Vuexy illustration at all.
     */
    public function test_auth_surfaces_render_the_neutral_panel_when_unconfigured(): void
    {
        config(['app.auth_illustration' => null]);
        Cache::forget(BrandingPresenter::CACHE_KEY);

        $user = $this->createAuthUser();

        $httpCases = [
            route('login'),
            route('verify.index'),
            route('password.request'),
            route('password.reset', 'dummy-token'),
            route('sub_account.accept', 'dummy-token'),
        ];

        foreach ($httpCases as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $this->assertStringContainsString('data-role="auth-brand-panel"', $response->getContent(), $url);
            $this->assertStringNotContainsString('images/pages/', $response->getContent(), $url);
        }

        $verifyResponse = $this->actingAs($user)->get(route('verification.notice'));
        $verifyResponse->assertOk();
        $this->assertStringContainsString('data-role="auth-brand-panel"', $verifyResponse->getContent());
        $this->assertStringNotContainsString('images/pages/', $verifyResponse->getContent());

        // register.blade.php is rendered directly rather than through
        // RegisterController::showRegistrationForm(), which depends on a
        // live geo-IP lookup unrelated to branding and would make this
        // test flaky/network-dependent.
        $registerHtml = view('auth.register', $this->registerViewData())->render();
        $this->assertStringContainsString('data-role="auth-brand-panel"', $registerHtml);
        $this->assertStringNotContainsString('images/pages/', $registerHtml);
    }

    /**
     * Customer Experience Slice 2: the configured asset must be a real
     * public file under images/branding/ — the seam never emits a
     * reference it cannot serve — so this test writes one for its run.
     */
    public function test_auth_illustrations_render_the_configured_asset_when_set(): void
    {
        $configured = 'images/branding/auth_illustration/test-illustration.png';
        $this->writeTemporaryPublicPng($configured);
        config(['app.auth_illustration' => $configured]);
        Cache::forget(BrandingPresenter::CACHE_KEY);

        $user = $this->createAuthUser();

        foreach ([
            route('login'),
            route('verify.index'),
            route('password.request'),
            route('password.reset', 'dummy-token'),
            route('sub_account.accept', 'dummy-token'),
        ] as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $this->assertStringContainsString($configured, $response->getContent(), $url);
        }

        $verifyResponse = $this->actingAs($user)->get(route('verification.notice'));
        $verifyResponse->assertOk();
        $this->assertStringContainsString($configured, $verifyResponse->getContent());

        $registerHtml = view('auth.register', $this->registerViewData())->render();
        $this->assertStringContainsString($configured, $registerHtml);
    }

    /** @var list<string> */
    private array $temporaryPublicFiles = [];

    private function writeTemporaryPublicPng(string $relativePath): void
    {
        $fullPath = public_path($relativePath);

        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0775, true);
        }

        // A real 1×1 transparent PNG, so the seam's existence check passes.
        file_put_contents($fullPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $this->temporaryPublicFiles[] = $fullPath;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPublicFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        Cache::forget(BrandingPresenter::CACHE_KEY);
        parent::tearDown();
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
            'email' => 'branding-illustration-test' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }
}
