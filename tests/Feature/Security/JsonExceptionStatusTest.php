<?php

namespace Tests\Feature\Security;

use App\Exceptions\GeneralException;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\Templates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * App\Exceptions\Handler — a JSON error travels with the exception's own status.
 *
 * Before this fix the handler answered EVERY exception on a JSON request with
 * HTTP 200 and `{"status":"error","message":...}`. A client could not tell "not
 * found", "not signed in", "invalid input" or a server fault from success
 * without reading the body, and a tenancy denial for a foreign Account answered
 * with a success status.
 *
 * The contract proven here, and nothing wider:
 *
 *   - JSON: the body is byte-for-byte the same envelope; the status is now the
 *     exception's own (Laravel's canonical mapping, plus this application's
 *     two documented conventions — authorization denials are 401, and
 *     GeneralException stays a 200 business error).
 *   - HTML: every status is exactly what it was, oddities included. This fix
 *     does not touch the page branch, and each HTML case pins today's status so
 *     that stays true.
 *
 * The throwaway routes use the `web` stack, so sessions, CSRF and the real
 * handler are all in play — the same pipeline a customer's request takes.
 */
class JsonExceptionStatusTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function (): void {
            Route::get('/__handler-test/model-not-found', fn () => throw (new ModelNotFoundException())->setModel(Templates::class, [42]));
            Route::get('/__handler-test/abort-404', fn () => abort(404, 'Missing thing'));
            Route::get('/__handler-test/abort-403', fn () => abort(403, 'Forbidden thing'));
            Route::get('/__handler-test/unauthenticated', fn () => throw new AuthenticationException('Unauthenticated.'));
            Route::get('/__handler-test/unauthorized', fn () => throw new AuthorizationException('This action is unauthorized.'));
            Route::get('/__handler-test/unauthorized-as-not-found', fn () => AccessResponse::denyAsNotFound('Nothing here.')->authorize());
            Route::get('/__handler-test/validation', fn () => Validator::make([], ['name' => 'required'])->validate());
            Route::get('/__handler-test/token-mismatch', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
            Route::get('/__handler-test/server-fault', fn () => throw new \RuntimeException('Something broke inside.'));
            Route::get('/__handler-test/general', fn () => throw new GeneralException('Not enough balance.'));
            Route::get('/__handler-test/http-response', fn () => throw new HttpResponseException(response('teapot', 418)));
            Route::get('/__handler-test/throttled', fn () => response()->json(['status' => 'success']))->middleware('throttle:1,1');
        });
    }

    // =================================================================
    // JSON — the status is the exception's own, the body is unchanged
    // =================================================================

    public function test_model_not_found_is_a_json_404(): void
    {
        $this->getJson('/__handler-test/model-not-found')
            ->assertNotFound()
            ->assertExactJson(['status' => 'error', 'message' => 'No query results for model [App\Models\Templates] 42']);
    }

    public function test_an_explicit_404_is_a_json_404(): void
    {
        $this->getJson('/__handler-test/abort-404')
            ->assertNotFound()
            ->assertExactJson(['status' => 'error', 'message' => 'Missing thing']);
    }

    public function test_an_explicit_403_keeps_its_403(): void
    {
        $this->getJson('/__handler-test/abort-403')
            ->assertForbidden()
            ->assertExactJson(['status' => 'error', 'message' => 'Forbidden thing']);
    }

    public function test_an_unauthenticated_request_is_a_json_401(): void
    {
        $this->getJson('/__handler-test/unauthenticated')
            ->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'Unauthenticated.']);
    }

    public function test_an_authorization_denial_keeps_this_applications_401(): void
    {
        // The HTML branch has always answered this with errors.401 / 401 in
        // every non-local environment; the JSON answer now agrees with it.
        $this->getJson('/__handler-test/unauthorized')
            ->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'This action is unauthorized.']);
    }

    public function test_an_authorization_denial_that_names_its_own_status_keeps_it(): void
    {
        $this->getJson('/__handler-test/unauthorized-as-not-found')
            ->assertNotFound()
            ->assertExactJson(['status' => 'error', 'message' => 'Nothing here.']);
    }

    public function test_a_validation_failure_is_a_json_422_in_the_same_envelope(): void
    {
        $response = $this->getJson('/__handler-test/validation')->assertUnprocessable();

        // Still the application's envelope — not Laravel's `errors` bag, which
        // would be a format change this fix does not make.
        $this->assertSame(['status', 'message'], array_keys($response->json()));
        $this->assertSame('error', $response->json('status'));
        $this->assertSame('The name field is required.', $response->json('message'));
    }

    public function test_a_token_mismatch_is_a_json_419(): void
    {
        $this->getJson('/__handler-test/token-mismatch')
            ->assertStatus(419)
            ->assertExactJson(['status' => 'error', 'message' => 'CSRF token mismatch.']);
    }

    public function test_throttling_is_a_json_429(): void
    {
        $this->getJson('/__handler-test/throttled')->assertOk();

        $refused = $this->getJson('/__handler-test/throttled')->assertStatus(429);

        $this->assertSame(['status', 'message'], array_keys($refused->json()));
        $this->assertSame('error', $refused->json('status'));
    }

    public function test_an_unexpected_server_fault_is_a_json_500(): void
    {
        // The status is the subject here. The message outside `local` is the
        // generic one — the raw text of an unexpected fault is never returned
        // to a client; see JsonServerErrorSanitizationTest for that contract.
        $this->getJson('/__handler-test/server-fault')
            ->assertStatus(500)
            ->assertExactJson(['status' => 'error', 'message' => __('locale.exceptions.something_went_wrong')]);
    }

    public function test_an_exception_carrying_its_own_response_keeps_that_status(): void
    {
        $this->getJson('/__handler-test/http-response')->assertStatus(418);
    }

    public function test_no_json_error_is_a_200_except_the_deliberate_business_error(): void
    {
        foreach ([
            '/__handler-test/model-not-found' => 404,
            '/__handler-test/abort-404' => 404,
            '/__handler-test/abort-403' => 403,
            '/__handler-test/unauthenticated' => 401,
            '/__handler-test/unauthorized' => 401,
            '/__handler-test/validation' => 422,
            '/__handler-test/token-mismatch' => 419,
            '/__handler-test/server-fault' => 500,
        ] as $uri => $status) {
            $response = $this->getJson($uri);

            $this->assertSame($status, $response->getStatusCode(), $uri . ' must carry its own status, not 200.');
            $this->assertSame('error', $response->json('status'), $uri . ' keeps the error envelope.');
        }
    }

    /**
     * GeneralException is this application's user-facing business error, not
     * an HTTP exception: it has no status of its own and the legacy AJAX
     * screens read `status: error` from a 200. Preserved exactly, on both
     * branches, and pinned so a future change to it is a decision rather than
     * an accident.
     */
    public function test_the_general_business_error_is_deliberately_still_a_200(): void
    {
        $this->getJson('/__handler-test/general')
            ->assertOk()
            ->assertExactJson(['status' => 'error', 'message' => 'Not enough balance.']);

        $this->get('/__handler-test/general')
            ->assertOk()
            ->assertExactJson(['status' => 'error', 'message' => 'Not enough balance.']);
    }

    // =================================================================
    // HTML — unchanged, oddities included
    // =================================================================

    /**
     * Every page status is exactly what the handler produced before this fix.
     * Some of these are not what a greenfield handler would choose — a
     * ModelNotFound page is a 500, any HttpException page is a 404 — and that
     * is recorded rather than corrected here: this fix is the JSON status only.
     */
    public function test_html_statuses_are_unchanged(): void
    {
        $this->get('/__handler-test/model-not-found')->assertStatus(500);
        $this->get('/__handler-test/abort-404')->assertNotFound();
        $this->get('/__handler-test/abort-403')->assertNotFound();
        $this->get('/__handler-test/unauthenticated')->assertUnauthorized();
        $this->get('/__handler-test/unauthorized')->assertUnauthorized();
        $this->get('/__handler-test/token-mismatch')->assertStatus(419);
        $this->get('/__handler-test/validation')->assertRedirect();
        $this->get('/__handler-test/server-fault')->assertStatus(500);
    }

    public function test_html_error_pages_are_still_pages_not_json(): void
    {
        $page = $this->get('/__handler-test/abort-404');

        $this->assertStringStartsWith('text/html', (string) $page->headers->get('Content-Type'));
        $this->assertNull(json_decode((string) $page->getContent(), true), 'A page request must not receive the JSON envelope.');
    }

    // =================================================================
    // The real case: a foreign Account's JSON request
    // =================================================================

    public function test_a_json_request_into_a_foreign_account_is_a_404_not_a_200(): void
    {
        $this->ensureAppConfig();

        $owner = $this->createCustomer();
        $ownBusiness = $this->createBusinessWithWorkspace($owner, $this->businessAttributes(['name' => 'Own Business']));

        $stranger = $this->createCustomer();
        $foreignBusiness = $this->createBusinessWithWorkspace($stranger, $this->businessAttributes(['name' => 'Foreign Business']));

        $template = Templates::create([
            'user_id' => $stranger->user_id,
            'business_id' => $foreignBusiness->id,
            'name' => 'Foreign template',
            'message' => 'Secret foreign text',
            'status' => true,
        ]);

        $this->signIn($owner, ['sms_quick_send']);

        // The owner reaches into the STRANGER's Account and Business by uid.
        $url = route('customer.workspaces.businesses.outreach.templates.show_data', [
            $foreignBusiness->workspace->uid,
            $foreignBusiness->uid,
            $template->id,
        ]);

        $response = $this->postJson($url);

        $this->assertNotSame(200, $response->getStatusCode(), 'A tenancy denial must never answer with a success status.');
        $response->assertNotFound()
            ->assertJson(['status' => 'error'])
            ->assertDontSee('Secret foreign text');

        // The owner's own Account still works, so the 404 above is the denial
        // and not a broken route.
        $ownTemplate = Templates::create([
            'user_id' => $owner->user_id,
            'business_id' => $ownBusiness->id,
            'name' => 'Own template',
            'message' => 'Own text',
            'status' => true,
        ]);

        $this->postJson(route('customer.workspaces.businesses.outreach.templates.show_data', [
            $ownBusiness->workspace->uid,
            $ownBusiness->uid,
            $ownTemplate->id,
        ]))->assertOk()->assertJson(['status' => 'success', 'message' => 'Own text']);

        // And the page equivalent is unchanged: still the ordinary 404.
        $this->post($url)->assertNotFound();
    }

    private function signIn(Customer $customer, array $permissions): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function ensureAppConfig(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            AppConfig::create(collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions'));
        }
    }
}
