<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact Tags foundation — the canonical tag entity.
 *
 * Business-wide, never Location-scoped (Contract 02/06 precedent:
 * `contact_groups` and `contacts_custom_field` — the closest existing CRM
 * "named definition" entities a Contact associates with — carry no
 * `location_id` either; only a Contact's OWN row is Location-pinned). A
 * Business-wide tag attached to a Location-bound Contact is correct: the
 * Contact's own `location_id` keeps its meaning untouched, and the pinned
 * Location context for a tag EVENT comes from the Contact at the moment of
 * the mutation, not from the tag definition itself.
 *
 * `normalized_name` backs the uniqueness rule deterministically: two tags
 * differing only by case/whitespace within the same Business are the same
 * tag (`UNIQUE(business_id, normalized_name)`); the same visible name is
 * freely reusable across different Businesses, since the Business is part
 * of the key.
 *
 * `archived_at`, not a hard delete: archiving must never corrupt a Contact's
 * existing `contact_tags` membership rows, matching the `BusinessLocation`
 * lifecycle precedent (`BusinessLocationLifecycleState::Archived`) — an
 * archived tag stays attached wherever it already is, and is simply refused
 * for NEW attachment.
 *
 * `UNIQUE(id, business_id)` exists solely so `contact_tags` can carry a
 * composite foreign key `(tag_id, business_id) -> tags(id, business_id)` —
 * the DB-level backstop against a cross-Business tag reaching a pivot row
 * stamped with a different Business id. It adds no new uniqueness
 * constraint beyond `id` alone already being unique; it only makes that
 * fact referenceable by a composite key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->string('name', 191);
            $table->string('normalized_name', 191);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'business_id'], 'tags_id_business_id_unique');
            $table->unique(['business_id', 'normalized_name'], 'tags_business_normalized_unique');
            $table->index('business_id', 'tags_business_id_index');

            // RESTRICT, matching `contacts.business_id`'s own precedent —
            // a Business is never silently taken down with its tags by a
            // cascading delete elsewhere.
            $table->foreign('business_id', 'tags_business_id_foreign')
                ->references('id')->on('businesses')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
