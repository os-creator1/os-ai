<?php

use App\Models\QuestionnaireDefinition;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV2Seeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Deploying the smarter Website setup must not silently leave an existing
 * installation on v1 because nobody ran a class-specific seeder (same
 * precedent as `seed_seo_citation_directories`: reference data ships with
 * the deploy, without anyone running `db:seed`).
 *
 * It is deliberately conditional. It only acts on an installation that
 * ALREADY provisioned the Photo Booth setup questionnaire (a definition with
 * a published version) — publishing v2 as the next version of that same
 * definition. It never creates the definition from nothing: a fresh database
 * gets both versions from DatabaseSeeder (the V2 seeder ensures v1 first),
 * and one that never provisioned the questionnaire keeps showing "setup
 * unavailable" exactly as before.
 *
 * Safe by construction: versions are immutable and every response is pinned
 * to the version it started on, so in-progress sessions, completed responses
 * and generated websites are untouched; only NEW setups resolve the newest
 * published version. The V2 seeder is idempotent (a no-op once the published
 * tree equals v2), so re-running this migration or the seeder creates no
 * second version.
 */
return new class extends Migration
{
    public function up(): void
    {
        $provisioned = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->first();

        if ($provisioned === null || $provisioned->publishedVersion() === null) {
            return;
        }

        (new PhotoboothWebsiteSetupQuestionnaireV2Seeder())->run();
    }

    public function down(): void
    {
        // Published questionnaire versions are immutable history; nothing to undo.
    }
};
