<?php

namespace Tests\Unit\Usage;

use App\Library\Usage\PaymentProviderEventRetryPolicy;
use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §19 — proves PaymentProviderEventRetryPolicy's
 * own normalization in isolation, plus cross-consumer agreement.
 */
class PaymentProviderEventRetryPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_negative_configured_max_attempts_value_normalizes_to_zero(): void
    {
        config(['usage_billing.webhook_event.max_attempts' => -5]);

        $this->assertSame(0, PaymentProviderEventRetryPolicy::normalizedMaxAttempts());
    }

    public function test_a_zero_configured_max_attempts_value_normalizes_to_zero(): void
    {
        config(['usage_billing.webhook_event.max_attempts' => 0]);

        $this->assertSame(0, PaymentProviderEventRetryPolicy::normalizedMaxAttempts());
    }

    public function test_the_default_configured_max_attempts_value_of_five_normalizes_unchanged(): void
    {
        config(['usage_billing.webhook_event.max_attempts' => 5]);

        $this->assertSame(5, PaymentProviderEventRetryPolicy::normalizedMaxAttempts());
    }

    public function test_a_configured_max_attempts_value_exactly_at_the_locked_ceiling_normalizes_unchanged(): void
    {
        config(['usage_billing.webhook_event.max_attempts' => PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING]);

        $this->assertSame(PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING, PaymentProviderEventRetryPolicy::normalizedMaxAttempts());
    }

    public function test_a_configured_max_attempts_value_far_above_the_locked_ceiling_normalizes_down_to_the_ceiling(): void
    {
        config(['usage_billing.webhook_event.max_attempts' => 1000000]);

        $this->assertSame(PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING, PaymentProviderEventRetryPolicy::normalizedMaxAttempts());
    }

    private function createEvent(array $overrides = []): int
    {
        return DB::table('payment_provider_events')->insertGetId(array_merge([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.uniqid(),
            'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => 'pi_x',
            'payload_hash' => hash('sha256', 'x'),
            'state' => 'received',
            'attempts' => 0,
            'received_at' => now(),
        ], $overrides));
    }

    public function test_claim_eligibility_exhausted_disposition_eligibility_and_the_stale_processing_bucket_count_all_observe_the_identical_normalized_maximum(): void
    {
        config(['usage_billing.webhook_event.max_attempts' => 1000000]);
        $normalized = PaymentProviderEventRetryPolicy::normalizedMaxAttempts();
        $this->assertSame(PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING, $normalized);

        $repository = app(PaymentProviderEventRepository::class);

        // claim() eligibility: a failed row with attempts == normalized
        // ceiling must NOT be reclaimable (>= the ceiling, not < it).
        $atCeilingId = $this->createEvent(['state' => 'failed', 'attempts' => $normalized]);
        $this->assertSame(0, $repository->claim($atCeilingId, 5, $normalized), 'A row at the normalized ceiling must not be claimable.');

        $belowCeilingId = $this->createEvent(['state' => 'failed', 'attempts' => $normalized - 1]);
        $this->assertSame(1, $repository->claim($belowCeilingId, 5, $normalized), 'A row one below the normalized ceiling must be claimable.');

        // exhausted()/disposition eligibility: the at-ceiling row must
        // appear as exhausted using the identical normalized value.
        $exhaustedIds = $repository->exhausted($normalized)->pluck('id')->all();
        $this->assertContains($atCeilingId, $exhaustedIds);

        // retryable()'s own stale-processing bucket count: exactly
        // 2 + normalized queries worth of buckets are consulted — proven
        // indirectly here by confirming a processing row sitting exactly
        // at attempts == normalized - 1 (the highest eligible bucket) is
        // recoverable, while one at attempts == normalized is not.
        DB::table('payment_provider_events')->where('id', $belowCeilingId)->update([
            'state' => 'processing', 'lease_expires_at' => now()->subMinute(), 'attempts' => $normalized - 1,
        ]);
        DB::table('payment_provider_events')->where('id', $atCeilingId)->update([
            'state' => 'processing', 'lease_expires_at' => now()->subMinute(), 'attempts' => $normalized,
        ]);

        $retryable = $repository->retryable($normalized, 5, 200)->pluck('id')->all();
        $this->assertContains($belowCeilingId, $retryable, 'The highest eligible bucket (normalized - 1) must be scanned.');
        $this->assertNotContains($atCeilingId, $retryable, 'A row at attempts == normalized must never be scanned by any bucket.');
    }
}
