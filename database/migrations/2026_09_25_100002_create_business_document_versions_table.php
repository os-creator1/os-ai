<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17 §5.3, Sub-slice A — the immutability boundary.
 *
 * Commercial content (content, content_hash, totals, currency, and — via the
 * child tables — line items and schedule commercial terms) is immutable once a
 * version is `issued`; `state` may make exactly one authorized transition
 * afterwards, issued -> superseded (§5.3.1). The row itself is therefore NOT
 * physically write-once, which is why it keeps `updated_at`: a DRAFT version
 * is edited in place (§7.1) and `state` transitions. The contract's §5.3
 * block reads "created_at timestamp only"; §18.A's implementation prompt
 * states the row "keeps timestamps". Those disagree, and the mechanically
 * consistent reading is the latter.
 *
 * `business_document_id` is RESTRICT, not the CASCADE §5.3's block lists.
 * MySQL forbids a foreign key with a CASCADE/SET NULL referential action on
 * the base column of a STORED generated column, and `draft_guard` (below) is
 * generated from this column — the same reason the
 * automation_workflow_versions precedent uses restrictOnDelete on its own
 * guard base. Documents are voided, never hard-deleted (§7.1), so RESTRICT
 * costs nothing.
 *
 * `draft_guard` is the automation_workflow_versions technique: MySQL has no
 * partial unique index, so "at most ONE draft version per document" is a
 * STORED generated column plus a plain UNIQUE key (§3.5, §5.3).
 *
 * `content_hash` is char(64) storage for the canonical frozen-commercial
 * SHA-256 defined in §5.3.2. It is nullable only because a DRAFT has no hash
 * yet; nothing here computes it — that is Sub-slice B/C.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_document_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_document_id');
            $table->unsignedInteger('version_number');
            $table->string('state', 16)->default('draft');
            $table->json('content');
            $table->char('content_hash', 64)->nullable();
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->char('currency_code', 3);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('business_document_id', 'bdv_document_foreign')
                ->references('id')->on('business_documents')->restrictOnDelete();

            $table->unique(['business_document_id', 'version_number'], 'bdv_document_version_number_unique');
            $table->index(['business_document_id', 'state'], 'bdv_document_state_index');
        });

        Schema::table('business_document_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('draft_guard')
                ->nullable()
                ->storedAs("CASE WHEN state = 'draft' THEN business_document_id ELSE NULL END")
                ->after('state');
        });

        Schema::table('business_document_versions', function (Blueprint $table) {
            $table->unique('draft_guard', 'bdv_draft_guard_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_versions');
    }
};
