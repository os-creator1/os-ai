<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('workspaces', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->string('name');
                $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('owner_user_id');
                $table->index('is_active');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('workspaces');
        }
    };
