<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The person's own Light / Dark / System preference. NULL means "never
     * chosen" and renders as the existing light interface, so rollout changes
     * nothing for existing users.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('appearance', 10)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('appearance');
        });
    }
};
