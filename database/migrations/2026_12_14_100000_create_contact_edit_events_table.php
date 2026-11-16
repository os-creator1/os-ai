<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of MANUAL edits to a contact's name, canonical email,
 * phone and group (the Edit-contact modal and the profile email form). One
 * row per field that really changed. No history is backfilled: nothing before
 * this table existed was recorded, so nothing before it is shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_edit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('field', 16); // name | email | phone | group
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'contact_id', 'id'], 'contact_edit_events_contact_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_edit_events');
    }
};
