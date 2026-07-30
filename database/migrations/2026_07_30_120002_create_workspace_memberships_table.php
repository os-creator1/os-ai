<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('workspace_memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->string('role', 32);
                $table->string('business_access_scope', 32);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['workspace_id', 'user_id']);
                $table->index('user_id');
                $table->index(['workspace_id', 'is_active']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('workspace_memberships');
        }
    };
