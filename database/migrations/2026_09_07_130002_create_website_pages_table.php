<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Website Generation + Hosting Slice A contract §5.2/§33 migration 2
     * of 5. Flat page model — no nested tree. The homepage's slug is
     * always NULL; MySQL treats multiple NULLs as distinct under a
     * unique index, so (website_id, slug) is safe with more than one
     * NULL-slug row never occurring in practice (the is_home invariant
     * itself is enforced at the application layer transactionally,
     * contract §6.2 — MySQL has no native filtered unique index).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('website_pages', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->string('title', 150);
                $table->string('slug', 80)->nullable();
                $table->boolean('is_home')->default(false);
                $table->json('sections');
                $table->string('seo_title', 70)->nullable();
                $table->string('meta_description', 160)->nullable();
                $table->boolean('noindex')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['website_id', 'slug']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('website_pages');
        }
    };
