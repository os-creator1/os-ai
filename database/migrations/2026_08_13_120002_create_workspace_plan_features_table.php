<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-004 §10.2 — packaging only. Existence of a row means a tier
     * structurally includes that feature; says nothing about implementation
     * availability (PlatformFeatureRegistry, §11, checked separately).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('workspace_plan_features', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_plan_catalog_id')->constrained('workspace_plan_catalog')->restrictOnDelete();
                $table->string('feature_key', 64);
                $table->timestamps();

                $table->unique(['workspace_plan_catalog_id', 'feature_key']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('workspace_plan_features');
        }
    };
