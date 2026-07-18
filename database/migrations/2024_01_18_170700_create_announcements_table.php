<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
            Schema::create('announcements', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid');
                $table->unsignedBigInteger('user_id')->default(1);
                $table->string('title');
                $table->text('description');
                $table->enum('type', ['email', 'sms'])->default('email');
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('announcements');
        }

    };
