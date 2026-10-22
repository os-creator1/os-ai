<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Zoom account authorized by ONE staff user for ONE Business. The (business_id, user_id) pair is the tenant
 * boundary: every read in the integration is keyed by the Business, so a Growth Business, an Agency's own Business
 * and each managed client Business hold completely separate connections. Tokens are stored encrypted (model cast).
 * The privileged host start_url of a meeting is never stored anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoom_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');                    // the staff member whose Zoom account this is
            $table->string('state', 16)->default('pending');          // pending | active | failed | revoked
            $table->string('state_nonce', 64)->nullable();            // the pending authorization attempt
            $table->string('zoom_user_id', 64)->nullable();
            $table->string('zoom_account_email', 190)->nullable();
            $table->string('zoom_display_name', 190)->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoom_connections');
    }
};
