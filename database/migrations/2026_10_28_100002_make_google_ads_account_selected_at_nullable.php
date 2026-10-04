<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads Module V1 (contract 23 §3) — `google_ads_accounts.selected_at`
 * becomes NULLABLE.
 *
 * Why: disconnect (and revoke) keeps the account row and its facts for
 * history, but the Business must no longer read that data as its live
 * account. A NULL `selected_at` means "no selected account": the pages show
 * the account-selection state, sync and mutations refuse, and re-selecting
 * the SAME customer simply stamps `selected_at` again (facts and targets are
 * kept), while a DIFFERENT customer purges as before. No new table or column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_ads_accounts', function (Blueprint $table) {
            $table->timestamp('selected_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('google_ads_accounts')->whereNull('selected_at')->update(['selected_at' => DB::raw('created_at')]);

        Schema::table('google_ads_accounts', function (Blueprint $table) {
            $table->timestamp('selected_at')->nullable(false)->change();
        });
    }
};
