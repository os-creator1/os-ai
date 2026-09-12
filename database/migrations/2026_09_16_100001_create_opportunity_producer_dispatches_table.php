<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COO C-1 — the durable coordination row for automatic Opportunity producer
 * triggering, one per (Business, worker).
 *
 * WHY THIS TABLE EXISTS, given opportunity_runs already records runs: a run
 * row is created by the WORKER, inside beginRun(). Between a dispatch and the
 * queue picking that job up there is no run row at all, so opportunity_runs
 * cannot answer the only question a trigger must answer before it enqueues
 * anything — "has a dispatch for this window already been claimed?". Without
 * a pre-dispatch answer, a burst of profile edits enqueues one job per edit
 * and beginRun() collapses them only after the queue has already done the
 * work. This row moves that decision in front of the queue.
 *
 * It is COORDINATION STATE ONLY. It records no candidate, no opportunity, no
 * customer-visible fact, and it is never read to decide what a run produces —
 * opportunity_runs stays the single authority on run state and history
 * (RFC-002 §15).
 *
 * Both claims are single atomic conditional UPDATEs whose affected-row count
 * is the arbiter (see EloquentOpportunityProducerDispatchRepository), so the
 * coordination holds across processes, queue workers and concurrent requests
 * without any in-memory state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('opportunity_producer_dispatches')) {
            return;
        }

        Schema::create('opportunity_producer_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->string('worker_key', 50);

            // When this (Business, worker) pair last had a producer job
            // enqueued by either automatic path. The debounce window is
            // measured from here, never from a run's own timestamps.
            $table->timestamp('last_dispatched_at')->nullable();

            // The calendar day, in the application timezone, this pair was
            // last swept for. A date rather than a timestamp because the rule
            // is "at most one scheduled dispatch per Business per day", and a
            // date turns that into one comparison a second sweep of the same
            // day cannot win twice.
            $table->date('last_swept_on')->nullable();

            $table->timestamps();

            // One row per pair, and the uniqueness that makes the
            // insert-then-conditional-update claim safe under concurrency.
            $table->unique(['business_id', 'worker_key']);

            // The daily sweep's eligibility filter.
            $table->index(['worker_key', 'last_swept_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_producer_dispatches');
    }
};
