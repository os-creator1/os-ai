<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Website Guided Generation contract §5.3, §16 Slice 1, migration 4 of
     * 5. Append-only change ledger, mirroring the established bespoke
     * per-feature ledger pattern (business_google_operations, §2/§27) --
     * never a generic activity-log package. Rows are never updated or
     * deleted by application code (business_id cascade-deletes with the
     * Business itself, matching every other Business-scoped table). A
     * location-scoped `hours` change JSON-encodes the business_location_id
     * inside old_value/new_value (§5.3) -- no separate column, since this
     * table stays business_id-scoped.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_knowledge_profile_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->string('field_key', 40);
                $table->json('old_value')->nullable();
                $table->json('new_value')->nullable();
                $table->string('source', 24);
                $table->foreignId('actor_user_id')->constrained('users');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['business_id', 'field_key']);
                $table->index(['business_id', 'created_at']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_knowledge_profile_changes');
        }
    };
