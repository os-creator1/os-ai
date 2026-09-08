<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Website Guided Generation contract §6.2/§6.3, §16 Slice 2,
     * migration 2 of 2. Packs are immutable once referenced -- a new
     * question or changed wording ships as a new `version` row under the
     * same `key`, never an in-place edit (mirrors WebsiteRevision's own
     * immutability discipline). `applies_to_vertical_key` references
     * business_verticals.key (nullable FK; a pack need not target a
     * vertical at all). At most one of applies_to_industry/
     * applies_to_vertical_key may be non-null per row -- enforced at the
     * application level (BusinessKnowledgeProfileManager /
     * QuestionPack), since this Laravel/MySQL version has no portable
     * multi-column CHECK-constraint builder in this codebase's existing
     * migration conventions.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('question_packs', function (Blueprint $table) {
                $table->id();
                $table->string('key', 40);
                $table->string('applies_to_industry', 40)->nullable();
                $table->string('applies_to_vertical_key', 40)->nullable();
                $table->unsignedInteger('version');
                $table->json('questions');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['key', 'version']);
                $table->index(['applies_to_vertical_key', 'is_active']);
                $table->index(['applies_to_industry', 'is_active']);

                $table->foreign('applies_to_vertical_key')->references('key')->on('business_verticals')->nullOnDelete();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('question_packs');
        }
    };
