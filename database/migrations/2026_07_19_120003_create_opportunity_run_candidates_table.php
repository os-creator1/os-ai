<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('opportunity_run_candidates', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('opportunity_run_id')->constrained('opportunity_runs')->onDelete('cascade');
                $table->string('type', 100);
                $table->unsignedTinyInteger('fingerprint_version');
                $table->char('fingerprint', 64);
                $table->string('context_key', 191)->nullable();
                $table->string('title', 255);
                $table->text('summary');
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
                $table->timestamps();

                $table->unique(['opportunity_run_id', 'fingerprint']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('opportunity_run_candidates');
        }
    };
