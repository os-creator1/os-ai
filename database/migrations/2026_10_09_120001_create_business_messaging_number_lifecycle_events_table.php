<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2/§13.3's own audit
 * trail: every renewal charge, advance warning, suspension, release
 * notice and release is recorded here, exactly once, so "never silent" is
 * provable after the fact and not merely asserted. actor_user_id carries
 * no foreign key, mirroring payment_provider_events.disposed_by_user_id
 * and business_messaging_provisioning_incidents.resolved_by_user_id — an
 * audit-trail column, not a tenancy relationship. Null for a
 * system/scheduled event (a renewal charge, a warning, a suspension);
 * populated only for the one genuinely human action, release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_messaging_number_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_messaging_number_id');
            $table->string('event_type', 32);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('business_id', 'bmnle_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('business_messaging_number_id', 'bmnle_number_foreign')
                ->references('id')->on('business_messaging_numbers')->restrictOnDelete();

            $table->index('business_id', 'bmnle_business_index');
            $table->index('business_messaging_number_id', 'bmnle_number_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_messaging_number_lifecycle_events');
    }
};
