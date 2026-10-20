<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correction round 3. Additive only.
 *
 *  voice_calls.leg_teardown_exhausted_at  when provider-hangup retries ran out (a provider leg may remain);
 *                                         stamped once under the row lock so the alert is emitted once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->timestamp('leg_teardown_exhausted_at')->nullable()->after('leg_teardown_at');
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->dropColumn('leg_teardown_exhausted_at');
        });
    }
};
