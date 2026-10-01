<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms V1 domain foundation — the canonical, Business-wide form definition.
 *
 * WHY A NEW TABLE. `website_forms` is a Website-owned presentation row (one
 * per Website, no Business column, no Location). Forms / Questionnaires is its
 * own product surface (V1 decision 1), so its definition cannot live under a
 * Website. This is the single Business-scoped definition every consumer — the
 * form's own public link today, a Website embed or a booking follow-up later —
 * points at.
 *
 * ONE ROW PER FORM, NEVER PER LOCATION. A form is Business-wide; the Locations
 * it is offered at are `form_deployments` rows. Duplicating a form per
 * Location just to attribute a lead would fork every later edit.
 *
 * WHAT THIS ROW HOLDS: identity, ownership, the customer's own label, and the
 * lifecycle. What a form actually ASKS lives in `form_versions` (next
 * migration) so an edit can never change how an old submission reads;
 * `current_version` is the number of the version new submissions use.
 *
 * `lifecycle_state` is NOT mass-assignable on the model — only FormManager's
 * activate()/deactivate() change it, the same shape as `catalog_items`.
 * `business_id` is RESTRICT: a Business with forms is not silently deletable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('lifecycle_state', 16)->default('draft');
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'lifecycle_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
