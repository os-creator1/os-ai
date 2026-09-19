<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17 §5.4, Sub-slice A — line items belong to a
 * VERSION, not to the document: that is what makes an issued document's
 * commercial content immutable (§5.3.1).
 *
 * Write-once discipline: `created_at` only, no `updated_at` (mirrors
 * package_snapshots / website_revisions). A draft version's lines are
 * replaced/re-created by the manager rather than updated in place.
 *
 * `package_snapshot_uid` is a plain uuid column with NO foreign key, by
 * design (§5.4, §12.A): it references Contract 16's package_snapshots by
 * `uid` (never `id`), which keeps 17A independent of Contract 16 B/C/D and
 * keeps the immutable snapshot table free of inbound FK coupling. Each line
 * also stores its own denormalized copy so rendering never depends on another
 * module's current row shape.
 *
 * `source = custom` is the contract's one flagged reasonable-default
 * exercise (§5.4); a custom line has a NULL package_snapshot_uid.
 *
 * Money is unsignedBigInteger minor units + char(3) currency — never signed,
 * never micro-units (that is lane D's convention).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_document_line_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_document_version_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('source', 16);
            $table->uuid('package_snapshot_uid')->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->char('currency_code', 3);
            $table->timestamp('created_at')->nullable();

            $table->foreign('business_document_version_id', 'bdli_version_foreign')
                ->references('id')->on('business_document_versions')->cascadeOnDelete();

            $table->index(['business_document_version_id', 'position'], 'bdli_version_position_index');
            $table->index('package_snapshot_uid', 'bdli_snapshot_uid_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_line_items');
    }
};
