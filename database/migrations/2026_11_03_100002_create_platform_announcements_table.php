<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Owner V1 final — announcement MANAGEMENT state (draft → scheduled →
 * published, or cancelled; expiry is derived from expires_at). Delivery is not
 * stored here: it goes through the PlatformAnnouncementDelivery seam so the
 * Platform Automations lane owns it. The legacy `announcements` table (an
 * immediate email/SMS blast with no lifecycle) is untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('title', 160);
            $table->text('body');
            $table->string('status', 16)->default('draft');
            // Audience: all | tiers (audience_tiers holds plan tier values)
            $table->string('audience', 16)->default('all');
            $table->json('audience_tiers')->nullable();
            $table->json('channels');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_ref', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_announcements');
    }
};
