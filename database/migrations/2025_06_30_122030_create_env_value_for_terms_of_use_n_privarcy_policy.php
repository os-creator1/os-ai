<?php

    use Illuminate\Database\Migrations\Migration;

    return new class extends Migration {

        public function up()
        {
            $envPath = base_path('.env');

            // Key-value pairs to update
            $envUpdates = [
                'TERMS_OF_USE'   => false,
                'PRIVACY_POLICY' => false,
            ];

            if (file_exists($envPath)) {
                $envContent = file_get_contents($envPath);
                $envLines   = preg_split("/\r\n|\n|\r/", trim($envContent));

                // Filter out existing keys
                $filteredLines = array_filter($envLines, function ($line) use ($envUpdates) {
                    foreach (array_keys($envUpdates) as $key) {
                        if (stripos(trim($line), $key . '=') === 0) {
                            return false;
                        }
                    }

                    return true;
                });

                // Append new key-value pairs
                foreach ($envUpdates as $key => $value) {
                    $filteredLines[] = "$key=$value";
                }

                // Write back updated env
                $newEnvContent = implode(PHP_EOL, $filteredLines) . PHP_EOL;
                file_put_contents($envPath, $newEnvContent);
            }
        }

    };
