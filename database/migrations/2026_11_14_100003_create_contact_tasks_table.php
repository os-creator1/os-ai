<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's to-do about a contact (first producer: an automation's manual "call the lead" step).
 * Tenant-scoped; at most one task per (enrollment, node), so a retried or replayed step can never double-create.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('node_id')->nullable();
            $table->string('kind', 16)->default('call');
            $table->string('title', 255);
            $table->timestamp('due_at')->nullable();
            $table->string('status', 12)->default('open'); // open | done | cancelled
            $table->string('source', 24)->default('automation');
            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->unique(['enrollment_id', 'node_id'], 'contact_tasks_step_unique');
            $table->index(['business_id', 'status', 'due_at'], 'contact_tasks_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tasks');
    }
};
