<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Text messaging setup/number/compliance hub — US carrier registration.
 *
 * One row per Business (unique `business_id`), matching the confirmed
 * Telnyx cardinality rule: a Business maps to exactly one 10DLC brand.
 * Campaign is folded into the same row rather than a separate table as a
 * deliberate launch simplification (Telnyx allows up to five campaigns per
 * brand; this platform provisions exactly one number and one use case per
 * Business at launch, so brand and campaign share a 1:1 lifecycle here) —
 * splitting them into their own table is a normal additive migration if a
 * later slice ever needs more than one campaign per Business.
 *
 * Captures exactly the fields the compliance research (see
 * docs/automation/TELNYX-US-CANADA-COMPLIANCE-AND-RETAIL-RATE-DECISION.md
 * §3.1/§3.5) confirms Telnyx's registries require and this platform does
 * not already store anywhere else: legal entity identity (name, entity
 * type, EIN), a physical address, a contact, a website, and the campaign
 * facts (use case, opt-in method, two sample messages, privacy/terms
 * URLs). `Business`/`BusinessLocation` carry adjacent but distinct data
 * (marketing profile, physical service locations) and are deliberately
 * not reused here: this row is the one legal/compliance record Telnyx's
 * registries are actually submitted from, and must not silently change
 * meaning if a customer edits an unrelated Business field elsewhere.
 *
 * `number_type` decides which real-world process this row represents —
 * 10DLC brand+campaign for a `local` number, the materially simpler
 * carrier toll-free verification for a `toll_free` number — never both at
 * once, since a Business is assigned exactly one managed number at
 * launch.
 *
 * `status` is the plain three-state customer-facing lifecycle (not
 * started / pending / rejected / approved); `provider_brand_id` and
 * `provider_campaign_id` are opaque, admin-visible-only identifiers,
 * exactly like BusinessMessagingIdentity's own provider references —
 * never a credential, never rendered to the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_messaging_registrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('number_type', 16)->default('local');
            $table->string('status', 16)->default('not_started');

            // Legal entity identity.
            $table->string('legal_business_name', 191)->nullable();
            $table->string('entity_type', 20)->nullable();
            $table->string('ein', 20)->nullable();

            // Physical address — no PO boxes, per carrier requirement;
            // enforced at the form-validation layer, not the schema.
            $table->string('address_line_1', 191)->nullable();
            $table->string('address_line_2', 191)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->default('US');

            // Contact and legitimacy.
            $table->string('website_url', 255)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->string('contact_phone', 32)->nullable();

            // Campaign facts (10DLC) / use-case facts (toll-free) — folded
            // into the same row, see class docblock.
            $table->string('use_case', 60)->nullable();
            $table->text('opt_in_method')->nullable();
            $table->text('sample_message_1')->nullable();
            $table->text('sample_message_2')->nullable();
            $table->string('privacy_policy_url', 255)->nullable();
            $table->string('terms_url', 255)->nullable();

            // Opaque provider references and the outcome.
            $table->string('provider_brand_id', 191)->nullable();
            $table->string('provider_campaign_id', 191)->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id', 'bmr_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();
            $table->unique('business_id', 'bmr_business_unique');
            $table->index('status', 'bmr_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_messaging_registrations');
    }
};
