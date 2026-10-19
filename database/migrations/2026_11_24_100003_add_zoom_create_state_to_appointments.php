<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Durable create state. started_at set = a create call may have reached Zoom (outcome unknown until reconciled).
            // lease_until = a live worker owns the create right now (a crashed worker's lease simply expires).
            $table->timestamp('zoom_create_started_at')->nullable();
            $table->timestamp('zoom_create_lease_until')->nullable();
            $table->string('zoom_create_token', 36)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['zoom_create_started_at', 'zoom_create_lease_until', 'zoom_create_token']);
        });
    }
};
