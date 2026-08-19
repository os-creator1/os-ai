<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Design System M2 Slice 1 contract §6.9/§9 item 2. Uploaded webfont
     * registry. safe_filename is server-generated (sha256 of validated
     * bytes + validated extension) and is the only value ever used to
     * build a storage path — original_filename is display-only.
     * Soft-deleted (deleted_at) rather than hard-deleted, so historical
     * preset-event metadata that once referenced a font stays legible
     * after the bytes are gone. uploaded_by_user_id is deliberately not a
     * foreign key, matching this codebase's own established convention.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('platform_theme_fonts', function (Blueprint $table) {
                $table->id();
                $table->string('safe_filename', 191)->unique();
                $table->string('original_filename', 191);
                $table->string('mime_type', 100);
                $table->unsignedInteger('file_size_bytes');
                $table->string('storage_path', 255);
                $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('platform_theme_fonts');
        }
    };
