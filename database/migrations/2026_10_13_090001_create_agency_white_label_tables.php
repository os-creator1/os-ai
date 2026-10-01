<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency V1 completion — the Agency's own white-label identity, and the audit
 * of every change to it.
 *
 * WHY `agency_white_label_settings`. Blueprint §28: the Agency's branding is
 * applied to the client-facing product. Until now no storage existed
 * (`white_label` was Planned; Customer Experience Slice 2 §4.1 recorded "no
 * Workspace branding storage" and left AgencyBrandSource unbound). Branding
 * belongs to the AGENCY Workspace, so the row is keyed `unique(agency_workspace_id)`:
 * exactly one identity per Agency, never one per client, and never readable
 * through any other Agency's id. Clients never own a copy — they resolve the
 * managing Agency's row through the persisted management relationship, so
 * ending that relationship returns them to platform branding with nothing to
 * clean up.
 *
 * It is deliberately NOT the platform's branding (`config('app.*')`/.env,
 * Design System M2): that is one global identity owned by the Platform Owner.
 *
 * `is_enabled` separates "the Agency has saved a draft identity" from "clients
 * see it", so an Agency can prepare branding before switching it on, and
 * switch it off without losing it. Defaults to false: nothing is branded until
 * the owner turns it on.
 *
 * `logo_path` is a relative path under public/images/branding/agency/ written
 * by AgencyWhiteLabelManager from validated image bytes (content-hashed name,
 * never client-derived). No colour, name or URL here is ever trusted raw: the
 * manager validates on write and the presenter re-normalizes on read.
 *
 * WHY `agency_white_label_changes`. Blueprint/Acceptance: a white-label change
 * is a high-impact Agency operation and must be audit-visible. The existing
 * audit architecture is the per-domain, insert-only transition table
 * (workspace_transitions, platform_theme_preset_events,
 * agency_saas_plan_pricing_changes); this is that pattern for this domain. It
 * records who changed what and when (field-level before/after), and never
 * stores the logo bytes. No central Automations or event bus is involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_white_label_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->unsignedBigInteger('agency_workspace_id');

            $table->boolean('is_enabled')->default(false);

            // What a client sees in place of the platform's identity.
            $table->string('display_name', 80);
            $table->string('tagline', 160)->nullable();
            $table->char('accent_color', 7)->nullable();
            $table->string('support_email', 191)->nullable();
            $table->string('logo_path', 191)->nullable();

            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('agency_workspace_id', 'agency_white_label_settings_agency_unique');

            // restrictOnDelete: a Workspace with a brand cannot be deleted out
            // from under it, matching every other Agency-owned table.
            $table->foreign('agency_workspace_id', 'agency_white_label_settings_agency_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();
        });

        Schema::create('agency_white_label_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->unsignedBigInteger('agency_workspace_id');
            $table->unsignedBigInteger('changed_by_user_id');

            // created | updated | enabled | disabled | logo_replaced | logo_removed
            $table->string('change_type', 24);

            // {field: [from, to]} for every field that actually changed.
            $table->json('changes')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['agency_workspace_id', 'id'], 'agency_white_label_changes_agency_index');

            $table->foreign('agency_workspace_id', 'agency_white_label_changes_agency_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_white_label_changes');
        Schema::dropIfExists('agency_white_label_settings');
    }
};
