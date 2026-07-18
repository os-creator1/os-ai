<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('failed_contact_imports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('contact_group_id')->constrained('contact_groups')->onDelete('cascade');
                $table->json('record_data'); // Stores the full failed record
                $table->text('reason'); // Stores the validation error or blacklist reason
                $table->string('job_id')->nullable();
                $table->timestamps();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('failed_contact_imports');
        }

    };
