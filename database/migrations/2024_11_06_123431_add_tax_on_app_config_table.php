<?php

    use App\Models\AppConfig;
    use Illuminate\Database\Migrations\Migration;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
            $tax = AppConfig::where('setting', 'tax')->first();
            if ( ! $tax) {

                $data = json_encode([
                    'enabled'      => 'no',
                    'default_rate' => 10,
                    'countries'    => [],
                ]);

                $tax          = new AppConfig();
                $tax->setting = 'tax';
                $tax->value   = $data;
                $tax->save();
            }
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            //
        }

    };
