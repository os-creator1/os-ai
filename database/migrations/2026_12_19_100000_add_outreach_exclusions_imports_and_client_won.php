<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outreach intelligence: workspace-wide Existing Client exclusions, the durable Client won
 * outcome, prospect enrichment from the smart CSV importer, and import provenance.
 *
 * Existing Client exclusions are a COMMERCIAL suppression and are deliberately a separate table
 * from STOP / opt-out (prospect status + Business blacklist), which is a compliance suppression
 * that nothing here can reverse.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('agency_prospect_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('kind', 24)->default('prospects');           // prospects | existing_clients
            $table->string('filename')->nullable();
            $table->string('status', 16)->default('analyzed');          // analyzed | imported
            $table->string('region', 2)->nullable();                    // confirmed region for numbers without a country code
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->json('summary')->nullable();
            $table->unsignedBigInteger('contact_group_id')->nullable();
            $table->string('group_name')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('agency_prospect_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('agency_prospect_import_batches')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('original');                                    // every original value, untouched
            $table->json('normalized')->nullable();
            $table->string('phone', 32)->nullable();                     // canonical digits
            $table->string('outcome', 24);                               // new|existing|excluded|stopped|duplicate|missing_phone|invalid_phone|empty|review
            $table->json('issues')->nullable();
            $table->unsignedBigInteger('prospect_id')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'outcome']);
            $table->index(['workspace_id', 'phone']);
        });

        Schema::table('agency_prospects', function (Blueprint $table) {
            $table->string('business_type')->nullable();
            $table->decimal('google_rating', 3, 2)->nullable();
            $table->unsignedInteger('review_count')->nullable();
            $table->boolean('has_website')->nullable();
            $table->string('social_url', 2048)->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('maps_url', 2048)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('opening_hours')->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('client_won_at')->nullable();
            $table->unsignedBigInteger('acquisition_campaign_id')->nullable();
            $table->unsignedBigInteger('acquisition_member_id')->nullable();
            $table->json('client_won_evidence')->nullable();

            $table->index(['workspace_id', 'business_type']);
            $table->index(['workspace_id', 'client_won_at']);
            $table->index('import_batch_id');
        });

        // Analytics: step-to-reply attribution reads each member's confirmed outbound/inbound timeline in order.
        Schema::table('agency_prospect_messages', function (Blueprint $table) {
            $table->index(['campaign_member_id', 'direction', 'status', 'sent_at'], 'apm_member_dir_status_sent');
            $table->index(['workspace_id', 'direction', 'received_at'], 'apm_ws_dir_received');
        });

        Schema::create('agency_prospect_exclusions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('phone', 32);                                 // canonical digits (AgencyProspectPhoneNormalizer)
            $table->string('active_phone', 32)->nullable();              // = phone while active, NULL once removed
            $table->string('business_name')->nullable();
            $table->string('reason', 24)->default('manual');            // manual | client_won | csv_import
            $table->string('source_label')->nullable();
            $table->unsignedBigInteger('prospect_id')->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->unsignedBigInteger('removed_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'active_phone']);
            $table->index(['workspace_id', 'phone']);
            $table->index(['workspace_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_prospect_exclusions');

        Schema::table('agency_prospect_messages', function (Blueprint $table) {
            $table->dropIndex('apm_member_dir_status_sent');
            $table->dropIndex('apm_ws_dir_received');
        });

        Schema::table('agency_prospects', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'business_type']);
            $table->dropIndex(['workspace_id', 'client_won_at']);
            $table->dropIndex(['import_batch_id']);
            $table->dropColumn(['business_type', 'google_rating', 'review_count', 'has_website', 'social_url', 'address', 'city',
                'state', 'country', 'maps_url', 'latitude', 'longitude', 'opening_hours', 'import_batch_id', 'imported_at',
                'client_won_at', 'acquisition_campaign_id', 'acquisition_member_id', 'client_won_evidence']);
        });

        Schema::dropIfExists('agency_prospect_import_rows');
        Schema::dropIfExists('agency_prospect_import_batches');
    }
};
