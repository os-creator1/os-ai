<?php

namespace App\Console\Commands;

use App\Library\Migration\Ghl\GhlImportPlanner;
use Illuminate\Console\Command;

class GhlPlanImportCommand extends Command
{
    protected $signature = 'ghl:plan-import {snapshot : Directory written by ghl:export-snapshot} {--out= : Write the full plan JSON here}';

    protected $description = 'Dry run: map a GHL snapshot to a MotionGrove import plan and print counts and issues. Writes nothing to the database and sends nothing.';

    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('snapshot'), '/');
        $snapshot = [];
        foreach (glob("{$dir}/*.json") as $file) {
            $name = basename($file, '.json');
            if ($name !== '_report') {
                $snapshot[$name] = json_decode(file_get_contents($file), true) ?? [];
            }
        }
        if (empty($snapshot['contacts'])) {
            $this->error('No contacts.json in snapshot.');

            return self::FAILURE;
        }

        $plan = (new GhlImportPlanner())->plan($snapshot);
        $this->line(json_encode($plan['counts'], JSON_PRETTY_PRINT));
        $codes = array_count_values(array_column($plan['issues'], 'code'));
        ksort($codes);
        $this->table(['issue', 'count'], array_map(fn ($k, $v) => [$k, $v], array_keys($codes), $codes));
        if ($out = $this->option('out')) {
            file_put_contents($out, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
