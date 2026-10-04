<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Contract 18 §8.6 — seo_review_requests.status is requested|reviewed|declined.
 * A legacy/hand-seeded `resolved` value (a terminal state, not a rejection)
 * cannot hydrate SeoReviewRequestStatus and crashed the Reviews page.
 *
 * `resolved` is mapped to `reviewed`: it is the completed outcome, and mapping
 * it to `declined` would free the Contact from the cooldown. No rating is
 * inferred, nothing is claimed as Google-verified (`reviewed` is self-reported)
 * and resolved_at is left exactly as stored. The table is pinned to its
 * contracted columns, so provenance is only logged, not stored.
 *
 * Idempotent; valid rows are never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $migrated = DB::table('seo_review_requests')
            ->where('status', 'resolved')
            ->update(['status' => 'reviewed']);

        if ($migrated > 0) {
            Log::info('seo_review_requests: migrated legacy status "resolved" to "reviewed".', ['rows' => $migrated]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: the original `resolved` rows cannot be told apart from genuine `reviewed` rows.
    }
};
