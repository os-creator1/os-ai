<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Microsoft Graph's incremental cursor is a delta URL (hundreds of characters),
 * longer than the 64-character Gmail historyId the column was sized for.
 * Widening to TEXT keeps every existing value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_email_accounts', function (Blueprint $table) {
            $table->text('inbound_cursor')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Values longer than 64 characters (Graph delta URLs) cannot be kept;
        // clearing them makes the next sync restart from the bounded window.
        \Illuminate\Support\Facades\DB::table('business_email_accounts')->whereRaw('CHAR_LENGTH(inbound_cursor) > 64')->update(['inbound_cursor' => null]);

        Schema::table('business_email_accounts', function (Blueprint $table) {
            $table->string('inbound_cursor', 64)->nullable()->change();
        });
    }
};
