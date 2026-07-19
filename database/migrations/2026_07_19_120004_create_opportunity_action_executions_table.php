<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('opportunity_action_executions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('opportunity_id')->constrained('opportunities')->onDelete('cascade');
                $table->string('action_key', 100);
                $table->char('recommended_action_hash', 64);
                $table->unsignedTinyInteger('action_schema_version');
                $table->unsignedInteger('occurrence_number');
                $table->unsignedInteger('attempt_number')->default(1);
                $table->char('idempotency_key', 64)->unique();
                $table->string('status', 16)->default('pending');
                $table->foreignId('initiated_by_user_id')->constrained('users');
                $table->string('initiated_by_type', 16);
                $table->string('completion_policy', 32);
                $table->string('safe_result_summary', 255)->nullable();
                $table->string('safe_error_summary', 255)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index('opportunity_id');
                $table->index(['opportunity_id', 'action_key']);
                $table->index('status');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('opportunity_action_executions');
        }
    };
