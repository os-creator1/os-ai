<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browser (WebRTC) calling. A browser call has two Call Control legs: the agent's browser (a Telnyx telephony
 * credential, dialled by SIP URI) and the contact (PSTN), bridged once the contact answers. `channel` tells them apart
 * from the existing server-only calls; `agent_call_control_id` is the browser leg (telnyx_call_control_id stays the
 * contact leg). Per-user, per-Business telephony credentials are provisioned server-side and only a short-lived login
 * token is ever handed to the browser. Additive and nullable/defaulted: existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_voice_calls', function (Blueprint $table): void {
            $table->string('channel', 16)->default('server'); // server | browser
            $table->string('agent_call_control_id', 191)->nullable()->unique();
        });

        Schema::create('business_voice_browser_credentials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('user_id');
            $table->string('telnyx_credential_id', 191);
            $table->string('sip_username', 191);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['business_id', 'user_id'], 'bvbc_business_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_voice_browser_credentials');
        Schema::table('business_voice_calls', function (Blueprint $table): void {
            $table->dropUnique(['agent_call_control_id']);
            $table->dropColumn(['channel', 'agent_call_control_id']);
        });
    }
};
