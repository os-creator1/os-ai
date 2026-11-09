<?php

namespace Tests\Feature\ErrorPages;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The maintenance "notify me" flow, exercised through a REAL maintenance mode
 * (artisan down --render) with CSRF verification genuinely enforced.
 *
 * The prerendered page is produced by the CLI outside any visitor session, so
 * it cannot carry a valid CSRF token. It links to a live, session-backed form
 * (maintenance.notify.form) whose token the POST accepts.
 */
class MaintenanceNotifyErrorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel skips CSRF verification under unit tests; switch it back ON for this class so a token
        // that is not valid for the visitor's session is actually refused. CSRF is never disabled.
        $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    protected function tearDown(): void
    {
        Artisan::call('up');
        parent::tearDown();
    }

    private function goDown(): void
    {
        Artisan::call('down', ['--render' => 'errors.503', '--retry' => 60]);
    }

    /** @return array<string, string> the session cookie of a response, ready to replay on the next request */
    private function cookiesOf($response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }

    public function test_the_prerendered_page_links_to_the_live_form_and_embeds_no_token(): void
    {
        $this->goDown();

        $page = $this->get('/some/page');

        $page->assertStatus(503)
            ->assertSee('Temporarily unavailable')
            ->assertSee('data-role="maintenance-notify-link"', false)
            ->assertSee(route('maintenance.notify.form'), false)
            ->assertDontSee('name="_token"', false)
            ->assertDontSee('<form', false)
            ->assertDontSee('misc-wrapper', false);
    }

    public function test_the_live_form_submits_successfully_during_maintenance(): void
    {
        $this->goDown();

        $form = $this->get(route('maintenance.notify.form'));
        $form->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertSee('data-role="maintenance-notify-form"', false)
            ->assertSee('action="' . route('maintenance.notify') . '"', false);

        preg_match('/name="_token" value="([^"]+)"/', $form->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'The live form must carry a session token.');

        $response = $this->call('POST', route('maintenance.notify'), ['_token' => $m[1], 'email' => 'visitor@example.com'], $this->cookiesOf($form));

        $response->assertRedirect('/?notified=1');
        $this->assertSame(1, DB::table('maintenance_notifications')->where('email', 'visitor@example.com')->count());
    }

    public function test_csrf_is_still_enforced_a_post_without_a_valid_token_is_refused(): void
    {
        $this->goDown();

        $this->post(route('maintenance.notify'), ['email' => 'attacker@example.com'])->assertStatus(419);
        $this->post(route('maintenance.notify'), ['_token' => 'not-a-real-token', 'email' => 'attacker@example.com'])->assertStatus(419);

        $this->assertSame(0, DB::table('maintenance_notifications')->count());
    }

    public function test_validation_is_preserved(): void
    {
        $this->goDown();

        $form = $this->get(route('maintenance.notify.form'));
        preg_match('/name="_token" value="([^"]+)"/', $form->getContent(), $m);

        $this->call('POST', route('maintenance.notify'), ['_token' => $m[1], 'email' => 'not-an-email'], $this->cookiesOf($form))
            ->assertSessionHasErrors('email');
        $this->assertSame(0, DB::table('maintenance_notifications')->count());
    }

    public function test_the_confirmation_message_renders_on_the_live_form(): void
    {
        $this->goDown();

        $this->get(route('maintenance.notify.form', ['notified' => 1]))
            ->assertSee('data-role="maintenance-notified"', false)
            ->assertSee('You will be notified when we are back online.');
    }

    public function test_nothing_is_offered_and_the_endpoints_refuse_outside_maintenance(): void
    {
        $html = (string) view('errors.503')->render();
        $this->assertStringContainsString('Temporarily unavailable', $html);
        $this->assertStringNotContainsString('maintenance-notify', $html);

        $this->get(route('maintenance.notify.form'))->assertNotFound();
        $this->assertSame(0, DB::table('maintenance_notifications')->count());
    }
}
