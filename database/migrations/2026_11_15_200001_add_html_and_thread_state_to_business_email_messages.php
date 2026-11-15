<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email conversations: the sanitized HTML alternative of an outbound message, and the three thread actions of the Email
 * view (archive, mark unread, delete from MotionGrove). All additive and nullable, so every existing row is untouched.
 * Delete is a soft delete inside MotionGrove only; the mailbox at the provider is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_email_messages', function (Blueprint $table): void {
            $table->mediumText('body_html')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('marked_unread_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->index(['business_id', 'deleted_at'], 'bem_business_deleted_index');
        });
    }

    public function down(): void
    {
        Schema::table('business_email_messages', function (Blueprint $table): void {
            $table->dropIndex('bem_business_deleted_index');
            $table->dropColumn(['body_html', 'archived_at', 'marked_unread_at', 'deleted_at']);
        });
    }
};
