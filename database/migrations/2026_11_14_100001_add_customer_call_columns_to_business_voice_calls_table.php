<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer outbound calls reuse business_voice_calls. Additive and nullable so existing admin test-call rows are
 * untouched: an opaque public uid for the CRM interface, who/what the call was for, and a per-business idempotency key
 * so a repeated request can never dial twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_voice_calls', function (Blueprint $table): void {
            $table->char('uid', 36)->nullable()->unique();
            $table->string('kind', 16)->default('admin_test'); // admin_test | customer
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->unique(['business_id', 'idempotency_key'], 'bvc_business_idempotency_unique');
            $table->index(['business_id', 'status', 'created_at'], 'bvc_business_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('business_voice_calls', function (Blueprint $table): void {
            $table->dropUnique('bvc_business_idempotency_unique');
            $table->dropIndex('bvc_business_status_created_index');
            $table->dropColumn(['uid', 'kind', 'contact_id', 'initiated_by_user_id', 'idempotency_key']);
        });
    }
};
