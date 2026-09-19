<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 18 §8.6, Sub-slice F — a Location-bound LEDGER of
 * "we asked this person for a review".
 *
 * WORKFLOW TRACKING, NOT REVIEW INGESTION. There is deliberately no rating,
 * reviewer name, review text, message body or any other Google-review
 * content column, and no sentiment/score/incentive/quota/target column:
 * GBP §13/§36.2 forbid a durable review archive, and Google's policy forbids
 * review gating and incentives. `channel` is a RECORDED FACT about how the
 * person was asked — SEO sends nothing. `status = reviewed` is
 * SELF-REPORTED by the user, never observed.
 *
 * business_location_id is NOT NULL and protected by the composite FK to
 * business_locations (id, business_id). contact_id is nullable with
 * nullOnDelete (deleting a Contact keeps the ledger row, minus the person);
 * the rule that the Contact's own location_id must equal this row's Location
 * is an application invariant enforced by SeoReviewRequestManager under a
 * Business row lock, not a constraint — a single-column FK cannot express it.
 * crm_opportunity_id is the optional objective trigger (e.g. a won
 * opportunity), nullOnDelete.
 *
 * The cooldown (at most one non-declined request per Contact+Location inside
 * a window) is a time-window rule no unique index can express, so it is
 * enforced serially by the manager; the index below serves that lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_review_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->unsignedBigInteger('business_location_id');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('crm_opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16)->default('requested');
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_location_id', 'contact_id', 'requested_at'], 'seo_review_requests_cooldown_index');
            $table->index(['business_id', 'business_location_id', 'requested_at'], 'seo_review_requests_business_location_index');

            $table->foreign(['business_location_id', 'business_id'], 'seo_review_requests_location_business_foreign')
                ->references(['id', 'business_id'])
                ->on('business_locations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_review_requests');
    }
};
