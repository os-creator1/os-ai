<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GBP Slice A contract §24.3 — makes the per-Business provider-call budget
 * ENFORCEABLE (correction pass item 6).
 *
 * config('google_business_profile.sync.max_calls_per_business_per_hour')
 * previously existed only as configuration. Counting is deliberately kept
 * inside the existing three-table architecture rather than adding a fourth
 * GBP table: an operation row already exists before every provider call
 * (contract §24.4), is already Business-scoped and time-stamped, and is
 * already indexed on (business_id, created_at) — which is exactly the
 * rolling-window query this counter needs.
 *
 * The counter records ACTUAL OUTBOUND REQUESTS, not high-level operations:
 * one operation that pages through three location pages and refreshes a
 * token counts four. Laravel Cache is deliberately not used — GBP Slice A
 * introduces no cross-request cache (contract §31), and a cache is the
 * wrong substrate for a quota that must survive a worker restart.
 *
 * ROLLBACK: dropping this column disables budget accounting. It destroys
 * no authorization and no Google Content; the next reservation simply
 * starts from zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_google_operations', function (Blueprint $table) {
            $table->unsignedInteger('provider_call_count')->default(0)->after('request_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('business_google_operations', function (Blueprint $table) {
            $table->dropColumn('provider_call_count');
        });
    }
};
