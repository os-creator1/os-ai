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

    public function test_the_http_timeout_is_the_requests_own_bound_but_never_above_the_provider_ceiling(): void
    {
        // Behaviour, not source text: the value the client hands to its HTTP layer.
        $ceiling = (new \ReflectionClass(OpenAiCompletionClient::class))->getConstant('PROVIDER_TIMEOUT_SECONDS');

        $this->assertSame($ceiling, OpenAiCompletionClient::effectiveTimeoutSeconds(null), 'No request bound: the provider ceiling applies.');
        $this->assertSame($ceiling, OpenAiCompletionClient::effectiveTimeoutSeconds(0), 'A zero or negative bound is ignored.');
        $this->assertSame($ceiling, OpenAiCompletionClient::effectiveTimeoutSeconds(-5));
        $this->assertSame(25, OpenAiCompletionClient::effectiveTimeoutSeconds(25), 'A shorter request bound is honoured.');
        $this->assertSame($ceiling, OpenAiCompletionClient::effectiveTimeoutSeconds($ceiling + 500), 'A request can never extend the provider ceiling.');
        $this->assertLessThan(WebsiteGenerationCoordinator::LEASE_SECONDS, OpenAiCompletionClient::effectiveTimeoutSeconds(PHP_INT_MAX), 'Whatever is requested, a call ends before the generation lease can be reclaimed.');
    }
}
