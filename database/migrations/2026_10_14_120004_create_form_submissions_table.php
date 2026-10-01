<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms V1 domain foundation — one logical submission, as an immutable fact.
 *
 * WHY THIS TABLE EXISTS. The canonical, standalone record of an inquiry,
 * independent of whether a Contact or Opportunity could also be produced. It
 * answers, for ever: which Business and Location, which form and which VERSION
 * of it, through which deployment/source, what was submitted (normalized, keyed
 * by field key — the version row says what each key meant), who it resolved to,
 * and which deal (if any) it created.
 *
 * IDEMPOTENCY IS A DATABASE FACT. `operation_nonce` is the random half of the
 * one-time operation token issued when the form was rendered (FormOperationToken,
 * HMAC-bound to the deployment). The unique index on
 * (form_deployment_id, operation_nonce) is the real concurrency backstop: a
 * browser retry, double-click or racing request cannot insert twice; the loser
 * gets a duplicate-key error and converges on the winner's row. Two genuine
 * submissions with identical bodies come from two renders, hence two nonces, and
 * stay separate. `payload_hash` lets a replay of the SAME token with a DIFFERENT
 * body be recognised and refused instead of silently returning the first row.
 *
 * `occurrence_key` is the stable identity a later Automations lane dedupes on
 * (`form_submission:<uid>`); unique so no two rows can claim one occurrence.
 *
 * The row is written once and never edited: the model refuses update/delete,
 * and the only post-insert write is the same-transaction link step
 * (contact, resolution, opportunity), which never reaches a customer-visible
 * moment because the whole thing is one transaction. `contact_id` and
 * `crm_opportunity_id` are SET NULL if those rows are ever removed — the
 * submission itself, its values and its Location must survive.
 *
 * `business_id` IS repeated here (unlike deployments) because Location-scoped
 * listing filters on it constantly and a submission is a historical fact that
 * must not change meaning if a form row were ever re-pointed. All foreign keys
 * to the definition side are RESTRICT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('business_location_id')->constrained('business_locations')->restrictOnDelete();
            $table->foreignId('form_id')->constrained('forms')->restrictOnDelete();
            $table->foreignId('form_version_id')->constrained('form_versions')->restrictOnDelete();
            $table->foreignId('form_deployment_id')->constrained('form_deployments')->restrictOnDelete();
            $table->string('source', 32);
            $table->char('operation_nonce', 32);
            $table->char('payload_hash', 64);
            $table->json('values');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('contact_resolution', 16)->default('none');
            $table->foreignId('crm_opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->string('occurrence_key', 80)->unique();
            $table->timestamps();

            $table->unique(['form_deployment_id', 'operation_nonce'], 'form_submissions_deployment_nonce_unique');
            $table->index(['business_id', 'business_location_id', 'id'], 'form_submissions_business_location_index');
            $table->index(['form_id', 'id']);
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
