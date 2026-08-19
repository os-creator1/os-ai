<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Design System M2 Slice 1 contract §6.1/§9 item 3. Append-only,
     * immutable audit trail for preset lifecycle events — this codebase's
     * own established idiom (workspace_entitlement_transitions,
     * business_feature_toggles), scoped to presets. preset_id is
     * deliberately a plain scalar with no foreign key (internal reference
     * only — locked decision: never accept uid here, uid is a route-
     * binding concern, not an internal audit-log concern). No updated_at:
     * this table is genuinely immutable.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('platform_theme_preset_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('preset_id');
                $table->enum('event_type', [
                    'created', 'duplicated', 'edited', 'renamed',
                    'activated', 'rolled_back', 'deleted',
                ]);
                $table->json('metadata_json')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index('preset_id');
                $table->index('event_type');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('platform_theme_preset_events');
        }
    };
