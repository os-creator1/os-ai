<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 15 §5.8.3, Sub-slice A — the Location-local
 * Contact identity serialization row.
 *
 * WHY IT EXISTS. Contact identity for a booking is Location-local (Addendum
 * §5: "Contacts belong to one Location; the same real person MAY have
 * separate Contact records in different Locations"), and two simultaneous
 * public bookings from one phone at one Location must resolve to ONE
 * Contact. `contacts` cannot enforce that itself: it carries no unique index
 * of any kind, and one cannot be added retroactively (§5.8.1) — group
 * cloning, CSV import, paste import and multi-group inbound opt-in all
 * produce duplicate (location_id, phone) rows as their ordinary, intended
 * behaviour, and five existing tests assert that duplication is legitimate.
 * `insertOrIgnore` on `contacts` would therefore guarantee nothing, because
 * INSERT IGNORE only suppresses a duplicate-key error where a key exists.
 *
 * So this narrow table is the lock, and it locks nothing another domain
 * uses. It carries NO contact_id and no other data: a second pointer to the
 * resolved Contact would be a second source of truth that can drift from
 * `contacts`. The UNIQUE key is not decoration — it is exactly what makes
 * §5.8.4's ensure-then-lock sequence idempotent.
 *
 * `cascadeOnDelete` because the row is pure infrastructure with no audit
 * value — an archived Location's lock rows are meaningless (§5's opening
 * rule names this as the one such Location FK in the schema).
 *
 * `normalized_phone` is the form `contacts.phone` ACTUALLY stores — §5.8.2:
 * trim(str_replace(['+','-','(',')',' '], '', $raw)). Sub-slice E must not
 * key this on E164Normalizer output, which would never match existing rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_contact_identity_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_location_id')->constrained('business_locations')->cascadeOnDelete();
            $table->string('normalized_phone', 32);
            $table->timestamps();

            $table->unique(
                ['business_location_id', 'normalized_phone'],
                'bcil_location_normalized_phone_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_contact_identity_locks');
    }
};
