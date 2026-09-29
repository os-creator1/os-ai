<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Guided Generation contract §8.2/§8.4 (atomicity, idempotency),
 * completed by this lane. One row per generation attempt (full
 * generation or a safe rebuild) — never mutated into a different
 * attempt; a retry is a NEW row referencing the same `idempotency_key`
 * only to detect and short-circuit a genuine duplicate submission
 * (double form submit, retried request), never to resume or merge into
 * a prior attempt. `status` starts `pending`, and ends in exactly one of
 * `succeeded`/`failed` — see GuidedGenerationCommitService. A `failed`
 * row never has an associated page/revision change (§8.2: zero pages
 * created or modified on any failure).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_guided_generation_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('template_key', 40);
            $table->string('mode', 16);
            $table->string('idempotency_key', 100);
            $table->string('status', 16)->default('pending');
            $table->json('warnings')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'idempotency_key'], 'wgga_website_idempotency_unique');
            $table->index(['website_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_guided_generation_attempts');
    }
};
