<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The generator's page-intent map, persisted: what each page IS (its role) and what it is about (its place and/or service),
 * decided when the page is planned instead of being guessed later from its slug. Additive and nullable: a page written
 * before this column existed (or by the owner) has no role and is classified from its content by WebsitePageIntentClassifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_pages', function (Blueprint $table) {
            $table->string('page_role', 24)->nullable()->after('is_home');
            $table->json('page_intent')->nullable()->after('page_role');
        });
    }

    public function down(): void
    {
        Schema::table('website_pages', function (Blueprint $table) {
            $table->dropColumn(['page_role', 'page_intent']);
        });
    }
};
