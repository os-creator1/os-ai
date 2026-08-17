<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Design System M2 Slice 1 contract §6.1/§9 item 1. Presets are
     * mutable, named entities (not append-only) — status holds only the
     * three genuine lifecycle values; is_factory is a wholly independent
     * protected marker, never a fourth status value, so every edit/delete
     * guard checks it separately regardless of the row's own lifecycle
     * position. uid is server-generated only (HasUid, never client input)
     * and is the sole public route-binding key, matching this codebase's
     * own established convention for directly URL-bound admin resources
     * (e.g. workspaces.uid). font_uploaded_id and every actor column are
     * deliberately not foreign keys, matching this codebase's own
     * established append-only-audit-column convention.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('platform_theme_presets', function (Blueprint $table) {
                $table->id();
                $table->string('uid', 40)->unique();
                $table->string('name', 191);
                $table->enum('status', ['draft', 'saved', 'active']);
                $table->boolean('is_factory')->default(false);
                $table->json('input_tokens_json');
                $table->json('overrides_json')->nullable();
                $table->json('derived_tokens_json');
                $table->enum('font_choice', ['bundled', 'uploaded']);
                $table->string('font_bundled_key', 60)->nullable();
                $table->unsignedBigInteger('font_uploaded_id')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('is_factory');
                $table->index('font_uploaded_id');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('platform_theme_presets');
        }
    };
