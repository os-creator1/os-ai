<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy Provider Webhook Measurement Contract §3.4 — S0's single measurement
 * table. Aggregate counters only, keyed by (route_name, provider_slug); there
 * are deliberately no per-request event rows, no payload column, no headers
 * column, no body-hash column, no address column, no IP column and no
 * free-form metadata column. A column that does not exist cannot be written,
 * so the table has no column capable of holding personal data.
 *
 * Timestamp chosen per §3.4's binding rule: read fresh from the merged tree
 * at implementation start, not from the contract document. The latest
 * migration on `main` at that moment was
 * 2026_09_12_100006_complete_legacy_ai_messaging_schema.php (part of the
 * Customer Experience Slice 3 tree), so this migration sorts strictly after
 * it without renaming, re-timestamping or colliding with any Slice 3 file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_webhook_route_usage', function (Blueprint $table): void {
            $table->id();
            $table->string('route_name', 191);
            $table->string('provider_slug', 64);
            $table->unsignedBigInteger('hit_count')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['route_name', 'provider_slug'], 'lwru_route_provider_unique');
            $table->index('last_seen_at', 'lwru_last_seen_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_webhook_route_usage');
    }
};
