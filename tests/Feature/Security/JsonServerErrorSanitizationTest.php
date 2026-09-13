<?php

namespace Tests\Feature\Security;

use App\Exceptions\GeneralException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * App\Exceptions\Handler — an unexpected server fault never tells a JSON
 * client what went wrong inside.
 *
 * An exception with no HTTP status of its own was not written for a customer.
 * Its message is whatever the failing layer produced — a QueryException's SQL
 * and connection, an ErrorException's server path — so outside `local` a JSON
 * client receives HTTP 500 and one generic, localized message, while the full
 * exception still reaches the logs.
 *
 * Everything the client was MEANT to read is untouched: 401/403/404/419/422
 * messages and the GeneralException business error keep their exact text, and
 * the envelope stays {"status","message"}.
 *
 * The environments are pinned explicitly, because the rule is keyed on
 * `app.env` (the handler's existing convention), not on `app.debug`:
 *
 *   testing / production / staging   sanitized — even with debug on
 *   local                             raw message, for the developer
 */
class JsonServerErrorSanitizationTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_EN = 'Something went wrong. Please try again';

    private const SQL_LEAK = "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'prod_db.secret_ledger' doesn't exist (Connection: mysql, Host: 10.0.4.17, SQL: select * from secret_ledger)";

    private const PATH_LEAK = 'include(/var/www/app/storage/private/keys/provider.php): Failed to open stream';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function (): void {
            Route::get('/__sanitize-test/sql-message', fn () => throw new \RuntimeException(self::SQL_LEAK));
            Route::get('/__sanitize-test/path-message', fn () => throw new \RuntimeException(self::PATH_LEAK));
            Route::get('/__sanitize-test/real-query', fn () => DB::select('select * from v2_sanitize_table_that_does_not_exist'));
            Route::get('/__sanitize-test/real-file', fn () => file_get_contents(base_path('storage/definitely/not/here/secret-config.php')));
            Route::get('/__sanitize-test/abort-404', fn () => abort(404, 'That contact was not found.'));
            Route::get('/__sanitize-test/abort-403', fn () => abort(403, 'You cannot open this Business.'));
            Route::get('/__sanitize-test/unauthenticated', fn () => throw new AuthenticationException('Unauthenticated.'));
            Route::get('/__sanitize-test/unauthorized', fn () => throw new AuthorizationException('This action is unauthorized.'));
            Route::get('/__sanitize-test/validation', fn () => Validator::make([], ['name' => 'required'])->validate());
            Route::get('/__sanitize-test/token-mismatch', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
            Route::get('/__sanitize-test/general', fn () => throw new GeneralException('Not enough balance.'));
        });
    }

    // =================================================================
    // Unexpected faults — HTTP 500, one generic message, nothing leaked
    // =================================================================

    public function test_an_unexpected_fault_in_a_production_like_env_is_a_500_with_the_generic_message(): void
    {
        config(['app.env' => 'production']);

        $this->getJson('/__sanitize-test/sql-message')
            ->assertStatus(500)
            ->assertExactJson(['status' => 'error', 'message' => self::GENERIC_EN]);
    }

    public function test_sql_and_connection_text_never_reaches_the_body(): void
    {
        config(['app.env' => 'production']);

        $body = $this->getJson('/__sanitize-test/sql-message')->assertStatus(500)->getContent();

        foreach (['SQLSTATE', 'secret_ledger', 'prod_db', '10.0.4.17', 'select * from', 'Connection: mysql'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "'{$leak}' must not be exposed.");
        }
    }

    public function test_a_server_file_path_never_reaches_the_body(): void
    {
        config(['app.env' => 'production']);

        $body = $this->getJson('/__sanitize-test/path-message')->assertStatus(500)->getContent();

        foreach (['/var/www', 'storage/private', 'provider.php', 'Failed to open stream'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "'{$leak}' must not be exposed.");
        }
    }

    /** Not a synthetic message: a real query failing against the real connection. */
    public function test_a_real_query_exception_is_sanitized(): void
    {
        config(['app.env' => 'production']);

        $response = $this->getJson('/__sanitize-test/real-query')->assertStatus(500);

        $response->assertExactJson(['status' => 'error', 'message' => self::GENERIC_EN]);

        $database = (string) config('database.connections.' . config('database.default') . '.database');
        $host = (string) config('database.connections.' . config('database.default') . '.host');

        foreach (['SQLSTATE', 'v2_sanitize_table_that_does_not_exist', $database, 'Host: ' . $host] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    /** Not a synthetic message: a real PHP warning turned into an ErrorException. */
    public function test_a_real_filesystem_error_is_sanitized(): void
    {
        config(['app.env' => 'production']);

        $response = $this->getJson('/__sanitize-test/real-file')->assertStatus(500);

        $response->assertExactJson(['status' => 'error', 'message' => self::GENERIC_EN]);
        $this->assertStringNotContainsString('secret-config.php', $response->getContent());
        $this->assertStringNotContainsString(str_replace('\\', '/', base_path()), str_replace('\\/', '/', $response->getContent()));
    }

    public function test_debug_mode_does_not_unlock_the_raw_message_outside_local(): void
    {
        // A production server deployed with debug left on by mistake.
        config(['app.env' => 'production', 'app.debug' => true]);

        $this->getJson('/__sanitize-test/sql-message')
            ->assertStatus(500)
            ->assertExactJson(['status' => 'error', 'message' => self::GENERIC_EN]);
    }

    public function test_the_generic_message_is_localized(): void
    {
        config(['app.env' => 'production']);
        app()->setLocale('es');

        $this->getJson('/__sanitize-test/sql-message')
            ->assertStatus(500)
            ->assertExactJson(['status' => 'error', 'message' => __('locale.exceptions.something_went_wrong')]);

        $this->assertNotSame(self::GENERIC_EN, __('locale.exceptions.something_went_wrong'), 'Sanity: the Spanish translation is in use.');
    }

    // =================================================================
    // Diagnostics still reach the logs
    // =================================================================

    public function test_the_full_exception_is_still_reported(): void
    {
        config(['app.env' => 'production']);
        Exceptions::fake();

        $this->getJson('/__sanitize-test/sql-message')->assertStatus(500);

        // The body is generic; the report is not.
        Exceptions::assertReported(fn (\RuntimeException $exception): bool => $exception->getMessage() === self::SQL_LEAK);
    }

    // =================================================================
    // Environments, pinned
    // =================================================================

    public function test_the_testing_environment_is_sanitized_like_production(): void
    {
        $this->assertSame('testing', config('app.env'));

        // The testing .env runs with debug on; that must not matter.
        $this->getJson('/__sanitize-test/sql-message')
            ->assertStatus(500)
            ->assertExactJson(['status' => 'error', 'message' => self::GENERIC_EN]);
    }

    public function test_staging_is_sanitized_too(): void
    {
        config(['app.env' => 'staging']);

        $this->getJson('/__sanitize-test/sql-message')
            ->assertStatus(500)
            ->assertExactJson(['status' => 'error', 'message' => self::GENERIC_EN]);
    }

    public function test_local_keeps_the_raw_diagnostic_message_for_the_developer(): void
    {
        config(['app.env' => 'local']);

        $this->getJson('/__sanitize-test/sql-message')
            ->assertStatus(500)
            ->assertExactJson(['status' => 'error', 'message' => self::SQL_LEAK]);
    }

    // =================================================================
    // Expected exceptions — messages unchanged
    // =================================================================

    public function test_expected_exception_messages_are_unchanged_outside_local(): void
    {
        config(['app.env' => 'production']);

        $this->getJson('/__sanitize-test/abort-404')->assertNotFound()
            ->assertExactJson(['status' => 'error', 'message' => 'That contact was not found.']);

        $this->getJson('/__sanitize-test/abort-403')->assertForbidden()
            ->assertExactJson(['status' => 'error', 'message' => 'You cannot open this Business.']);

        $this->getJson('/__sanitize-test/unauthenticated')->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'Unauthenticated.']);

        $this->getJson('/__sanitize-test/unauthorized')->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'This action is unauthorized.']);

        $this->getJson('/__sanitize-test/token-mismatch')->assertStatus(419)
            ->assertExactJson(['status' => 'error', 'message' => 'CSRF token mismatch.']);

        $validation = $this->getJson('/__sanitize-test/validation')->assertUnprocessable();
        $this->assertSame(['status' => 'error', 'message' => 'The name field is required.'], $validation->json());
    }

    public function test_the_general_business_error_contract_is_unchanged(): void
    {
        foreach (['production', 'local'] as $environment) {
            config(['app.env' => $environment]);

            $this->getJson('/__sanitize-test/general')
                ->assertOk()
                ->assertExactJson(['status' => 'error', 'message' => 'Not enough balance.']);

            $this->get('/__sanitize-test/general')
                ->assertOk()
                ->assertExactJson(['status' => 'error', 'message' => 'Not enough balance.']);
        }
    }

    // =================================================================
    // HTML — unchanged
    // =================================================================

    public function test_html_responses_are_unchanged(): void
    {
        config(['app.env' => 'production']);

        $page = $this->get('/__sanitize-test/sql-message')->assertStatus(500);
        $this->assertStringStartsWith('text/html', (string) $page->headers->get('Content-Type'));
        $this->assertNull(json_decode((string) $page->getContent(), true), 'A page request must not receive the JSON envelope.');

        $this->get('/__sanitize-test/abort-404')->assertNotFound();
        $this->get('/__sanitize-test/unauthenticated')->assertUnauthorized();
        $this->get('/__sanitize-test/token-mismatch')->assertStatus(419);
        $this->get('/__sanitize-test/validation')->assertRedirect();
    }
}
