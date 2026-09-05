<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business Data Tenancy Foundation, Pass 1 — DDL only, additive. Adds a
 * nullable business_id to the 11 authorized legacy tables. No NOT NULL
 * enforcement happens in this pass (see the backfill migration's
 * docblock); phone_numbers.business_id in particular stays nullable
 * permanently for pooled/unassigned numbers.
 */
return new class extends Migration {
    private const TABLES = [
        'campaigns', 'reports', 'tracking_logs', 'templates', 'phone_numbers',
        'senderid', 'customer_based_sending_servers', 'contacts', 'contact_groups',
        'blacklists', 'keywords',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->unsignedBigInteger('business_id')->nullable()->after($this->afterColumn($table));
            });
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->index('business_id', "{$table}_business_id_index");
                $blueprint->foreign('business_id', "{$table}_business_id_foreign")
                    ->references('id')->on('businesses')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign("{$table}_business_id_foreign");
                $blueprint->dropIndex("{$table}_business_id_index");
                $blueprint->dropColumn('business_id');
            });
        }
    }

    private function afterColumn(string $table): string
    {
        return match ($table) {
            'contacts', 'contact_groups', 'tracking_logs' => 'customer_id',
            default => 'user_id',
        };
    }
};
