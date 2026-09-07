<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Website Generation + Hosting Slice A contract §5.4/§33 migration 5
     * of 5. business_id is deliberately NOT duplicated here — tenancy is
     * derived by joining through website_id -> websites.business_id
     * (contract §5.4/§12).
     *
     * first_published_at (Correction 1, contract §13.1) is a durable,
     * monotonic marker: NULL until the asset first appears in a
     * successful publish, set exactly once, never cleared by a later
     * publish that omits the asset. Any asset with a non-null value here
     * can never be physically deleted in Slice A — this is what
     * guarantees every retained historical revision remains
     * rollback-safe, without an expensive historical JSON scan, a
     * reference-count table, or a revision-assets pivot.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('website_assets', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->string('disk', 32);
                $table->string('path', 255);
                $table->string('mime_type', 64);
                $table->unsignedInteger('size');
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->string('alt_text', 160)->nullable();
                $table->string('content_hash', 64)->nullable();
                $table->timestamp('first_published_at')->nullable();
                $table->timestamps();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('website_assets');
        }
    };
