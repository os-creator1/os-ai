<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('opportunity_transitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('opportunity_id')->constrained('opportunities')->onDelete('cascade');
                $table->string('category', 16);
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32);
                $table->string('actor_type', 16);
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('opportunity_run_id')->nullable()->constrained('opportunity_runs')->nullOnDelete();
                $table->foreignId('action_execution_id')->nullable()->constrained('opportunity_action_executions')->nullOnDelete();
                $table->string('reason_code', 64);
                $table->string('safe_note', 255)->nullable();
                $table->timestamp('created_at');

                $table->index('opportunity_id');
                $table->index(['opportunity_id', 'created_at']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('opportunity_transitions');
        }
    };
