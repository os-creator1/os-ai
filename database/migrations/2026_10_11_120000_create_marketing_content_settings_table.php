<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public Marketing Homepage contract. A singleton settings row for the
 * Platform Owner's editable hero copy on the public marketing homepage —
 * prose content edited through a form, not a config value, so it is not
 * folded into AppConfig::setEnv()'s six-field branding mechanism. Exactly
 * one row ever exists; MarketingContentSettings::current() enforces that.
 *
 * Review correction: a fixed, unique `singleton_key` column is the
 * database-level half of that guarantee — without it, two concurrent
 * first requests could both observe an empty table and both insert a row.
 * The unique index makes a second concurrent insert fail outright rather
 * than silently succeed; MarketingContentSettingsRepository::current()
 * is the app-level half, catching that failure and re-reading the
 * winner's row. Edited in place rather than a second migration, since
 * this one has not yet merged/released.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_content_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 40)->default('default');
            $table->string('hero_headline', 191)->nullable();
            $table->text('hero_subheadline')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('singleton_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_content_settings');
    }
};
