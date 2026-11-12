<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound browser calls + personal-phone forwarding. Additive: existing outbound rows keep working untouched
 * (direction defaults to 'outbound'). The caller's number is stored ENCRYPTED (it is needed to identify the caller in
 * the CRM) with a keyed hash for matching; a forwarding number is encrypted too and never leaves the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_inbound_call_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id')->unique();
            $table->string('mode', 24)->default('off'); // off | browser | forward | browser_then_forward
            $table->unsignedSmallInteger('browser_ring_secs')->default(20);
            $table->text('forward_number')->nullable();            // encrypted E.164
            $table->char('forward_number_hash', 64)->nullable();   // HMAC of the current number
            $table->char('forward_verified_hash', 64)->nullable(); // the hash that was verified; forwarding needs both equal
            $table->timestamp('forward_verified_at')->nullable();
            $table->char('verify_code_hash', 64)->nullable();
            $table->text('verify_code')->nullable();               // encrypted, cleared on success/expiry; read only to speak it
            $table->timestamp('verify_expires_at')->nullable();
            $table->unsignedTinyInteger('verify_attempts')->default(0);
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->foreign('business_id', 'bics_business_foreign')->references('id')->on('businesses')->cascadeOnDelete();
        });

        Schema::table('business_voice_calls', function (Blueprint $table): void {
            $table->string('direction', 8)->default('outbound');
            $table->char('from_hash', 64)->nullable();
            $table->text('caller_number')->nullable(); // encrypted E.164 of an inbound caller
            $table->string('route_mode', 24)->nullable();
            $table->string('phase', 16)->nullable();   // ringing_browser | forwarding | done
            $table->string('outcome', 16)->nullable(); // answered | forwarded | missed | declined | failed
            $table->unsignedBigInteger('claimed_leg_id')->nullable();
            $table->unsignedBigInteger('answered_by_user_id')->nullable();
            $table->timestamp('ring_deadline_at')->nullable();
            $table->timestamp('bridged_at')->nullable();
            $table->index(['kind', 'status', 'created_at'], 'bvc_kind_status_created_index');
        });

        Schema::create('business_voice_call_legs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_voice_call_id');
            $table->string('kind', 8); // browser | phone
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('call_control_id', 191)->nullable()->unique();
            $table->char('client_state_hash', 64)->index();
            $table->string('status', 12)->default('ringing'); // ringing | claimed | declined | ended | failed
            $table->timestamps();
            $table->unique(['business_voice_call_id', 'kind', 'user_id'], 'bvcl_call_kind_user_unique');
            $table->foreign('business_voice_call_id', 'bvcl_call_foreign')->references('id')->on('business_voice_calls')->cascadeOnDelete();
        });

        Schema::table('business_voice_browser_credentials', function (Blueprint $table): void {
            $table->timestamp('presence_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_voice_browser_credentials', fn (Blueprint $t) => $t->dropColumn('presence_at'));
        Schema::dropIfExists('business_voice_call_legs');
        Schema::table('business_voice_calls', function (Blueprint $table): void {
            $table->dropIndex('bvc_kind_status_created_index');
            $table->dropColumn(['direction', 'from_hash', 'caller_number', 'route_mode', 'phase', 'outcome', 'claimed_leg_id', 'answered_by_user_id', 'ring_deadline_at', 'bridged_at']);
        });
        Schema::dropIfExists('business_inbound_call_settings');
    }
};
