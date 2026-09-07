<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Website Generation + Hosting Slice A contract §5.1/§33 migration 1
     * of 5. Deliberately created WITHOUT published_revision_id — that
     * column is added by migration 4
     * (add_published_revision_id_to_websites_table), once
     * website_revisions exists, resolving the websites <->
     * website_revisions circular reference explicitly rather than
     * leaving it ambiguous (contract §33).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('websites', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->uuid('public_id')->unique();
                $table->foreignId('business_id')->unique()->constrained('businesses')->restrictOnDelete();
                $table->string('name', 120);
                $table->string('status', 16)->default('draft');
                $table->json('theme')->nullable();
                $table->timestamps();

                $table->index('status');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('websites');
        }
    };
