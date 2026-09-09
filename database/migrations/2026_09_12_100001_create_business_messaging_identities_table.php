<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Slice 3 §4.2 — the provider-neutral, authoritative
 * per-Business Messaging-Profile mapping.
 *
 * One active-or-pending managed identity per Business is enforced by MySQL
 * itself, through a STORED generated guard column plus a real UNIQUE index —
 * not by an application check, and not by a PostgreSQL-only partial index.
 * A suspended/archived row's guard column is NULL, and MySQL permits
 * unlimited NULLs under a UNIQUE index, so historical rows accumulate
 * without limit and never collide (§4.2's archival/replacement/reactivation
 * rules). The generated column is added in a separate Schema::table() call
 * after create(), mirroring
 * database/migrations/2026_08_16_140001_create_payment_provider_customers_table.php
 * exactly.
 *
 * Deliberately absent: any credential column, any provider-managed-account
 * identifier, any JSON escape hatch, and any phone_number column — numbers
 * live in business_messaging_numbers, one-to-many.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_messaging_identities', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 36);
            $table->unsignedBigInteger('business_id');
            $table->string('provider', 32);
            $table->string('status', 16)->default('pending');
            $table->string('messaging_profile_id', 191);
            $table->string('messaging_connection_id', 191)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();

            $table->unique('uid');
            $table->unique('messaging_profile_id');
            $table->index('business_id');
        });

        Schema::table('business_messaging_identities', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_or_pending_business_id')
                ->nullable()
                ->storedAs("CASE WHEN status IN ('pending','active') THEN business_id ELSE NULL END")
                ->after('status');
        });

        Schema::table('business_messaging_identities', function (Blueprint $table): void {
            // Explicitly named: MySQL's 64-character identifier limit rejects
            // Laravel's auto-generated name for this column pair.
            $table->unique(['provider', 'active_or_pending_business_id'], 'bmi_provider_active_or_pending_business_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_messaging_identities');
    }
};
