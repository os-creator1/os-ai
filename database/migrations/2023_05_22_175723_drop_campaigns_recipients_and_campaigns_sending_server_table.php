<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('campaigns_recipients');
        Schema::dropIfExists('campaigns_sending_servers');
        Schema::dropIfExists('plans_sending_servers');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
