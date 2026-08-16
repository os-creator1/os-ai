<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §17.A, M2 contract §11.5/§6.F — one row per Business, created
     * lazily on first write (no listener/backfill — unlike the payer
     * assignment, an unconfigured billing contact is a fully legitimate M2
     * state). contact_user_id nullable to support independent contact data;
     * contact_name/contact_email are required together only when
     * contact_user_id is null (manager-enforced, not a DB constraint).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_billing_contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->unique()->constrained('businesses')->restrictOnDelete();
                $table->foreignId('contact_user_id')->nullable()
                    ->constrained('users')->restrictOnDelete();
                $table->string('contact_name', 191)->nullable();
                $table->string('contact_email', 191)->nullable();
                $table->boolean('notification_opt_in')->default(true);
                $table->unsignedBigInteger('updated_by_user_id');
                $table->timestamps();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_billing_contacts');
        }
    };
