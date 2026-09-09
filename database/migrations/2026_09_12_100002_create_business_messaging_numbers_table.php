<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Slice 3 §4.2 — the one-to-many phone-number mapping a
 * single column on the identity table could not honestly represent.
 *
 * Ownership has exactly one source of truth: every number belongs to one
 * identity, and the owning Business is resolved by joining through it — no
 * redundant business_id column that could drift.
 *
 * Two independent STORED generated guard columns, each under a real MySQL
 * UNIQUE index, are the sole enforcement mechanism (§4.2):
 *
 *   active_or_pending_phone_number — no provider phone number belongs to two
 *   Businesses. Deliberately NOT scoped by provider: a real E.164 number is
 *   unique in reality regardless of which provider label a row carries.
 *
 *   active_primary_identity_id — at most one active primary number per
 *   identity, so outbound resolution never has to pick between two.
 *
 * A suspended/released row, and a non-primary or non-active row, generate
 * NULL and therefore never collide — released numbers are retained forever
 * without claiming an impossible eternal reservation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_messaging_numbers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_messaging_identity_id');
            $table->string('phone_number', 32);
            $table->string('provider_number_reference', 191)->nullable();
            $table->string('status', 16)->default('pending');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->foreign('business_messaging_identity_id', 'bmn_identity_foreign')
                ->references('id')->on('business_messaging_identities')->restrictOnDelete();
        });

        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->string('active_or_pending_phone_number', 32)
                ->nullable()
                ->storedAs("CASE WHEN status IN ('pending','active') THEN phone_number ELSE NULL END")
                ->after('phone_number');

            $table->unsignedBigInteger('active_primary_identity_id')
                ->nullable()
                ->storedAs("CASE WHEN is_primary = 1 AND status = 'active' THEN business_messaging_identity_id ELSE NULL END")
                ->after('is_primary');
        });

        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->unique('active_or_pending_phone_number', 'bmn_active_or_pending_phone_number_unique');
            $table->unique('active_primary_identity_id', 'bmn_active_primary_identity_id_unique');
            $table->index('business_messaging_identity_id', 'bmn_identity_index');
            $table->index('status', 'bmn_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_messaging_numbers');
    }
};
