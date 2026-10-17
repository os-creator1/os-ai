<?php

namespace Tests\Feature\ErrorPages;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The platform's 404 and 429 pages are one shell (components/error-page):
 * same layout everywhere, only copy and the context card change, status codes
 * and headers are untouched, and JSON clients never see HTML.
 */
class UniversalErrorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function (): void {
            Route::get('/__error-test/abort-404', fn () => abort(404, 'SECRET-INTERNAL-MESSAGE'));
            Route::get('/__error-test/model', fn () => throw (new ModelNotFoundException())->setModel(\stdClass::class, [1]));
            Route::get('/__error-test/limited', fn () => abort(429, 'Too Many Attempts.', ['Retry-After' => '42', 'X-RateLimit-Limit' => '10']));
            Route::get('/__error-test/forbidden', fn () => abort(403));
            Route::get('/customer-like/{x}', fn () => abort(404))->name('customer.__error_test');
        });
    }

    public function test_a_normal_404_uses_the_universal_template(): void
    {
        $page = $this->get('/__error-test/abort-404');

        $page->assertNotFound()
            ->assertSee('We can\'t find that page')
            ->assertSee('data-error-code="404"', false)
            ->assertSee('Error 404')
            ->assertSee('Back to Home')
            ->assertSee('Go back')
            ->assertDontSee('Opening a document someone sent you?')
            ->assertDontSee('SECRET-INTERNAL-MESSAGE');
    }

    public function test_an_unmatched_url_and_a_missing_model_use_the_same_template(): void
    {
        $this->get('/no/such/page/anywhere')->assertNotFound()->assertSee('data-error-code="404"', false);
        $this->get('/__error-test/model')->assertNotFound()->assertSee('data-error-code="404"', false);
    }

    public function test_a_forbidden_abort_is_still_rendered_as_it_was(): void
    {
        $this->get('/__error-test/forbidden')->assertNotFound();
    }

    public function test_an_invalid_public_document_link_uses_the_shell_with_the_document_card(): void
    {
        $page = $this->get(route('public.documents.show', ['uid' => 'nope', 'token' => str_repeat('a', 64)]));

        $page->assertNotFound()
            ->assertSee('data-error-code="404"', false)
            ->assertSee('Opening a document someone sent you?')
            ->assertSee('Signing links can expire. Ask the business that sent it for a new link.');
    }

    public function test_a_429_is_a_429_with_its_headers_and_its_own_copy(): void
    {
        $page = $this->get('/__error-test/limited');

        $page->assertStatus(429)
            ->assertHeader('Retry-After', '42')
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertSee('data-error-code="429"', false)
            ->assertSee('Too many attempts')
            ->assertSee('Please wait a moment before trying again.')
            ->assertSee('Error 429')
            ->assertDontSee('Page Not Found')
            ->assertDontSee('We can\'t find that page');
    }

    public function test_the_logo_is_size_constrained_and_no_legacy_imagery_is_referenced(): void
    {
        foreach (['/__error-test/abort-404', '/__error-test/limited'] as $url) {
            $body = (string) $this->get($url)->getContent();

            $this->assertStringContainsString('.eb-logo img{display:block;height:1.75rem;width:auto;max-width:11rem', $body);
            $this->assertStringNotContainsString('pages/error', $body);
            $this->assertStringNotContainsString('error.svg', $body);
            $this->assertStringNotContainsString('error-dark', $body);
            $this->assertStringNotContainsStringIgnoringCase('ultimatesms', $body);
            $this->assertSame(0, preg_match('/<img[^>]+(error|illustration)/i', $body));
        }
    }

    public function test_a_guest_goes_home_to_the_public_login_and_a_recipient_to_the_site_root(): void
    {
        $this->get('/__error-test/abort-404')->assertSee('href="' . route('login') . '"', false);
        $this->get(route('public.documents.show', ['uid' => 'nope', 'token' => str_repeat('b', 64)]))
            ->assertSee('href="' . url('/') . '"', false);
    }

    public function test_json_clients_still_receive_the_json_envelope(): void
    {
        $this->getJson('/__error-test/abort-404')
            ->assertNotFound()
            ->assertExactJson(['status' => 'error', 'message' => 'SECRET-INTERNAL-MESSAGE']);

        $this->getJson('/__error-test/limited')
            ->assertStatus(429)
            ->assertJson(['status' => 'error']);
    }
}
