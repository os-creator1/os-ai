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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_content_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('hero_headline', 191)->nullable();
            $table->text('hero_subheadline')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_content_settings');
    }
};
