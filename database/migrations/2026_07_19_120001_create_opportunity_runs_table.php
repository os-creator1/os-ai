<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('opportunity_runs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
                $table->string('worker_key', 50);
                $table->unsignedInteger('producer_version');
                $table->string('status', 16)->default('running');
                $table->timestamp('started_at');
                $table->timestamp('heartbeat_at');
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('abandoned_at')->nullable();
                $table->string('reason_code', 64)->nullable();
                $table->string('safe_error_summary', 255)->nullable();
                $table->timestamps();

                $table->index('business_id');
                $table->index(['business_id', 'worker_key', 'status']);
                $table->index(['business_id', 'worker_key', 'completed_at']);
                $table->index(['status', 'heartbeat_at']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('opportunity_runs');
        }
    };
