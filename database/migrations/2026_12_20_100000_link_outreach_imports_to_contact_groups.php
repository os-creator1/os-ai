<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One import → one Contact Group. This is the durable provenance between the CRM (Contact Group, Contacts) and
 * Outreach (the canonical Workspace prospect), without a second group system:
 *
 *  - agency_prospect_group_links: which Contact Groups reference which logical prospect. One row per
 *    (group, prospect); a prospect keeps ONE identity (unique workspace+phone) however many groups reference it.
 *  - import rows remember the Contact they produced, so a retry never creates a second one.
 *  - the batch remembers its extra-column plan (new custom field / reuse / ignore) and its group report.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('agency_prospect_group_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('contact_group_id');
            $table->foreignId('prospect_id')->constrained('agency_prospects')->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable();          // the Contact that carries the number (may live in another group)
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->boolean('contact_in_group')->default(true);            // false = referenced: the Contact belongs to another group
            $table->timestamps();

            $table->unique(['contact_group_id', 'prospect_id'], 'apgl_group_prospect_unique');
            $table->index(['workspace_id', 'prospect_id'], 'apgl_ws_prospect');
            $table->index('contact_id');
            $table->index('import_batch_id');
        });

        Schema::table('agency_prospect_import_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->nullable()->after('prospect_id');
        });

        Schema::table('agency_prospect_import_batches', function (Blueprint $table) {
            $table->json('extra_fields')->nullable()->after('mapping');   // header => {action: new|existing|ignore, definition_uid?, label?}
            $table->json('group_report')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('agency_prospect_import_batches', fn (Blueprint $t) => $t->dropColumn(['extra_fields', 'group_report']));
        Schema::table('agency_prospect_import_rows', fn (Blueprint $t) => $t->dropColumn('contact_id'));
        Schema::dropIfExists('agency_prospect_group_links');
    }
};
