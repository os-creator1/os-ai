<?php

namespace Tests\Feature\Website;

use App\Library\Website\GuidedGeneration\GenerationFailureLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * The diagnostic entry for an unexpected generation failure must never carry an exception message,
 * SQL, bindings, request data or an absolute path — all synthetic values below.
 */
class GenerationFailureLoggerTest extends TestCase
{
    private const SECRETS = ['jane.doe@example.test', 'hunter2-password', 'tok_live_FAKE123456', 'https://secret.example.test/path?key=abc', 'SECRET-ANSWER'];

    private function captured(\Throwable $e): array
    {
        Log::spy();
        GenerationFailureLogger::log($e, 'apply_answers', 7, 11);

        $captured = null;
        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) use (&$captured) {
            $captured = ['message' => $message, 'context' => $context];

            return true;
        })->once();

        return $captured;
    }

    /** Built one call deep, so the trace has an application frame (as a real in-request failure does). */
    private function raised(): \Throwable
    {
        return new ModelNotFoundException('No query results for model [X] ' . self::SECRETS[0]);
    }

    private function assertClean(array $captured): void
    {
        $dump = json_encode($captured);

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }

        $this->assertStringNotContainsString(base_path(), $dump, 'No absolute filesystem path may be logged.');
        $this->assertArrayNotHasKey('message', $captured['context'], 'The exception message is never logged.');
    }

    public function test_a_model_not_found_exception_produces_a_diagnostic_entry(): void
    {
        $captured = $this->captured($this->raised());
        $context = $captured['context'];

        $this->assertSame('Website generation failed', $captured['message']);
        $this->assertSame('apply_answers', $context['stage']);
        $this->assertSame(ModelNotFoundException::class, $context['exception']);
        $this->assertSame(7, $context['website_id']);
        $this->assertSame(11, $context['questionnaire_response_id']);
        $this->assertStringStartsWith('tests/Feature/Website/GenerationFailureLoggerTest.php:', $context['location']);
        $this->assertNotEmpty($context['frames']);
        $this->assertLessThanOrEqual(8, count($context['frames']));
        $this->assertClean($captured);
    }

    public function test_a_validation_exception_does_not_expose_input_or_messages(): void
    {
        $captured = $this->captured(ValidationException::withMessages(['email' => [self::SECRETS[0] . ' ' . self::SECRETS[4] . ' is invalid']]));

        $this->assertSame(ValidationException::class, $captured['context']['exception']);
        $this->assertClean($captured);
    }

    public function test_a_query_exception_logs_only_a_validated_sqlstate_and_driver_code(): void
    {
        $pdo = new PDOException('Duplicate entry ' . self::SECRETS[0] . ' ' . self::SECRETS[1]);
        $pdo->errorInfo = ['23000', 1062, 'Duplicate entry ' . self::SECRETS[0]];

        $captured = $this->captured(new QueryException('mysql', 'insert into users (email, password) values (?, ?)', [self::SECRETS[0], self::SECRETS[1]], $pdo));
        $context = $captured['context'];

        $this->assertSame('23000', $context['sqlstate']);
        $this->assertSame(1062, $context['driver_code']);
        $this->assertStringNotContainsString('insert into', json_encode($captured));
        $this->assertClean($captured);
    }

    public function test_a_malformed_sqlstate_is_dropped_not_echoed(): void
    {
        $pdo = new PDOException('x');
        $pdo->errorInfo = [self::SECRETS[0], 'not-a-number', 'x'];

        $captured = $this->captured(new QueryException('mysql', 'select 1', [], $pdo));

        $this->assertNull($captured['context']['sqlstate']);
        $this->assertNull($captured['context']['driver_code']);
        $this->assertClean($captured);
    }

    public function test_an_arbitrary_message_never_appears_in_the_log_context(): void
    {
        $captured = $this->captured(new RuntimeException(implode(' | ', self::SECRETS) . ' ' . base_path('storage/app/private/x.json')));

        $this->assertSame(RuntimeException::class, $captured['context']['exception']);
        $this->assertClean($captured);
    }

    public function test_an_anonymous_exception_class_does_not_leak_a_path(): void
    {
        $captured = $this->captured(new class('boom') extends RuntimeException {
        });
        $this->assertSame('anonymous', $captured['context']['exception']);
        $this->assertClean($captured);
    }

    public function test_an_external_source_path_is_reported_as_external(): void
    {
        $external = new \ErrorException('x', 0, E_ERROR, '/etc/secret/outside.php', 12);
        $captured = $this->captured($external);
        $this->assertSame('external', $captured['context']['location']);
        $this->assertStringNotContainsString('/etc/secret', json_encode($captured));
    }

    public function test_a_failure_inside_the_logger_cannot_mask_the_handled_failure(): void
    {
        Log::shouldReceive('error')->andThrow(new RuntimeException('log channel down'));

        GenerationFailureLogger::log(new ModelNotFoundException('original'), 'resolve_template', 1, 2);

        $this->addToAssertionCount(1); // reaching here without an exception is the assertion
    }
}
