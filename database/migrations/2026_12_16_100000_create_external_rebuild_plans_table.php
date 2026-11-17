<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner-reviewable plan for rebuilding an externally hosted website as a MotionGrove-hosted one. One row per
 * Business. It is only a plan: it never touches the live external website, the Business's address, a domain or a
 * published revision. Tenant-scoped by business_id; the crawl it was built from is kept as provenance only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_rebuild_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('crawl_id')->nullable();
            $table->string('source_host', 255);
            $table->string('status', 16)->default('draft'); // draft | confirmed | started
            $table->json('pages');
            $table->json('facts');
            $table->json('decisions')->nullable();
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id', 'erp_business_fk')->references('id')->on('businesses')->cascadeOnDelete();
            $table->unique('business_id', 'erp_business_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_rebuild_plans');
    }
};
