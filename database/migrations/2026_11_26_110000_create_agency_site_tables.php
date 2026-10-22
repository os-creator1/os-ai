<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White Label — Landing Page. The Agency's own SaaS marketing site.
 *
 * WHY NOT THE GROWTH `websites` TABLE. A Growth Website is a Business's local-service
 * site: a closed set of local-service section types, AI generation and local SEO rules.
 * The Agency's site sells software and is rendered by the existing MotionGrove marketing
 * views, so it needs its own content schema. What IS reused from the Growth design is
 * its publishing model: a mutable draft and immutable published revisions, so a draft
 * edit can never alter the live site (AgencySiteManager::publish).
 *
 * One site per Agency Workspace. `config` is the draft (validated overrides on top of
 * the starter content, never raw HTML). A revision is a frozen copy of `config`.
 * Prices and plan availability are NOT frozen: they are read live from the Agency's
 * plan catalog at render time so a public price always equals what Stripe will charge.
 *
 * `agency_site_signups` is the durable record that attributes a public registration and
 * its checkout to exactly one Agency, and makes retries idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_sites', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            // The public, unguessable address segment of the hosted site (never the workspace uid).
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('agency_workspace_id');
            // draft | published | unpublished
            $table->string('status', 20)->default('draft');
            $table->json('config');
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->unsignedBigInteger('published_revision_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('agency_workspace_id', 'agency_sites_agency_unique');
            $table->foreign('agency_workspace_id', 'agency_sites_agency_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();
        });

        Schema::create('agency_site_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('agency_site_id');
            $table->unsignedInteger('number');
            $table->json('config');
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['agency_site_id', 'number'], 'agency_site_revisions_number_unique');
            $table->foreign('agency_site_id', 'agency_site_revisions_site_foreign')
                ->references('id')->on('agency_sites')->cascadeOnDelete();
        });

        Schema::create('agency_site_signups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('agency_workspace_id');
            $table->unsignedBigInteger('agency_site_id');
            $table->unsignedBigInteger('agency_saas_plan_id');
            // Lower-cased; one signup per (Agency, email) — a retry resumes it, never duplicates it.
            $table->string('email', 191);
            $table->string('business_name', 191);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('client_workspace_id')->nullable();
            // started | provisioned | checkout_started | active | abandoned
            $table->string('status', 24)->default('started');
            $table->string('consent_ip', 64)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['agency_workspace_id', 'email'], 'agency_site_signups_agency_email_unique');
            $table->index('client_workspace_id', 'agency_site_signups_client_index');
            $table->foreign('agency_workspace_id', 'agency_site_signups_agency_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();
            $table->foreign('agency_site_id', 'agency_site_signups_site_foreign')
                ->references('id')->on('agency_sites')->restrictOnDelete();
            $table->foreign('agency_saas_plan_id', 'agency_site_signups_plan_foreign')
                ->references('id')->on('agency_saas_plans')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_site_signups');
        Schema::dropIfExists('agency_site_revisions');
        Schema::dropIfExists('agency_sites');
    }
};
