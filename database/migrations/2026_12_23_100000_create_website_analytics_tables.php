<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Native website funnel analytics (Agency → White Label → Analytics, Growth → Website → Analytics).
 *
 * ONE collection and reporting store for both owners:
 *  - `website_analytics_properties`: one measured site. Ownership is resolved separately per owner type (an Agency
 *    White Label site, or a Business's published Website) and recorded here, server side, so a request can never name a
 *    tenant. `public_key` is an opaque WRITE-ONLY key the published page carries; it identifies the property, nothing more.
 *    `collection_started_at` is when measurement really began, so earlier dates are shown as "not measured", never as zero.
 *  - `website_analytics_campaigns`: the opaque value an SMS prospecting campaign appends to its landing link (`mgc`).
 *    It names a campaign of ONE Agency workspace; it never names a person.
 *  - `website_analytics_events`: anonymous funnel events. No phone number, e-mail, name, IP address, user agent or query
 *    string is stored. `(property_id, dedupe_key)` is UNIQUE, so a retried beacon, a refreshed confirmation or a replayed
 *    submission cannot count twice even if the application code were bypassed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('website_analytics_properties', function (Blueprint $table) {
            $table->id();
            $table->string('public_key', 40)->unique();
            $table->string('owner_type', 24); // agency_site | website
            $table->foreignId('agency_site_id')->nullable()->unique()->constrained('agency_sites')->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->unique()->constrained('websites')->cascadeOnDelete();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('business_id')->nullable()->index();
            $table->timestamp('collection_started_at');
            $table->timestamps();
        });

        Schema::create('website_analytics_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('token', 24)->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('prospect_campaign_id')->unique();
            $table->string('label', 190);
            $table->timestamps();
        });

        Schema::create('website_analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('website_analytics_properties')->cascadeOnDelete();
            $table->string('dedupe_key', 96);
            $table->string('kind', 24); // pageview | calendar_open | form_open | booking_confirmed | form_inquiry
            $table->timestamp('occurred_at');
            $table->char('visitor_id', 32)->nullable();
            $table->char('session_id', 32)->nullable();
            $table->string('landing', 120)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('referrer_host', 120)->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained('website_analytics_campaigns')->nullOnDelete();
            $table->string('subject_type', 24)->nullable(); // appointment | form_submission
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['property_id', 'dedupe_key'], 'wa_events_dedupe');
            $table->index(['property_id', 'kind', 'occurred_at'], 'wa_events_kind_time');
            $table->index(['property_id', 'campaign_id'], 'wa_events_campaign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_analytics_events');
        Schema::dropIfExists('website_analytics_campaigns');
        Schema::dropIfExists('website_analytics_properties');
    }
};
