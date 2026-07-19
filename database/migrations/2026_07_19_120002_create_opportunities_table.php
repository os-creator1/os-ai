<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('opportunities', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
                $table->string('worker_key', 50);
                $table->string('type', 100);
                $table->unsignedTinyInteger('fingerprint_version');
                $table->char('fingerprint', 64)->unique();
                $table->string('context_key', 191)->nullable();
                $table->string('title', 255);
                $table->text('summary');
                $table->string('status', 32)->default('open');
                $table->string('freshness', 16)->default('current');
                $table->unsignedTinyInteger('impact');
                $table->unsignedTinyInteger('urgency');
                $table->unsignedTinyInteger('effort');
                $table->decimal('confidence', 3, 2);
                $table->unsignedTinyInteger('goal_relevance_rank');
                $table->unsignedTinyInteger('evidence_freshness_rank');
                $table->unsignedTinyInteger('priority_score');
                $table->unsignedTinyInteger('scoring_version');
                $table->timestamp('scored_at');
                $table->json('evidence');
                $table->json('recommended_action')->nullable();
                $table->char('recommended_action_hash', 64)->nullable();
                $table->unsignedTinyInteger('action_schema_version')->nullable();
                $table->unsignedInteger('occurrence_number')->default(1);
                $table->foreignId('last_confirmed_run_id')->nullable()->constrained('opportunity_runs')->nullOnDelete();
                $table->timestamp('last_confirmed_at');
                $table->timestamp('first_detected_at');
                $table->timestamp('snoozed_until')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamp('stale_at')->nullable();
                $table->timestamps();

                $table->index('business_id');
                $table->index(['business_id', 'status']);
                $table->index(['business_id', 'freshness', 'status', 'priority_score']);
                $table->index(['business_id', 'worker_key']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('opportunities');
        }
    };
