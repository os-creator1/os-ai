<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Text messaging setup/number/compliance hub — a US local number needs
 * 10DLC registration; a US toll-free number needs toll-free verification
 * instead. Neither the identity nor the number table records which regime
 * applies, so this is purely additive: `number_type` defaults to `local`
 * (every number provisioned before this migration is a 10DLC-eligible
 * local number under Candidate B; no toll-free number was ever
 * provisioned, since nothing provisioned any number at all yet), never
 * backfilled from a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->string('number_type', 16)->default('local')->after('phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->dropColumn('number_type');
        });
    }
};
