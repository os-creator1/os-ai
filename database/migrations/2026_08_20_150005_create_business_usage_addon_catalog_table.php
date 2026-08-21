<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 §18, M4 contract §10/§18 — structural machinery only. Zero rows
 * at M4 launch; no seeder of any kind is authorized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_usage_addon_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('addon_key', 64)->unique();
            $table->string('display_name', 191);
            $table->bigInteger('price_micro');
            $table->unsignedBigInteger('currency_id');
            $table->string('fulfillment_mode', 24);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_usage_addon_catalog');
    }
};
