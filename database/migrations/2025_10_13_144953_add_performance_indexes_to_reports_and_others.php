<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
            Schema::table('reports', function (Blueprint $table) {
                $table->string('status')->change();

                $table->index(['user_id', 'created_at'], 'idx_reports_user_id_created_at');
                $table->index([DB::raw('status(50)'), DB::raw('customer_status(50)')], 'idx_reports_status_customer_status');
                $table->index('sending_server_id', 'idx_reports_sending_server_id');
            });

            // reports.direction ships via 2025_05_29_131834_add_direction_to_reports_table.php.bak,
            // which is currently disabled (the .bak extension means the migrator never runs it).
            // Only index the column when it genuinely exists so a fresh migration run doesn't fail.
            if (Schema::hasColumn('reports', 'direction')) {
                Schema::table('reports', function (Blueprint $table) {
                    $table->index(['sms_type', 'direction'], 'idx_reports_sms_type_direction');
                });
            }

            Schema::table('invoices', function (Blueprint $table) {
                $table->index('created_at', 'idx_invoices_created_at');
            });

            Schema::table('customers', function (Blueprint $table) {
                $table->index('created_at', 'idx_customers_created_at');
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::table('reports', function (Blueprint $table) {
                $table->dropIndex('idx_reports_user_id_created_at');
                $table->dropIndex('idx_reports_status_customer_status');
                $table->dropIndex('idx_reports_sending_server_id');
            });

            if (Schema::hasIndex('reports', 'idx_reports_sms_type_direction')) {
                Schema::table('reports', function (Blueprint $table) {
                    $table->dropIndex('idx_reports_sms_type_direction');
                });
            }

            Schema::table('invoices', function (Blueprint $table) {
                $table->dropIndex('idx_invoices_created_at');
            });

            Schema::table('customers', function (Blueprint $table) {
                $table->dropIndex('idx_customers_created_at');
            });
        }

    };
