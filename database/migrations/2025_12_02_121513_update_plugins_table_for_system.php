<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            // 1. Handle Column Creation
            Schema::table('plugins', function (Blueprint $table) {
                if ( ! Schema::hasColumn('plugins', 'vendor')) {
                    $table->string('vendor')->after('id');
                }
                if ( ! Schema::hasColumn('plugins', 'path')) {
                    $table->string('path')->after('vendor');
                }
            });

            // 2. Handle Unique Index (Prefix Agnostic)
            $indexes = Schema::getIndexes('plugins');

            // We check if any existing index covers the 'vendor' and 'name' columns
            $hasIndex = collect($indexes)->contains(function ($index) {
                return $index['columns'] === ['vendor', 'name'] && $index['unique'];
            });

            if ( ! $hasIndex) {
                Schema::table('plugins', function (Blueprint $table) {
                    $table->unique(['vendor', 'name']);
                });
            }
        }

        public function down(): void
        {
            Schema::table('plugins', function (Blueprint $table) {
                $indexes = Schema::getIndexes('plugins');

                // Find the specific name assigned to the unique index on these columns
                $indexName = collect($indexes)
                    ->where('columns', ['vendor', 'name'])
                    ->first()['name'] ?? null;

                if ($indexName) {
                    $table->dropUnique($indexName);
                }

                if (Schema::hasColumn('plugins', 'vendor')) $table->dropColumn('vendor');
                if (Schema::hasColumn('plugins', 'path')) $table->dropColumn('path');
            });
        }

    };
