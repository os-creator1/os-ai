<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17 §5.9, Sub-slice A — the payment schedule
 * BELONGS TO THE DOCUMENT VERSION, never to the document.
 *
 * §5.3.1 makes schedule commercial terms part of what freezes when a version
 * is issued and part of that version's content_hash; a document-scoped
 * schedule cannot implement that. This table therefore has
 * `business_document_version_id` and deliberately NO `business_document_id`:
 * every schedule read/write resolves through an exact Document Version, and
 * revising a sent document COPIES the commercial terms into new rows for the
 * new version while the old version's rows are never mutated.
 *
 * Commercial terms (frozen at issue): sequence, kind, amount_minor,
 * currency_code, due_at. Progress fields (status, paid_at, reminder_*) are not
 * commercial content and stay mutable for the currently payable version
 * (§5.3.1) — which is why this table keeps `updated_at`.
 *
 * `unique(business_document_version_id, sequence)` is one half of the
 * deposit+balance-only rule (Blueprint §34): a version has one `full` item or
 * exactly `deposit` then `balance`; the sequence ∈ {1,2} and the sum-to-total
 * rules are enforced by the later manager.
 *
 * Reminder markers live on the row, written by the manager inside the locked
 * transaction that selects it — never by a job (§8.4).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_document_payment_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_document_version_id');
            $table->unsignedTinyInteger('sequence');
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->timestamp('due_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reminder_last_sent_at')->nullable();
            $table->unsignedTinyInteger('reminder_count')->default(0);
            $table->timestamps();

            $table->foreign('business_document_version_id', 'bdpsi_version_foreign')
                ->references('id')->on('business_document_versions')->cascadeOnDelete();

            $table->unique(['business_document_version_id', 'sequence'], 'bdpsi_version_sequence_unique');
            $table->index(['status', 'due_at'], 'bdpsi_status_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_payment_schedule_items');
    }
};
