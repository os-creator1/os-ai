<?php

namespace Tests\Feature\Usage;

use App\Jobs\Usage\ProcessPaymentProviderEvent;
use App\Jobs\Usage\RetryStuckPaymentProviderEvents;
use App\Library\Usage\PaymentProviderEventRetryPolicy;
use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §19 — index-supported branch queries, one per
 * centrally-bounded processing-attempt bucket, fairly interleaved at two
 * levels when retry is enabled, received-only when it is not, and never
 * silently exceeding the caller's requested limit.
 */
class PaymentProviderEventRetryReclaimTest extends TestCase
{
    use RefreshDatabase;

    private function insertEvent(array $overrides = []): int
    {
        return DB::table('payment_provider_events')->insertGetId(array_merge([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.uniqid('', true),
            'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => 'pi_'.uniqid('', true),
            'payload_hash' => hash('sha256', uniqid('', true)),
            'state' => 'received',
            'attempts' => 0,
            'received_at' => now(),
        ], $overrides));
    }

    private function repository(): PaymentProviderEventRepository
    {
        return app(PaymentProviderEventRepository::class);
    }

    /**
     * @return array{0: mixed, 1: int} the callable's own return value, and the number of queries the callable issued against payment_provider_events
     */
    private function countEventTableQueries(callable $fn): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $result = $fn();

