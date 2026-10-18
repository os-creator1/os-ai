<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17B §6 — reusable proposal / contract layouts.
 *
 * WHY A NEW TABLE. A template is a LAYOUT (blocks of headings, text, merge
 * tokens, a signature position, a generic product placeholder). It is neither
 * a document nor a catalog item: it has no Contact, no lines, no prices and no
 * schedule, so it cannot live in business_documents without nullable-ing half
 * of that table. One table serves both owners:
 *
 *   business_id NULL      platform-owned canonical template (Platform Owner).
 *   business_id = X       Business-private template ("Save as template").
 *
 *   template_type   proposal | contract (a label; both create a `proposal`
 *                   document — there is no new DocumentKind).
 *   blocks          the sanitised 17B §2 block list (BlockSchema is the only
 *                   writer-side authority).
 *   lock_version    optimistic-concurrency counter for the template editor.
 *   status          draft | active | archived (archive only; never hard-delete).
 *
 * The (business_id, status) index serves the "My templates" listing and the
 * platform listing (business_id IS NULL) alike.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->string('template_type', 16)->default('proposal');
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->json('blocks');
            $table->unsignedSmallInteger('schema_version')->default(2);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('business_id', 'document_templates_business_foreign')
                ->references('id')->on('businesses')->cascadeOnDelete();
            $table->index(['business_id', 'status'], 'document_templates_business_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
