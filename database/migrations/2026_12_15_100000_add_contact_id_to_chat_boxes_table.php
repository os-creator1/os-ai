<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A verified link from an SMS conversation to its Contact, written only at the moment a
 * manual phone change would otherwise orphan the conversation (see
 * ChatBox::linkConversationsToContact). Nullable: nothing is backfilled and nothing is
 * guessed. chat_boxes.to is never changed — historical recipient numbers stay as sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_boxes', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->nullable()->after('business_id');
            $table->index(['business_id', 'contact_id'], 'chat_boxes_business_contact_index');
        });
    }

    public function down(): void
    {
        Schema::table('chat_boxes', function (Blueprint $table) {
            $table->dropIndex('chat_boxes_business_contact_index');
            $table->dropColumn('contact_id');
        });
    }
};