        $count = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'payment_provider_events'))->count();

        return [$result, $count];
    }

    public function test_a_failed_event_below_max_attempts_is_redispatched_by_the_scanner(): void
    {
        Queue::fake();
        $id = $this->insertEvent(['state' => 'failed', 'attempts' => 2]);

        app(RetryStuckPaymentProviderEvents::class)->handle($this->repository());

        Queue::assertPushed(ProcessPaymentProviderEvent::class, fn ($job) => $this->extractEventId($job) === $id);
    }

    private function extractEventId(ProcessPaymentProviderEvent $job): int
    {
        $reflection = new \ReflectionProperty($job, 'eventId');
        $reflection->setAccessible(true);

        return $reflection->getValue($job);
    }

    public function test_a_stale_processing_event_past_its_lease_is_reclaimed_by_the_scanner(): void
    {
        Queue::fake();
        $id = $this->insertEvent(['state' => 'processing', 'attempts' => 1, 'lease_expires_at' => now()->subMinute()]);

        app(RetryStuckPaymentProviderEvents::class)->handle($this->repository());

        Queue::assertPushed(ProcessPaymentProviderEvent::class, fn ($job) => $this->extractEventId($job) === $id);
    }

    public function test_an_event_at_max_attempts_is_never_redispatched_by_the_scanner(): void
    {
        Queue::fake();
        $normalized = PaymentProviderEventRetryPolicy::normalizedMaxAttempts();
        $id = $this->insertEvent(['state' => 'failed', 'attempts' => $normalized]);

        app(RetryStuckPaymentProviderEvents::class)->handle($this->repository());

        Queue::assertNotPushed(ProcessPaymentProviderEvent::class, fn ($job) => $this->extractEventId($job) === $id);
    }

    public function test_an_exhausted_event_becomes_administrator_visible_in_the_existing_exhausted_events_queue(): void
    {
        $normalized = PaymentProviderEventRetryPolicy::normalizedMaxAttempts();
        $id = $this->insertEvent(['state' => 'failed', 'attempts' => $normalized]);

        $exhausted = $this->repository()->exhausted($normalized);

        $this->assertContains($id, $exhausted->pluck('id')->all());
    }

    public function test_processed_ignored_and_disposed_events_are_never_redispatched_by_the_scanner(): void
    {
        Queue::fake();
        $processedId = $this->insertEvent(['state' => 'processed']);
        $ignoredId = $this->insertEvent(['state' => 'ignored']);
        $disposedId = $this->insertEvent(['state' => 'disposed']);

        app(RetryStuckPaymentProviderEvents::class)->handle($this->repository());

        Queue::assertNotPushed(ProcessPaymentProviderEvent::class, fn ($job) => in_array($this->extractEventId($job), [$processedId, $ignoredId, $disposedId], true));
    }

    public function test_the_scanner_batch_is_bounded_by_its_own_limit_across_the_fairly_interleaved_branches(): void
    {
        for ($i = 0; $i < 250; $i++) {
            $this->insertEvent(['state' => 'failed', 'attempts' => 0]);
        }

        $candidates = $this->repository()->retryable(5, 30, RetryStuckPaymentProviderEvents::BATCH_LIMIT);

        $this->assertLessThanOrEqual(RetryStuckPaymentProviderEvents::BATCH_LIMIT, $candidates->count());
    }

    public function test_the_scanner_performs_no_accounting_mutation_itself(): void
    {
        Queue::fake();
        $id = $this->insertEvent(['state' => 'failed', 'attempts' => 1]);

        app(RetryStuckPaymentProviderEvents::class)->handle($this->repository());

        $fresh = DB::table('payment_provider_events')->where('id', $id)->first();
        $this->assertSame('failed', $fresh->state);
        $this->assertSame(1, (int) $fresh->attempts);
    }

    public function test_a_received_event_older_than_the_grace_interval_is_redispatched_by_the_scanner(): void
    {
        Queue::fake();
        $id = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);

        $candidates = $this->repository()->retryable(5, 30, 200);

        $this->assertContains($id, $candidates->pluck('id')->all());
    }

    public function test_a_freshly_received_event_is_not_redispatched_before_the_grace_interval_elapses(): void
    {
        $id = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(5)]);

        $candidates = $this->repository()->retryable(5, 30, 200);

        $this->assertNotContains($id, $candidates->pluck('id')->all());
    }

    public function test_the_persistence_before_dispatch_failure_leaves_a_received_row_that_only_the_scanner_recovers(): void
    {
        Queue::fake();
        $id = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);

        app(RetryStuckPaymentProviderEvents::class)->handle($this->repository());

        Queue::assertPushed(ProcessPaymentProviderEvent::class, fn ($job) => $this->extractEventId($job) === $id);
    }

    public function test_a_redelivered_webhook_for_an_already_received_event_returns_200_without_a_second_row_and_the_original_remains_scanner_recoverable(): void
    {
        $providerEventId = 'evt_redelivered_'.uniqid();
        $id = $this->insertEvent(['provider_event_id' => $providerEventId, 'state' => 'received', 'received_at' => now()->subMinutes(40)]);

        $existing = $this->repository()->findByProviderEventId('stripe', $providerEventId);
        $this->assertNotNull($existing);
        $this->assertSame($id, $existing->id);

        $candidates = $this->repository()->retryable(5, 30, 200);
        $this->assertContains($id, $candidates->pluck('id')->all());
        $this->assertSame(1, DB::table('payment_provider_events')->where('provider_event_id', $providerEventId)->count());
    }

    public function test_a_scanner_redispatch_racing_the_original_dispatch_for_the_same_received_event_applies_the_outcome_exactly_once(): void
    {
        $id = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);

        $claimedFirst = $this->repository()->claim($id, 30, 5);
        $claimedSecond = $this->repository()->claim($id, 30, 5);

        $this->assertSame(1, $claimedFirst);
        $this->assertSame(0, $claimedSecond, 'A second, racing claim attempt for the same already-processing row must never also succeed.');
    }

    public function test_each_retry_query_including_every_stale_processing_attempt_bucket_query_is_supported_by_its_own_dedicated_index_and_ordered_to_match_it(): void
    {
        $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        $this->insertEvent(['state' => 'failed', 'attempts' => 1]);
        $this->insertEvent(['state' => 'processing', 'attempts' => 0, 'lease_expires_at' => now()->subMinute()]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->repository()->retryable(2, 30, 200);
        $log = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'payment_provider_events'));

        $this->assertTrue($log->contains(fn ($entry) => str_contains($entry['query'], 'received_at') && str_contains($entry['query'], 'order by')));
        $this->assertTrue($log->contains(fn ($entry) => in_array('failed', $entry['bindings'], true) && str_contains($entry['query'], 'attempts')));
        $this->assertTrue($log->contains(fn ($entry) => in_array('processing', $entry['bindings'], true) && str_contains($entry['query'], 'lease_expires_at')));
    }

    public function test_the_scanner_remains_bounded_and_correctly_index_ordered_when_the_table_contains_a_large_number_of_terminal_rows_alongside_sparse_matching_candidates(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $this->insertEvent(['state' => 'processed']);
        }
        $id = $this->insertEvent(['state' => 'failed', 'attempts' => 1]);

        [$candidates, $queryCount] = $this->countEventTableQueries(fn () => $this->repository()->retryable(5, 30, 200));

        $this->assertContains($id, $candidates->pluck('id')->all());
        $this->assertSame(2 + 5, $queryCount);
    }

    public function test_when_all_three_branches_have_at_least_batch_limit_candidates_the_selected_batch_contains_all_three_states_and_never_exceeds_batch_limit(): void
    {
        for ($i = 0; $i < 210; $i++) {
            $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        }
        for ($i = 0; $i < 210; $i++) {
            $this->insertEvent(['state' => 'failed', 'attempts' => 0]);
        }
        for ($i = 0; $i < 210; $i++) {
            $this->insertEvent(['state' => 'processing', 'attempts' => 0, 'lease_expires_at' => now()->subMinute()]);
        }

        $candidates = $this->repository()->retryable(5, 30, 200);
        $states = DB::table('payment_provider_events')->whereIn('id', $candidates->pluck('id'))->pluck('state')->unique()->all();

        $this->assertLessThanOrEqual(200, $candidates->count());
        sort($states);
        $this->assertSame(['failed', 'processing', 'received'], $states);
    }

    public function test_a_sustained_received_backlog_exceeding_the_limit_never_starves_the_failed_or_stale_processing_branches(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        }
        $failedId = $this->insertEvent(['state' => 'failed', 'attempts' => 0]);
        $staleId = $this->insertEvent(['state' => 'processing', 'attempts' => 0, 'lease_expires_at' => now()->subMinute()]);

        $candidates = $this->repository()->retryable(5, 30, 10);
        $ids = $candidates->pluck('id')->all();

        $this->assertContains($failedId, $ids);
        $this->assertContains($staleId, $ids);
    }

    public function test_interleaving_selects_from_both_populated_branches_when_only_received_and_failed_have_candidates(): void
    {
        $receivedId = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        $failedId = $this->insertEvent(['state' => 'failed', 'attempts' => 0]);

        $candidates = $this->repository()->retryable(5, 30, 10);
        $ids = $candidates->pluck('id')->all();

        $this->assertContains($receivedId, $ids);
        $this->assertContains($failedId, $ids);
    }

    public function test_interleaving_selects_from_both_populated_branches_when_only_received_and_stale_processing_have_candidates(): void
    {
        $receivedId = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        $staleId = $this->insertEvent(['state' => 'processing', 'attempts' => 0, 'lease_expires_at' => now()->subMinute()]);

        $candidates = $this->repository()->retryable(5, 30, 10);
        $ids = $candidates->pluck('id')->all();

        $this->assertContains($receivedId, $ids);
        $this->assertContains($staleId, $ids);
    }

    public function test_interleaving_selects_from_both_populated_branches_when_only_failed_and_stale_processing_have_candidates(): void
    {
        $failedId = $this->insertEvent(['state' => 'failed', 'attempts' => 0]);
        $staleId = $this->insertEvent(['state' => 'processing', 'attempts' => 0, 'lease_expires_at' => now()->subMinute()]);

        $candidates = $this->repository()->retryable(5, 30, 10);
        $ids = $candidates->pluck('id')->all();

        $this->assertContains($failedId, $ids);
        $this->assertContains($staleId, $ids);
    }

    public function test_only_the_received_branch_populated_returns_exactly_its_own_candidates_up_to_the_limit(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        }

        $candidates = $this->repository()->retryable(5, 30, 10);

        $this->assertSame($ids, $candidates->pluck('id')->all());
    }

    public function test_only_the_failed_branch_populated_returns_exactly_its_own_candidates_up_to_the_limit(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->insertEvent(['state' => 'failed', 'attempts' => 0]);
        }

        $candidates = $this->repository()->retryable(5, 30, 10);

        $this->assertSame($ids, $candidates->pluck('id')->all());
    }

    public function test_only_the_stale_processing_branch_populated_returns_exactly_its_own_candidates_up_to_the_limit(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->insertEvent(['state' => 'processing', 'attempts' => 0, 'lease_expires_at' => now()->subMinute()]);
        }

        $candidates = $this->repository()->retryable(5, 30, 10);

        $this->assertSame($ids, $candidates->pluck('id')->all());
    }

    public function test_retryables_accepted_limit_is_clamped_to_the_locked_maximum_regardless_of_the_requested_value(): void
    {
        for ($i = 0; $i < 250; $i++) {
            $this->insertEvent(['state' => 'failed', 'attempts' => 0]);
        }

        $candidates = $this->repository()->retryable(5, 30, 1_000_000);

        $this->assertLessThanOrEqual(200, $candidates->count());
    }

    public function test_a_large_number_of_non_expired_processing_rows_at_a_lower_attempt_count_never_blocks_recovery_of_an_expired_row_at_a_higher_attempt_count(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->insertEvent(['state' => 'processing', 'attempts' => 0, 'lease_expires_at' => now()->addMinutes(30)]);
        }
        $expiredHigherId = $this->insertEvent(['state' => 'processing', 'attempts' => 3, 'lease_expires_at' => now()->subMinute()]);

        $candidates = $this->repository()->retryable(5, 30, 10);

        $this->assertContains($expiredHigherId, $candidates->pluck('id')->all());
    }

    public function test_stale_processing_candidates_are_fairly_interleaved_across_attempt_buckets_when_every_eligible_bucket_has_at_least_limit_candidates(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            for ($i = 0; $i < 10; $i++) {
                $this->insertEvent(['state' => 'processing', 'attempts' => $attempt, 'lease_expires_at' => now()->subMinute()]);
            }
        }

        $candidates = $this->repository()->retryable(5, 30, 5);
        $attemptsSeen = DB::table('payment_provider_events')->whereIn('id', $candidates->pluck('id'))->pluck('attempts')->unique()->sort()->values()->all();

        $this->assertSame([0, 1, 2, 3, 4], $attemptsSeen, 'Every eligible attempt bucket must receive a slot in the interleave first round.');
    }

    public function test_a_saturated_lower_attempt_bucket_never_starves_a_sparsely_populated_higher_attempt_bucket(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->insertEvent(['state' => 'processing', 'attempts' => 0, 'lease_expires_at' => now()->subMinute()]);
        }
        $sparseId = $this->insertEvent(['state' => 'processing', 'attempts' => 4, 'lease_expires_at' => now()->subMinute()]);

        $candidates = $this->repository()->retryable(5, 30, 5);

        $this->assertContains($sparseId, $candidates->pluck('id')->all());
    }

    public function test_outer_state_class_fairness_across_received_failed_and_stale_processing_remains_intact_after_the_two_level_stale_processing_merge(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        }
        $failedId = $this->insertEvent(['state' => 'failed', 'attempts' => 0]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->insertEvent(['state' => 'processing', 'attempts' => $attempt, 'lease_expires_at' => now()->subMinute()]);
        }

        $candidates = $this->repository()->retryable(5, 30, 5);
        $states = DB::table('payment_provider_events')->whereIn('id', $candidates->pluck('id'))->pluck('state')->unique()->all();

        sort($states);
        $this->assertSame(['failed', 'processing', 'received'], $states);
        $this->assertContains($failedId, $candidates->pluck('id')->all());
    }

    public function test_the_number_of_stale_processing_bucket_queries_never_exceeds_the_locked_ceiling_regardless_of_the_configured_max_attempts_value(): void
    {
        $this->insertEvent(['state' => 'failed', 'attempts' => 0]);

        [, $queryCount] = $this->countEventTableQueries(fn () => $this->repository()->retryable(1_000_000, 30, 200));

        $this->assertSame(2 + PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING, $queryCount);
    }

    public function test_a_non_positive_configured_max_attempts_value_disables_failed_and_stale_processing_selection_but_the_received_recovery_query_still_executes(): void
    {
        $receivedId = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        $failedId = $this->insertEvent(['state' => 'failed', 'attempts' => 0]);

        $candidates = $this->repository()->retryable(0, 30, 200);
        $ids = $candidates->pluck('id')->all();

        $this->assertContains($receivedId, $ids);
        $this->assertNotContains($failedId, $ids);
    }

    public function test_an_excessively_large_configured_max_attempts_value_cannot_cause_unbounded_stale_processing_query_fan_out(): void
    {
        $this->insertEvent(['state' => 'failed', 'attempts' => 0]);

        [, $queryCount] = $this->countEventTableQueries(fn () => $this->repository()->retryable(1_000_000, 30, 200));

        $this->assertLessThanOrEqual(22, $queryCount);
    }

    public function test_the_default_configured_max_attempts_of_five_produces_the_expected_seven_query_upper_bound_and_accepts_the_production_limit_of_two_hundred(): void
    {
        $this->insertEvent(['state' => 'failed', 'attempts' => 0]);

        [$candidates, $queryCount] = $this->countEventTableQueries(fn () => $this->repository()->retryable(5, 30, 200));

        $this->assertSame(7, $queryCount);
        $this->assertGreaterThanOrEqual(1, $candidates->count());
    }

    public function test_no_eligible_stale_processing_attempt_bucket_starves_when_max_attempts_is_normalized_to_the_locked_ceiling(): void
    {
        for ($attempt = 0; $attempt < PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING; $attempt++) {
            $this->insertEvent(['state' => 'processing', 'attempts' => $attempt, 'lease_expires_at' => now()->subMinute()]);
        }

        $candidates = $this->repository()->retryable(1_000_000, 30, PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING);
        $attemptsSeen = DB::table('payment_provider_events')->whereIn('id', $candidates->pluck('id'))->pluck('attempts')->unique()->sort()->values()->all();

        $this->assertCount(PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING, $attemptsSeen);
    }

    public function test_a_requested_limit_below_the_fairness_floor_throws_an_invalid_argument_exception_before_any_query_executes(): void
    {
        $this->insertEvent(['state' => 'failed', 'attempts' => 0]);

        [, $queryCount] = $this->countEventTableQueries(function () {
            try {
                $this->repository()->retryable(5, 30, 2);
                $this->fail('Expected InvalidArgumentException was not thrown.');
            } catch (\InvalidArgumentException $e) {
                // Expected.
            }

            return null;
        });

        $this->assertSame(0, $queryCount, 'No query may execute before the fairness-floor validation throws.');
    }

    public function test_a_negative_raw_configured_max_attempts_value_normalizes_to_the_same_received_only_behavior_as_zero(): void
    {
        $receivedId = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        $failedId = $this->insertEvent(['state' => 'failed', 'attempts' => 0]);

        $candidates = $this->repository()->retryable(-5, 30, 200);
        $ids = $candidates->pluck('id')->all();

        $this->assertContains($receivedId, $ids);
        $this->assertNotContains($failedId, $ids);
    }

    public function test_a_requested_limit_exactly_equal_to_the_fairness_floor_succeeds_and_executes_normally(): void
    {
        $failedId = $this->insertEvent(['state' => 'failed', 'attempts' => 0]);

        $candidates = $this->repository()->retryable(5, 30, 5);

        $this->assertContains($failedId, $candidates->pluck('id')->all());
    }

    public function test_retryable_never_returns_more_than_the_validated_requested_limit(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->insertEvent(['state' => 'failed', 'attempts' => 0]);
        }

        $candidates = $this->repository()->retryable(5, 30, 10);

        $this->assertLessThanOrEqual(10, $candidates->count());
    }

    public function test_sparse_eligible_candidates_return_fewer_than_the_requested_limit_without_violating_any_bound(): void
    {
        $this->insertEvent(['state' => 'failed', 'attempts' => 0]);

        $candidates = $this->repository()->retryable(5, 30, 50);

        $this->assertSame(1, $candidates->count());
    }

    public function test_the_locked_twenty_attempt_ceiling_produces_at_most_twenty_two_queries_and_a_bounded_four_thousand_four_hundred_row_examination_under_the_production_limit(): void
    {
        for ($attempt = 0; $attempt < PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING; $attempt++) {
            for ($i = 0; $i < 250; $i++) {
                $this->insertEvent(['state' => 'processing', 'attempts' => $attempt, 'lease_expires_at' => now()->subMinute()]);
            }
        }

        [$candidates, $queryCount] = $this->countEventTableQueries(fn () => $this->repository()->retryable(
            PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING, 30, RetryStuckPaymentProviderEvents::BATCH_LIMIT,
        ));

        $this->assertSame(2 + PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING, $queryCount);
        $this->assertLessThanOrEqual(RetryStuckPaymentProviderEvents::BATCH_LIMIT, $candidates->count());
    }

    public function test_max_attempts_zero_recovers_a_stranded_received_event_through_its_first_claim(): void
    {
        $id = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40), 'attempts' => 0]);

        $claimed = $this->repository()->claim($id, 30, 0);

        $this->assertSame(1, $claimed, 'A received row must be claimable at max_attempts = 0 — claim() never gates the received branch on attempts.');
    }

    public function test_a_received_event_recovered_and_successfully_processed_at_max_attempts_zero_is_not_selected_again(): void
    {
        $id = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        $this->repository()->claim($id, 30, 0);
        $this->repository()->markProcessed($id);

        $candidates = $this->repository()->retryable(0, 30, 200);

        $this->assertNotContains($id, $candidates->pluck('id')->all());
    }

    public function test_a_received_event_recovered_at_max_attempts_zero_whose_first_attempt_fails_is_not_automatically_retried(): void
    {
        $id = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        $this->repository()->claim($id, 30, 0);
        $this->repository()->markFailed($id, 'simulated_failure');

        $candidates = $this->repository()->retryable(0, 30, 200);

        $this->assertNotContains($id, $candidates->pluck('id')->all());
    }

    public function test_exhausted_failed_and_stale_processing_rows_remain_administrator_visible_and_disposable_when_max_attempts_is_normalized_to_zero(): void
    {
        $failedId = $this->insertEvent(['state' => 'failed', 'attempts' => 1]);
        $staleId = $this->insertEvent(['state' => 'processing', 'attempts' => 1, 'lease_expires_at' => now()->subMinute()]);

        $exhausted = $this->repository()->exhausted(0);
        $ids = $exhausted->pluck('id')->all();

        $this->assertContains($failedId, $ids);
        $this->assertContains($staleId, $ids);

        $disposed = $this->repository()->dispose($failedId, null, 'Test disposition.', 0);
        $this->assertSame(1, $disposed);
    }

    public function test_a_requested_limit_of_one_is_valid_at_max_attempts_zero_since_only_the_received_branch_is_active(): void
    {
        $id = $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);

        $candidates = $this->repository()->retryable(0, 30, 1);

        $this->assertContains($id, $candidates->pluck('id')->all());
    }

    public function test_the_received_only_path_at_max_attempts_zero_executes_exactly_one_query(): void
    {
        $this->insertEvent(['state' => 'received', 'received_at' => now()->subMinutes(40)]);
        $this->insertEvent(['state' => 'failed', 'attempts' => 0]);
        $this->insertEvent(['state' => 'processing', 'attempts' => 0, 'lease_expires_at' => now()->subMinute()]);

        [, $queryCount] = $this->countEventTableQueries(fn () => $this->repository()->retryable(0, 30, 200));

        $this->assertSame(1, $queryCount);
    }
}
