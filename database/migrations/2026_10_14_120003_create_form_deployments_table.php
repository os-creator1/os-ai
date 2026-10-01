<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms V1 domain foundation — the deterministic Location binding.
 *
 * WHY THIS TABLE EXISTS. A form definition is Business-wide, but every real
 * submission must resolve to exactly ONE Location. The deployment is the
 * evidence: "this form, offered at this Location, from this source". Its public
 * `uid` is what a visitor's link carries, so the Location is read off a
 * persisted row — never inferred from an IP, "the first Location", session
 * state or a Contact. Using one form at three Locations is three rows here and
 * still one `forms` row.
 *
 * A form can be deployed to many Locations; a (form, Location, source) triple
 * exists at most once — the unique index is the real guard, FormManager only
 * gives a friendlier message. `is_enabled` pauses one Location without
 * touching the others or losing the link.
 *
 * Both foreign keys are RESTRICT, matching every other FK to
 * `business_locations`/the owning table: a submission's deployment must outlive
 * the submission. The Business is deliberately NOT repeated here — it is
 * reached through `forms.business_id`, and FormDeploymentResolver re-proves on
 * every request that the Location belongs to that same Business.
 *
 * `source` is a plain string (not an enum column) so a later consumer can add a
 * case without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_deployments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('form_id')->constrained('forms')->restrictOnDelete();
            $table->foreignId('business_location_id')->constrained('business_locations')->restrictOnDelete();
            $table->string('source', 32)->default('direct_link');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['form_id', 'business_location_id', 'source'], 'form_deployments_form_location_source_unique');
            $table->index('business_location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_deployments');
    }
};
