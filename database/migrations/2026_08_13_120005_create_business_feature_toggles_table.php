<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-004 §10.6 — a Business-level feature disable, never a grant beyond
     * Workspace entitlement. No boolean/"enabled" column and no "allow"
     * state, by design: a row's mere existence is "disabled here"; absence
     * is "enabled, subject to Workspace entitlement." Re-enabling deletes
     * the row.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_feature_toggles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
                $table->string('feature_key', 64);
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'feature_key']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_feature_toggles');
        }
    };
