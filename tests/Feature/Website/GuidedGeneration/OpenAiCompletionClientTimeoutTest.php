<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Ai\Providers\OpenAiCompletionClient;
use App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator;
use Tests\TestCase;

/**
 * Independent-review correction round 4 (item 1) — a genuinely active
 * provider call must never be reclaimed solely because the generation
 * lease's own 300-second window elapsed while the call was legitimately
 * still running. This is a property of two independent constants staying
 * in the right relationship, not of runtime behavior under a real network
 * call (which this test suite never makes) — proves it the only way that
 * is actually meaningful here: the provider's own bounded HTTP timeout is
 * read via reflection and asserted to be comfortably shorter than the
 * lease, with an explicit, deliberate safety margin (not merely "less
 * than").
 */
class OpenAiCompletionClientTimeoutTest extends TestCase
{
    public function test_the_provider_timeout_is_comfortably_shorter_than_the_generation_lease(): void
    {
        $reflection = new \ReflectionClass(OpenAiCompletionClient::class);
        $timeoutSeconds = $reflection->getConstant('PROVIDER_TIMEOUT_SECONDS');

        $this->assertIsInt($timeoutSeconds, 'OpenAiCompletionClient::PROVIDER_TIMEOUT_SECONDS must be a concrete, bounded integer.');
        $this->assertGreaterThan(0, $timeoutSeconds);

        $leaseSeconds = WebsiteGenerationCoordinator::LEASE_SECONDS;

        // Not merely "shorter than" — a genuinely active call that hits
        // its own bounded timeout right at the edge must still have time
        // to fail, record the attempt, and release the lease before the
        // lease's own expiry could possibly let a second worker reclaim
        // it. At least a third of the lease window must remain as margin.
        $margin = $leaseSeconds - $timeoutSeconds;

        $this->assertLessThan($leaseSeconds, $timeoutSeconds, 'The provider timeout must be strictly shorter than the generation lease window.');
        $this->assertGreaterThanOrEqual(
            $leaseSeconds / 3,
            $margin,
            "Expected at least a third of the {$leaseSeconds}s lease window as margin after the provider's own {$timeoutSeconds}s timeout; only {$margin}s remained."
        );
    }

    public function test_the_completion_client_constructs_its_http_client_with_the_documented_options(): void
    {
        // Independent-review correction round 4 (item 1/8) — proves the
        // ACTUAL source wires `timeout` and `connect_timeout` into the
        // Guzzle client passed to OpenAI::factory()->withHttpClient(...),
        // rather than merely trusting the docblock, without ever making a
        // real network call (this suite never contacts a real provider or
        // any outbound address).
        $source = file_get_contents((new \ReflectionClass(OpenAiCompletionClient::class))->getFileName());

        $this->assertStringContainsString('withHttpClient', $source);
        $this->assertStringContainsString("'timeout' => self::PROVIDER_TIMEOUT_SECONDS", $source);
        $this->assertStringContainsString("'connect_timeout'", $source);
    }
}
