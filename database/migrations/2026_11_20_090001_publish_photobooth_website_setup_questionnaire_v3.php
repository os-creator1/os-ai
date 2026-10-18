<?php

use App\Models\QuestionnaireDefinition;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV3Seeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Deploying the guided, one-question-per-page Website setup must not leave an
 * existing installation on v2 because nobody ran a class-specific seeder (the
 * same precedent as the v2 publication migration).
 *
 * Conditional exactly like it: it only acts on an installation that ALREADY
 * provisioned the Photo Booth setup questionnaire (a definition with a
 * published version) and publishes v3 as the next version of that same
 * definition. A fresh database gets every version from DatabaseSeeder.
 *
 * Safe by construction: versions are immutable and every response is pinned to
 * the version it started on, so in-progress sessions, completed responses and
 * generated websites are untouched; only NEW setups resolve the newest
 * published version. The v3 seeder is idempotent (a no-op once the published
 * tree equals v3).
 */
return new class extends Migration
{
    public function up(): void
    {
        $provisioned = QuestionnaireDefinition::where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY)->first();

        if ($provisioned === null || $provisioned->publishedVersion() === null) {
            return;
        }

        (new PhotoboothWebsiteSetupQuestionnaireV3Seeder())->run();
    }

    public function down(): void
    {
        // Published questionnaire versions are immutable history; nothing to undo.
    }
};
