<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business-wide Custom Fields — the canonical definition entity.
 *
 * WHY A NEW TABLE. The only custom-field storage that existed was the legacy
 * Ultimate-SMS design: definitions per CONTACT GROUP (`contact_group_fields`,
 * keyed by an uppercase tag) with values as strings. That design cannot be a
 * Business-wide vocabulary (the V1 Blueprint §5/§25 locks custom fields as
 * Business-wide), cannot carry typed values, and is also where Contact identity
 * (FIRST_NAME, EMAIL, ...) lives. Identity stays there untouched; this table is
 * ONLY for user-created fields such as Event Date or Venue.
 *
 * SCOPE. Business-wide, never Location-scoped (same precedent as `tags`): the
 * Contact a value belongs to carries the Location, the definition does not.
 *
 * `key` is generated once from the label and is IMMUTABLE afterwards — it is the
 * stable name in `{{contact.<key>}}` and in published workflow conditions, so a
 * label edit can never break a template. `entity` exists so a later Opportunity /
 * Business / Appointment scope reuses this table; V1 only writes `contact`.
 *
 * `options` holds `[{id, label}]` for select / multi_select. Option ids are
 * stable, so renaming an option's label never corrupts a stored value.
 *
 * `archived_at`, not a delete: an archived field keeps its values and its
 * references; it is only refused for NEW picks and NEW mappings.
 *
 * `UNIQUE(id, business_id)` only makes the pair referenceable by the composite
 * foreign key on `custom_field_values`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->string('entity', 24)->default('contact');
            $table->string('key', 40);
            $table->string('label', 80);
            $table->string('type', 24);
            $table->json('options')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'business_id'], 'cfd_id_business_id_unique');
            $table->unique(['business_id', 'entity', 'key'], 'cfd_business_entity_key_unique');
            $table->index(['business_id', 'entity', 'position'], 'cfd_business_entity_position_index');

            $table->foreign('business_id', 'cfd_business_id_foreign')
                ->references('id')->on('businesses')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_definitions');
    }
};
