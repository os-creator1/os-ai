<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-004 §10.1 — sole authority for slot/ratio/pricing policy per tier.
     * price/currency_id are always both-null or both-populated (§12.5),
     * enforced at the application layer, not by a database CHECK constraint.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('workspace_plan_catalog', function (Blueprint $table) {
                $table->id();
                $table->string('tier', 20)->unique();
                $table->string('display_name');
                $table->decimal('price', 16, 2)->nullable();
                $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
                $table->string('billing_cycle', 20)->default('monthly');
                $table->unsignedTinyInteger('business_slot_included');
                $table->unsignedTinyInteger('business_slot_max')->nullable();
                $table->boolean('unlimited_business_slots')->default(false);
                $table->decimal('additional_business_slot_price_ratio', 6, 4)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('is_active');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('workspace_plan_catalog');
        }
    };
