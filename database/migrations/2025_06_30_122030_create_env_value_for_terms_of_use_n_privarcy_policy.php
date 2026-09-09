<?php

    use Illuminate\Database\Migrations\Migration;

    return new class extends Migration {

        public function up()
        {
            // The environment file the application is CURRENTLY using.
            // Was base_path('.env'), which is identical in production —
            // there the active file IS base_path('.env') — but under
            // APP_ENV=testing the framework has loaded .env.testing, and
            // the test harness points the application at a disposable
            // copy before this migration runs. Resolved once and reused
            // by the file_exists guard, the read and the write below,
            // exactly as before.
            $envPath = app()->environmentFilePath();

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
