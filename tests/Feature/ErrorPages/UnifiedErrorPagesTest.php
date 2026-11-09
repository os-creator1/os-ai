<?php

namespace Tests\Feature\ErrorPages;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every customer-facing error status renders the ONE unified page
 * (components/error-page.blade.php): correct status, fixed copy, safe
 * buttons, no legacy illustration, and never the exception's own text.
 */
class UnifiedErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'SECRET-EXCEPTION-TEXT /var/www/app/Secret.php SELECT * FROM users';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function (): void {
            Route::get('/__unified/401', fn () => throw new AuthenticationException(self::SECRET));
            Route::get('/__unified/denied', fn () => throw new AuthorizationException(self::SECRET));
            Route::get('/__unified/403', fn () => abort(403, self::SECRET));
            Route::get('/__unified/404', fn () => abort(404, self::SECRET));
            Route::get('/__unified/419', fn () => throw new TokenMismatchException(self::SECRET));
            Route::get('/__unified/429', fn () => abort(429, self::SECRET, ['Retry-After' => '30', 'X-RateLimit-Limit' => '5']));
            Route::get('/__unified/500', fn () => throw new \RuntimeException(self::SECRET));
            Route::get('/__unified/abort-500', fn () => abort(500, self::SECRET));
            Route::get('/__unified/503', fn () => abort(503, self::SECRET, ['Retry-After' => '60']));
        });
    }

    /** @return array<string, array{0: string, 1: int, 2: string, 3: string}> */
    public static function pages(): array
    {
        return [
            '401' => ['/__unified/401', 401, 'Please log in', 'Your session has ended, or you need to sign in to continue.'],
            '404' => ['/__unified/404', 404, 'Page not found', 'This page doesn\'t exist or isn\'t available to your account.'],
            '419' => ['/__unified/419', 419, 'Your session timed out', 'For your security, we couldn\'t finish that request. Log in again to pick up where you left off.'],
            '429' => ['/__unified/429', 429, 'Too many requests', 'You\'ve made too many requests. Please try again shortly.'],
            '500' => ['/__unified/500', 500, 'Something went wrong', 'We couldn\'t complete your request. Please try again.'],
            'abort 500' => ['/__unified/abort-500', 500, 'Something went wrong', 'We couldn\'t complete your request. Please try again.'],
            '503' => ['/__unified/503', 503, 'Temporarily unavailable', 'MotionGrove is temporarily unavailable. Please try again shortly.'],
        ];
    }

    /** @dataProvider pages */
    public function test_each_status_uses_the_unified_layout_with_its_copy(string $url, int $status, string $title, string $description): void
    {
        $page = $this->get($url);

        $page->assertStatus($status)
            ->assertSee('data-error-code="' . ($status) . '"', false)
            ->assertSee('data-role="status-icon"', false)
            ->assertSee('class="eb-logo"', false)
            ->assertSee($title)
            ->assertSee($description)
            ->assertSee('Error ' . $status);
    }

    /** @dataProvider pages */
    public function test_no_legacy_illustration_oversized_logo_or_raw_exception_text(string $url): void
    {
        $body = (string) $this->get($url)->getContent();

        $this->assertStringNotContainsString('misc-wrapper', $body);
        $this->assertStringNotContainsString('page-misc', $body);
        $this->assertStringNotContainsString('pages/error', $body);
        $this->assertStringNotContainsString('error.svg', $body);
        $this->assertSame(0, preg_match('/<img[^>]+(error|illustration)/i', $body));
        $this->assertStringContainsString('.eb-logo img{display:block;height:1.75rem;width:auto;max-width:11rem', $body);
        $this->assertStringNotContainsString('eb-code', $body, 'No giant error-code graphic.');
        foreach (['SECRET-EXCEPTION-TEXT', '/var/www', 'SELECT *', 'RuntimeException', 'TokenMismatch', 'Stack trace', 'CSRF token mismatch'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_the_401_page_offers_log_in_to_the_login_route(): void
    {
        $this->get('/__unified/401')
            ->assertSee('data-role="primary-action" href="' . route('login') . '"', false)
            ->assertSee('Log in');
    }

    public function test_the_419_page_offers_log_in_again_and_the_homepage(): void
    {
        $this->get('/__unified/419')
            ->assertSee('data-role="primary-action" href="' . route('login') . '"', false)
            ->assertSee('Log in again')
            ->assertSee('data-role="secondary-action" href="' . url('/') . '"', false)
            ->assertSee('Go to homepage');
    }

    public function test_a_denied_guest_is_asked_to_log_in_and_a_signed_in_user_is_told_they_lack_access(): void
    {
        $this->get('/__unified/denied')->assertUnauthorized()->assertSee('Please log in');

        $user = User::factory()->create();
        $page = $this->actingAs($user)->get('/__unified/denied');

        $page->assertUnauthorized() // this application's long-standing status for a denial is kept
            ->assertSee('You don\'t have access')
            ->assertSee('Your account doesn\'t have permission to open this page.')
            ->assertDontSee('Please log in')
            ->assertDontSee('SECRET-EXCEPTION-TEXT');
    }

    public function test_an_explicit_403_abort_stays_concealed_as_a_404(): void
    {
        $this->get('/__unified/403')->assertNotFound()->assertDontSee('SECRET-EXCEPTION-TEXT');
    }

    public function test_the_403_template_renders_with_a_safe_destination(): void
    {
        $guest = (string) view('errors.403')->render();
        $this->assertStringContainsString('You don&#039;t have access', $guest);
        $this->assertStringContainsString('Go to homepage', $guest);
        $this->assertStringContainsString('href="' . url('/') . '"', $guest);

        $this->actingAs(User::factory()->create());
        $signedIn = (string) view('errors.403')->render();
        $this->assertStringContainsString('data-error-code="403"', $signedIn);
        $this->assertStringContainsString('Go to ', $signedIn);
    }

    public function test_rate_limit_and_maintenance_headers_are_preserved(): void
    {
        $this->get('/__unified/429')->assertStatus(429)->assertHeader('Retry-After', '30')->assertHeader('X-RateLimit-Limit', '5');
        $this->get('/__unified/503')->assertStatus(503)->assertHeader('Retry-After', '60');
    }

    public function test_the_maintenance_template_renders_standalone(): void
    {
        $html = (string) view('errors.503')->render();

        $this->assertStringContainsString('Temporarily unavailable', $html);
        $this->assertStringContainsString('Error 503', $html);
    }

    public function test_json_contracts_are_unchanged(): void
    {
        $this->getJson('/__unified/401')->assertUnauthorized()->assertExactJson(['status' => 'error', 'message' => self::SECRET]);
        $this->getJson('/__unified/404')->assertNotFound()->assertJson(['status' => 'error']);
        $this->getJson('/__unified/429')->assertStatus(429)->assertHeader('Retry-After', '30')->assertJson(['status' => 'error']);
        $this->getJson('/__unified/500')->assertStatus(500)->assertJson(['status' => 'error'])->assertDontSee('SECRET-EXCEPTION-TEXT');
    }

    public function test_unauthenticated_admin_platform_owner_is_denied_without_leaking(): void
    {
        $page = $this->get('/admin/platform-owner');

        $page->assertUnauthorized()
            ->assertSee('Please log in')
            ->assertSee('data-role="primary-action" href="' . route('login') . '"', false)
            ->assertDontSee('misc-wrapper', false);
    }

    public function test_a_signed_in_user_without_permission_cannot_open_the_platform_owner_area(): void
    {
        $user = User::factory()->create();
        $status = $this->actingAs($user)->get('/admin/platform-owner')->getStatusCode();

        $this->assertContains($status, [401, 403, 404, 302], 'A user without the permission must stay denied.');
        $this->assertNotSame(200, $status);
    }

    public function test_logout_is_post_only_and_a_get_changes_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('logout'))->assertNotFound();
        $this->assertAuthenticatedAs($user);

        $this->actingAs($user)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_login_destination_does_not_loop_for_a_guest(): void
    {
        $this->get(route('login'))->assertOk();
    }
}
