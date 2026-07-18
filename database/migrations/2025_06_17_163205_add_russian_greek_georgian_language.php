<?php

    use App\Models\Language;
    use Illuminate\Database\Migrations\Migration;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
            $get_languages = [

                [
                    'name'     => 'German',
                    'code'     => 'de',
                    'iso_code' => 'de',
                    'status'   => true,
                ],
                [
                    'name'     => 'Russian',
                    'code'     => 'ru',
                    'iso_code' => 'ru',
                    'status'   => true,
                ],
                [
                    'name'     => 'Greek',
                    'code'     => 'el',
                    'iso_code' => 'gr',
                    'status'   => true,
                ],
                [
                    'name'     => 'Georgian',
                    'code'     => 'ka',
                    'iso_code' => 'ge',
                    'status'   => true,
                ],
            ];

            foreach ($get_languages as $language) {
                // Check if language with same code exists
                $existing = Language::where('code', $language['code'])->first();

                if ( ! $existing) {
                    Language::create($language);
                }
            }
        }

    };
