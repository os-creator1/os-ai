<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('customer_onboardings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->unique()->constrained('users')->onDelete('cascade');
                $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
                $table->boolean('is_required')->default(false);
                $table->string('status', 32)->default('not_started');
                $table->string('current_step', 32)->default('goals');
                $table->json('primary_goals')->nullable();
                $table->json('completed_steps')->nullable();
                $table->json('metadata')->nullable();
                $table->unsignedInteger('analysis_version')->default(0);
                $table->json('analysis_payload')->nullable();
                $table->text('analysis_error')->nullable();
                $table->timestamp('analysis_started_at')->nullable();
                $table->timestamp('analysis_completed_at')->nullable();
                $table->string('first_value_action_key', 100)->nullable();
                $table->timestamp('first_value_action_completed_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamps();

                $table->index('business_id');
                $table->index(['status', 'is_required']);
                $table->index('last_activity_at');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('customer_onboardings');
        }
    };
