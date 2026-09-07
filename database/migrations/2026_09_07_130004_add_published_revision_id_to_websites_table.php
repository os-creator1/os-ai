<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Website Generation + Hosting Slice A contract §5.1/§33 migration 4
     * of 5 — the only migration in this feature that alters a table
     * created earlier in this same set. Resolves the websites <->
     * website_revisions circular reference explicitly: this column
     * cannot exist until website_revisions (migration 3) does.
     *
     * down() drops the foreign key constraint FIRST, then the column —
     * never the reverse order, and never via disabling FK checks.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('websites', function (Blueprint $table) {
                $table->foreignId('published_revision_id')->nullable()
                    ->after('status')
                    ->constrained('website_revisions')->nullOnDelete();
            });
        }

        public function down(): void
        {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropForeign(['published_revision_id']);
            });

            Schema::table('websites', function (Blueprint $table) {
                $table->dropColumn('published_revision_id');
            });
        }
    };
