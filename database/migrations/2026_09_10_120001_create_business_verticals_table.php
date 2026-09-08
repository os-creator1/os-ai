<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Website Guided Generation contract §6.1, §16 Slice 2, migration 1 of
     * 2. An operator-controlled catalog table, never a PHP enum case per
     * trade (§6.1 correction) -- adding a new supported vertical is a
     * pure data-seeding operation. `business_knowledge_profiles.
     * vertical_key` (Slice 1) is validated against this table's `key`
     * (`is_active = true`) by BusinessKnowledgeProfileManager, closing
     * the Slice 1 gap where any non-null vertical_key failed safely
     * because this table did not yet exist.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_verticals', function (Blueprint $table) {
                $table->id();
                $table->string('key', 40)->unique();
                $table->string('display_name', 80);
                $table->string('broad_industry', 40)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_verticals');
        }
    };
