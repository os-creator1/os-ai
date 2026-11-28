<?php

use App\Models\QuestionnaireDefinition;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV3Seeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The gallery step gained an "Award or press badge" photo category (the Luxury Photo Booth badge strip). The V3 seeder publishes its step tree as the NEXT version when it differs from the published one; versions
 * are immutable and every existing response stays pinned to the version it started on, so in-progress setups, completed
 * responses and generated websites are untouched. Only NEW setups see the new questions.
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
