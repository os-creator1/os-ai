<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact Tags foundation — the normalized Contact<->Tag membership, in
 * place of the legacy `contacts.tags` JSON blob.
 *
 * `business_id` is denormalized onto this row (not re-derived through a
 * join every time) for exactly one reason: it is the referenced half of
 * `FOREIGN KEY (tag_id, business_id) -> tags(id, business_id)`, which is
 * the DB-level backstop against a cross-Business tag ever being attached
 * here — a row naming a real `tag_id` cannot be inserted unless a `tags`
 * row with that id AND that business_id actually exists, so a Business A
 * caller cannot attach a Business B tag id even by mistake. This mirrors
 * `CrmOpportunityService`'s own precedent for the CONTACT side of the same
 * tenant check: that service re-derives and compares `contact->business_id`
 * in application code rather than a second composite FK into the shared,
 * heavily-depended-on `contacts` table, and `TagManager` does the same here
 * — adding a new composite-FK-enabling index to `contacts` for this one
 * feature was judged a disproportionate, unjustified change to a table
 * every other domain in this app already depends on.
 *
 * `UNIQUE(contact_id, tag_id)` is the idempotency AND concurrency backstop:
 * attaching the same tag to the same Contact twice can never create two
 * rows, in a single request or under a genuine race — the loser's INSERT
 * fails the unique check and `ContactTagManager` treats that failure as
 * "already attached," not an error (the same pattern
 * `WorkflowEnrollmentService::enroll()` already uses for
 * `automation_enrollments.enrollment_key`).
 *
 * No `updated_at`: membership is a fact that exists or does not — there is
 * no "update" to a tag attachment, only attach and detach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('tag_id');
            $table->unsignedBigInteger('business_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['contact_id', 'tag_id'], 'contact_tags_contact_tag_unique');
            $table->index('tag_id', 'contact_tags_tag_id_index');
            $table->index('business_id', 'contact_tags_business_id_index');

            $table->foreign('contact_id', 'contact_tags_contact_id_foreign')
                ->references('id')->on('contacts')->onDelete('cascade');

            // The composite tenant backstop (see class docblock).
            $table->foreign(['tag_id', 'business_id'], 'contact_tags_tag_business_foreign')
                ->references(['id', 'business_id'])->on('tags')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tags');
    }
};
