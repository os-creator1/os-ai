<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone Numbers + A2P lane — messaging contract §13.4's "supported,
 * documented request path" for porting a managed number out. Records and
 * tracks the request only: no column here ever drives a real Telnyx call,
 * a number release, or a rate/credential change. The eventual release,
 * once a real port completes outside this platform (the customer's
 * winning carrier and Telnyx's own process, §13.3), is a later slice's
 * own additive work — this table adds nothing in anticipation of it.
 *
 * One active (status = 'requested') request per number is enforced by
 * MySQL itself through a STORED generated guard column plus a real UNIQUE
 * index, mirroring business_messaging_identities' own
 * active_or_pending_business_id pattern exactly
 * (database/migrations/2026_09_12_100001_create_business_messaging_identities_table.php).
 * A cancelled row's guard column is NULL, so history accumulates without
 * limit and never blocks a fresh request for the same number.
 *
 * requested_by_user_id / cancelled_by_user_id carry no foreign key,
 * mirroring payment_provider_events.disposed_by_user_id — an audit-trail
 * column, not a tenancy relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_messaging_number_port_out_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_messaging_number_id');
            $table->string('phone_number', 32);
            $table->string('status', 16)->default('requested');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('business_id', 'bmnpor_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('business_messaging_number_id', 'bmnpor_number_foreign')
                ->references('id')->on('business_messaging_numbers')->restrictOnDelete();

            $table->index('business_id', 'bmnpor_business_index');
            $table->index('business_messaging_number_id', 'bmnpor_number_index');
        });

        Schema::table('business_messaging_number_port_out_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_number_id')
                ->nullable()
                ->storedAs("CASE WHEN status = 'requested' THEN business_messaging_number_id ELSE NULL END")
                ->after('status');
        });

        Schema::table('business_messaging_number_port_out_requests', function (Blueprint $table): void {
            $table->unique('active_number_id', 'bmnpor_active_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_messaging_number_port_out_requests');
    }
};
