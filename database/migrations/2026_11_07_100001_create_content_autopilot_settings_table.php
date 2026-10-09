<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Autopilot (Contract 25) — one row per Business: whether Autopilot is on, why it is paused, and the few
 * Content facts MotionGrove cannot already know (what customers ask, what to emphasise, topics to avoid).
 *
 * Why a table: there is no per-Business Content setting anywhere today, and "is Autopilot on" must be a durable,
 * queryable fact (the daily tick selects enabled Businesses). Everything else the fact pack needs already lives in the
 * Business Knowledge Profile (differentiators, credentials, years operating, prohibited claims, brand voice), packages,
 * locations and the Website — none of it is copied here. There is deliberately NO budget column: the AI ceiling is plan
 * policy in config/ai.php, never an owner-editable (or per-row) value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_autopilot_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('businesses')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->dateTime('enabled_at')->nullable();
            $table->unsignedBigInteger('enabled_by_user_id')->nullable();
            // owner | budget | no_website | profile — why a switched-on Autopilot is not currently running.
            $table->string('paused_reason', 32)->nullable();
            // { common_questions: string[], emphasis: string[], avoid_topics: string[] }
            $table->json('profile')->nullable();
            $table->dateTime('profile_completed_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'paused_reason'], 'content_autopilot_enabled_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_autopilot_settings');
    }
};
