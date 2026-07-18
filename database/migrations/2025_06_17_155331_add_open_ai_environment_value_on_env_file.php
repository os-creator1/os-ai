<?php

    use Illuminate\Database\Migrations\Migration;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up()
        {
            $envPath = base_path('.env');

            // Key-value pairs to update
            $envUpdates = [
                'OPENAI_ACTIVE'       => 'false',
                'OPENAI_API_KEY'      => 'OpenAI_API_KEY',
                'OPENAI_MODEL'        => 'gpt-4o',
                'OPENAI_ORGANIZATION' => 'org-SxzREnJV749FjOOR7XuKcMqP',
                'OPENAI_PROJECT'      => 'proj_zR0x0L24D3q8d165055diJsp',
                'OPENAI_ROLE'         => 'user',
                'APP_TIME_FORMAT'     => "'g:i A'", // wrapped with single quotes
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
