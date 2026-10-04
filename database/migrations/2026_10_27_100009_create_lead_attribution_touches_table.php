<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads Module V1 contract 23 §10 / D4 — the canonical, APPEND-ONLY
 * lead attribution foundation. Purpose: nothing in the platform captured
 * click ids, UTMs, landing page or entry surface before; this is the single
 * store so first-touch, last-touch and multi-event history all fit without
 * migrating existing rows, and so offline conversion upload (deferred, D6)
 * needs no attribution redesign.
 *
 * Append-only: rows are inserted by LeadAttributionRecorder and never
 * updated (the model refuses it). There is deliberately no updated_at. One
 * `first` and at most one `last` row per conversion event: unique
 * (subject_type, subject_id, touch_role) makes recording idempotent.
 *
 * business_id is the server-resolved Business of the public page, never a
 * cookie value. contact_id is NULL until the contact is resolved and is
 * removed with the contact (cascade) so click ids do not outlive the lead.
 * landing_page is a PATH ONLY (query string stripped). No IP address and no
 * user agent is stored. captured_at = when the visitor arrived; recorded_at
 * = when the conversion happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_attribution_touches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('business_location_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('entry_surface', 24);
            $table->string('touch_role', 8);
            $table->string('gclid', 255)->nullable();
            $table->string('gbraid', 255)->nullable();
            $table->string('wbraid', 255)->nullable();
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('utm_content', 255)->nullable();
            $table->string('landing_page', 512)->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('recorded_at');

            $table->foreign('business_id', 'lat_business_fk')
                ->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('business_location_id', 'lat_location_fk')
                ->references('id')->on('business_locations')->nullOnDelete();
            $table->foreign('contact_id', 'lat_contact_fk')
                ->references('id')->on('contacts')->cascadeOnDelete();

            $table->unique(['subject_type', 'subject_id', 'touch_role'], 'lat_subject_role_unique');
            $table->index(['business_id', 'contact_id'], 'lat_business_contact_idx');
            $table->index(['business_id', 'captured_at'], 'lat_business_captured_idx');
            $table->index(['business_id', 'gclid'], 'lat_business_gclid_idx');
            $table->index(['business_id', 'gbraid'], 'lat_business_gbraid_idx');
            $table->index(['business_id', 'wbraid'], 'lat_business_wbraid_idx');
            $table->index('business_location_id', 'lat_location_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_attribution_touches');
    }
};
