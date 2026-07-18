<?php

    use App\Models\Language;
    use Illuminate\Database\Migrations\Migration;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
            $existing = Language::where('code', 'th')->first();

            if ( ! $existing) {
                Language::create([
                    'name'     => 'Thai',
                    'code'     => 'th',
                    'iso_code' => 'th',
                    'status'   => true,
                ]);
            }
        }

    };
