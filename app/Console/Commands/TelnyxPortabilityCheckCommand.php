<?php

namespace App\Console\Commands;

use App\Library\Messaging\PortIn\TelnyxPortabilityChecker;
use Illuminate\Console\Command;
use Throwable;

class TelnyxPortabilityCheckCommand extends Command
{
    protected $signature = 'telnyx:portability-check {numbers* : One or more numbers, e.g. +17205028306}';

    protected $description = 'Read-only: ask Telnyx whether numbers can be ported in. Creates no order, submits nothing, costs nothing.';

    public function handle(TelnyxPortabilityChecker $checker): int
    {
        try {
            $results = $checker->check($this->argument('numbers'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['number', 'portable', 'fast', 'messaging', 'reason'], array_map(
            fn ($n, $r) => [$n, $r['portable'] ? 'yes' : 'NO', var_export($r['fast_portable'], true), var_export($r['messaging_capable'], true), $r['reason'] ?? ''],
            array_keys($results), $results,
        ));

        return self::SUCCESS;
    }
}
