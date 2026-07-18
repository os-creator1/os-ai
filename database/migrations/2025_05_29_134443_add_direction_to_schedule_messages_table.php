<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\DB;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
            Schema::table('schedule_messages', function (Blueprint $table) {
                $table->enum('direction', ['outgoing', 'incoming', 'api'])->after('send_by')->nullable();
            });

            DB::table('schedule_messages')
                ->whereNotNull('send_by')
                ->update([
                    'direction' => DB::raw("
            CASE
                WHEN send_by = 'from' THEN 'outgoing'
                WHEN send_by = 'to' THEN 'incoming'
                WHEN send_by = 'api' THEN 'api'
                ELSE direction
            END
        "),
                ]);
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::table('schedule_messages', function (Blueprint $table) {
                $table->dropColumn('direction');
            });
        }

    };
