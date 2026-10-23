<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The idempotency + provenance ledger for imports from another system (first
 * user: GoHighLevel). One row per source record: re-running an import finds the
 * row and skips; a crashed import resumes where it stopped; every imported
 * record can be traced back to its source id. Tenant-scoped by business_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_import_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('source_system', 32);
            $table->string('source_type', 32);
            $table->string('source_id', 64);
            $table->string('local_type', 48);
            $table->unsignedBigInteger('local_id');
            $table->json('meta')->nullable();
            $table->timestamp('imported_at')->useCurrent();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->unique(['business_id', 'source_system', 'source_type', 'source_id'], 'eir_source_unique');
            $table->index(['business_id', 'local_type', 'local_id'], 'eir_local_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_import_records');
    }
};
