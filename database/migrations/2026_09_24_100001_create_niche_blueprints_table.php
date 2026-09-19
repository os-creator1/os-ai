<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract 20 §5.1, Sub-slice A, migration 1 of 4 — Blueprint identity: one
 * row per niche in the Platform Template Library (Blueprint §30).
 *
 * Identity is separated from versions (migration 2) because it outlives every
 * one of them: installation records reference the Blueprint plus the
 * `version_number` they were made from, never a version row's id.
 *
 * `vertical_key` IS UNIQUE AND NULLABLE, AND BOTH HALVES ARE DELIBERATE.
 * Unique gives "at most one Blueprint per vertical", so signup's niche ->
 * Blueprint resolution is decided by the database rather than by query order.
 * Nullable, combined with MySQL allowing many NULLs in a unique index, means a
 * Blueprint not yet bound to a vertical simply cannot be resolved by signup —
 * fail-closed, and the reason this table does not repeat QuestionPack's
 * documented determinism problem (`assertNoCompetingActiveFamily` exists only
 * because its scope columns are nullable and therefore unconstrainable).
 *
 * `broad_industry` is the coarse fallback (§7.1) and is deliberately NOT
 * unique: a vertical-bound Blueprint and a broad fallback may legitimately
 * share an industry. It holds a BusinessIndustry value as a plain string,
 * validated in the domain layer exactly as `question_packs.applies_to_industry`
 * is — never an enum column, matching the existing convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('niche_blueprints', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid');
            $table->string('key', 40);
            $table->string('display_name', 80);
            $table->string('vertical_key', 40)->nullable();
            $table->string('broad_industry', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('uid', 'nb_uid_unique');
            $table->unique('key', 'nb_key_unique');

            // At most one Blueprint per vertical. Many NULLs are permitted and
            // are the unbound state.
            $table->unique('vertical_key', 'nb_vertical_key_unique');

            // §7.1 resolution step 2: the active broad-industry fallback lookup.
            $table->index(['is_active', 'broad_industry'], 'nb_active_industry_index');

            $table->foreign('vertical_key', 'nb_vertical_foreign')
                ->references('key')->on('business_verticals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('niche_blueprints');
    }
};
