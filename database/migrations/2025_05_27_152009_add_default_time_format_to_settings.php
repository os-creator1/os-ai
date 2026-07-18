<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        /**
         * Run the migrations.
         */

        public function up()
        {
            $envPath = base_path('.env');
            $key     = 'APP_TIME_FORMAT';
            $value   = "'g:i A'"; // wrapped with single quotes

            if (file_exists($envPath)) {
                $envContent = file_get_contents($envPath);

                // Normalize line endings and trim spaces
                $envLines = preg_split("/\r\n|\n|\r/", trim($envContent));

                // Filter out existing APP_TIME_FORMAT
                $filteredLines = array_filter($envLines, function ($line) use ($key) {
                    return stripos(trim($line), $key . '=') !== 0;
                });

                // Add the new setting
                $filteredLines[] = "$key=$value";

                // Reconstruct and write back
                $newEnvContent = implode(PHP_EOL, $filteredLines) . PHP_EOL;
                file_put_contents($envPath, $newEnvContent);
            }

        }


        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
        }

    };
