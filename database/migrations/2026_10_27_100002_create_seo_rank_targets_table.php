<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Keyword Rank Tracking V1 — a rank target is keyword + provider search
 * geography + device. It is the unit of paid tracking: the same keyword in two
 * cities is two targets and consumes two slots. seo_keywords stays "what the
 * Business wants to rank for"; nothing a provider reports is ever written there.
 *
 * One row per (keyword, provider, geography, device) for ever: stopping sets
 * tracking_state='stopped' (history kept, no further paid calls, slot freed)
 * and restarting flips it back, so a keyword is never duplicated and history
 * resumes. The Location attribution of a target is its keyword's — there is
 * deliberately no second Location column to drift out of sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_rank_targets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('seo_keyword_id')->constrained('seo_keywords')->restrictOnDelete();
            $table->string('provider', 16);
            $table->foreignId('seo_rank_location_id')->constrained('seo_rank_locations')->restrictOnDelete();
            $table->unsignedBigInteger('search_location_code');
            $table->string('language_code', 8)->default('en');
            $table->string('device', 8)->default('mobile');
            $table->string('tracking_state', 16)->default('tracking');
            $table->timestamp('tracked_since')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['seo_keyword_id', 'provider', 'search_location_code', 'device'], 'seo_rank_target_identity_unique');
            $table->index(['business_id', 'tracking_state'], 'seo_rank_target_business_state_index');
            $table->index(['tracking_state', 'next_check_at'], 'seo_rank_target_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_rank_targets');
    }
};
