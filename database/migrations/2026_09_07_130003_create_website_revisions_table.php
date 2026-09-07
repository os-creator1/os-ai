<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Website Generation + Hosting Slice A contract §5.3/§33 migration 3
     * of 5. A revision is write-once and immutable: no updated_at, no
     * soft delete, no status column — nothing in the implementation may
     * UPDATE a row in this table after insert.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('website_revisions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->unsignedInteger('version_number');
                $table->json('snapshot');
                $table->unsignedSmallInteger('schema_version');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->unique(['website_id', 'version_number']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('website_revisions');
        }
    };
