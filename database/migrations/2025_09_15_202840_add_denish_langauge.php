<?php

    use App\Models\Language;
    use Illuminate\Database\Migrations\Migration;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {

            $existing = Language::where('code', 'da')->first();

            if ( ! $existing) {
                Language::create([
                    'name'     => 'Danish',
                    'code'     => 'da',
                    'iso_code' => 'dk',
                    'status'   => true,
                ]);
            }
        }

    };
