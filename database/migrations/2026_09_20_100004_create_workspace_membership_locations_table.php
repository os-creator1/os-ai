<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 02 (Location ACL Foundation) §5 — the canonical
 * equivalent of workspace_membership_locations (Addendum §4), mirroring
 * database/migrations/2026_07_30_120003_create_workspace_membership_businesses_table.php
 * exactly, substituting business_location_id for business_id.
 *
 * restrictOnDelete() on business_location_id, NOT cascade — deliberately
 * different from business_locations.business_id's own onDelete('cascade'):
 * a grant row is Location-scoping metadata belonging to the MEMBERSHIP,
 * not data that should vanish silently if the Location record itself is
 * ever hard-deleted (which, per the Archived-not-deleted convention,
 * should not normally happen anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_membership_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_membership_id')->constrained('workspace_memberships')->restrictOnDelete();
            $table->foreignId('business_location_id')->constrained('business_locations')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['workspace_membership_id', 'business_location_id'], 'workspace_membership_locations_membership_location_unique');
            $table->index('business_location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_membership_locations');
    }
};
