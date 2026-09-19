<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §5.1, Sub-slice A — what can be booked and for
 * how long, at exactly one Location.
 *
 * `business_location_id` is NOT NULL with `restrictOnDelete`: every Booking
 * Type belongs to one Location from creation (Addendum §5), there is no
 * legacy backfill state, and Location attribution is audit-relevant (§5's
 * opening rule, the `contacts`/`chat_boxes` precedent).
 *
 * TWO DISTINCT IDENTIFIERS, deliberately (§5.1):
 *   - `uid` is the ordinary internal `HasUid` identifier. The model
 *     overrides generateUid() with Str::uuid(), because the trait's default
 *     is uniqid().
 *   - `public_booking_uuid` is the public scheduler's ONLY address
 *     (Sub-slice E). It is a separate column filled by the model's own
 *     booted() creating hook with a genuinely random v4 UUID, mirroring
 *     `websites.public_id` exactly (2026_09_07_130001:22 +
 *     app/Models/Website.php:60-67), which exists for the identical reason:
 *     `Business.uid`-shaped uniqid() values were rejected for public
 *     addressing in writing at routes/public.php:210-215.
 * Holding a valid public_booking_uuid is addressing, never authorization —
 * §6's six public checks still run on every render and every write.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_types', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->uuid('public_booking_uuid')->unique();
            $table->foreignId('business_location_id')->constrained('business_locations')->restrictOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('color', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_location_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_types');
    }
};
