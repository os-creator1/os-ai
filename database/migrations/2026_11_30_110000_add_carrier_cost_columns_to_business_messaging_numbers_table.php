<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The carrier's own per-number price, as quoted by Telnyx at search time
 * (cost_information) and confirmed by the customer before purchase. The
 * monthly figure is what each rental renewal charges (times the owner's
 * markup multiplier — zero markup is 1.0); the upfront figure is kept for
 * the audit trail. Nullable: a number without a recorded cost is never
 * charged a guessed rental (its renewal is reported NotConfigured).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->unsignedBigInteger('upfront_cost_micro')->nullable();
            $table->unsignedBigInteger('monthly_cost_micro')->nullable();
            $table->string('cost_currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->dropColumn(['upfront_cost_micro', 'monthly_cost_micro', 'cost_currency']);
        });
    }
};
